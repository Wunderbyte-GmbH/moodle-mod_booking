<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Real-LLM multistep conversation tests.
 *
 * Scenario:
 * - create booking option
 * - assign Billy as teacher
 * - make the option visible
 *
 * Each mutating step must remain separately confirmed. The test acts as the
 * multistep DoD for the confirmation-flow work.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Multistep confirmation flow with a real LLM.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class confirmation_flow_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    /**
     * Create option, then update teacher, then make visible.
     */
    public function test_multistep_create_assign_teacher_and_make_visible(): void {
        global $DB;

        $this->setUser($this->teacher);

        $billy = $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Teacher',
            'email' => 'billy.teacher.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($billy->id, $this->course->id, 'editingteacher');

        [$store, $runtime, $threadid] = $this->build_runtime();

        if (!$this->is_skill_available('mod_booking.create_option')) {
            $this->enforcegeneratetextassertion = false;
            $this->fail('booking.create_option is not available in the current skill catalog.');
        }

        $title = 'Multistep Real LLM ' . uniqid('', true);

        // A confirmation is evidenced by the response type OR a queue-backed item — the WS payload
        // does not always carry the command array (the queue is the durable ground truth, same
        // handling as the skill matrix). All three steps confirm the staged action and assert the
        // deterministic DB effect instead of probing the payload command shape.
        $hasconfirmable = static function (array $result): bool {
            return (string)($result['response_type'] ?? '') === 'confirmation_request'
                || trim((string)($result['queueitemid'] ?? '')) !== '';
        };

        $result1 = $this->chat(
            'Create a booking option called "' . $title . '" with 8 spots, optiontype normal, '
                . 'start 2045-11-10T09:00:00, end 2045-11-10T11:00:00.',
            $threadid,
            $store,
            $runtime
        );
        if (!$hasconfirmable($result1)) {
            $result1 = $this->chat(
                'Please create a booking option called "' . $title . '" with 8 spots. '
                    . 'It should run from 2045-11-10T09:00:00 to 2045-11-10T11:00:00.',
                $threadid,
                $store,
                $runtime
            );
        }
        $this->assertTrue(
            $hasconfirmable($result1),
            'The agent must stage a confirmable create_option. Response type: '
                . (string)($result1['response_type'] ?? '')
                . ' Message: ' . $this->payload_text($result1)
        );

        $createconfirm = $this->confirm_pending_result($result1, (int)$threadid, $store, false);
        $this->assertTrue((bool)($createconfirm['success'] ?? false), (string)($createconfirm['message'] ?? ''));

        $option = $DB->get_record('booking_options', [
            'bookingid' => (int)$this->booking->id,
            'text' => $title,
        ]);
        $this->assertNotFalse($option, 'Created booking option must exist.');

        $result2 = $this->chat(
            'Make Billy Teacher responsible for "' . $title . '". Use teacher email "' . $billy->email . '".',
            $threadid,
            $store,
            $runtime
        );
        if (!$hasconfirmable($result2)) {
            $result2 = $this->chat(
                'Please assign Billy Teacher to the booking option "' . $title . '". '
                    . 'His email address is "' . $billy->email . '".',
                $threadid,
                $store,
                $runtime
            );
        }
        // The whole point of step 2 is that the agent CAN assign a teacher: it must stage a
        // confirmable action, and the deterministic post-condition below (Billy on the option)
        // decides whether the assignment really happened — not the staged command's shape.
        $this->assertTrue(
            $hasconfirmable($result2),
            'The agent must stage a confirmable teacher assignment. Response type: '
                . (string)($result2['response_type'] ?? '')
                . ' Message: ' . $this->payload_text($result2)
        );
        $teacherconfirm = $this->confirm_pending_result($result2, (int)$threadid, $store, false);
        $this->assertTrue((bool)($teacherconfirm['success'] ?? false), (string)($teacherconfirm['message'] ?? ''));

        $details = $this->exec_command('mod_booking.get_option_details', [
            'optionquery' => $title,
            'requested_fields' => ['title', 'teachers'],
            'includesessions' => false,
        ]);
        if ((string)($details['status'] ?? '') !== 'executed') {
            $details = $this->exec_command('mod_booking.get_option_details', [
                'optionid' => (int)$option->id,
                'requested_fields' => ['title', 'teachers'],
                'includesessions' => false,
            ]);
        }
        // Deterministic post-condition: Billy is now a teacher of the option (LLM prose may vary, this
        // does not). Asserted unconditionally — previously it degraded to a non-empty check that passed
        // even when the assignment never happened.
        $this->assertSame('executed', (string)($details['status'] ?? ''), 'get_option_details must succeed.');
        $teachers = json_encode($details['optiondetails'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString(
            'Billy',
            (string)$teachers,
            'The assigned teacher must appear on the option. Teachers: ' . (string)$teachers . ' Queue: '
                . json_encode(
                    (new \bookingextension_agent\local\wizard\queue\queue_manager($store))->get_queue_items((int)$threadid),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
        );

        // The planner may legitimately route the visibility change through the single-option
        // update OR through bulk_update_options with a precise target — both carry the same
        // schema fields and risk class, and the deterministic post-conditions decide, not the
        // skill choice. The hidden decoy pins the precision: it must STAY hidden, so a bulk
        // command is only acceptable when it hits exactly the named option (never apply_to_all).
        $decoy = $this->gen->create_option([
            'bookingid' => (int)$this->booking->id,
            'text' => 'Decoy stays hidden ' . uniqid('', true),
            'maxanswers' => 5,
            'type' => 0,
        ]);
        $DB->set_field('booking_options', 'invisible', MOD_BOOKING_OPTION_INVISIBLE, ['id' => (int)$decoy->id]);

        // Hide the target too, so "make it visible" is a real state transition (1 → 0) instead of
        // a no-op on an already-visible option — the no-op shape occasionally provoked an inverted
        // flag from the model, which reads as a product failure although nothing was ever hidden.
        $DB->set_field('booking_options', 'invisible', MOD_BOOKING_OPTION_INVISIBLE, ['id' => (int)$option->id]);

        $result3 = $this->chat(
            'Now make "' . $title . '" visible.',
            $threadid,
            $store,
            $runtime
        );
        if (!$hasconfirmable($result3)) {
            $result3 = $this->chat(
                'Please make the booking option "' . $title . '" visible.',
                $threadid,
                $store,
                $runtime
            );
        }

        // Step 3 must go through the AGENT confirmation flow (no direct-exec fallback, which would mask
        // an agent that stopped handling visibility). Require a confirmable action, confirm it, then
        // assert the deterministic effect unconditionally — the outcome decides, not the skill choice
        // or the staged input shape.
        $this->assertTrue(
            $hasconfirmable($result3),
            'The agent must stage a confirmable visibility update. Response type: '
                . (string)($result3['response_type'] ?? '')
                . ' Message: ' . $this->payload_text($result3)
        );
        $visibleconfirm = $this->confirm_pending_result($result3, (int)$threadid, $store, false);
        $this->assertTrue((bool)($visibleconfirm['success'] ?? false), (string)($visibleconfirm['message'] ?? ''));

        $updated = $this->get_option_from_db((int)$option->id);
        $this->assertSame(
            MOD_BOOKING_OPTION_VISIBLE,
            (int)$updated->invisible,
            'The named option must be visible after the confirmed update. Queue: '
                . json_encode(
                    (new \bookingextension_agent\local\wizard\queue\queue_manager($store))->get_queue_items((int)$threadid),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
        );
        $decoyafter = $this->get_option_from_db((int)$decoy->id);
        $this->assertSame(
            MOD_BOOKING_OPTION_INVISIBLE,
            (int)$decoyafter->invisible,
            'The visibility change must hit only the named option — the hidden decoy may never flip.'
        );
    }

    /**
     * Check if a skill currently exists in the registry.
     *
     * @param string $skillname
     * @return bool
     */
    private function is_skill_available(string $skillname): bool {
        $registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();
        return $registry->get_skill($skillname) !== null;
    }
}

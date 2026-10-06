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
 * Thread-589 replay: a clarification-blocked plan step stays owed and settles on success.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\external\ai_send_message;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\confirm_run_service;
use bookingextension_agent\local\wizard\services\queue_status_policy;
use bookingextension_agent\local\wizard\services\queue_transition_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Deterministic replay of the thread-589 defect through the real chat entry.
 *
 * Live sequence (2026-07-11): the first multi-step turn's create step blocked on a preflight
 * clarification; the step had no representation in the pending plan, so the next selector
 * prompt directed the model to the SCAFFOLD step ("Select the real skill for the next pending
 * step below: 1. Fülle Kurs …") and the course was never created, while a placeholder stood
 * succeeded at zero runs. With F5 the blocked step leads the pending list of the next turn,
 * nothing claims success before execution, and the step settles when the confirmed command
 * actually runs.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\queue\queue_manager
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 */
final class plan_resume_after_clarification_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Full replay: clarification keeps the step owed; the next selector sees it FIRST; the
     * confirmed execution settles exactly this step.
     */
    public function test_blocked_first_step_stays_owed_and_settles_on_success(): void {
        global $DB;

        // Two identically named targets make the activityquery ambiguous (needs_clarification),
        // one uniquely named target resolves the answer turn.
        $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id, 'name' => 'Dup target', 'bookingmanager' => 'admin',
        ]);
        $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id, 'name' => 'Dup target', 'bookingmanager' => 'admin',
        ]);
        $unique = $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id, 'name' => 'Unique target', 'bookingmanager' => 'admin',
        ]);

        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $queuesvc = new queue_manager($store);

        // Turn 1: multi-step plan; the current create step blocks on the ambiguous target.
        $this->install_scripted_planner([
            $this->selector_skill_call(
                'mod_booking.create_option',
                ['Zweiter Schritt: Option aktualisieren', 'Dritter Schritt: Teilnehmer buchen'],
                'Option in Dup target anlegen'
            ),
            $this->constructor_confirmation_request('mod_booking.create_option', [
                'text' => 'Wikinger Option',
                'activityquery' => 'Dup target',
                'maxanswers' => 10,
            ]),
        ]);
        $turn1 = ai_send_message::execute(
            $contextid,
            'Lege eine Option in Dup target an, danach zwei weitere Schritte.',
            $threadid
        );

        $this->assertSame('clarification', (string)($turn1['response_type'] ?? ''));
        $this->assertSame(0, $this->count_runs_with_results($threadid), 'Nothing may execute in turn 1.');

        $realitem1 = $this->find_real_item($queuesvc, $threadid, 'mod_booking.create_option');
        $this->assertSame(queue_status_policy::failed_status(), (string)$realitem1['status']);
        $this->assertSame(
            queue_transition_service::REASON_PREFLIGHT_NEEDS_CLARIFICATION,
            (string)($realitem1['reason_code'] ?? ''),
            'A category/target question is a clarification block, not a hard block.'
        );

        $intents = $queuesvc->get_planned_placeholder_intents($threadid);
        $this->assertCount(3, $intents, 'The blocked step plus both future steps are owed.');
        $this->assertStringStartsWith(
            'mod_booking.create_option',
            $intents[0],
            'The blocked current step must lead the pending list (thread 589).'
        );
        $this->assertSame(
            0,
            $this->count_placeholders_with_status($queuesvc, $threadid, queue_status_policy::succeeded_status()),
            'No placeholder may claim success at zero runs (the 544/589 lie).'
        );

        // Turn 2: the user answers; the selector prompt must show the owed step FIRST.
        $promptsbefore = count($this->scriptedplannerprompts);
        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option', [], 'Option in Unique target anlegen'),
            $this->constructor_confirmation_request('mod_booking.create_option', [
                'text' => 'Wikinger Option',
                'activityquery' => 'Unique target',
                'maxanswers' => 10,
            ]),
        ]);
        $turn2 = ai_send_message::execute($contextid, 'Nimm Unique target.', $threadid);

        $selectorprompt = (string)($this->scriptedplannerprompts[$promptsbefore] ?? '');
        $createpos = strpos($selectorprompt, 'mod_booking.create_option (text: Wikinger Option');
        $futurepos = strpos($selectorprompt, 'Zweiter Schritt: Option aktualisieren');
        $this->assertNotFalse($createpos, 'The turn-2 selector prompt must list the blocked create step.');
        $this->assertNotFalse($futurepos, 'The turn-2 selector prompt must list the future steps.');
        $this->assertLessThan($futurepos, $createpos, 'The owed create step must lead the pending block.');

        $this->assertSame('confirmation_request', (string)($turn2['response_type'] ?? ''));
        $realitem2 = $this->find_real_item($queuesvc, $threadid, 'mod_booking.create_option', 'blocked_confirmation');
        $boundid = (string)($realitem2['realizes_placeholder'] ?? '');
        $this->assertNotSame('', $boundid, 'The re-derived command must bind the owed placeholder.');
        $bound = $queuesvc->get_queue_item($threadid, $boundid);
        $this->assertSame(queue_status_policy::realizing_status(), (string)($bound['status'] ?? ''));

        // Confirm: the step executes; ONLY now may its placeholder become succeeded.
        $confirm = (new confirm_run_service(skill_registry::make_default(), $store, new authorization_service()))
            ->confirm($contextid, 0, $threadid, (int)$this->teacher->id, (string)$realitem2['queue_item_id'], false);

        $this->assertTrue((bool)($confirm['success'] ?? false), 'Confirm must execute the resolved step.');
        $this->assertSame(1, $this->count_runs_with_results($threadid));
        $bound = $queuesvc->get_queue_item($threadid, $boundid);
        $this->assertSame(queue_status_policy::succeeded_status(), (string)($bound['status'] ?? ''));
        $this->assertSame(
            2,
            $this->count_placeholders_with_status($queuesvc, $threadid, queue_status_policy::planned_status()),
            'The two future steps stay owed after step one succeeded.'
        );
        $this->assertTrue(
            $DB->record_exists('booking_options', [
                'bookingid' => (int)$unique->id,
                'text' => 'Wikinger Option',
            ]),
            'The option must exist in the uniquely resolved target.'
        );
    }

    /**
     * Find the newest real (non-placeholder) queue item for a skill, optionally by status.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $skill
     * @param string $status Optional status filter.
     * @return array
     */
    private function find_real_item(queue_manager $queuesvc, int $threadid, string $skill, string $status = ''): array {
        $found = null;
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if ((string)($item['skill'] ?? '') !== $skill) {
                continue;
            }
            if ($status !== '' && (string)($item['status'] ?? '') !== $status) {
                continue;
            }
            $found = $item;
        }
        $this->assertIsArray($found, 'Expected a ' . $skill . ' queue item' . ($status !== '' ? ' in ' . $status : ''));
        return $found;
    }

    /**
     * Count placeholders in a given status.
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $status
     * @return int
     */
    private function count_placeholders_with_status(queue_manager $queuesvc, int $threadid, string $status): int {
        $count = 0;
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if (
                (string)($item['skill'] ?? '') === '__placeholder__'
                && (string)($item['status'] ?? '') === $status
            ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Count runs that produced results for the thread.
     *
     * @param int $threadid
     * @return int
     */
    private function count_runs_with_results(int $threadid): int {
        global $DB;
        $count = 0;
        foreach ($DB->get_records('bx_agent_ai_runs', ['threadid' => $threadid]) as $run) {
            $results = json_decode((string)($run->resultsjson ?? ''), true);
            if (is_array($results) && !empty($results)) {
                $count++;
            }
        }
        return $count;
    }
}

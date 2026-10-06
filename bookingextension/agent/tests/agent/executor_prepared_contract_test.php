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
 * Executor prepared-input contract tests (thread 590 N1).
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\preflight_execution_gate;

/**
 * The prepared input is preflight's contract, not the planner schema's.
 *
 * Thread 590 N1: preflight canonicalizes prompt-facing keys (linkedcoursequery →
 * coursequery), but the executor re-ran check_structure() — the PLANNER-phase
 * validator (ch. 14 §1) — on that prepared input and rejected the engine's own
 * mapping ("Unknown properties: coursequery"). The fix: a verified guard token
 * attests the input byte-for-byte as preflight-approved, so no structural
 * re-check runs for token-bound mutating commands; only token-less read-only
 * commands keep the lightweight structural guard.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\executor
 */
final class executor_prepared_contract_test extends abstract_agent_testcase {
    /**
     * Thread-590 replay: prepared input carrying the engine-canonical coursequery
     * (mapped from linkedcoursequery in preflight) must execute, not die at the
     * executor with "Unknown properties: coursequery".
     */
    public function test_prepared_input_with_canonical_coursequery_executes(): void {
        global $DB;
        // Admin: the course resolver only offers courses the acting user may see.
        $this->setAdminUser();
        $admin = get_admin();

        $linked = $this->getDataGenerator()->create_course([
            'fullname' => 'Das Leben der Wikinger',
            'shortname' => 'wikinger_' . uniqid(),
        ]);

        $contextid = $this->booking_contextid();
        $skill = \bookingextension_agent\local\wizard\skill_registry::make_default()
            ->get_skill('mod_booking.create_option');
        $dto = $skill->preflight([
            'text' => 'Wikinger Selbstlernkurs',
            'maxanswers' => 20,
            'linkedcoursequery' => 'Das Leben der Wikinger',
        ], $contextid, (int)$admin->id);

        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assertSame(
            'Das Leben der Wikinger',
            $prepared['coursequery'] ?? null,
            'Precondition: preflight canonicalizes linkedcoursequery to coursequery.'
        );

        $result = $this->exec_command('mod_booking.create_option', $prepared, null, (int)$admin->id);

        $this->assertStringNotContainsString(
            'Unknown properties',
            (string)($result['detail'] ?? ''),
            json_encode($result)
        );
        $this->assertSame('executed', (string)($result['status'] ?? ''), json_encode($result));

        $option = $DB->get_record('booking_options', ['text' => 'Wikinger Selbstlernkurs'], '*', MUST_EXIST);
        $this->assertSame(
            (int)$linked->id,
            (int)$option->courseid,
            'The linked course must land on the created option.'
        );
    }

    /**
     * Order flip guard: a mutating command without a token reports the GUARD error,
     * never a structural one — the structural check must not run first anymore.
     */
    public function test_mutating_command_without_token_reports_guard_missing(): void {
        $result = $this->execute_raw_command([
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            // Deliberately structurally invalid (unknown key) — the guard must win.
            'input' => ['text' => 'x', 'coursequery' => 'y'],
        ]);

        $this->assertContains(
            'EXECUTION_GUARD_MISSING',
            (array)($result['issue_codes'] ?? []),
            json_encode($result)
        );
    }

    /**
     * A tampered prepared input (token built from different input) still blocks hard.
     */
    public function test_guard_token_mismatch_still_blocks(): void {
        $contextid = $this->booking_contextid();
        $result = $this->execute_raw_command([
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => ['text' => 'tampered'],
            'guard_token' => preflight_execution_gate::build_guard_token(
                'mod_booking.create_option',
                $contextid,
                ['text' => 'original']
            ),
        ]);

        $this->assertContains(
            'EXECUTION_GUARD_MISMATCH',
            (array)($result['issue_codes'] ?? []),
            json_encode($result)
        );
    }

    /**
     * The token-less read-only path keeps the lightweight structural guard
     * (chat read-only executes without the preflight pipeline, thread 542).
     */
    public function test_readonly_structural_guard_still_active(): void {
        // Empty input: get_option_details requires optionid/optionids/optionquery.
        $result = $this->exec_command('mod_booking.get_option_details', []);

        $this->assertSame('error', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertStringNotContainsString('guard', strtolower((string)($result['detail'] ?? '')));
    }

    /**
     * Run one command through the executor verbatim (no token auto-attach).
     *
     * @param array $command
     * @return array
     */
    private function execute_raw_command(array $command): array {
        $this->setUser($this->teacher);
        $contextid = $this->booking_contextid();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$this->teacher->id, $contextid);
        $key = hash('sha256', 'prepared_contract:' . uniqid('', true));
        $runid = $store->create_run($thread->id, (int)$this->teacher->id, $contextid, $key, []);

        $results = $this->make_executor()->execute_commands(
            [$command],
            $contextid,
            (int)$this->teacher->id,
            $key,
            $runid
        );

        return $results[0];
    }
}

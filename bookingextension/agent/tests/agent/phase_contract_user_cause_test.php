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

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;

/**
 * Phase-contract violations never leak planner vocabulary to the user (N-591a).
 *
 * Thread 591 msg 1601: the raw string "CONTRACT_VIOLATION: phase parameter_construction
 * command skill is outside discovery-ranked allow-list." reached the user verbatim, because
 * the interpreter put it into message/errors AND the finalization classifier routed the
 * CONTRACT_PHASE_* family direct_final. Decision (George, 2026-07-14, option C): two-channel
 * split at the interpreter (plain user_cause in message/errors, technical detail in
 * repair_hints) + llm_polish routing so the synchronizer formulates the reply.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\interpreter
 */
final class phase_contract_user_cause_test extends \advanced_testcase {
    /**
     * Invoke the interpreter's phase guard for a construction command outside the allow-list.
     *
     * @return array The error result produced by the guard.
     */
    private function trigger_skill_not_allowed(): array {
        $interpreter = new interpreter(skill_registry_factory::get_default());
        $method = new \ReflectionMethod($interpreter, 'enforce_phase_contract');
        $method->setAccessible(true);

        return $method->invoke($interpreter, [
            'response_type' => 'skill_call',
            'commands' => [
                ['skill' => 'mod_booking.update_option', 'version' => 1, 'input' => []],
            ],
            'message' => 'Executing.',
        ], 'parameter_construction', [
            'allowed_skills' => ['course.add_activity'],
        ]);
    }

    /**
     * The guard result carries a plain user cause; the technical string is planner-only.
     */
    public function test_phase_guard_splits_user_cause_and_repair_channels(): void {
        $result = $this->trigger_skill_not_allowed();

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PHASE_SKILL_NOT_ALLOWED', $result['issue_codes']);

        // User channel (message + errors): plain cause, no planner vocabulary.
        $userchannel = trim((string)$result['message']) . ' ' . implode(' ', (array)$result['errors']);
        $this->assertStringNotContainsString('CONTRACT_VIOLATION', $userchannel);
        $this->assertStringNotContainsString('allow-list', $userchannel);
        $this->assertStringNotContainsString('parameter_construction', $userchannel);
        $this->assertStringContainsString('planning error', (string)$result['message']);
        $this->assertNotEmpty($result['errors']);

        // Planner channel (repair_hints): the full technical detail, incl. the attempted
        // skill and the allow-list — this is what a re-plan round needs.
        $repair = implode(' ', (array)($result['repair_hints'] ?? []));
        $this->assertStringContainsString('CONTRACT_VIOLATION', $repair);
        $this->assertStringContainsString('mod_booking.update_option', $repair);
        $this->assertStringContainsString('course.add_activity', $repair);
    }

    /**
     * The synchronizer's [ERROR] observation built from the guard result stays clean.
     */
    public function test_sync_error_observation_carries_no_planner_vocabulary(): void {
        $result = $this->trigger_skill_not_allowed();

        $builder = new synchronizer_input_builder();
        $method = new \ReflectionMethod($builder, 'build_error_observation');
        $method->setAccessible(true);
        $observation = (string)$method->invoke($builder, $result);

        $this->assertNotSame('', $observation);
        $this->assertStringNotContainsString('CONTRACT_VIOLATION', $observation);
        $this->assertStringNotContainsString('allow-list', $observation);
        $this->assertStringContainsString('planning error', $observation);
    }
}

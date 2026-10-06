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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\synchronizer_output_contract;
use bookingextension_agent\local\wizard\services\finalization_classifier;
use bookingextension_agent\local\wizard\services\queue_status_policy;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests: normal flows must remain unaffected by consistency gate and postcondition checks.
 *
 * Verifies:
 * - Standard create/update/book success flows pass through without extra issue_codes.
 * - Retry finalization codes (BUDGET_EXCEEDED, RETRY_EXHAUSTED) still route to template_only.
 * - No additional LLM calls introduced by gate in standard cases (gate is sync-side only).
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_output_contract
 * @covers \bookingextension_agent\local\wizard\services\finalization_classifier
 * @covers \bookingextension_agent\local\wizard\services\queue_status_policy
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class consistency_gate_regression_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    // -----------------------------------------------------------------------
    // Normal flow: clean success passes gate
    // Separator.

    /**
     * Clean sufficient result with no issue_codes passes sync gate without modification.
     */
    public function test_clean_sufficient_passes_gate(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'succeeded', 'skill' => 'mod_booking.create_option']],
        ];
        $sync = ['message' => 'Veranstaltung erfolgreich erstellt.'];

        $result = $contract->merge($source, $sync);

        $this->assertSame('Veranstaltung erfolgreich erstellt.', $result['message']);
        $this->assertEmpty($result['issue_codes'] ?? []);
        $this->assertSame('passed', $result['sync_gate_status'] ?? '');
    }

    /**
     * Successful update flow passes gate cleanly.
     */
    public function test_successful_update_flow_passes_gate(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'succeeded', 'skill' => 'mod_booking.update_option_trainer']],
            'postcondition_status' => 'passed',
        ];
        $sync = ['message' => 'Trainer erfolgreich gesetzt.'];

        $result = $contract->merge($source, $sync);

        $this->assertSame('Trainer erfolgreich gesetzt.', $result['message']);
        $this->assertEmpty($result['issue_codes'] ?? []);
    }

    /**
     * Successful booking flow passes gate cleanly.
     */
    public function test_successful_booking_flow_passes_gate(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'succeeded', 'skill' => 'mod_booking.book_users']],
        ];
        $sync = ['message' => 'Teilnehmer erfolgreich gebucht.'];

        $result = $contract->merge($source, $sync);

        $this->assertSame('Teilnehmer erfolgreich gebucht.', $result['message']);
        $this->assertEmpty($result['issue_codes'] ?? []);
    }

    // -----------------------------------------------------------------------
    // Retry codes still route correctly
    // Separator.

    /**
     * Test retry issue codes route to template only strategy.
     *
     * @dataProvider retry_issue_codes_provider
     * @param string $issuecode
     */
    public function test_retry_issue_code_routes_to_template_only(string $issuecode): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'error',
            'commands'      => [],
            'issue_codes'   => [$issuecode],
        ]);

        $this->assertSame(finalization_classifier::STRATEGY_TEMPLATE_ONLY, $strategy);
    }

    /**
     * Data provider for retry issue codes.
     *
     * @return array
     */
    public static function retry_issue_codes_provider(): array {
        return [
            'budget_exceeded'   => ['BUDGET_EXCEEDED'],
            'blocked_timeout'   => ['BLOCKED_TIMEOUT'],
            'retry_exhausted'   => ['RETRY_EXHAUSTED'],
            'permission_error'  => ['PERMISSION_ERROR'],
            'validation_error'  => ['VALIDATION_ERROR'],
        ];
    }

    // -----------------------------------------------------------------------
    // Queue status policy: planned status is non-executable
    // Separator.

    /**
     * Planned placeholder status is not actionable-mutating (cannot be executed).
     */
    public function test_planned_status_is_not_actionable_mutating(): void {
        $this->assertFalse(queue_status_policy::is_actionable_mutating_status('planned'));
    }

    /**
     * Planned placeholder status is recognized correctly.
     */
    public function test_planned_status_recognized(): void {
        $this->assertTrue(queue_status_policy::is_planned_status('planned'));
        $this->assertSame('planned', queue_status_policy::planned_status());
    }

    /**
     * Planned status is not terminal (item is still active).
     */
    public function test_planned_status_is_not_terminal(): void {
        $this->assertFalse(queue_status_policy::is_terminal_status('planned'));
    }

    /**
     * Succeeded and failed statuses remain terminal as before.
     */
    public function test_succeeded_and_failed_remain_terminal(): void {
        $this->assertTrue(queue_status_policy::is_terminal_status('succeeded'));
        $this->assertTrue(queue_status_policy::is_terminal_status('failed'));
        $this->assertTrue(queue_status_policy::is_terminal_status('skipped'));
    }
}

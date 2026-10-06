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
use PHPUnit\Framework\TestCase;

/**
 * Contract tests: postcondition enforcement and consistency gate issue_codes.
 *
 * Verifies that:
 * - Failed postconditions block sufficient success (SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED).
 * - Sync fact conflicts produce deterministic issue_codes (SYNC_FACT_CONFLICT_REJECTED).
 * - All SYNC_* issue_codes route to template_only (never llm_polish → no retry loop).
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_output_contract
 * @covers \bookingextension_agent\local\wizard\services\finalization_classifier
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronizer_output_contract_postcondition_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    // -----------------------------------------------------------------------
    // Postcondition enforcement
    // Separator.

    /**
     * Failed postcondition blocks merge and produces SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED.
     */
    public function test_failed_postcondition_blocks_merge(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type'      => 'sufficient',
            'commands'           => [],
            'issue_codes'        => [],
            'postcondition_status' => 'failed',
            'results'            => [],
        ];
        $sync = ['message' => 'Trainer erfolgreich zugewiesen.'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED', $result['issue_codes']);
        $this->assertSame('failed', $result['sync_gate_status'] ?? '');
    }

    /**
     * Passed postcondition allows merge through.
     */
    public function test_passed_postcondition_allows_merge(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type'      => 'sufficient',
            'commands'           => [],
            'issue_codes'        => [],
            'postcondition_status' => 'passed',
            'results'            => [],
        ];
        $sync = ['message' => 'Trainer erfolgreich zugewiesen.'];

        $result = $contract->merge($source, $sync);

        $this->assertNotContains('SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame('Trainer erfolgreich zugewiesen.', $result['message']);
    }

    /**
     * Missing postcondition_status (absent) does not block merge.
     */
    public function test_absent_postcondition_status_does_not_block(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [],
        ];
        $sync = ['message' => 'Veranstaltung erstellt.'];

        $result = $contract->merge($source, $sync);

        $this->assertSame('Veranstaltung erstellt.', $result['message']);
    }

    // -----------------------------------------------------------------------
    // Consistency gate issue_codes
    // Separator.

    /**
     * Source error response_type produces SYNC_SOURCE_RESPONSE_ERROR_REJECTED.
     */
    public function test_source_error_response_type_rejected(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [],
        ];
        $sync = ['message' => 'Alles okay.'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', $result['issue_codes']);
    }

    /**
     * Sync command payload is always rejected (synchronizer must never emit commands).
     */
    public function test_sync_command_payload_rejected(): void {
        $contract = new synchronizer_output_contract();
        // A REAL sufficient source (with its own message): stray sync commands must reject.
        // The empty-message flavour sanitizes instead (#2253) and is pinned separately in
        // sync_empty_source_message_sanitization_test.
        $source = [
            'response_type' => 'sufficient',
            'message'       => 'Die Option wurde erstellt.',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [],
        ];
        $sync = [
            'message'  => 'Okay.',
            'commands' => [['skill' => 'mod_booking.create_option']],
        ];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_COMMAND_PAYLOAD_REJECTED', $result['issue_codes']);
    }

    /**
     * Empty sync message produces SYNC_EMPTY_MESSAGE.
     */
    public function test_empty_sync_message_rejected(): void {
        $contract = new synchronizer_output_contract();
        $source = ['response_type' => 'sufficient', 'commands' => [], 'issue_codes' => [], 'results' => []];
        $sync   = ['message' => ''];

        $result = $contract->merge($source, $sync);

        $this->assertSame('failed', $result['sync_gate_status'] ?? '');
        $this->assertSame('SYNC_EMPTY_MESSAGE', $result['sync_gate_reason'] ?? '');
    }

    // -----------------------------------------------------------------------
    // Finalization classifier: SYNC_* codes → template_only (no retry loop)
    // Separator.

    /**
     * Test that sync issue codes route to template only strategy.
     *
     * @dataProvider sync_issue_codes_provider
     * @param string $issuecode
     */
    public function test_sync_issue_code_routes_to_template_only(string $issuecode): void {
        $classifier = new finalization_classifier();

        $strategy = $classifier->classify([
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [$issuecode],
        ]);

        $this->assertSame(
            finalization_classifier::STRATEGY_TEMPLATE_ONLY,
            $strategy,
            "Issue code {$issuecode} must route to template_only to prevent retry loops."
        );
    }

    /**
     * Data provider for sync issue codes.
     *
     * @return array
     */
    public static function sync_issue_codes_provider(): array {
        return [
            'fact_conflict'          => ['SYNC_FACT_CONFLICT_REJECTED'],
            'result_status_conflict' => ['SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED'],
            'postcondition_failed'   => ['SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED'],
            'response_error'         => ['SYNC_SOURCE_RESPONSE_ERROR_REJECTED'],
            'command_payload'        => ['SYNC_COMMAND_PAYLOAD_REJECTED'],
            'empty_message'          => ['SYNC_EMPTY_MESSAGE'],
            'raw_excerpt'            => ['SYNC_RAW_EXCERPT_REJECTED'],
        ];
    }
}

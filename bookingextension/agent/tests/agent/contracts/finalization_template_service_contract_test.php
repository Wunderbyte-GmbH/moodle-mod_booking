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

use bookingextension_agent\local\wizard\services\finalization_template_service;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for deterministic template-only finalization messages.
 *
 * @covers \bookingextension_agent\local\wizard\services\finalization_template_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class finalization_template_service_contract_test extends TestCase {
    /**
     * Known issue code maps to deterministic message.
     */
    public function test_resolves_message_from_issue_code(): void {
        $service = new finalization_template_service();

        $message = $service->resolve_message([
            'issue_codes' => ['BUDGET_EXCEEDED'],
        ]);

        $this->assertStringContainsString('loop budget is exhausted', $message);
    }

    /**
     * Known error class maps to deterministic message when no issue code matches.
     */
    public function test_resolves_message_from_error_class(): void {
        $service = new finalization_template_service();

        $message = $service->resolve_message([
            'issue_codes' => [],
            'error_class' => 'provider_timeout',
        ]);

        $this->assertStringContainsString('timed out', $message);
    }

    /**
     * Issue-code mapping has precedence over error-class mapping.
     */
    public function test_issue_code_precedence_over_error_class(): void {
        $service = new finalization_template_service();

        $message = $service->resolve_message([
            'issue_codes' => ['PERMISSION_ERROR'],
            'error_class' => 'provider_timeout',
        ]);

        $this->assertStringContainsString('permissions', $message);
    }

    /**
     * Unknown values return empty message to allow higher-level fallback logic.
     */
    public function test_returns_empty_message_for_unknown_values(): void {
        $service = new finalization_template_service();

        $message = $service->resolve_message([
            'issue_codes' => ['UNKNOWN_ISSUE'],
            'error_class' => 'unknown_error',
        ]);

        $this->assertSame('', $message);
    }

    /**
     * A dead provider key resolves to the specific auth message even when the
     * synchronizer itself could not run (no error_class on the final result).
     */
    public function test_resolves_trial_token_invalid_issue_code(): void {
        $service = new finalization_template_service();

        $message = $service->resolve_message([
            'response_type' => 'error',
            'issue_codes' => ['TRIAL_TOKEN_INVALID'],
        ]);

        $this->assertStringContainsStringIgnoringCase('authentication failed', $message);
    }

    /**
     * An exhausted provider quota resolves to the specific quota message from
     * the issue code alone.
     */
    public function test_resolves_provider_quota_issue_code(): void {
        $service = new finalization_template_service();

        $message = $service->resolve_message([
            'response_type' => 'error',
            'issue_codes' => ['AI_PROVIDER_QUOTA_EXCEEDED'],
        ]);

        $this->assertStringContainsStringIgnoringCase('quota', $message);
    }
}

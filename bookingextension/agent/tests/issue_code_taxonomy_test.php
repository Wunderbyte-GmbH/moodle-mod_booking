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
 * Tests for the canonical issue_code_taxonomy (audit C3-F02 / 08-F01).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\issue_code_taxonomy;

/**
 * Locks the SINGLE classification walk both issue-code views project from.
 *
 * The two views (display error_class, retry category) previously used opposite substring
 * precedence, which made DOMAIN_CHECK_TIMEOUT "a timeout" for the user but a terminal DOMAIN
 * error for the retry engine (thread 549 defect 2). Consolidated per George 2026-07-10:
 * one ordered rule table, retryable families first.
 *
 * @covers \bookingextension_agent\local\wizard\services\issue_code_taxonomy
 */
final class issue_code_taxonomy_test extends \advanced_testcase {
    /**
     * error_class_for: first match wins (TIMEOUT before TRANSIENT/PERMISSION/CONFLICT/VALIDATION).
     */
    public function test_error_class_precedence(): void {
        $this->assertSame('provider_timeout', issue_code_taxonomy::error_class_for(['SOME_TIMEOUT']));
        $this->assertSame('transient_io', issue_code_taxonomy::error_class_for(['TRANSIENT_IO']));
        $this->assertSame('permission_error', issue_code_taxonomy::error_class_for(['PERMISSION_DENIED']));
        $this->assertSame('domain_conflict', issue_code_taxonomy::error_class_for(['DOMAIN_CONFLICT']));
        $this->assertSame('validation_error', issue_code_taxonomy::error_class_for(['MISSING_FIELD']));
        $this->assertSame('', issue_code_taxonomy::error_class_for(['AUTH_FAILED']));
        // TIMEOUT is checked before PERMISSION, so a code carrying both is a timeout here.
        $this->assertSame('provider_timeout', issue_code_taxonomy::error_class_for(['PERMISSION_TIMEOUT']));
    }

    /**
     * Both views agree on every code — one precedence, one deciding rule (the 549 fix).
     */
    public function test_both_views_share_one_precedence(): void {
        // The thread-549 trap: DOMAIN_CHECK_TIMEOUT is a timeout (retryable) in BOTH views.
        $this->assertSame('provider_timeout', issue_code_taxonomy::error_class_for(['DOMAIN_CHECK_TIMEOUT']));
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('', ['DOMAIN_CHECK_TIMEOUT'], '')
        );

        // Ambiguity doctrine: retryable interpretation wins for composite codes.
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('', ['PERMISSION_TIMEOUT'], '')
        );

        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('', ['SOME_TIMEOUT'], '')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_EXTERNAL_DEPENDENCY,
            issue_code_taxonomy::retry_category_for('', ['RATE_LIMIT'], '')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_DOMAIN,
            issue_code_taxonomy::retry_category_for('', ['DOMAIN_CONFLICT'], '')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_DOMAIN,
            issue_code_taxonomy::retry_category_for('', ['VALIDATION_ERROR'], '')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_DOMAIN,
            issue_code_taxonomy::retry_category_for('', ['PERMISSION_DENIED'], '')
        );

        // The FIRST code in the list decides — for both views identically.
        $this->assertSame('', issue_code_taxonomy::error_class_for(['AUTH_FAILED', 'MISSING_FIELD']));
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_EXTERNAL_DEPENDENCY,
            issue_code_taxonomy::retry_category_for('', ['AUTH_FAILED', 'MISSING_FIELD'], '')
        );
    }

    /**
     * retry_category_for: error-class and layer fallbacks when no issue code matches.
     */
    public function test_retry_category_fallbacks(): void {
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('provider_timeout', [], '')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('something', [], 'execution')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_UNDEFINED,
            issue_code_taxonomy::retry_category_for('', [], 'execution')
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_UNDEFINED,
            issue_code_taxonomy::retry_category_for('', [], '')
        );
    }
}

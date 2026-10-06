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
 * Taxonomy pin for the anonymizer collision gate issue code (#2226 D3).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\issue_code_taxonomy;

/**
 * ANON_PERSON_REFERENCE_VALIDATION is deliberately named so the existing
 * VALIDATION rule classifies it as a non-retryable domain validation error —
 * a hint-less retry re-fails identically. This pins the name choice against
 * future taxonomy edits (a TRANSIENT/SELECTION match would make it retryable).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\issue_code_taxonomy
 */
final class issue_taxonomy_anon_code_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * The gate code projects to validation_error / non-retryable domain in both views.
     */
    public function test_gate_code_classifies_as_validation_error(): void {
        $this->assertSame(
            'validation_error',
            issue_code_taxonomy::error_class_for(['ANON_PERSON_REFERENCE_VALIDATION']),
            'The gate code must match the VALIDATION display rule.'
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_DOMAIN,
            issue_code_taxonomy::retry_category_for('', ['ANON_PERSON_REFERENCE_VALIDATION']),
            'Without new user input the gate re-fails identically — it must classify as non-retryable domain.'
        );
    }
}

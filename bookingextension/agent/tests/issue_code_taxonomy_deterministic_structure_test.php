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
 * Deterministic structure/schema failures must be non-retryable (audit C4, thread 590).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\issue_code_taxonomy;
use bookingextension_agent\local\wizard\services\preflight_error_classifier;
use bookingextension_agent\local\wizard\services\retry_policy_service;

/**
 * Pins the taxonomy rule "structure/schema failure => terminal" the C4 fix introduces.
 *
 * Thread 590, runs 214 -> 215: the framework retried an IDENTICAL command after an identical
 * structural failure ("Create option schema mismatch") — a deterministic error that no retry
 * can ever heal. The engine's structure/schema issue codes (SCHEMA_ERROR from the preflight
 * pipeline/contract validator fallback, SCHEMA_UNAVAILABLE from preflight_schema_validator,
 * error_class 'schema_error' from the schema/version validators) match NO rule in the ordered
 * walk today, so the retry view either drops to UNDEFINED or — worse — the execution-layer
 * fallback turns them into retryable CATEGORY_TECHNICAL.
 *
 * @covers \bookingextension_agent\local\wizard\services\issue_code_taxonomy
 */
final class issue_code_taxonomy_deterministic_structure_test extends \advanced_testcase {
    /**
     * The retry view must classify structure/schema codes as terminal CATEGORY_DOMAIN.
     */
    public function test_schema_codes_classify_domain_in_retry_view(): void {
        foreach (['SCHEMA_ERROR', 'SCHEMA_UNAVAILABLE'] as $code) {
            $this->assertSame(
                issue_code_taxonomy::CATEGORY_DOMAIN,
                issue_code_taxonomy::retry_category_for('', [$code], ''),
                $code . ' is a deterministic structure/schema failure: identical input can never heal it'
                . ' (thread 590, runs 214 -> 215 retried the identical "Create option schema mismatch").'
                . ' The taxonomy rule walk must classify it terminal (CATEGORY_DOMAIN) instead of'
                . ' leaving it unmatched.'
            );
        }
    }

    /**
     * The thread-590 shape exactly: schema failure carried through the execution layer.
     *
     * Today first_match() finds no rule for SCHEMA_ERROR, and the execution-layer fallback
     * ("execution" + non-empty error class) declares the failure retryable TECHNICAL — this is
     * the hole that let runs 214 -> 215 retry a deterministic schema mismatch.
     */
    public function test_schema_error_survives_execution_layer_fallback(): void {
        $category = issue_code_taxonomy::retry_category_for('schema_error', ['SCHEMA_ERROR'], 'execution');

        $this->assertSame(
            issue_code_taxonomy::CATEGORY_DOMAIN,
            $category,
            'SCHEMA_ERROR at the execution layer must stay terminal: the structure rule has to win in'
            . ' the ordered walk BEFORE the execution-layer TECHNICAL fallback can fire (thread 590,'
            . ' runs 214 -> 215: the fallback made a deterministic schema mismatch retryable).'
        );
        $this->assertFalse(
            (new retry_policy_service())->is_retryable_category($category),
            'The retry gate must reject the schema-failure category as non-retryable.'
        );
    }

    /**
     * View coherence: the display view must never call a schema failure a retryable class.
     *
     * Both views project from the ONE ordered rule walk; whatever error_class the structure
     * rule assigns, it must not be one of the retryable execution classes.
     */
    public function test_display_view_agrees_schema_is_terminal(): void {
        $errorclass = issue_code_taxonomy::error_class_for(['SCHEMA_ERROR']);
        $this->assertFalse(
            preflight_error_classifier::is_retryable_error_class($errorclass),
            'The display view may not classify SCHEMA_ERROR as a retryable error class ("'
            . $errorclass . '") while the retry view calls it terminal — one walk, one precedence.'
        );
    }

    /**
     * Direction guard: the new structure rule must respect the first-match ambiguity doctrine.
     *
     * Retryable families stay FIRST in the walk (George 2026-07-10): a composite code that also
     * carries a timeout keeps classifying as a retryable timeout in BOTH views. The structure
     * rule therefore belongs in the terminal DOMAIN block, not in front of the timeout rules.
     */
    public function test_composite_timeout_code_stays_retryable_in_both_views(): void {
        $this->assertSame(
            'provider_timeout',
            issue_code_taxonomy::error_class_for(['SCHEMA_CHECK_TIMEOUT']),
            'TIMEOUT must keep winning over the structure family in the display view.'
        );
        $this->assertSame(
            issue_code_taxonomy::CATEGORY_TECHNICAL,
            issue_code_taxonomy::retry_category_for('', ['SCHEMA_CHECK_TIMEOUT'], ''),
            'TIMEOUT must keep winning over the structure family in the retry view.'
        );
    }
}

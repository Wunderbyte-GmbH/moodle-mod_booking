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
 * Unit tests for agent_decision_service and supporting infrastructure.
 *
 * Tests the new decision layer and related infrastructure introduced by the
 * agent architecture refactor:
 *  - agent_decision_service  (extracted from agent_runtime::decide())
 *  - ai_error_classifier     (unified error classification)
 *  - result_payload_summarizer (unified observation generation)
 *
 * No LLM calls are made; all tests are deterministic.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use mod_booking\local\wbagent\ai_error_classifier;
use mod_booking\local\wbagent\privacy_anonymizer;
use mod_booking\local\wbagent\result_payload_summarizer;

/**
 * Tests for agent_decision_service and supporting infrastructure.
 *
 * @group mod_booking
 * @group mod_booking_agent
 * @coversNothing
 */
final class agent_decision_service_test extends advanced_testcase {

    // -------------------------------------------------------------------------
    // ai_error_classifier: classify_from_response.

    /**
     * HTTP 401 maps to TRIAL_TOKEN_INVALID.
     */
    public function test_error_classifier_401_maps_to_trial_token_invalid(): void {
        $codes = ai_error_classifier::classify_from_response('Unauthorized', 401, 'AuthenticationError');
        $this->assertContains('TRIAL_TOKEN_INVALID', $codes);
    }

    /**
     * HTTP 429 maps to AI_PROVIDER_QUOTA_EXCEEDED.
     */
    public function test_error_classifier_429_maps_to_quota_exceeded(): void {
        $codes = ai_error_classifier::classify_from_response('Rate limit exceeded', 429);
        $this->assertContains('AI_PROVIDER_QUOTA_EXCEEDED', $codes);
    }

    /**
     * String-based token error detection.
     */
    public function test_error_classifier_string_invalid_api_key(): void {
        $codes = ai_error_classifier::classify_from_response('Invalid API key provided.');
        $this->assertContains('TRIAL_TOKEN_INVALID', $codes);
    }

    /**
     * String-based quota detection.
     */
    public function test_error_classifier_string_insufficient_quota(): void {
        $codes = ai_error_classifier::classify_from_response('You exceeded your current quota (insufficient_quota).');
        $this->assertContains('AI_PROVIDER_QUOTA_EXCEEDED', $codes);
    }

    /**
     * Unrecognized error returns empty array.
     */
    public function test_error_classifier_unknown_error_returns_empty(): void {
        $codes = ai_error_classifier::classify_from_response('Some random network error', 500);
        $this->assertSame([], $codes);
    }

    /**
     * Empty message returns empty array.
     */
    public function test_error_classifier_empty_message_returns_empty(): void {
        $codes = ai_error_classifier::classify_from_response('', 0);
        $this->assertSame([], $codes);
    }

    // -------------------------------------------------------------------------
    // result_payload_summarizer: for_observation.

    /**
     * Options list produces a correct observation string.
     */
    public function test_observation_summarizer_options(): void {
        $results = [[
            'options' => [
                ['name' => 'Yoga'],
                ['text' => 'Pilates'],
            ],
        ]];
        $obs = result_payload_summarizer::for_observation($results, 1);
        $this->assertStringContainsString('Step 1:', $obs);
        $this->assertStringContainsString('2 booking option(s)', $obs);
        $this->assertStringContainsString('Yoga', $obs);
        $this->assertStringContainsString('Pilates', $obs);
    }

    /**
     * Users list produces a correct observation string.
     */
    public function test_observation_summarizer_users(): void {
        $results = [['users' => [['userid' => 1], ['userid' => 2]]]];
        $obs = result_payload_summarizer::for_observation($results, 2);
        $this->assertStringContainsString('2 user(s)', $obs);
    }

    /**
     * Empty results fall back to a generic success message.
     */
    public function test_observation_summarizer_empty_fallback(): void {
        $obs = result_payload_summarizer::for_observation([], 3);
        $this->assertSame('Step 3: Tool executed successfully.', $obs);
    }

    /**
     * Courses list is detected correctly.
     */
    public function test_observation_summarizer_courses(): void {
        $results = [['courses' => [['id' => 1], ['id' => 2], ['id' => 3]]]];
        $obs = result_payload_summarizer::for_observation($results, 1);
        $this->assertStringContainsString('3 course(s)', $obs);
    }

    /**
     * Current user detection is correct.
     */
    public function test_observation_summarizer_current_user(): void {
        $results = [['fullname' => 'Alice Smith', 'email' => 'alice@example.com']];
        $obs = result_payload_summarizer::for_observation($results, 1);
        $this->assertStringContainsString('Current user identified', $obs);
        $this->assertStringContainsString('Alice Smith', $obs);
    }

    // -------------------------------------------------------------------------
    // privacy_anonymizer: looks_like_anon_token.

    /**
     * Valid ANON token is detected.
     */
    public function test_anon_token_detection_positive(): void {
        $this->assertTrue(privacy_anonymizer::looks_like_anon_token('ANON_USER_3'));
        $this->assertTrue(privacy_anonymizer::looks_like_anon_token('some text with ANON_USER_42 inside'));
    }

    /**
     * Real values are not mistaken for ANON tokens.
     */
    public function test_anon_token_detection_negative(): void {
        $this->assertFalse(privacy_anonymizer::looks_like_anon_token('Yoga for Beginners'));
        $this->assertFalse(privacy_anonymizer::looks_like_anon_token('ANON_OTHER_1'));
        $this->assertFalse(privacy_anonymizer::looks_like_anon_token(''));
    }
}

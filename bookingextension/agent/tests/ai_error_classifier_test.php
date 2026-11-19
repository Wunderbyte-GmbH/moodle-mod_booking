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
 * Tests for the AI provider error classifier.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\ai_error_classifier;

/**
 * Provider errors map to issue codes by HTTP code, so they survive core_ai's message masking.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\ai_error_classifier
 */
final class ai_error_classifier_test extends \advanced_testcase {
    /** Generic message core_ai (5.1+) returns instead of the provider message outside developer debug. */
    private const CORE_MASKED_MESSAGE = 'There was an error processing your request. Try again later.';

    /**
     * Provider error responses and the issue codes they must produce.
     *
     * @return array
     */
    public static function provider_errors(): array {
        return [
            'deepseek 402, masked message (production)' => [
                self::CORE_MASKED_MESSAGE, 402, ['AI_PROVIDER_QUOTA_EXCEEDED'],
            ],
            'deepseek 402, raw message (developer debug)' => [
                'Insufficient Balance (request_id: ea3959ea-7473-4522-9648-748c9c2af6cc)', 402,
                ['AI_PROVIDER_QUOTA_EXCEEDED'],
            ],
            '429, masked message' => [
                self::CORE_MASKED_MESSAGE, 429, ['AI_PROVIDER_QUOTA_EXCEEDED'],
            ],
            '401, masked message' => [
                self::CORE_MASKED_MESSAGE, 401, ['TRIAL_TOKEN_INVALID'],
            ],
            'unrelated 500 stays unclassified' => [
                self::CORE_MASKED_MESSAGE, 500, [],
            ],
        ];
    }

    /**
     * Each provider error is classified to the expected issue codes.
     *
     * @dataProvider provider_errors
     * @param string $message
     * @param int $code
     * @param string[] $expected
     */
    public function test_classify_from_response(string $message, int $code, array $expected): void {
        $this->assertSame($expected, ai_error_classifier::classify_from_response($message, $code));
    }
}

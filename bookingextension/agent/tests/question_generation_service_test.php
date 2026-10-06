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

use advanced_testcase;
use bookingextension_agent\local\wizard\services\questions\question_generation_service;

/**
 * Tests for the deterministic parts of the question generation service.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\questions\question_generation_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_generation_service_test extends advanced_testcase {
    /**
     * The prompt encodes the requested constraints and embeds the source text.
     */
    public function test_build_prompt_encodes_constraints_and_source(): void {
        $prompt = question_generation_service::build_prompt(
            'The mitochondria is the powerhouse of the cell.',
            ['count' => 7, 'qtypes' => ['truefalse'], 'difficulty' => 'hard', 'outputlang' => 'de']
        );

        $this->assertStringContainsString('exactly 7', $prompt);
        $this->assertStringContainsString('truefalse', $prompt);
        $this->assertStringContainsString('hard', $prompt);
        $this->assertStringContainsString('"de"', $prompt);
        $this->assertStringContainsString('GIFT', $prompt);
        $this->assertStringContainsString('powerhouse of the cell', $prompt);
    }

    /**
     * Import feedback is woven into a retry prompt.
     */
    public function test_build_prompt_includes_retry_feedback(): void {
        $prompt = question_generation_service::build_prompt('X', [], 'Error: question 2 has no correct answer.');
        $this->assertStringContainsString('Error: question 2 has no correct answer.', $prompt);
        $this->assertStringContainsString('previous attempt', $prompt);
    }

    /**
     * Count is clamped to a sane range.
     */
    public function test_build_prompt_clamps_count(): void {
        $this->assertStringContainsString('exactly 1', question_generation_service::build_prompt('X', ['count' => 0]));
        $this->assertStringContainsString(
            'exactly ' . question_generation_service::MAX_COUNT,
            question_generation_service::build_prompt('X', ['count' => 9999])
        );
    }

    /**
     * GIFT is extracted from a fenced reply, and passed through when unfenced.
     */
    public function test_extract_gift_handles_code_fences(): void {
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Literal Markdown code-fence backticks in a test fixture, not shell execution.
        $fenced = "Here you go:\n```gift\n::Q:: The sky is blue. {TRUE}\n```\nHope that helps.";
        $this->assertSame('::Q:: The sky is blue. {TRUE}', question_generation_service::extract_gift($fenced));

        $plain = "::Q:: The sky is blue. {TRUE}";
        $this->assertSame($plain, question_generation_service::extract_gift($plain));

        $this->assertSame('', question_generation_service::extract_gift("   \n  "));
    }
}

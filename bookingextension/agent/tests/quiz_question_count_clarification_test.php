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
use bookingextension_agent\local\wizard\question\skills\generate_questions_skill;
use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use mod_booking\local\wizard\options\skills\create_option_skill;

/**
 * B4 (Georg 2026-07-14): the number of quiz questions is never silently defaulted.
 *
 * When a quiz is populated by generating questions and the user did not say how many, the skill
 * must clarify (RECOVERABLE_INPUT_ERROR → a real clarification turn), not proceed to a confirmation
 * card with a fabricated number (thread 587: the agent asked in the card and then took 7).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\question\skills\generate_questions_skill::check_structure
 */
final class quiz_question_count_clarification_test extends advanced_testcase {
    /**
     * generate_questions without a count must clarify, not default.
     */
    public function test_generate_questions_without_count_clarifies(): void {
        $result = (new generate_questions_skill())->check_structure(['content' => 'Photosynthesis basics']);

        $this->assertFalse($result['valid'], 'generate_questions without a count must not be valid — it must ask.');
        $this->assertContains(
            'RECOVERABLE_INPUT_ERROR',
            (array)($result['issue_codes'] ?? []),
            'B4: a missing quiz question count must route as a clarification (RECOVERABLE_INPUT_ERROR), not a '
            . 'confirmation with a fabricated number.'
        );
    }

    /**
     * generate_questions with an explicit count passes structure validation.
     */
    public function test_generate_questions_with_count_is_valid(): void {
        $result = (new generate_questions_skill())->check_structure(['content' => 'Photosynthesis basics', 'count' => 5]);
        $this->assertTrue($result['valid'], 'A specified question count must pass: ' . json_encode($result['errors'] ?? []));
    }

    /**
     * Wave 32 (GQ-4): a dictated question and answer is a number too. The count field (the constructor sees its
     * first 160 characters) and the construction guidance say so; the B4 gate itself stays unchanged — a request
     * that neither names a number nor dictates a question still asks.
     */
    public function test_generate_questions_count_covers_dictated_questions(): void {
        $skill = new generate_questions_skill();
        $description = (string)$skill->get_schema()['properties']['count']['description'];
        $this->assertStringContainsString('dictated', $description);
        $this->assertLessThanOrEqual(160, \core_text::strlen($description), 'fits the constructor prompt cut');

        $guidance = [];
        foreach ($skill->get_contextual_prompt_packs() as $pack) {
            $guidance = array_merge($guidance, (array)($pack['guidance'] ?? []));
        }
        $countlines = array_filter($guidance, static fn(string $line): bool => str_contains($line, 'input.count'));
        $this->assertNotEmpty($countlines);
        $this->assertStringContainsString('dictated', implode(' ', $countlines));

        // The gate is untouched: no count at all still routes as a clarification.
        $result = $skill->check_structure(['content' => 'Q: capital of France? A: Paris']);
        $this->assertFalse($result['valid']);
    }

    /**
     * add_quiz generating from content without a count must clarify.
     */
    public function test_add_quiz_generation_without_count_clarifies(): void {
        $result = (new add_quiz_skill())->check_structure(['name' => 'Chapter 1 quiz', 'content' => 'Photosynthesis']);

        $this->assertFalse($result['valid'], 'add_quiz generating from content without a count must ask.');
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
    }

    /**
     * add_quiz without generation content (e.g. an empty quiz) carries no count and must pass.
     */
    public function test_add_quiz_without_generation_content_is_valid(): void {
        $result = (new add_quiz_skill())->check_structure(['name' => 'Empty quiz']);
        $this->assertTrue($result['valid'], 'An empty quiz needs no question count.');
    }

    /**
     * Counterpart decision (Georg 2026-07-14): maxanswers is NEVER gated — no participant limit
     * (0) is the default and the user is not asked. A create_option without maxanswers must pass.
     */
    public function test_create_option_never_gates_maxanswers(): void {
        $this->resetAfterTest();
        $result = (new create_option_skill())->check_structure(['text' => 'Yoga am Morgen']);
        $this->assertTrue(
            (bool)$result['valid'],
            'maxanswers must never gate create_option — empty means no limit (0), never a clarification.'
        );
    }
}

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
use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use bookingextension_agent\local\wizard\question\skills\generate_questions_skill;

/**
 * The card of question.generate_questions never claims to create a quiz or test; course.add_quiz covers a test
 * built from an uploaded document.
 *
 * AQ-1, full runs L30-L41 (❌ in 9 of 10, both cards in every catalog): generate_questions' description said "Use
 * this whenever the user wants a question, quiz or test created ... (e.g. "create a quiz from the PDFs in the
 * course")" while its own NOT line and add_quiz's IS line give the quiz activity to add_quiz. Its WHEN and an anchor
 * spoke of "quiz questions from an uploaded document", and add_quiz's WHEN never mentioned a document - so "a test
 * with 15 questions from the uploaded script" matched generate_questions literally. The selector re-labelled the
 * user's "Test" as "test questions". Each card now states its own entity and nothing of the other's.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\question\skills\generate_questions_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_quiz_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_and_question_cards_do_not_overlap_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * Every routing-facing text of generate_questions (description, WHEN, anchors) keeps to the question bank.
     */
    public function test_generate_questions_claims_no_quiz_or_test(): void {
        $skill = new generate_questions_skill();
        $schema = $skill->get_schema();
        $texts = array_merge(
            [(string)$schema['description']],
            array_map(static fn(array $t): string => (string)$t['description'], $skill->get_message_triggers()),
            (array)$schema['example_utterances']
        );
        foreach ($texts as $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(quiz|test)\b/i', $text, 'claims the quiz/test entity: ' . $text);
        }
        $this->assertStringContainsString('course.add_quiz', (string)$schema['not'], 'the quiz activity is add_quiz');
    }

    /**
     * The construction guidance of generate_questions (surfaced unconditionally once the skill is chosen) does not
     * speak of a quiz or test either (wave 32: it still said "a quiz about the PDFs in this course" after 4f89781).
     */
    public function test_generate_questions_guidance_claims_no_quiz_or_test(): void {
        foreach ((new generate_questions_skill())->get_contextual_prompt_packs() as $pack) {
            foreach ((array)($pack['guidance'] ?? []) as $line) {
                $this->assertDoesNotMatchRegularExpression('/\b(quiz|test)\b/i', (string)$line, 'guidance: ' . $line);
            }
            foreach ((array)($pack['triggers'] ?? []) as $trigger) {
                $this->assertDoesNotMatchRegularExpression('/\b(quiz|test)\b/i', (string)$trigger, 'trigger: ' . $trigger);
            }
        }
    }

    /**
     * Wave 32: constructions put the page's course id into courseid although the user named another course (AQ-3 L41/L43
     * quiz landed in "ai" instead of Biologie; GQ-1 L38/L41 read the PDFs of "ai" instead of "Brandschutz im Betrieb").
     * The courseid field says that a named course goes into coursequery, inside the 160-character cut.
     */
    public function test_courseid_field_sends_a_named_course_to_coursequery(): void {
        foreach ([new add_quiz_skill(), new generate_questions_skill()] as $skill) {
            $description = (string)$skill->get_schema()['properties']['courseid']['description'];
            $this->assertStringContainsString('coursequery', $description, $skill->get_name());
            $this->assertLessThanOrEqual(160, \core_text::strlen($description), $skill->get_name());
        }
    }

    /**
     * add_quiz says in its WHEN line that the questions may come from an uploaded document.
     */
    public function test_add_quiz_covers_a_test_from_a_document(): void {
        $triggers = (new add_quiz_skill())->get_message_triggers();
        $this->assertStringContainsString('uploaded document', (string)$triggers[0]['description']);
    }

    /**
     * add_quiz declares its name as required: the construction asks for it instead of inventing one (AQ-2, AQ-3).
     */
    public function test_add_quiz_declares_the_name_required(): void {
        $schema = (new \bookingextension_agent\local\wizard\course\skills\add_quiz_skill())->get_schema();
        $this->assertTrue((bool)($schema['properties']['name']['required'] ?? false));
        $this->assertStringNotContainsString('Required.', (string)$schema['properties']['name']['description']);
    }
}

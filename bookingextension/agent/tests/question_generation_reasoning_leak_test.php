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
use bookingextension_agent\local\wizard\services\questions\question_import_service;

/**
 * A reply that carries the model's reasoning around the GIFT never turns that reasoning into questions.
 *
 * Wunderbyte-GmbH/Wunderbyte-GmbH#2493 (training, run 243): add_quiz confirmed 5 questions and created 261 - 248
 * description questions made of the model's reasoning ("The user wants me to create exactly 5 ...", "Let me write
 * these in German:") plus two drafts of the real questions. The GIFT importer turns every paragraph without an
 * answer block into a description question, and nothing compared the result with the requested count.
 * The fix is structural (GIFT syntax, no words): only blocks that open with a ::name:: and carry an answer block
 * are questions, more questions than requested fail the attempt (fewer stay allowed and are reported honestly,
 * thread 587), and an import that still produces a description question is rolled back.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\questions\question_generation_service
 * @covers     \bookingextension_agent\local\wizard\services\questions\question_import_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_generation_reasoning_leak_test extends advanced_testcase {
    /**
     * The reply shape of run 243: reasoning, a numbered draft glued to the first GIFT line, then the questions.
     *
     * @return string
     */
    private function reply_with_reasoning(): string {
        return implode("\n", [
            'The user wants me to create exactly 2 Moodle questions in GIFT format based on the source.',
            '',
            'The source document is very brief - just a title. This means I need to create questions from general knowledge.',
            '',
            'Requirements:',
            '- Exactly 2 questions',
            '- GIFT format',
            '',
            'Let me write these in German:',
            '',
            '1. multichoice: Was umfasst der Datenschutz am Arbeitsplatz?',
            '::Datenschutz Umfang:: Was umfasst der Datenschutz am Arbeitsplatz? {',
            '=Den Schutz personenbezogener Daten der Beschäftigten',
            '~Nur die IT-Sicherheit',
            '~Nur Papierakten',
            '}',
            '',
            '2. truefalse: Dürfen private E-Mails ohne Grund gelesen werden?',
            '::Private E-Mails:: Dürfen Arbeitgeber private E-Mails ohne Anlass lesen? {FALSE}',
            '',
        ]);
    }

    /**
     * Only the GIFT questions survive, each starting at its ::name::.
     */
    public function test_reasoning_is_not_extracted_as_questions(): void {
        $gift = question_generation_service::extract_gift($this->reply_with_reasoning());
        $blocks = question_generation_service::question_blocks($gift);

        $this->assertCount(2, $blocks, $gift);
        foreach ($blocks as $block) {
            $this->assertStringStartsWith('::', $block);
            $this->assertStringContainsString('{', $block);
        }
        $this->assertStringNotContainsString('The user wants me', $gift);
        $this->assertStringNotContainsString('1. multichoice', $gift);
    }

    /**
     * More questions than requested fail the attempt; fewer are imported and reported honestly (thread 587).
     */
    public function test_more_questions_than_requested_fail_the_attempt(): void {
        $gift = question_generation_service::extract_gift($this->reply_with_reasoning());
        $this->assertSame('', question_generation_service::count_error($gift, ['count' => 2]));
        $this->assertNotSame('', question_generation_service::count_error($gift, ['count' => 1]));
        $this->assertSame('', question_generation_service::count_error($gift, ['count' => 5]), 'fewer is not padded');
        $this->assertNotSame('', question_generation_service::count_error('', ['count' => 5]), 'none is a failure');
    }

    /**
     * An import that still yields a description question is rolled back as a failed attempt.
     */
    public function test_import_rolls_back_description_questions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($qbank->cmid);
        $before = $DB->count_records('question');

        $gift = implode("\n", [
            'Let me write these in German:',
            '',
            '::Sky:: The sky is blue. {TRUE}',
            '',
        ]);
        $result = (new question_import_service())->import_gift($gift, $context, $course);

        $this->assertFalse($result['success']);
        $this->assertSame([], $result['questionids']);
        $this->assertNotSame('', $result['errors']);
        $this->assertSame($before, $DB->count_records('question'), 'nothing of the failed attempt stays in the bank');
    }
}

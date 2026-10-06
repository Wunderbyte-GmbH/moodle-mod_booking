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
use bookingextension_agent\local\wizard\services\activities\quiz_question_service;

/**
 * The shared quiz "question source" clarification content builder (S6 small follow-up: deduped out
 * of add_quiz/update_quiz into quiz_question_service).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\services\activities\quiz_question_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_source_clarification_test extends advanced_testcase {
    /**
     * With no categories: the lead + the three source options + the footer, and no selectable options.
     */
    public function test_no_categories(): void {
        $content = quiz_question_service::build_source_clarification([], 'Which questions should I add to the quiz?');

        $this->assertSame([], $content['options']);
        $message = $content['message'];
        $this->assertStringStartsWith('Which questions should I add to the quiz?', $message);
        $this->assertStringContainsString('Generate new questions from a document/PDF', $message);
        $this->assertStringContainsString('make up questions on a topic', $message);
        $this->assertStringContainsString('Use existing questions from a question category', $message);
        $this->assertStringContainsString('Tell me which option', $message);
        $this->assertStringNotContainsString('Available categories:', $message);
    }

    /**
     * With categories: each is listed in the message and surfaced as a selectable option.
     */
    public function test_with_categories(): void {
        $categories = [
            ['categoryid' => 7, 'categoryname' => 'Algebra', 'bankname' => 'Course bank', 'questioncount' => 12],
        ];
        $content = quiz_question_service::build_source_clarification($categories, 'Lead');

        $this->assertStringContainsString('Available categories:', $content['message']);
        $this->assertStringContainsString('Course bank', $content['message']);
        $this->assertStringContainsString('Algebra', $content['message']);
        $this->assertStringContainsString('12 question(s)', $content['message']);

        $this->assertSame([
            ['categoryid' => 7, 'category' => 'Algebra', 'bank' => 'Course bank', 'questioncount' => 12],
        ], $content['options']);
    }
}

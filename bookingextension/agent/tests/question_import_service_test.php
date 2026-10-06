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
use bookingextension_agent\local\wizard\services\questions\question_import_service;

/**
 * Tests for the GIFT question import service.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\questions\question_import_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_import_service_test extends advanced_testcase {
    /**
     * A valid GIFT document is imported into the course question bank.
     */
    public function test_import_gift_creates_questions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($qbank->cmid);

        $gift = implode("\n", [
            '::Two plus two:: What is 2 + 2? {',
            '=4',
            '~3',
            '~5',
            '}',
            '',
            '::Sky:: The sky is blue. {TRUE}',
            '',
            '::Capital:: What is the capital of France? {=Paris}',
            '',
        ]);

        $result = (new question_import_service())->import_gift($gift, $context, $course);

        $this->assertTrue($result['success'], $result['errors']);
        $this->assertSame(3, $result['imported']);
        $this->assertCount(3, $result['questionids']);
        $this->assertGreaterThan(0, $result['categoryid']);
        foreach ($result['questionids'] as $id) {
            $this->assertTrue($DB->record_exists('question', ['id' => (int)$id]));
        }
    }

    /**
     * A document that yields no question is reported as a failure (so generation can retry).
     */
    public function test_import_gift_reports_failure_when_no_questions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $context = \context_module::instance($qbank->cmid);

        $gift = "// Just a comment, no questions in this document.\n";

        $result = (new question_import_service())->import_gift($gift, $context, $course);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['imported']);
        $this->assertSame([], $result['questionids']);
        $this->assertNotSame('', $result['errors']);
    }
}

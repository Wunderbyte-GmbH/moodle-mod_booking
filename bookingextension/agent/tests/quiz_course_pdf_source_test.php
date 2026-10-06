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
use context_course;
use bookingextension_agent\local\wizard\course\skills\add_quiz_skill;
use bookingextension_agent\local\wizard\course\skills\update_quiz_skill;
use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;

/**
 * The quiz skills take the PDFs stored in the course as a question source, like question.generate_questions.
 *
 * UQ-4 (baseline runs 38/39): "Häng an das Bienenkunde-Quiz noch drei Fragen aus dem PDF im Kurs an." update_quiz had
 * no PDF source, so the constructor asked for the PDF's content or the selector fell back to generate_questions (which
 * cannot attach to the quiz). Wave 30: usecoursepdfs / resourcecmid plan a generation from the course PDFs, read by
 * the shared course_pdf_source; a course without PDFs is a question, never an error.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\update_quiz_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_quiz_skill
 * @covers     \bookingextension_agent\local\wizard\services\activities\quiz_question_service
 * @covers     \bookingextension_agent\local\wizard\services\questions\course_pdf_source
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quiz_course_pdf_source_test extends advanced_testcase {
    /**
     * Course + teacher + quiz (+ optionally one PDF resource), acting as the teacher in the course context.
     *
     * @param bool $withpdf
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:int}
     */
    private function quiz_course(bool $withpdf): array {
        global $PAGE;
        $this->resetAfterTest();
        if (!(new pdf_text_extractor())->is_available()) {
            $this->markTestSkipped('no PDF extractor available');
        }
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Apikultur']);
        if ($withpdf) {
            $this->create_pdf_resource((int)$course->id);
        }
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Bienenkunde-Quiz']);
        $coursecontext = context_course::instance($course->id);
        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);
        return [$course, $teacher, $quiz, (int)$coursecontext->id];
    }

    /**
     * One File resource with a minimal valid PDF.
     *
     * @param int $courseid
     */
    private function create_pdf_resource(int $courseid): void {
        global $USER;
        $stream = 'BT /F1 12 Tf 72 720 Td (Bees collect nectar and pollen) Tj ET';
        $objects = [
            1 => "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            2 => "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            3 => "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R"
                . " /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            4 => "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n",
            5 => "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $body;
        }
        $xrefpos = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefpos . "\n%%EOF";

        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'component' => 'user',
            'filearea' => 'draft',
            'contextid' => \context_user::instance($USER->id)->id,
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'bienen.pdf',
        ], $pdf);
        $this->getDataGenerator()->create_module('resource', [
            'course' => $courseid,
            'name' => 'Bienen-Handout',
            'intro' => 'Intro.',
            'introformat' => FORMAT_HTML,
            'files' => $draftid,
        ]);
    }

    /**
     * UQ-4: the course PDF is a source; the preflight plans a generation from it and does not ask for its content.
     */
    public function test_update_quiz_plans_questions_from_the_course_pdf(): void {
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course(true);

        $result = (new update_quiz_skill())->preflight(
            ['cmid' => (int)$quiz->cmid, 'usecoursepdfs' => true, 'count' => 3],
            $ctxid,
            (int)$teacher->id
        );

        $this->assertNotSame('hard_block', $result->status, json_encode($result->to_array()));
        $plan = (array)($result->preparedinput['plan'] ?? []);
        $this->assertSame('generate', (string)($plan['mode'] ?? ''), json_encode($result->preparedinput));
        $this->assertArrayHasKey('pdfsource', $plan);
        $this->assertSame(3, (int)($plan['count'] ?? 0));
    }

    /**
     * add_quiz takes the same source.
     */
    public function test_add_quiz_plans_questions_from_the_course_pdf(): void {
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course(true);

        $result = (new add_quiz_skill())->preflight(
            ['name' => 'Bienen-Test', 'usecoursepdfs' => true, 'count' => 3],
            $ctxid,
            (int)$teacher->id
        );

        $this->assertNotSame('hard_block', $result->status, json_encode($result->to_array()));
        $plan = (array)($result->preparedinput['plan'] ?? []);
        $this->assertSame('generate', (string)($plan['mode'] ?? ''), json_encode($result->preparedinput));
    }

    /**
     * A course without PDFs is a question (the localized "no PDFs" message), never an error.
     */
    public function test_a_course_without_pdfs_is_a_question(): void {
        [$course, $teacher, $quiz, $ctxid] = $this->quiz_course(false);

        $result = (new update_quiz_skill())->preflight(
            ['cmid' => (int)$quiz->cmid, 'usecoursepdfs' => true, 'count' => 3],
            $ctxid,
            (int)$teacher->id
        );

        $this->assertSame('hard_block', $result->status);
        $this->assertContains('GENERATE_QUESTIONS_NO_COURSE_PDFS', $result->issuecodes);
        $this->assertSame('needs_clarification', (string)($result->issues[0]['severity'] ?? ''));
        $this->assertStringNotContainsString('GENERATE_QUESTIONS', (string)($result->issues[0]['message'] ?? ''));
    }
}

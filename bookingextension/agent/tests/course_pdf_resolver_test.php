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
use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;
use bookingextension_agent\local\wizard\services\questions\course_pdf_resolver;

/**
 * Course-PDF resolver for question.generate_questions: listing (visibility, type, size) and
 * text extraction into DOCUMENT blocks under a total character budget.
 *
 * Extraction-dependent tests skip when neither pdftotext nor the bundled PHP parser is available.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\questions\course_pdf_resolver
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_pdf_resolver_test extends advanced_testcase {
    /**
     * Skip when no PDF extraction method (pdftotext or bundled parser) is available.
     *
     * @return void
     */
    private function require_extractor(): void {
        if (!(new pdf_text_extractor())->is_available()) {
            $this->markTestSkipped('no PDF extractor available');
        }
    }

    /**
     * Build a minimal, valid single-page PDF containing $text (plain ASCII, no ()\ characters).
     *
     * The cross-reference offsets are computed dynamically, so the output parses with both
     * pdftotext and the bundled pure-PHP parser (same fixture technique as the site-content
     * file-indexing tests).
     *
     * @param string $text
     * @return string PDF bytes.
     */
    private function make_pdf(string $text): string {
        $stream = 'BT /F1 12 Tf 72 720 Td (' . $text . ') Tj ET';
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
        return $pdf;
    }

    /**
     * Create a File resource whose content file has the given name and raw bytes.
     *
     * @param int $courseid
     * @param string $name Activity name.
     * @param string $filename E.g. 'handout.pdf' (the extension drives the stored mimetype).
     * @param string $filecontent Raw file bytes.
     * @param array $extra Extra module options (e.g. visible => 0).
     * @return \stdClass The resource instance record (with cmid).
     */
    private function create_resource_with_file(
        int $courseid,
        string $name,
        string $filename,
        string $filecontent,
        array $extra = []
    ): \stdClass {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'component' => 'user',
            'filearea' => 'draft',
            'contextid' => \context_user::instance($USER->id)->id,
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], $filecontent);
        return $this->getDataGenerator()->create_module('resource', $extra + [
            'course' => $courseid,
            'name' => $name,
            'intro' => 'Intro.',
            'introformat' => FORMAT_HTML,
            'files' => $draftid,
        ]);
    }

    /**
     * Listing returns only PDFs the acting user can see: a hidden module, a non-PDF resource and
     * an oversized PDF are all excluded for a student.
     */
    public function test_list_course_pdfs_only_uservisible_pdfs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $visible = $this->create_resource_with_file((int)$course->id, 'Handout', 'handout.pdf', $this->make_pdf('Visible'));
        $this->create_resource_with_file(
            (int)$course->id,
            'Hidden handout',
            'hidden.pdf',
            $this->make_pdf('Hidden'),
            ['visible' => 0]
        );
        $this->create_resource_with_file((int)$course->id, 'Notes', 'notes.txt', 'plain text, not a pdf');
        $this->create_resource_with_file(
            (int)$course->id,
            'Big scan',
            'big.pdf',
            str_repeat('x', course_pdf_resolver::MAX_FILE_BYTES + 1)
        );

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $pdfs = (new course_pdf_resolver())->list_course_pdfs((int)$course->id, (int)$student->id);

        $this->assertCount(1, $pdfs);
        $this->assertSame((int)$visible->cmid, $pdfs[0]['cmid']);
        $this->assertSame('Handout', $pdfs[0]['name']);
        $this->assertSame('handout.pdf', $pdfs[0]['filename']);
        $this->assertInstanceOf(\stored_file::class, $pdfs[0]['file']);
    }

    /**
     * Single-resource lookup reports precise statuses: ok, notfound (bogus id AND hidden-to-user),
     * nopdf (non-PDF main file) and toolarge (over the size limit).
     */
    public function test_get_resource_pdf_statuses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $good = $this->create_resource_with_file((int)$course->id, 'Handout', 'handout.pdf', $this->make_pdf('Good'));
        $hidden = $this->create_resource_with_file(
            (int)$course->id,
            'Hidden handout',
            'hidden.pdf',
            $this->make_pdf('Hidden'),
            ['visible' => 0]
        );
        $txt = $this->create_resource_with_file((int)$course->id, 'Notes', 'notes.txt', 'plain text');
        $big = $this->create_resource_with_file(
            (int)$course->id,
            'Big scan',
            'big.pdf',
            str_repeat('x', course_pdf_resolver::MAX_FILE_BYTES + 1)
        );

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $resolver = new course_pdf_resolver();
        $courseid = (int)$course->id;
        $userid = (int)$student->id;

        $ok = $resolver->get_resource_pdf($courseid, (int)$good->cmid, $userid);
        $this->assertSame(course_pdf_resolver::STATUS_OK, $ok['status']);
        $this->assertSame('handout.pdf', $ok['pdf']['filename']);
        $this->assertSame((int)$good->cmid, $ok['pdf']['cmid']);

        $this->assertSame(
            course_pdf_resolver::STATUS_NOT_FOUND,
            $resolver->get_resource_pdf($courseid, 99999999, $userid)['status']
        );
        // Hidden to the student => reported as not found, never as a readable file.
        $this->assertSame(
            course_pdf_resolver::STATUS_NOT_FOUND,
            $resolver->get_resource_pdf($courseid, (int)$hidden->cmid, $userid)['status']
        );
        $this->assertSame(
            course_pdf_resolver::STATUS_NO_PDF,
            $resolver->get_resource_pdf($courseid, (int)$txt->cmid, $userid)['status']
        );
        $this->assertSame(
            course_pdf_resolver::STATUS_TOO_LARGE,
            $resolver->get_resource_pdf($courseid, (int)$big->cmid, $userid)['status']
        );
    }

    /**
     * Extraction assembles one DOCUMENT block per file in the format the skill parses, records the
     * used files, and a broken PDF fails soft (skipped, the others still extracted).
     */
    public function test_extract_texts_builds_document_blocks(): void {
        $this->resetAfterTest();
        $this->require_extractor();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->create_resource_with_file((int)$course->id, 'Alpha', 'alpha.pdf', $this->make_pdf('Alpha wolf pack basics'));
        $this->create_resource_with_file((int)$course->id, 'Beta', 'beta.pdf', $this->make_pdf('Beta decay physics'));
        $this->create_resource_with_file((int)$course->id, 'Broken', 'broken.pdf', 'this is not a pdf at all');

        global $USER;
        $resolver = new course_pdf_resolver();
        $pdfs = $resolver->list_course_pdfs((int)$course->id, (int)$USER->id);
        $this->assertCount(3, $pdfs);

        $result = $resolver->extract_texts($pdfs);

        $this->assertFalse($result['truncated']);
        $this->assertCount(2, $result['used']);
        $this->assertSame(['alpha.pdf', 'beta.pdf'], array_column($result['used'], 'filename'));
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('broken.pdf', $result['skipped'][0]['filename']);
        $this->assertSame('extractfailed', $result['skipped'][0]['reason']);

        $this->assertStringContainsString("--- DOCUMENT: alpha.pdf ---\n", $result['text']);
        $this->assertStringContainsString('Alpha wolf pack basics', $result['text']);
        $this->assertStringContainsString("--- DOCUMENT: beta.pdf ---\n", $result['text']);
        $this->assertStringContainsString('Beta decay physics', $result['text']);
        $this->assertStringContainsString('--- END DOCUMENT ---', $result['text']);
        $this->assertStringNotContainsString('broken.pdf', $result['text']);
    }

    /**
     * The total character budget is respected across files: the first file is hard-capped, later
     * files are skipped with reason budget, and the result is flagged truncated.
     */
    public function test_extract_texts_respects_total_budget(): void {
        $this->resetAfterTest();
        $this->require_extractor();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->create_resource_with_file((int)$course->id, 'Alpha', 'alpha.pdf', $this->make_pdf('Alpha wolf pack basics'));
        $this->create_resource_with_file((int)$course->id, 'Beta', 'beta.pdf', $this->make_pdf('Beta decay physics'));

        global $USER;
        $resolver = new course_pdf_resolver();
        $pdfs = $resolver->list_course_pdfs((int)$course->id, (int)$USER->id);

        $result = $resolver->extract_texts($pdfs, 10);

        $this->assertTrue($result['truncated']);
        $this->assertCount(1, $result['used']);
        $this->assertSame('alpha.pdf', $result['used'][0]['filename']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('beta.pdf', $result['skipped'][0]['filename']);
        $this->assertSame('budget', $result['skipped'][0]['reason']);

        // The single extracted text is hard-capped at the budget.
        $this->assertSame(1, preg_match('/--- DOCUMENT: alpha\.pdf ---\n(.*)\n--- END DOCUMENT ---/s', $result['text'], $m));
        $this->assertLessThanOrEqual(10, mb_strlen($m[1]));
        $this->assertStringNotContainsString('beta.pdf', $result['text']);
    }
}

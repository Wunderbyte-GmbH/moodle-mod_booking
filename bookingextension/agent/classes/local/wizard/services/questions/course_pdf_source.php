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

namespace bookingextension_agent\local\wizard\services\questions;

use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;

/**
 * Question source text from PDFs that live in a course as file/resource activities.
 *
 * Shared by every skill that generates questions (question.generate_questions, course.add_quiz, course.update_quiz):
 * the files are read server-side (never through the LLM) for the ACTING user only - course_pdf_resolver lists nothing
 * the user cannot see. Every failure is a localized needs_clarification issue, never a raw exception. Extracted from
 * generate_questions in wave 30 (UQ-4: "Häng an das Bienenkunde-Quiz noch drei Fragen aus dem PDF im Kurs an" could
 * not be served by update_quiz, which had no PDF source).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_pdf_source {
    /**
     * The effective character cap for course-PDF source text before the LLM call.
     *
     * The chat-upload path already caps the injected document text at pdf_text_extractor::MAX_CHARS (applied by
     * attachment_processor at upload time), so the course-PDF path reuses exactly that cap as its total budget.
     *
     * @return int
     */
    public static function effective_budget(): int {
        return min(course_pdf_resolver::DEFAULT_TOTAL_BUDGET, pdf_text_extractor::MAX_CHARS);
    }

    /**
     * Check that the course PDFs are available, without extracting them (for a preflight).
     *
     * @param int $resourcecmid One specific resource cm id, or 0 for all visible course PDFs.
     * @param int $courseid Target course id.
     * @param int $userid Acting user id.
     * @param string $lang Conversation output language.
     * @return array Issues; empty when the source can be read.
     */
    public function check(int $resourcecmid, int $courseid, int $userid, string $lang): array {
        [$issues] = $this->locate($resourcecmid, $courseid, $userid, $lang);
        return $issues;
    }

    /**
     * Read the source text from the course PDFs.
     *
     * @param int $resourcecmid One specific resource cm id, or 0 for all visible course PDFs.
     * @param int $courseid Target course id.
     * @param int $userid Acting user id.
     * @param string $lang Conversation output language.
     * @return array text (string|null), files (cmid/name/filename per used PDF), truncated (bool), issues (array).
     */
    public function resolve(int $resourcecmid, int $courseid, int $userid, string $lang): array {
        [$issues, $pdfs] = $this->locate($resourcecmid, $courseid, $userid, $lang);
        if (!empty($issues)) {
            return ['text' => null, 'files' => [], 'truncated' => false, 'issues' => $issues];
        }

        $resolver = new course_pdf_resolver();
        try {
            $extracted = $resolver->extract_texts($pdfs, self::effective_budget());
        } catch (\Throwable $e) {
            return $this->failure('ai_pdf_extraction_unavailable', null, 'GENERATE_QUESTIONS_EXTRACTOR_UNAVAILABLE', $lang);
        }
        if (trim($extracted['text']) === '') {
            return $this->failure('ai_generatequestions_extractionfailed', null, 'GENERATE_QUESTIONS_EXTRACTION_FAILED', $lang);
        }

        return [
            'text' => $extracted['text'],
            'files' => $extracted['used'],
            'truncated' => (bool)$extracted['truncated'],
            'issues' => [],
        ];
    }

    /**
     * Locate the PDFs to read.
     *
     * @param int $resourcecmid
     * @param int $courseid
     * @param int $userid
     * @param string $lang
     * @return array{0: array, 1: array} Issues and the PDFs to read.
     */
    private function locate(int $resourcecmid, int $courseid, int $userid, string $lang): array {
        if (!(new pdf_text_extractor())->is_available()) {
            $unavailable = $this->failure('ai_pdf_extraction_unavailable', null, 'GENERATE_QUESTIONS_EXTRACTOR_UNAVAILABLE', $lang);
            return [$unavailable['issues'], []];
        }

        $resolver = new course_pdf_resolver();
        if ($resourcecmid > 0) {
            $lookup = $resolver->get_resource_pdf($courseid, $resourcecmid, $userid);
            switch ($lookup['status']) {
                case course_pdf_resolver::STATUS_NOT_FOUND:
                    return [$this->failure(
                        'ai_generatequestions_resourcenotfound',
                        $resourcecmid,
                        'GENERATE_QUESTIONS_RESOURCE_NOT_FOUND',
                        $lang
                    )['issues'], []];
                case course_pdf_resolver::STATUS_NO_PDF:
                    return [$this->failure(
                        'ai_generatequestions_resourcenopdf',
                        $lookup['name'],
                        'GENERATE_QUESTIONS_RESOURCE_NO_PDF',
                        $lang
                    )['issues'], []];
                case course_pdf_resolver::STATUS_TOO_LARGE:
                    return [$this->failure(
                        'ai_generatequestions_pdftoolarge',
                        (object)[
                            'name' => $lookup['name'],
                            'limitmb' => (int)(course_pdf_resolver::MAX_FILE_BYTES / (1024 * 1024)),
                        ],
                        'GENERATE_QUESTIONS_PDF_TOO_LARGE',
                        $lang
                    )['issues'], []];
            }
            return [[], [$lookup['pdf']]];
        }

        $pdfs = $resolver->list_course_pdfs($courseid, $userid);
        if (empty($pdfs)) {
            return [$this->failure(
                'ai_generatequestions_nopdfsincourse',
                $this->course_display_name($courseid),
                'GENERATE_QUESTIONS_NO_COURSE_PDFS',
                $lang
            )['issues'], []];
        }
        return [[], $pdfs];
    }

    /**
     * A failed source as one localized needs_clarification issue.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $code
     * @param string $lang
     * @return array
     */
    private function failure(string $identifier, $a, string $code, string $lang): array {
        $lang = trim($lang);
        $message = $lang === ''
            ? get_string($identifier, 'bookingextension_agent', $a)
            : get_string_manager()->get_string($identifier, 'bookingextension_agent', $a, $lang);
        return ['text' => null, 'files' => [], 'truncated' => false, 'issues' => [[
            'severity' => 'needs_clarification',
            'message' => $message,
            'code' => $code,
        ]]];
    }

    /**
     * The formatted course full name, or '#<id>' when the course cannot be read.
     *
     * @param int $courseid
     * @return string
     */
    private function course_display_name(int $courseid): string {
        try {
            return format_string(get_course($courseid)->fullname);
        } catch (\Throwable $e) {
            return '#' . $courseid;
        }
    }
}

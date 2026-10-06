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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\questions;

use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;
use context_module;

/**
 * Resolves PDF files that live INSIDE a course (as mod_resource activities) into source text.
 *
 * This lets question.generate_questions work on "the PDFs in the course" without the user
 * re-uploading them and without copying file text through the LLM: the files are listed via
 * course modinfo (only modules the ACTING user can see), their text is extracted server-side
 * with pdf_text_extractor, and assembled into the same "--- DOCUMENT: <filename> ---" blocks
 * the skill already understands from the chat-upload path (attachment_processor).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_pdf_resolver {
    /** Maximum size of a single source PDF in bytes (20 MB); bigger files are never opened. */
    public const MAX_FILE_BYTES = 20 * 1024 * 1024;

    /** Default total character budget across ALL extracted PDFs of one request. */
    public const DEFAULT_TOTAL_BUDGET = 100000;

    /** Lookup status: the cm exists, is visible to the user and carries a processable PDF. */
    public const STATUS_OK = 'ok';

    /** Lookup status: no resource cm with that id is visible to the user in the course. */
    public const STATUS_NOT_FOUND = 'notfound';

    /** Lookup status: the resource's main file is not a PDF. */
    public const STATUS_NO_PDF = 'nopdf';

    /** Lookup status: the resource's PDF exceeds MAX_FILE_BYTES. */
    public const STATUS_TOO_LARGE = 'toolarge';

    /**
     * List all PDF files of a course that the acting user is allowed to see.
     *
     * Walks the course's mod_resource instances via modinfo FOR THE ACTING USER, so hidden or
     * restricted activities the user cannot see are never listed (the skill must not read files
     * the user has no access to). Per resource only the main content file is considered, and it
     * is included only when it is a PDF within the size limit.
     *
     * @param int $courseid Course id.
     * @param int $userid Acting user id (visibility is evaluated for this user).
     * @return array[] One entry per PDF: cmid (int), name (string, formatted activity name),
     *                 filename (string), file (stored_file).
     */
    public function list_course_pdfs(int $courseid, int $userid): array {
        $pdfs = [];
        $modinfo = get_fast_modinfo($courseid, $userid);
        foreach ($modinfo->get_instances_of('resource') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $entry = $this->main_pdf_entry($cm);
            if ($entry !== null) {
                $pdfs[] = $entry;
            }
        }
        return $pdfs;
    }

    /**
     * Resolve ONE specific resource activity of the course to its PDF.
     *
     * Unlike list_course_pdfs() this reports WHY a cm yields no PDF, so the caller can surface
     * a precise, localized error ("not found/not visible" vs "no PDF attached" vs "too large").
     *
     * @param int $courseid Course id the cm must belong to.
     * @param int $cmid Course-module id of the resource activity.
     * @param int $userid Acting user id (visibility is evaluated for this user).
     * @return array status (one of the STATUS_* constants), pdf (array entry as in
     *               list_course_pdfs() when status is ok, null otherwise), name (string,
     *               formatted activity name when the cm was found, '' otherwise).
     */
    public function get_resource_pdf(int $courseid, int $cmid, int $userid): array {
        $modinfo = get_fast_modinfo($courseid, $userid);
        $cms = $modinfo->get_cms();
        $cm = $cms[$cmid] ?? null;
        if ($cm === null || $cm->modname !== 'resource' || !$cm->uservisible) {
            return ['status' => self::STATUS_NOT_FOUND, 'pdf' => null, 'name' => ''];
        }

        $name = (string)$cm->get_formatted_name();
        $file = $this->main_file($cm);
        if ($file === null || !$this->is_pdf($file->get_mimetype(), $file->get_filename())) {
            return ['status' => self::STATUS_NO_PDF, 'pdf' => null, 'name' => $name];
        }
        if ((int)$file->get_filesize() > self::MAX_FILE_BYTES) {
            return ['status' => self::STATUS_TOO_LARGE, 'pdf' => null, 'name' => $name];
        }

        return [
            'status' => self::STATUS_OK,
            'pdf' => [
                'cmid' => (int)$cm->id,
                'name' => $name,
                'filename' => (string)$file->get_filename(),
                'file' => $file,
            ],
            'name' => $name,
        ];
    }

    /**
     * Extract the text of the given PDFs and assemble it into DOCUMENT blocks.
     *
     * Per file the text is extracted server-side (pdf_text_extractor) against the REMAINING
     * character budget and wrapped as "--- DOCUMENT: <filename> ---\n<text>\n--- END DOCUMENT ---"
     * — the exact block format the generate_questions skill already parses for chat uploads.
     * A file that cannot be extracted is skipped (fail-soft, recorded under skipped); once the
     * total budget is used up the remaining files are skipped and the result is flagged truncated.
     *
     * @param array $pdfs Entries as returned by list_course_pdfs()/get_resource_pdf().
     * @param int $totalbudget Total character budget across all files.
     * @return array text (string, the assembled blocks), used (array of cmid/name/filename per
     *               extracted file), skipped (array of cmid/name/filename/reason, reason is
     *               'budget' or 'extractfailed'), truncated (bool, true when the budget cut text).
     * @throws \moodle_exception When no PDF extraction method is available on this server.
     */
    public function extract_texts(array $pdfs, int $totalbudget = self::DEFAULT_TOTAL_BUDGET): array {
        $extractor = new pdf_text_extractor();
        if (!$extractor->is_available()) {
            throw new \moodle_exception('ai_pdf_extraction_unavailable', 'bookingextension_agent');
        }

        $blocks = [];
        $used = [];
        $skipped = [];
        $truncated = false;
        $remaining = max(0, $totalbudget);

        foreach ($pdfs as $pdf) {
            $meta = [
                'cmid' => (int)($pdf['cmid'] ?? 0),
                'name' => (string)($pdf['name'] ?? ''),
                'filename' => (string)($pdf['filename'] ?? ''),
            ];
            if ($remaining <= 0) {
                $truncated = true;
                $skipped[] = $meta + ['reason' => 'budget'];
                continue;
            }

            // Extract one char beyond the remaining budget: a longer result proves the file had
            // more content than fits, so the overshoot is cut here and the result flagged.
            $text = $this->extract_single($pdf['file'], $extractor, $remaining + 1);
            if ($text === '') {
                $skipped[] = $meta + ['reason' => 'extractfailed'];
                continue;
            }
            if (mb_strlen($text) > $remaining) {
                $text = rtrim(mb_substr($text, 0, $remaining));
                $truncated = true;
                if ($text === '') {
                    $skipped[] = $meta + ['reason' => 'budget'];
                    $remaining = 0;
                    continue;
                }
            }
            $remaining -= mb_strlen($text);

            $blocks[] = '--- DOCUMENT: ' . $meta['filename'] . " ---\n" . $text . "\n--- END DOCUMENT ---";
            $used[] = $meta;
        }

        return [
            'text' => implode("\n\n", $blocks),
            'used' => $used,
            'skipped' => $skipped,
            'truncated' => $truncated,
        ];
    }

    /**
     * Extract one stored PDF's text via a temp copy, fail-soft.
     *
     * @param \stored_file $file The PDF file.
     * @param pdf_text_extractor $extractor Extractor (availability already checked).
     * @param int $maxchars Hard character cap for this file.
     * @return string Extracted text, '' when the file could not be processed.
     */
    private function extract_single(\stored_file $file, pdf_text_extractor $extractor, int $maxchars): string {
        $tmppath = null;
        try {
            $tmppath = $file->copy_content_to_temp();
            if ($tmppath === false) {
                return '';
            }
            return trim($extractor->extract($tmppath, $maxchars));
        } catch (\Throwable $e) {
            return '';
        } finally {
            if (is_string($tmppath) && $tmppath !== '') {
                @unlink($tmppath);
            }
        }
    }

    /**
     * Build the list entry for a resource cm's main file, or null when it is not a usable PDF.
     *
     * @param \cm_info $cm Resource course module (already visibility-checked).
     * @return array|null Entry with cmid/name/filename/file, or null.
     */
    private function main_pdf_entry(\cm_info $cm): ?array {
        $file = $this->main_file($cm);
        if (
            $file === null
            || !$this->is_pdf($file->get_mimetype(), $file->get_filename())
            || (int)$file->get_filesize() > self::MAX_FILE_BYTES
        ) {
            return null;
        }
        return [
            'cmid' => (int)$cm->id,
            'name' => (string)$cm->get_formatted_name(),
            'filename' => (string)$file->get_filename(),
            'file' => $file,
        ];
    }

    /**
     * The resource's main content file (same selection rule as mod/resource/view.php).
     *
     * @param \cm_info $cm Resource course module.
     * @return \stored_file|null
     */
    private function main_file(\cm_info $cm): ?\stored_file {
        $context = context_module::instance($cm->id);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_resource',
            'content',
            0,
            'sortorder DESC, id ASC',
            false
        );
        $file = reset($files);
        return $file ?: null;
    }

    /**
     * Whether a stored file is a PDF (by stored mimetype, with a filename fallback).
     *
     * @param string|null $mimetype Stored mimetype.
     * @param string $filename File name.
     * @return bool
     */
    private function is_pdf(?string $mimetype, string $filename): bool {
        if (strtolower((string)$mimetype) === 'application/pdf') {
            return true;
        }
        return str_ends_with(strtolower($filename), '.pdf');
    }
}

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

/**
 * Server-side preview renderer for markdown documents.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\external\ai_get_doc_content;

/**
 * Doc markdown preview renderer.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class doc_markdown_preview_renderer {
    /**
     * Render markdown doc file preview as HTML.
     *
     * @param array $payload      The preview payload containing path or docpath.
     * @param int   $contextid    Moodle context id.
     * @param int   $userid       Current user id.
     * @return string             Rendered HTML.
     */
    public function render(array $payload, int $contextid, int $userid): string {
        $path = $payload['path'] ?? $payload['docpath'] ?? '';
        $corpusid = trim((string)($payload['corpus_id'] ?? ''));
        if ($path === '' || $corpusid === '') {
            return '';
        }

        try {
            $result = ai_get_doc_content::execute($contextid, $corpusid, $path);
            if (!empty($result['success']) && !empty($result['html'])) {
                return $result['html'];
            }
            if (!empty($result['error'])) {
                return '<div class="alert alert-danger">' . htmlspecialchars($result['error']) . '</div>';
            }
        } catch (\Throwable $e) {
            return '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        return '';
    }
}

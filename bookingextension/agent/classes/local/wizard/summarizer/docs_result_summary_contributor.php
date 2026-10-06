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
 * Docs result summary contributor.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\summarizer;

use bookingextension_agent\local\wizard\interfaces\summarizer\result_summary_contributor_interface;

/**
 * Summarizes documentation result payloads.
 */
class docs_result_summary_contributor implements result_summary_contributor_interface {
    /**
     * Check whether this contributor handles docs category entries.
     *
     * @param string $category
     * @param array $entry
     * @return bool
     */
    public function supports(string $category, array $entry): bool {
        return $category === 'docs' && !empty($entry['docs']) && is_array($entry['docs']);
    }

    /**
     * Summarize docs payload into compact observation text.
     *
     * @param array $entry
     * @param int $step
     * @return string
     */
    public function summarize(array $entry, int $step = 0): string {
        $docs = (array)($entry['docs'] ?? []);
        $isfirststep = $step > 0 && $step <= 1;
        $hasrichcontent = !empty(array_filter(
            $docs,
            static fn(array $d): bool => trim((string)($d['chunk_content'] ?? $d['full_content'] ?? '')) !== ''
        ));
        $maxobservation = $isfirststep ? 1400 : ($hasrichcontent ? 4500 : 2000);
        $maxperdoc = $isfirststep ? 700 : ($hasrichcontent ? 2500 : 500);
        $parts = [];
        $linklines = [];

        foreach ($docs as $doc) {
            if (!is_array($doc)) {
                continue;
            }

            $title = trim((string)($doc['title'] ?? ''));
            $chunkcontent = trim((string)($doc['chunk_content'] ?? ''));
            $fullcontent = trim((string)($doc['full_content'] ?? ''));
            $excerpt = trim((string)($doc['excerpt'] ?? ''));
            $url = trim((string)($doc['url'] ?? ''));
            $hasmore = !empty($doc['has_more']);
            $nextline = (int)($doc['next_line_start'] ?? 0);
            $chunklinks = array_values(array_filter(array_map(
                static fn($item): string => trim((string)$item),
                (array)($doc['chunk_links'] ?? [])
            )));

            $body = $chunkcontent !== '' ? $chunkcontent : ($fullcontent !== '' ? $fullcontent : $excerpt);

            if ($body !== '') {
                $block = '';
                if ($title !== '') {
                    $block .= "## {$title}\n";
                }
                if (mb_strlen($body) > $maxperdoc) {
                    $block .= mb_substr($body, 0, $maxperdoc) . '[...]';
                } else {
                    $block .= $body;
                }
                $parts[] = $block;
            }

            if (!$isfirststep && $hasmore && $nextline > 0) {
                $parts[] = 'Continue this document from line ' . $nextline . ' if more detail is needed.';
            }

            if (!$isfirststep && !empty($chunklinks)) {
                $parts[] = 'Linked docs in this section: ' . implode(', ', array_slice($chunklinks, 0, 4));
            }

            if (!$isfirststep && $url !== '') {
                $linkline = $title !== '' ? "- {$title}: {$url}" : "- {$url}";
                $linklines[] = $linkline;
            }
        }

        $body = implode("\n\n", $parts);

        if (!empty($linklines)) {
            $linksblock = "Links:\n" . implode("\n", $linklines);
            $separator = $body !== '' ? "\n\n" : '';
            $body .= $separator . $linksblock;
        }

        if ($body === '') {
            return 'Retrieved ' . count($docs) . ' documentation chunk(s) (no text available).';
        }

        if (mb_strlen($body) > $maxobservation) {
            $body = mb_substr($body, 0, $maxobservation) . '[...]';
        }

        return $body;
    }
}

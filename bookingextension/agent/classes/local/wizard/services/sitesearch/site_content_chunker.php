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
 * Deterministic overlap chunker for plain-text site content.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

/**
 * Splits `html_to_text`-style plain text into ~2000-character chunks with ~200 characters of
 * overlap between consecutive chunks (blueprint §5.5), preferring line breaks, then sentence
 * ends, then word breaks as cut points — never the middle of a UTF-8 character.
 *
 * DETERMINISM IS LOAD-BEARING: the search service re-chunks the live document at query time and
 * addresses the result by the stored chunk number (`refindex`), comparing content hashes for the
 * self-heal check. The same input MUST therefore always produce byte-identical chunks. This class
 * is a pure function of its input — no config, no locale, no randomness — and any behavioural
 * change (budget, overlap, boundary preference) MUST bump {@see VERSION}, which is part of the
 * store fingerprint and forces a full re-index (§11.22), keeping index-time and query-time
 * chunking aligned.
 *
 * This chunker is intentionally separate from {@see \bookingextension_agent\local\wizard\services\lookup\markdown_chunker}
 * (heading-oriented, no overlap) — the docs pipeline depends on that one and must not change.
 */
class site_content_chunker {
    /**
     * Chunker behaviour version. Part of the site-content store fingerprint: bump it whenever the
     * chunk boundaries this class produces can change for the same input.
     */
    public const VERSION = 'v1';

    /** Character budget per chunk (~500 tokens via strlen/4, blueprint §5.5). */
    public const TARGET_CHARS = 2000;

    /** Characters of overlap carried from the end of one chunk into the next. */
    public const OVERLAP_CHARS = 200;

    /**
     * Earliest acceptable soft cut inside a window: a boundary found before this offset is
     * ignored (the next preference is tried) so chunks never degenerate into tiny fragments.
     */
    private const MIN_CUT_CHARS = 1000;

    /**
     * Chunk plain text into overlapping, deterministically bounded pieces.
     *
     * @param string $text Plain text (html_to_text output).
     * @return array[] List of ['text' => string]; the array index is the chunk number (refindex).
     */
    public static function chunk(string $text): array {
        if (trim($text) === '') {
            return [];
        }
        $len = strlen($text);
        if ($len <= self::TARGET_CHARS) {
            return [['text' => $text]];
        }

        $chunks = [];
        $start = 0;
        while ($start < $len) {
            if (($len - $start) <= self::TARGET_CHARS) {
                $piece = substr($text, $start);
                if (trim($piece) !== '') {
                    $chunks[] = ['text' => $piece];
                }
                break;
            }
            $cut = self::find_cut($text, $start);
            $piece = substr($text, $start, $cut - $start);
            if (trim($piece) !== '') {
                $chunks[] = ['text' => $piece];
            }
            // The next chunk re-reads the last OVERLAP_CHARS of this one (aligned forward onto a
            // UTF-8 character boundary). find_cut() guarantees cut - start >= MIN_CUT_CHARS >
            // OVERLAP_CHARS, so the cursor always moves forward.
            $next = self::align_forward($text, $cut - self::OVERLAP_CHARS);
            if ($next <= $start) {
                $next = $cut;
            }
            $start = $next;
        }
        return $chunks;
    }

    /**
     * Find the cut position (absolute byte offset, exclusive) for the chunk starting at $start.
     *
     * Preference order inside the TARGET_CHARS window, each only accepted in the back half of the
     * window (>= MIN_CUT_CHARS): last line break, last sentence end, last word break; otherwise a
     * hard cut at the budget, aligned back onto a UTF-8 character boundary.
     *
     * @param string $text Full text.
     * @param int $start Chunk start (byte offset).
     * @return int Cut position, always > $start.
     */
    private static function find_cut(string $text, int $start): int {
        $window = substr($text, $start, self::TARGET_CHARS);

        $pos = strrpos($window, "\n");
        if ($pos !== false && $pos >= self::MIN_CUT_CHARS) {
            return $start + $pos + 1;
        }

        if (preg_match_all('/[.!?](\s)/', $window, $matches, PREG_OFFSET_CAPTURE)) {
            $last = end($matches[1]);
            $pos = (int)$last[1];
            if ($pos >= self::MIN_CUT_CHARS) {
                return $start + $pos + 1;
            }
        }

        $pos = strrpos($window, ' ');
        if ($pos !== false && $pos >= self::MIN_CUT_CHARS) {
            return $start + $pos + 1;
        }

        return self::align_backward($text, $start + self::TARGET_CHARS, $start + 1);
    }

    /**
     * Move a byte offset backward until it no longer points into the middle of a UTF-8 character.
     *
     * @param string $text
     * @param int $pos Candidate offset.
     * @param int $floor Never move below this offset.
     * @return int
     */
    private static function align_backward(string $text, int $pos, int $floor): int {
        while ($pos > $floor && (ord($text[$pos]) & 0xC0) === 0x80) {
            $pos--;
        }
        return $pos;
    }

    /**
     * Move a byte offset forward until it no longer points into the middle of a UTF-8 character.
     *
     * @param string $text
     * @param int $pos Candidate offset (may be negative).
     * @return int
     */
    private static function align_forward(string $text, int $pos): int {
        if ($pos < 0) {
            return 0;
        }
        $len = strlen($text);
        while ($pos < $len && (ord($text[$pos]) & 0xC0) === 0x80) {
            $pos++;
        }
        return $pos;
    }

    /**
     * Assemble the chunk input from a core_search document — title + content + description1
     * (description2 and files deliberately skipped, blueprint §5.3).
     *
     * SHARED HELPER, used by both the indexer and the query-time snippet re-extraction in the
     * search service: the two assemble the input through this single code path so the stored
     * content hashes and the re-extracted chunks can never drift apart.
     *
     * @param \core_search\document $doc
     * @return string
     */
    public static function assemble_document_text(\core_search\document $doc): string {
        $parts = [];
        $parts[] = $doc->is_set('title') ? trim((string)$doc->get('title')) : '';
        $parts[] = $doc->is_set('content') ? (string)$doc->get('content') : '';
        $parts[] = $doc->is_set('description1') ? (string)$doc->get('description1') : '';
        return trim(implode("\n", array_filter($parts, static fn(string $p): bool => trim($p) !== '')));
    }

    /**
     * The content hash stored per chunk row — shared between indexer (write) and search service
     * (self-heal comparison) so the composition can never drift.
     *
     * @param string $chunktext
     * @param string $model Embedding model.
     * @param int $dims Embedding dimensions.
     * @return string
     */
    public static function content_hash(string $chunktext, string $model, int $dims): string {
        return sha1($chunktext . '|m=' . $model . '|d=' . $dims);
    }
}

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
 * Splits markdown into heading/size-bounded chunks.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\lookup;

/**
 * Turns a markdown document into retrieval-sized chunks with real line ranges.
 *
 * A new chunk starts at every level-2..6 heading and whenever the running size would exceed the
 * character budget; each chunk records its 1-based start/end lines and the nearest heading (or the
 * document H1) as its title. Whitespace-only chunks are dropped. The whole document is covered, so
 * content beyond the legacy 6000-char truncation is embedded too.
 */
class markdown_chunker {
    /** Default maximum characters per chunk before an oversized section is split. */
    public const DEFAULT_MAX_CHARS = 4000;

    /**
     * Chunk markdown content.
     *
     * @param string $content  Raw file content.
     * @param int    $maxchars Size budget per chunk (characters).
     * @return array[]
     */
    public static function chunk(string $content, int $maxchars = self::DEFAULT_MAX_CHARS): array {
        $maxchars = max(500, $maxchars);
        $filetitle = self::extract_h1($content);
        $lines = explode("\n", $content);
        $total = count($lines);

        $chunks = [];
        $buf = [];
        $bufstart = 1;
        $buftitle = $filetitle;
        $bufsize = 0;

        $flush = static function (int $endline) use (&$chunks, &$buf, &$bufstart, &$buftitle): void {
            if (empty($buf)) {
                return;
            }
            $text = implode("\n", $buf);
            if (trim($text) === '') {
                return;
            }
            $chunks[] = [
                'title' => $buftitle,
                'line_start' => $bufstart,
                'line_end' => $endline,
                'text' => $text,
            ];
        };

        foreach ($lines as $idx => $line) {
            $lineno = $idx + 1;
            $isheading = (bool)preg_match('/^#{2,6}\s+(.+?)\s*$/', $line, $m);
            $overbudget = $bufsize > 0 && ($bufsize + strlen($line) + 1) > $maxchars;

            if (!empty($buf) && ($isheading || $overbudget)) {
                $flush($lineno - 1);
                $buf = [];
                $bufstart = $lineno;
                $bufsize = 0;
            }
            if (empty($buf) && $isheading) {
                // A heading that opens a fresh chunk becomes its title; an over-budget continuation
                // (no heading) keeps the current section title.
                $buftitle = trim((string)$m[1]);
            }

            $buf[] = $line;
            $bufsize += strlen($line) + 1;
        }
        $flush($total);

        return $chunks;
    }

    /**
     * Extract the first H1 heading as the document title.
     *
     * @param string $content
     * @return string
     */
    private static function extract_h1(string $content): string {
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}

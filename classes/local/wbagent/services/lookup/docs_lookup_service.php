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

namespace mod_booking\local\wbagent\services\lookup;

/**
 * Deterministic lookup over booking/docs markdown files.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docs_lookup_service {
    /** @var string */
    private string $docsroot;

    /**
     * Constructor.
     *
     * @param string|null $docsroot
     */
    public function __construct(?string $docsroot = null) {
        $this->docsroot = $docsroot ?? dirname(__DIR__, 4) . '/docs';
    }

    /**
     * Search documentation files for the most relevant topic.
     *
     * @param string $question
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public function search(string $question, int $limit = 3): array {
        $tokens = $this->extract_query_tokens($question);
        if (empty($tokens)) {
            return [];
        }

        $docs = [];
        foreach ($this->load_docs() as $doc) {
            $score = $this->score_doc($doc, $tokens, $question);
            if ($score <= 0) {
                continue;
            }

            $doc['score'] = $score;
            $doc['exactbasenamehit'] = $this->has_exact_basename_hit($doc, $question);
            $docs[] = $doc;
        }

        usort($docs, static function (array $left, array $right): int {
            $scorecompare = ((int)($right['score'] ?? 0)) <=> ((int)($left['score'] ?? 0));
            if ($scorecompare !== 0) {
                return $scorecompare;
            }
            return strcmp((string)($left['path'] ?? ''), (string)($right['path'] ?? ''));
        });

        return array_slice($docs, 0, max(1, $limit));
    }

    /**
     * Whether the given search result set should be treated as ambiguous.
     *
     * @param array $docs
     * @return bool
     */
    public function is_ambiguous(array $docs): bool {
        if (count($docs) < 2) {
            return false;
        }

        $first = $docs[0] ?? [];
        $second = $docs[1] ?? [];
        if (!empty($first['exactbasenamehit'])) {
            return false;
        }

        $topscore = (int)($first['score'] ?? 0);
        $secondscore = (int)($second['score'] ?? 0);
        if ($topscore <= 0 || $secondscore <= 0) {
            return false;
        }

        return $secondscore >= (int)floor($topscore * 0.7);
    }

    /**
     * Return human-readable top candidate titles for ambiguity prompts.
     *
     * @param array $docs
     * @param int $limit
     * @return array
     */
    public function get_ambiguity_candidates(array $docs, int $limit = 4): array {
        $candidates = [];
        foreach (array_slice($docs, 0, max(2, $limit)) as $doc) {
            $title = trim((string)($doc['title'] ?? ''));
            $path = trim((string)($doc['path'] ?? ''));
            if ($title === '') {
                $title = $path;
            }
            if ($title === '') {
                continue;
            }
            $candidates[] = $title;
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Build a concise user-facing explanation from a matched doc.
     *
     * @param array $doc
     * @return string
     */
    public function build_summary(array $doc): string {
        $excerpt = $this->strip_markdown((string)($doc['excerpt'] ?? ''));
        $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt) ?? $excerpt);
        if ($excerpt === '') {
            $excerpt = $this->strip_markdown((string)($doc['title'] ?? ''));
        }

        if (preg_match('/^(.+?[.!?])(\s|$)/', $excerpt, $matches)) {
            return trim($matches[1]);
        }

        return trim($excerpt);
    }

    /**
     * Load and parse all markdown docs.
     *
     * @return array<int,array<string,string>>
     */
    private function load_docs(): array {
        if (!is_dir($this->docsroot)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->docsroot, \FilesystemIterator::SKIP_DOTS)
        );

        $docs = [];
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo instanceof \SplFileInfo || !$fileinfo->isFile()) {
                continue;
            }

            if (strtolower($fileinfo->getExtension()) !== 'md') {
                continue;
            }

            $fullpath = $fileinfo->getPathname();
            $content = @file_get_contents($fullpath);
            if (!is_string($content) || $content === '') {
                continue;
            }

            $relativepath = ltrim(str_replace($this->docsroot, '', $fullpath), '/');
            $relativepath = str_replace('\\', '/', $relativepath);
            $title = $this->extract_title($content, $fileinfo->getBasename('.md'));
            $excerpt = $this->extract_excerpt($content);

            $docs[] = [
                'path' => $relativepath,
                'title' => $title,
                'excerpt' => $excerpt,
                'basename' => $fileinfo->getBasename('.md'),
                'content' => $content,
            ];
        }

        return $docs;
    }

    /**
     * Score a doc for a given question.
     *
     * @param array $doc
     * @param array $tokens
     * @param string $question
     * @return int
     */
    private function score_doc(array $doc, array $tokens, string $question): int {
        $score = 0;
        $path = strtolower((string)($doc['path'] ?? ''));
        $title = strtolower((string)($doc['title'] ?? ''));
        $excerpt = strtolower((string)($doc['excerpt'] ?? ''));
        $content = strtolower((string)($doc['content'] ?? ''));
        $basename = strtolower((string)($doc['basename'] ?? ''));
        $questioncompact = preg_replace('/[^a-z0-9]+/', '', strtolower($question)) ?? '';

        if ($basename !== '' && $questioncompact !== '' && strpos($questioncompact, $basename) !== false) {
            $score += 250;
        }

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (strpos($title, $token) !== false) {
                $score += 60;
            }
            if (strpos($path, $token) !== false) {
                $score += 40;
            }
            if (strpos($excerpt, $token) !== false) {
                $score += 20;
            }
            if (strpos($content, $token) !== false) {
                $score += 5;
            }
        }

        return $score;
    }

    /**
     * Detect whether the question explicitly contains the markdown basename.
     *
     * @param array $doc
     * @param string $question
     * @return bool
     */
    private function has_exact_basename_hit(array $doc, string $question): bool {
        $basename = strtolower((string)($doc['basename'] ?? ''));
        if ($basename === '') {
            return false;
        }

        $genericbasenames = [
            'readme', 'action', 'actions', 'condition', 'conditions', 'overview',
        ];
        if (in_array($basename, $genericbasenames, true)) {
            return false;
        }

        $questioncompact = preg_replace('/[^a-z0-9]+/', '', strtolower($question)) ?? '';
        return $questioncompact !== '' && strpos($questioncompact, $basename) !== false;
    }

    /**
     * Extract significant query tokens.
     *
     * @param string $question
     * @return array
     */
    private function extract_query_tokens(string $question): array {
        $normalized = strtolower($question);
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?? $normalized;
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];

        $stopwords = [
            'a', 'an', 'and', 'are', 'briefly', 'can', 'does', 'explain', 'for', 'function', 'help', 'how', 'i', 'is',
            'it', 'mean', 'me', 'of', 'please', 'the', 'this', 'to', 'what', 'with', 'works',
            'bitte', 'bedeutet', 'der', 'die', 'das', 'eine', 'ein', 'erklaer', 'erklaere', 'erklaeren', 'funktion',
            'ist', 'was', 'wie', 'wofuer', 'wozu',
        ];
        $stopwordmap = array_fill_keys($stopwords, true);

        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) < 3 || isset($stopwordmap[$part])) {
                continue;
            }
            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Extract the markdown H1 title.
     *
     * @param string $content
     * @param string $fallback
     * @return string
     */
    private function extract_title(string $content, string $fallback): string {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return $fallback;
    }

    /**
     * Extract a useful short excerpt.
     *
     * @param string $content
     * @return string
     */
    private function extract_excerpt(string $content): string {
        $section = '';
        if (preg_match('/^##\s+What it does\s*$([\s\S]*?)(?=^##\s+|\z)/mi', $content, $matches)) {
            $section = trim($matches[1]);
        }

        $source = $section !== '' ? $section : $content;
        $lines = preg_split('/\R/', $source) ?: [];
        $paragraph = [];
        $started = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($started) {
                    break;
                }
                continue;
            }

            if (str_starts_with($trimmed, '#') || str_starts_with($trimmed, '|') || str_starts_with($trimmed, '- ')) {
                if ($started) {
                    break;
                }
                continue;
            }

            $paragraph[] = $trimmed;
            $started = true;
        }

        if (!empty($paragraph)) {
            return trim(implode(' ', $paragraph));
        }

        return trim($this->strip_markdown(substr($content, 0, 240)));
    }

    /**
     * Strip simple markdown markup.
     *
     * @param string $text
     * @return string
     */
    private function strip_markdown(string $text): string {
        $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '$1', $text) ?? $text;
        $text = str_replace(['**', '__', chr(96)], '', $text);
        return trim($text);
    }
}

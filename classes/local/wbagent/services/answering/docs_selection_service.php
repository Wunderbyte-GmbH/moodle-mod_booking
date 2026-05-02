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

namespace mod_booking\local\wbagent\services\answering;

/**
 * LLM-based doc selection from a lightweight index.
 *
 * Receives the full doc index (path + title + excerpt) and the user question,
 * and asks the LLM to return up to $limit most relevant doc paths as JSON.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docs_selection_service extends base_answering_service {
    /**
     * Ask the LLM to pick the most relevant docs from an index.
     *
     * @param string $question        User question (any language).
     * @param array  $docindex        Output of docs_lookup_service::get_all_doc_index().
     * @param int    $limit           Maximum number of docs to select.
     * @param int    $cmid
     * @param int    $userid
     * @return array<int,string>      List of selected relative doc paths, empty on failure.
     */
    public function select_docs(string $question, array $docindex, int $limit, int $cmid, int $userid): array {
        if (empty($docindex)) {
            return [];
        }

        $prompt = $this->build_prompt($question, $docindex, $limit);
        $result = $this->generate_answer($prompt, $cmid, $userid);
        $raw = trim((string)($result['answer'] ?? ''));
        if ($raw === '') {
            return [];
        }

        return $this->parse_paths($raw, $docindex);
    }

    /**
     * Build the selection prompt.
     *
     * @param string $question
     * @param array  $docindex
     * @param int    $limit
     * @return string
     */
    private function build_prompt(string $question, array $docindex, int $limit): string {
        $lines = [];
        foreach ($docindex as $doc) {
            $path    = (string)($doc['path'] ?? '');
            $title   = (string)($doc['title'] ?? '');
            $excerpt = (string)($doc['excerpt'] ?? '');
            $lines[] = "- path: {$path} | title: {$title} | excerpt: {$excerpt}";
        }
        $indexsection = implode("\n", $lines);

        return "You are a documentation retrieval assistant for the Moodle Booking plugin.\n"
            . "Given a user question and a list of documentation files, return the paths of the "
            . "most relevant files — up to {$limit} — as a JSON array of strings.\n\n"
            . "Rules:\n"
            . "- Output ONLY a JSON array, e.g.: [\"booking_rules/README.md\", \"booking_rules/actions.md\"]\n"
            . "- Include only paths from the list below. Do not invent paths.\n"
            . "- If nothing is relevant, return an empty array: []\n"
            . "- Do not include explanations, markdown fences, or any other text.\n\n"
            . "User question:\n{$question}\n\n"
            . "Documentation index:\n{$indexsection}";
    }

    /**
     * Parse the LLM response into a list of valid doc paths.
     *
     * @param string $raw
     * @param array  $docindex
     * @return array<int,string>
     */
    private function parse_paths(string $raw, array $docindex): array {
        // Strip optional markdown code fences.
        $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw) ?? $raw;
        $raw = preg_replace('/```\s*$/', '', $raw) ?? $raw;
        $raw = trim($raw);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Build a set of known paths for validation.
        $knownpaths = [];
        foreach ($docindex as $doc) {
            $knownpaths[(string)($doc['path'] ?? '')] = true;
        }

        $paths = [];
        foreach ($decoded as $item) {
            $path = trim((string)$item);
            if ($path !== '' && isset($knownpaths[$path])) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }
}

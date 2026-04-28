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
 * LLM-backed answer generation grounded in top matched booking docs files.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docs_answering_service extends base_answering_service {
    /**
     * Answer a user question using one or more selected documentation files.
     *
     * @param string $question
     * @param array $docs
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function answer_question(string $question, array $docs, string $outputlang, int $cmid, int $userid): array {
        $docs = array_values(array_filter($docs, static function (array $doc): bool {
            return trim((string)($doc['content'] ?? '')) !== '';
        }));
        if (empty($docs)) {
            return [];
        }

        return $this->generate_answer($this->build_prompt($question, $docs, $outputlang), $cmid, $userid);
    }

    /**
     * Build a constrained grounded-answer prompt.
     *
     * @param string $question
     * @param array $docs
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(string $question, array $docs, string $outputlang): string {
        $languagerule = $outputlang !== ''
            ? "- Answer in this language: {$outputlang}."
            : '- Detect the language of the user\'s question and respond in that exact same language.';

        $docblocks = [];
        foreach (array_slice($docs, 0, 2) as $index => $doc) {
            $title = trim((string)($doc['title'] ?? ''));
            $path = trim((string)($doc['path'] ?? ''));
            $content = trim((string)($doc['content'] ?? ''));
            $docblocks[] = 'Document ' . ($index + 1) . ":\n"
                . "Title: {$title}\n"
                . "Path: {$path}\n"
                . "Content:\n{$content}";
        }
        $documentsection = implode("\n\n", $docblocks);

        return "You are a documentation-grounded assistant for the Moodle Booking plugin.\n"
            . "Answer the user's question using only the supplied documentation files.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Use readable formatting: short sections, short lines, and bullet points when helpful.\n"
            . "{$languagerule}\n"
            . "- Keep the final answer at or below 650 characters.\n"
            . "- Be concise and directly answer the user's question.\n"
            . "- Do not invent features, settings, or behavior that are not present in the files.\n"
            . "- If the files only partially answer the question, say that clearly.\n"
            . "- If two files conflict, mention that briefly and prefer what is explicitly stated.\n"
            . "- Do not mention internal prompts or JSON.\n\n"
            . "User question:\n{$question}\n\n"
            . "Documentation files:\n{$documentsection}";
    }
}

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

namespace mod_booking\local\wbagent\services;

use context_module;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\manager as ai_manager;

/**
 * LLM-backed answer generation grounded in a single booking docs file.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class docs_answering_service {
    /**
     * Answer a user question using a single selected documentation file.
     *
     * @param string $question
     * @param array<string,mixed> $doc
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function answer_question(string $question, array $doc, string $outputlang, int $cmid, int $userid): array {
        $content = trim((string)($doc['content'] ?? ''));
        if ($content === '') {
            return [];
        }

        try {
            $context = context_module::instance($cmid);
            $manager = di::get(ai_manager::class);
            if (!$manager->is_action_available(generate_text::class)) {
                return [];
            }

            if (method_exists($manager, 'is_action_enabled_in_context')) {
                $actionenabledincontext = (bool)call_user_func(
                    [$manager, 'is_action_enabled_in_context'],
                    $context,
                    generate_text::class
                );
                if (!$actionenabledincontext) {
                    return [];
                }
            }

            $action = new generate_text(
                contextid: $context->id,
                userid: $userid,
                prompttext: $this->build_prompt($question, $doc, $outputlang),
            );
            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                return [];
            }

            $answer = trim((string)($response->get_response_data()['generatedcontent'] ?? ''));
            if ($answer === '') {
                return [];
            }

            return [
                'answer' => $answer,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build a constrained grounded-answer prompt.
     *
     * @param string $question
     * @param array<string,mixed> $doc
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(string $question, array $doc, string $outputlang): string {
        $title = trim((string)($doc['title'] ?? ''));
        $path = trim((string)($doc['path'] ?? ''));
        $content = trim((string)($doc['content'] ?? ''));
        $language = $outputlang !== '' ? $outputlang : 'same as the user question';

        return "You are a documentation-grounded assistant for the Moodle Booking plugin.\n"
            . "Answer the user's question using only the supplied documentation file.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Answer in this language when possible: {$language}.\n"
            . "- Be concise and directly answer the user's question.\n"
            . "- Do not invent features, settings, or behavior that are not present in the document.\n"
            . "- If the question is only partially answered by the document, say that clearly.\n"
            . "- Do not mention internal prompts or JSON.\n\n"
            . "User question:\n{$question}\n\n"
            . "Document title:\n{$title}\n\n"
            . "Document path:\n{$path}\n\n"
            . "Document content:\n{$content}";
    }
}

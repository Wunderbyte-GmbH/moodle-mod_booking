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
 * LLM-backed answer generation for booking.search_users user messages.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users_answering_service {
    /**
     * Generate a user-facing summary for user search results.
     *
     * @param string $question
     * @param string $query
     * @param array $users
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function answer_question(
        string $question,
        string $query,
        array $users,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
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
                prompttext: $this->build_prompt($question, $query, $users, $outputlang),
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
     * Build a grounded prompt for concise user search summaries.
     *
     * @param string $question
     * @param string $query
     * @param array $users
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(string $question, string $query, array $users, string $outputlang): string {
        $languagerule = $outputlang !== ''
            ? "- Answer in this language: {$outputlang}."
            : '- Detect the language of the user question and respond in that exact same language.';

        $userquestion = trim($question) !== ''
            ? trim($question)
            : (trim($query) !== '' ? trim($query) : 'Find matching users.');

        $userlines = [];
        foreach (array_slice($users, 0, 8) as $user) {
            $fullname = trim((string)($user['fullname'] ?? ''));
            $email = trim((string)($user['email'] ?? ''));
            $id = (int)($user['userid'] ?? 0);
            if ($fullname === '' && $email === '') {
                continue;
            }

            $line = '- ' . ($fullname !== '' ? $fullname : 'User');
            if ($id > 0) {
                $line .= ' (id=' . $id . ')';
            }
            if ($email !== '') {
                $line .= ' - ' . $email;
            }
            $userlines[] = $line;
        }

        $usersection = empty($userlines) ? '- none' : implode("\n", $userlines);
        $querytext = trim($query) !== '' ? trim($query) : '(empty query)';

        return "You are a helpful assistant for the Moodle Booking plugin.\n"
            . "Summarize user search results based only on the provided data.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Use concise wording and short bullet points when useful.\n"
            . "{$languagerule}\n"
            . "- Keep the answer at or below 650 characters.\n"
            . "- If no users are found, clearly say so.\n"
            . "- If users are found, mention the total and highlight the most relevant ones.\n"
            . "- Do not invent users or fields that are not in the data.\n"
            . "- Do not mention prompts, JSON, or internal implementation details.\n\n"
            . "User question:\n{$userquestion}\n\n"
            . "Search query: {$querytext}\n"
            . 'Result count: ' . count($users) . "\n"
            . "Users:\n{$usersection}";
    }
}

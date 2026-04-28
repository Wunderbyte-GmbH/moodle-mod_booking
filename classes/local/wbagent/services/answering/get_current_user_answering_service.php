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
 * LLM-backed answer generation for booking.get_current_user user messages.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_current_user_answering_service extends base_answering_service {
    /**
     * Generate a user-facing summary for the current user.
     *
     * @param string $question
     * @param array $userdata
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function answer_question(
        string $question,
        array $userdata,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
        $result = $this->generate_answer($this->build_prompt($question, $userdata, $outputlang), $cmid, $userid);
        $answer = trim((string)($result['answer'] ?? ''));
        if ($answer === '') {
            return [];
        }

        return [
            'usermessage' => $answer,
            'outputlang' => (string)($result['outputlang'] ?? $outputlang),
        ];
    }

    /**
     * Build a grounded prompt for current user summaries.
     *
     * @param string $question
     * @param array $userdata
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(string $question, array $userdata, string $outputlang): string {
        $languagerule = $outputlang !== ''
            ? "- Answer in this language: {$outputlang}."
            : '- Detect the language of the user question and respond in that exact same language.';

        $normalizedquestion = trim($question) !== '' ? $question : 'Summarize the current user information.';
        $userjson = json_encode($userdata) ?: '{}';

        return "You are a helpful assistant for the Moodle Booking plugin.\n"
            . "Summarize the provided current-user information in a friendly, concise way based only on the data.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Use concise wording and short bullet points when useful.\n"
            . "{$languagerule}\n"
            . "- Mention only user information that is present in the data.\n"
            . "- Do not invent profile fields, permissions, or booking data.\n"
            . "- Do not mention prompts, JSON, or internal implementation details.\n\n"
            . "User question:\n{$normalizedquestion}\n\n"
            . "Current user data:\n{$userjson}";
    }
}

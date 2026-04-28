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
 * LLM-backed answer generation for booking.bulk_update_options user messages.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_update_options_answering_service extends base_answering_service {
    /**
     * Generate a user-facing summary for bulk update results.
     *
     * @param string $question
     * @param array $resultdata
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function answer_question(
        string $question,
        array $resultdata,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
        $result = $this->generate_answer($this->build_prompt($question, $resultdata, $outputlang), $cmid, $userid);
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
     * Build a grounded prompt for bulk update summaries.
     *
     * @param string $question
     * @param array $resultdata
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(string $question, array $resultdata, string $outputlang): string {
        $languagerule = $outputlang !== ''
            ? "- Answer in this language: {$outputlang}."
            : '- Detect the language of the user question and respond in that exact same language.';

        $normalizedquestion = trim($question) !== '' ? $question : 'Summarize the bulk update result.';
        $resultjson = json_encode($resultdata) ?: '{}';

        return "You are a helpful assistant for the Moodle Booking plugin.\n"
            . "Summarize the result of a bulk update operation for the user based only on the provided data.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Use concise wording and short bullet points when useful.\n"
            . "{$languagerule}\n"
            . "- Keep the answer focused on what changed, what failed, and what the user should know next.\n"
            . "- Do not invent changes or statuses that are not present in the result data.\n"
            . "- Do not mention prompts, JSON, or internal implementation details.\n\n"
            . "User question:\n{$normalizedquestion}\n\n"
            . "Bulk update result data:\n{$resultjson}";
    }
}

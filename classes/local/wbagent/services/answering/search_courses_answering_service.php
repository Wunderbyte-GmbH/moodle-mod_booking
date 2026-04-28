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
 * LLM-backed answer generation for booking.search_courses user messages.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_courses_answering_service extends base_answering_service {
    /**
     * Generate a user-facing summary for course search results.
     *
     * @param string $question
     * @param string $query
     * @param array $courses
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function answer_question(
        string $question,
        string $query,
        array $courses,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
        return $this->generate_answer(
            $this->build_prompt($question, $query, $courses, $outputlang),
            $cmid,
            $userid
        );
    }

    /**
     * Build a grounded prompt for concise course search summaries.
     *
     * @param string $question
     * @param string $query
     * @param array $courses
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(string $question, string $query, array $courses, string $outputlang): string {
        $languagerule = $outputlang !== ''
            ? "- Answer in this language: {$outputlang}."
            : '- Detect the language of the user question and respond in that exact same language.';

        $userquestion = trim($question) !== ''
            ? trim($question)
            : (trim($query) !== '' ? trim($query) : 'Find matching courses.');

        $courselines = [];
        foreach (array_slice($courses, 0, 8) as $course) {
            $fullname = trim((string)($course['fullname'] ?? ''));
            $shortname = trim((string)($course['shortname'] ?? ''));
            $id = (int)($course['courseid'] ?? 0);
            if ($fullname === '' && $shortname === '') {
                continue;
            }

            $line = '- ' . ($fullname !== '' ? $fullname : $shortname);
            if ($shortname !== '' && $shortname !== $fullname) {
                $line .= ' [' . $shortname . ']';
            }
            if ($id > 0) {
                $line .= ' (id=' . $id . ')';
            }
            $courselines[] = $line;
        }

        $coursesection = empty($courselines) ? '- none' : implode("\n", $courselines);
        $querytext = trim($query) !== '' ? trim($query) : '(empty query)';

        return "You are a helpful assistant for the Moodle Booking plugin.\n"
            . "Summarize course search results based only on the provided data.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Use concise wording and short bullet points when useful.\n"
            . "{$languagerule}\n"
            . "- Keep the answer at or below 650 characters.\n"
            . "- If no courses are found, clearly say so.\n"
            . "- If courses are found, mention the total and highlight the most relevant ones.\n"
            . "- Do not invent courses or fields that are not in the data.\n"
            . "- Do not mention prompts, JSON, or internal implementation details.\n\n"
            . "User question:\n{$userquestion}\n\n"
            . "Search query: {$querytext}\n"
            . 'Result count: ' . count($courses) . "\n"
            . "Courses:\n{$coursesection}";
    }
}

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
 * LLM-backed answer generation for booking.list_option_properties user messages.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_option_properties_answering_service extends base_answering_service {
    /**
     * Generate a user-facing summary for option properties.
     *
     * @param string $question
     * @param string $scope
     * @param array $properties
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function answer_question(
        string $question,
        string $scope,
        array $properties,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
        return $this->generate_answer(
            $this->build_prompt($question, $scope, $properties, $outputlang),
            $cmid,
            $userid
        );
    }

    /**
     * Build a grounded prompt from structured property data.
     *
     * @param string $question
     * @param string $scope
     * @param array $properties
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(string $question, string $scope, array $properties, string $outputlang): string {
        $languagerule = $outputlang !== ''
            ? "- Answer in this language: {$outputlang}."
            : '- Detect the language of the user question and respond in that exact same language.';

        $propertylines = [];
        foreach (array_slice($properties, 0, 20) as $property) {
            $label = trim((string)($property['label'] ?? ''));
            $name = trim((string)($property['name'] ?? ''));
            $type = trim((string)($property['type'] ?? 'mixed'));
            $description = trim((string)($property['description'] ?? ''));
            $scopeinfo = [];
            if (!empty($property['increate'])) {
                $scopeinfo[] = 'create';
            }
            if (!empty($property['inupdate'])) {
                $scopeinfo[] = 'update';
            }
            $scopepart = empty($scopeinfo) ? '' : ' [' . implode('/', $scopeinfo) . ']';
            $title = $label !== '' ? $label : $name;

            if ($title === '' && $description === '') {
                continue;
            }

            $line = '- ' . $title . $scopepart . ' (' . $type . ')';
            if ($description !== '') {
                $line .= ': ' . $description;
            }
            $propertylines[] = $line;
        }

        $propertysection = empty($propertylines) ? '- none' : implode("\n", $propertylines);
        $normalizedscope = trim($scope) !== '' ? $scope : 'all';
        $normalizedquestion = trim($question) !== '' ? $question : 'Which option properties are available?';

        return "You are a helpful assistant for the Moodle Booking plugin.\n"
            . "Summarize the available option properties based only on the supplied structured data.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Use concise formatting and short bullet points where useful.\n"
            . "{$languagerule}\n"
            . "- Keep the answer at or below 650 characters.\n"
            . "- Mention concrete property names/labels from the provided list.\n"
            . "- If the list is long, highlight only the most relevant ones and mention that more exist.\n"
            . "- Do not invent properties not present in the list.\n"
            . "- Do not mention prompts, JSON, or internal implementation details.\n\n"
            . "User question:\n{$normalizedquestion}\n\n"
            . "Selected scope: {$normalizedscope}\n"
            . "Properties:\n{$propertysection}";
    }
}

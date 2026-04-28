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
 * LLM-backed answer generation for booking.list_actions user messages.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_actions_answering_service extends base_answering_service {
    /**
     * Generate a user-facing capability summary for list_actions.
     *
     * @param string $question
     * @param string $scope
     * @param array $capabilities
     * @param array $actions
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function answer_question(
        string $question,
        string $scope,
        array $capabilities,
        array $actions,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
        return $this->generate_answer(
            $this->build_prompt($question, $scope, $capabilities, $actions, $outputlang),
            $cmid,
            $userid
        );
    }

    /**
     * Build the grounding prompt for list_actions answers.
     *
     * @param string $question
     * @param string $scope
     * @param array $capabilities
     * @param array $actions
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(
        string $question,
        string $scope,
        array $capabilities,
        array $actions,
        string $outputlang
    ): string {
        $languagerule = $outputlang !== ''
            ? "- Answer in this language: {$outputlang}."
            : '- Detect the language of the user question and respond in that exact same language.';

        $capabilitylines = [];
        foreach ($capabilities as $capability) {
            $title = trim((string)($capability['title'] ?? ''));
            $description = trim((string)($capability['description'] ?? ''));
            if ($title === '' && $description === '') {
                continue;
            }

            if ($title !== '' && $description !== '') {
                $capabilitylines[] = '- ' . $title . ': ' . $description;
            } else if ($title !== '') {
                $capabilitylines[] = '- ' . $title;
            } else {
                $capabilitylines[] = '- ' . $description;
            }
        }

        $actionlines = [];
        foreach ($actions as $action) {
            $label = trim((string)($action['label'] ?? ''));
            $description = trim((string)($action['description'] ?? ''));
            if ($label === '' && $description === '') {
                continue;
            }

            if ($label !== '' && $description !== '') {
                $actionlines[] = '- ' . $label . ': ' . $description;
            } else if ($label !== '') {
                $actionlines[] = '- ' . $label;
            } else {
                $actionlines[] = '- ' . $description;
            }
        }

        $capabilitysection = empty($capabilitylines) ? '- none' : implode("\n", $capabilitylines);
        $actionsection = empty($actionlines) ? '- none' : implode("\n", array_slice($actionlines, 0, 12));
        $normalizedscope = trim($scope) !== '' ? $scope : 'all';
        $normalizedquestion = trim($question) !== '' ? $question : 'List what you can do.';

        return "You are a helpful assistant for the Moodle Booking plugin.\n"
            . "Summarize what actions/capabilities are available based only on the provided data.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Use concise wording and short bullet points when useful.\n"
            . "{$languagerule}\n"
            . "- Keep the answer at or below 650 characters.\n"
            . "- Mention at least one concrete capability title when available.\n"
            . "- Do not invent capabilities or actions not present in the data.\n"
            . "- Do not mention prompts, JSON, or internal implementation details.\n\n"
            . "User question:\n{$normalizedquestion}\n\n"
            . "Selected scope: {$normalizedscope}\n"
            . "Capabilities:\n{$capabilitysection}\n\n"
            . "Actions:\n{$actionsection}";
    }
}

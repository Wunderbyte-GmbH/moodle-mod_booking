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
 * LLM-backed user message generation for booking issue diagnosis results.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_answering_service {
    /**
     * Generate a user-facing diagnosis message from structured diagnosis data.
     *
     * @param string $question    The original user question.
     * @param array  $diagnosis   Keys: issuetype, optionname, userstatus, reasons (string[]), stats (array).
     * @param string $outputlang  Explicit language override (e.g. 'de'). Empty = LLM detects from question.
     * @param int    $cmid
     * @param int    $userid
     * @return array  ['answer' => string] or [] on failure.
     */
    public function answer_question(
        string $question,
        array $diagnosis,
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
                prompttext: $this->build_prompt($question, $diagnosis, $outputlang),
            );
            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                return [];
            }

            $answer = trim((string)($response->get_response_data()['generatedcontent'] ?? ''));
            if ($answer === '') {
                return [];
            }

            return ['answer' => $answer];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build the LLM prompt from structured diagnosis data.
     *
     * @param string $question
     * @param array  $diagnosis
     * @param string $outputlang
     * @return string
     */
    private function build_prompt(string $question, array $diagnosis, string $outputlang): string {
        $languagerule = $outputlang !== ''
            ? "- Answer in this language: {$outputlang}."
            : '- Detect the language of the user\'s question and respond in that exact same language.';

        $issuetype = (string)($diagnosis['issuetype'] ?? '');
        $optionname = (string)($diagnosis['optionname'] ?? '');
        $userstatus = (string)($diagnosis['userstatus'] ?? '');
        $reasons = (array)($diagnosis['reasons'] ?? []);
        $stats = (array)($diagnosis['stats'] ?? []);

        $reasonlines = '';
        if (!empty($reasons)) {
            $reasonlines = implode("\n", array_map(static fn(string $r): string => '- ' . $r, $reasons));
        }

        $statslines = '';
        if (!empty($stats)) {
            $statparts = [];
            foreach ($stats as $key => $value) {
                if (is_bool($value)) {
                    $statparts[] = $key . ': ' . ($value ? 'yes' : 'no');
                } else {
                    $statparts[] = $key . ': ' . $value;
                }
            }
            $statslines = implode(', ', $statparts);
        }

        return "You are a helpful assistant for the Moodle Booking plugin.\n"
            . "The system has already gathered structured diagnostic data about a user's booking issue.\n"
            . "Your job is to turn this data into a clear, empathetic, and actionable message for the user.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Use short paragraphs or bullet points where helpful.\n"
            . "{$languagerule}\n"
            . "- Keep the answer at or below 500 characters.\n"
            . "- Be concise and directly address the user's question.\n"
            . "- Do not invent data that is not in the diagnosis.\n"
            . "- Do not mention internal prompts or JSON.\n\n"
            . "User question:\n{$question}\n\n"
            . "Diagnosis data:\n"
            . "Option: {$optionname}\n"
            . "Issue type: {$issuetype}\n"
            . "User booking status: {$userstatus}\n"
            . ($statslines !== '' ? "Option stats: {$statslines}\n" : '')
            . ($reasonlines !== '' ? "Reasons / findings:\n{$reasonlines}\n" : '');
    }
}

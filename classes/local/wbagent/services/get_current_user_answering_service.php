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
 * LLM-backed answer generation for booking.get_current_user user messages.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_current_user_answering_service {
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
        try {
            $context = context_module::instance($cmid);
            $manager = di::get(ai_manager::class);
            if (!$manager->is_action_available(generate_text::class)) {
                return [];
            }

            if (method_exists($manager, 'is_action_enabled_in_context')) {
                $actionenabledincontext = (bool)call_user_func(
                    [$manager, 'is_action_enabled_in_context'],
                    generate_text::class,
                    $context
                );
                if (!$actionenabledincontext) {
                    return [];
                }
            }

            $prompt = "You are a helpful assistant. "
                . "Summarize the following user information for the user in a friendly, concise way. "
                . "Use the same language as the question, unless outputlang is set.\n";
            $prompt .= "User data: " . json_encode($userdata) . "\n";
            $prompt .= "Question: " . $question . "\n";
            if (!empty($outputlang)) {
                $prompt .= "Output language: " . $outputlang . "\n";
            }

            $action = new generate_text(
                contextid: $context->id,
                userid: $userid,
                prompttext: $prompt,
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
                'usermessage' => $answer,
                'outputlang' => $outputlang,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

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

use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\privacy_anonymizer;
use context_module;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\manager as ai_manager;

/**
 * Shared base class for LLM-backed answering services.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_answering_service {
    /**
     * Execute a prompt via core_ai generate_text and return normalized answer payload.
     *
     * @param string $prompt
     * @param int $cmid
     * @param int $userid
     * @return array{answer:string}|array
     */
    protected function generate_answer(string $prompt, int $cmid, int $userid): array {
        try {
            $context = context_module::instance($cmid);
            $promptforllm = $prompt;

            // Task-level LLM prompts must follow the same privacy guarantees as the
            // main orchestrator path when privacy mode is enabled.
            $store = new conversation_store();
            $thread = $store->get_active_thread($userid, $cmid);
            if ($thread && !empty($thread->id)) {
                $anonymizer = new privacy_anonymizer($store);
                $promptforllm = (string)$anonymizer->anonymize_value_for_llm((int)$thread->id, $promptforllm);
            }

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
                prompttext: $promptforllm,
            );
            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                return [];
            }

            $responsedata = $response->get_response_data();
            $answer = trim((string)($responsedata['generatedcontent'] ?? ''));
            if ($answer === '') {
                return [];
            }

            $result = ['answer' => $answer];
            if (!empty($responsedata['outputlang'])) {
                $result['outputlang'] = (string)$responsedata['outputlang'];
            } else if (!empty($responsedata['detectedlang'])) {
                $result['outputlang'] = (string)$responsedata['detectedlang'];
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

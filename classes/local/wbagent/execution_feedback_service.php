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

/**
 * Build user-facing execution feedback after task execution.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wbagent;

use context_module;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\manager as ai_manager;

/**
 * Generates LLM-authored post-execution feedback and client-safe run results.
 */
class execution_feedback_service {
    /** @var conversation_store */
    private conversation_store $store;

    /** @var privacy_anonymizer */
    private privacy_anonymizer $anonymizer;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
        $this->anonymizer = new privacy_anonymizer($store);
    }

    /**
     * Build the final assistant message and client-safe result payload.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array<int,array<string,mixed>> $commands
     * @param array<int,array<string,mixed>> $results
     * @param string $outputlang
     * @return array{message:string,results:array<int,array<string,mixed>>}
     */
    public function build_completion_feedback(
        int $threadid,
        int $cmid,
        int $userid,
        array $commands,
        array $results,
        string $outputlang = ''
    ): array {
        // Always generate the final user-facing message through the feedback layer
        // so language policy is consistently enforced (same language as user input).
        $message = $this->generate_llm_feedback($threadid, $cmid, $userid, $commands, $results, $outputlang);

        return [
            'message' => $message,
            'results' => $this->sanitize_results_for_client($results, $outputlang),
        ];
    }

    /**
     * Ask the LLM for the final user-facing post-execution message.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array<int,array<string,mixed>> $commands
     * @param array<int,array<string,mixed>> $results
     * @param string $outputlang
     * @return string
     */
    private function generate_llm_feedback(
        int $threadid,
        int $cmid,
        int $userid,
        array $commands,
        array $results,
        string $outputlang
    ): string {
        $context = context_module::instance($cmid);
        $recentmessages = $this->store->get_recent_messages($threadid, 8);
        $latestusermessage = '';
        for ($i = count($recentmessages) - 1; $i >= 0; $i--) {
            if (($recentmessages[$i]->role ?? '') === 'user') {
                $latestusermessage = (string)($recentmessages[$i]->content ?? '');
                break;
            }
        }

        $sanitizedcommands = $this->anonymizer->anonymize_value_for_llm($threadid, $commands);
        $sanitizedresults = $this->anonymizer->anonymize_value_for_llm($threadid, $results);

        $prompt = $this->build_feedback_prompt(
            $outputlang,
            $latestusermessage,
            $sanitizedcommands,
            $sanitizedresults
        );

        try {
            $manager = di::get(ai_manager::class);
            if (!$manager->is_action_available(generate_text::class)) {
                return $this->fallback_message_for_results($results, $outputlang);
            }

            $hascontextavailabilitycheck = method_exists($manager, 'is_action_enabled_in_context');
            $actiondisabledincontext = $hascontextavailabilitycheck
                && !$manager->is_action_enabled_in_context($context, generate_text::class);
            if ($actiondisabledincontext) {
                return $this->fallback_message_for_results($results, $outputlang);
            }

            $action = new generate_text(
                contextid: $context->id,
                userid: $userid,
                prompttext: $prompt,
            );
            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                return $this->fallback_message_for_results($results, $outputlang);
            }

            $message = trim((string)($response->get_response_data()['generatedcontent'] ?? ''));
            if ($message === '') {
                return $this->fallback_message_for_results($results, $outputlang);
            }

            return $message;
        } catch (\Throwable $e) {
            return $this->fallback_message_for_results($results, $outputlang);
        }
    }

    /**
     * Build the summary prompt for the post-execution LLM pass.
     *
     * @param string $outputlang
     * @param string $latestusermessage
     * @param array<int,array<string,mixed>> $commands
     * @param array<int,array<string,mixed>> $results
     * @return string
     */
    private function build_feedback_prompt(
        string $outputlang,
        string $latestusermessage,
        array $commands,
        array $results
    ): string {
        $commandsjson = json_encode($commands, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $resultsjson = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return "You are the final user-facing assistant message writer for Moodle Booking.\n"
            . "The internal tasks have already been executed successfully or with structured result data.\n"
            . "Write exactly the assistant message that should be shown to the end user now.\n\n"
            . "Rules:\n"
            . "- Output plain text only.\n"
            . "- Do not output JSON, bullet lists, code fences, or internal metadata.\n"
            . "- Use the same language as the latest user message. If unclear, prefer this language code: "
            . ($outputlang !== '' ? $outputlang : 'current') . ".\n"
            . "- Do not mention task names, command numbers, run ids, response types, or raw JSON.\n"
            . "- If there are zero matches, say that clearly.\n"
            . "- If there are matches, summarize them naturally and concisely.\n"
            . "- If booking options are included, use their real option ids from the structured results.\n"
            . "- Never renumber options as 1, 2, 3, ... unless those are the actual option ids.\n"
            . "- If ANON_USER tokens appear, keep them unchanged.\n"
            . "- Never invent details not present in the results.\n\n"
            . "Latest user message:\n"
            . ($latestusermessage !== '' ? $latestusermessage : '(none)') . "\n\n"
            . "Executed commands:\n"
            . ($commandsjson !== false ? $commandsjson : '[]') . "\n\n"
            . "Structured results:\n"
            . ($resultsjson !== false ? $resultsjson : '[]');
    }

    /**
     * Remove sensitive or low-value raw result fields before data reaches the client.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array<string,mixed>>
     */
    private function sanitize_results_for_client(array $results, string $outputlang = ''): array {
        $sanitized = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $entry = [
                'status' => (string)($result['status'] ?? ''),
                'detail' => $this->sanitize_result_detail($result, $outputlang),
                'resultid' => isset($result['resultid']) ? (int)$result['resultid'] : null,
            ];

            // Only pass task-authored user text through directly when no explicit output language
            // was requested (legacy/internal paths). Otherwise, frontend should use the normalized
            // top-level completion message to preserve language consistency.
            if (
                $outputlang === ''
                && isset($result['usermessage'])
                && is_string($result['usermessage'])
                && trim($result['usermessage']) !== ''
            ) {
                $entry['usermessage'] = trim($result['usermessage']);
            }

            if (isset($result['debugmessage']) && is_string($result['debugmessage']) && trim($result['debugmessage']) !== '') {
                $entry['debugmessage'] = trim($result['debugmessage']);
            }

            if (isset($result['userid'])) {
                $entry['userid'] = (int)$result['userid'];
            }

            if (isset($result['fullname']) && is_string($result['fullname']) && trim($result['fullname']) !== '') {
                $entry['fullname'] = trim($result['fullname']);
            }

            if (isset($result['email']) && is_string($result['email']) && trim($result['email']) !== '') {
                $entry['email'] = trim($result['email']);
            }

            if (isset($result['previewmode']) && is_string($result['previewmode']) && trim($result['previewmode']) !== '') {
                $entry['previewmode'] = trim($result['previewmode']);
            }

            if (isset($result['previewdata']) && is_array($result['previewdata'])) {
                $entry['previewdata'] = $result['previewdata'];
            }

            if (!empty($result['previewoptionids']) && is_array($result['previewoptionids'])) {
                $entry['previewoptionids'] = array_values(array_map('intval', $result['previewoptionids']));
            }

            if (!empty($result['properties']) && is_array($result['properties'])) {
                $entry['properties'] = $result['properties'];
            }

            if (!empty($result['actions']) && is_array($result['actions'])) {
                $entry['actions'] = $result['actions'];
            }

            if (!empty($result['capabilities']) && is_array($result['capabilities'])) {
                $entry['capabilities'] = $result['capabilities'];
            }

            if (
                $outputlang === ''
                && isset($result['summary'])
                && is_string($result['summary'])
                && trim($result['summary']) !== ''
            ) {
                $entry['summary'] = trim($result['summary']);
            }

            $sanitized[] = $entry;
        }

        return $sanitized;
    }

    /**
     * Collapse raw task details into a safe client detail string.
     *
     * @param array<string,mixed> $result
     * @return string
     */
    private function sanitize_result_detail(array $result, string $outputlang = ''): string {
        $isgerman = strpos(strtolower($outputlang), 'de') === 0;

        if (isset($result['diagnosis']) && is_array($result['diagnosis'])) {
            $optionname = trim((string)($result['diagnosis']['optionname'] ?? ''));
            if ($optionname !== '') {
                return $isgerman
                    ? ('Ich habe die Situation fuer die Buchungsoption "' . $optionname . '" analysiert.')
                    : ('I analyzed the situation for booking option "' . $optionname . '".');
            }

            return $isgerman
                ? 'Ich habe die Buchungssituation analysiert.'
                : 'I analyzed the booking situation.';
        }

        $usermessage = trim((string)($result['usermessage'] ?? ''));
        if ($usermessage !== '' && $outputlang === '') {
            return $usermessage;
        }

        if (isset($result['users']) && is_array($result['users'])) {
            $count = count($result['users']);
            if ($count === 0) {
                return $isgerman ? 'Keine passenden Nutzer gefunden.' : 'No matching users found.';
            }
            return $isgerman
                ? ('Es wurden ' . $count . ' passende Nutzer gefunden.')
                : ('Found ' . $count . ' matching user(s).');
        }

        if (isset($result['courses']) && is_array($result['courses'])) {
            $count = count($result['courses']);
            if ($count === 0) {
                return $isgerman ? 'Keine passenden Kurse gefunden.' : 'No matching courses found.';
            }
            return $isgerman
                ? ('Es wurden ' . $count . ' passende Kurse gefunden.')
                : ('Found ' . $count . ' matching course(s).');
        }

        if (isset($result['options']) && is_array($result['options'])) {
            $count = count($result['options']);
            if ($count === 0) {
                return $isgerman ? 'Keine passende Buchungsoption gefunden.' : 'No matching booking options found.';
            }
            return $isgerman
                ? ('Es wurden ' . $count . ' Buchungsoption(en) gefunden.')
                : ('Found ' . $count . ' option(s).');
        }

        if (array_key_exists('fullname', $result) || array_key_exists('email', $result)) {
            return $isgerman ? 'Aktueller Nutzer identifiziert.' : 'Current user identified.';
        }

        if (!empty($result['capabilities']) && is_array($result['capabilities'])) {
            $summary = trim((string)($result['summary'] ?? ''));
            if ($summary !== '') {
                return $summary;
            }
        }

        $detail = trim((string)($result['detail'] ?? ''));
        if ($detail !== '' && $outputlang === '') {
            return $detail;
        }

        if ($detail !== '' && $outputlang !== '') {
            return $isgerman ? 'Die Aktion wurde ausgefuehrt.' : 'The action was executed.';
        }

        return $isgerman ? 'Die Aktion wurde ausgefuehrt.' : 'The action was executed.';
    }

    /**
     * Extract the first explicit task-authored user message.
     *
     * @param array<int,array<string,mixed>> $results
     * @return string
     */
    private function extract_task_user_message(array $results): string {
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $usermessage = trim((string)($result['usermessage'] ?? ''));
            if ($usermessage !== '') {
                return $usermessage;
            }

            $summary = trim((string)($result['summary'] ?? ''));
            if ($summary !== '') {
                return $summary;
            }
        }

        return '';
    }

    /**
     * Deterministic fallback when no post-execution LLM feedback can be generated.
     *
     * @param array<int,array<string,mixed>> $results
     * @param string $outputlang
     * @return string
     */
    private function fallback_message_for_results(array $results, string $outputlang): string {
        $isgerman = strpos(strtolower($outputlang), 'de') === 0;
        if (empty($results)) {
            return $isgerman ? 'Die Ausführung ist abgeschlossen.' : 'The action is complete.';
        }

        $first = $results[0] ?? [];
        if (!is_array($first)) {
            return $isgerman ? 'Die Ausführung ist abgeschlossen.' : 'The action is complete.';
        }

        if (isset($first['users']) && is_array($first['users'])) {
            $count = count($first['users']);
            if ($count === 0) {
                return $isgerman ? 'Ich habe keine passenden Nutzer gefunden.' : 'I could not find any matching users.';
            }
            return $isgerman
                ? 'Ich habe ' . $count . ' passende Nutzer gefunden.'
                : 'I found ' . $count . ' matching users.';
        }

        if (isset($first['courses']) && is_array($first['courses'])) {
            $count = count($first['courses']);
            if ($count === 0) {
                return $isgerman ? 'Ich habe keine passenden Kurse gefunden.' : 'I could not find any matching courses.';
            }
            return $isgerman
                ? 'Ich habe ' . $count . ' passende Kurse gefunden.'
                : 'I found ' . $count . ' matching courses.';
        }

        if (isset($first['options']) && is_array($first['options'])) {
            $count = count($first['options']);
            if ($count === 0) {
                $nomatches = $isgerman
                    ? 'Ich habe keine passende Buchungsoption gefunden.'
                    : 'I could not find a matching booking option.';
                return $nomatches;
            }
            $foundmessage = $isgerman
                ? 'Ich habe ' . $count . ' passende Buchungsoption(en) gefunden.'
                : 'I found ' . $count . ' matching booking option(s).';
            return $foundmessage;
        }

        if (
            array_key_exists('fullname', $first)
            || array_key_exists('email', $first)
        ) {
            return $isgerman ? 'Ich habe dein Benutzerkonto gefunden.' : 'I identified your user account.';
        }

        $detail = trim((string)($first['detail'] ?? ''));
        if ($detail !== '') {
            return $detail;
        }

        $defaultmessage = $isgerman ? 'Die Ausführung ist abgeschlossen.' : 'The action is complete.';
        return $defaultmessage;
    }
}

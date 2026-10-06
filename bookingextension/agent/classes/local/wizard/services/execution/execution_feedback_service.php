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
 * Build user-facing execution feedback after skill execution.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\execution;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\result_payload_summarizer;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\services\localized_string_service;

/**
 * Generates post-execution feedback and client-safe run results.
 */
class execution_feedback_service {
    /** @var conversation_store */
    private conversation_store $store;

    /** @var skill_registry */
    private skill_registry $registry;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     * @param skill_registry|null $registry
     */
    public function __construct(conversation_store $store, ?skill_registry $registry = null) {
        $this->store = $store;
        $this->registry = $registry ?? skill_registry_factory::get_default();
    }

    /**
     * Build the final assistant message and client-safe result payload.
     *
     * Message generation is now deterministic — the previous secondary LLM call
     * has been removed to comply with the "one agent-controlled LLM loop" rule.
     *
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param array $commands
     * @param array $results
     * @param string $outputlang
     * @return array
     */
    public function build_completion_feedback(
        int $threadid,
        int $cmid,
        int $userid,
        array $commands,
        array $results,
        string $outputlang = ''
    ): array {
        $message = $this->fallback_message_for_results($results, $outputlang);
        $message = $this->append_link_to_message(
            $message,
            $this->extract_primary_link_from_results($results),
            $outputlang
        );

        return [
            'message' => $message,
            'results' => $this->sanitize_results_for_client($results, $outputlang),
        ];
    }

    /**
     * Sanitize execution results to return to the client.
     *
     * @param array  $results    Raw execution results.
     * @param string $outputlang Target language for message localizations.
     * @return array Sanitized results.
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

            foreach (['link', 'url', 'editlink', 'viewlink', 'editurl', 'viewurl'] as $linkkey) {
                if (!isset($result[$linkkey]) || !is_string($result[$linkkey])) {
                    continue;
                }
                $linkvalue = trim((string)$result[$linkkey]);
                if ($linkvalue !== '') {
                    $entry[$linkkey] = $linkvalue;
                }
            }

            if (isset($result['skill']) && is_string($result['skill']) && trim($result['skill']) !== '') {
                $entry['skill'] = trim($result['skill']);
            }

            // Issue codes are engine vocabulary, not skill internals, and the turn's own classification depends
            // on them: without RECOVERABLE_INPUT_ERROR reaching this far, agent_runtime cannot tell a target the
            // user mistyped from a provider outage and stamps both as a failed run (run 17/18: UTP-3, TDP-4,
            // DMD-1/3). They never reach the user — the synchronizer writes the text.
            $issuecodes = array_values(array_filter(array_map('strval', (array)($result['issue_codes'] ?? []))));
            if (!empty($issuecodes)) {
                $entry['issue_codes'] = $issuecodes;
            }

            // Keep executor-provided input payload for planner runtime memory.
            // This is consumed by orchestrator SYSTEM_RUNTIME.completed_commands.
            if (isset($result['executed_input']) && is_array($result['executed_input'])) {
                $entry['executed_input'] = $result['executed_input'];
            } else if (isset($result['input']) && is_array($result['input'])) {
                $entry['executed_input'] = $result['input'];
            }

            // Only pass skill-authored user text through directly when no explicit output language
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

            if (
                isset($result['next_step_intent'])
                && is_string($result['next_step_intent'])
                && trim($result['next_step_intent']) !== ''
            ) {
                $entry['next_step_intent'] = trim($result['next_step_intent']);
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

            // Self-contained preview block ({type, html, js_module, payload}) precomputed by the
            // executor. This is the ONE preview-related field that must survive sanitization; the
            // bespoke per-skill fields it was rendered from (doc_path, etc.) deliberately do not.
            if (isset($result['preview']) && is_array($result['preview'])) {
                $entry['preview'] = $result['preview'];
            }

            if (!empty($result['previewoptionids']) && is_array($result['previewoptionids'])) {
                $entry['previewoptionids'] = array_values(array_map('intval', $result['previewoptionids']));
            }

            if (!empty($result['options']) && is_array($result['options'])) {
                $entry['options'] = $result['options'];
            }

            if (!empty($result['optiondetails']) && is_array($result['optiondetails'])) {
                $entry['optiondetails'] = $result['optiondetails'];
            }

            if (!empty($result['detail_capabilities']) && is_array($result['detail_capabilities'])) {
                $entry['detail_capabilities'] = $result['detail_capabilities'];
            }

            if (!empty($result['users']) && is_array($result['users'])) {
                $entry['users'] = $result['users'];
            }

            if (!empty($result['courses']) && is_array($result['courses'])) {
                $entry['courses'] = $result['courses'];
            }

            if (!empty($result['diagnosis']) && is_array($result['diagnosis'])) {
                $entry['diagnosis'] = $result['diagnosis'];
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

            if (!empty($result['docs']) && is_array($result['docs'])) {
                $entry['docs'] = $result['docs'];
            }

            if (!empty($result['suggestions']) && is_array($result['suggestions'])) {
                $entry['suggestions'] = $result['suggestions'];
            }

            if (
                isset($result['followupmessage'])
                && is_string($result['followupmessage'])
                && trim($result['followupmessage']) !== ''
            ) {
                $entry['followupmessage'] = trim($result['followupmessage']);
            }

            if (
                $outputlang === ''
                && isset($result['summary'])
                && is_string($result['summary'])
                && trim($result['summary']) !== ''
            ) {
                $entry['summary'] = trim($result['summary']);
            }

            // Pass through verbatim observation content so the LLM loop receives
            // the full list without truncation from compact_text.
            if (
                isset($result['observation_full'])
                && is_string($result['observation_full'])
                && trim($result['observation_full']) !== ''
            ) {
                $entry['observation_full'] = trim($result['observation_full']);
            }

            // The skill's declaration that its observation is shipped, non-personal text (e.g. the documentation
            // excerpt of explain_docs). Dropping it here made every masking layer mask it anyway: L43 ED-1 thread
            // 13343 read "ANON_USER_2_firstname. number of participants" for "Max. number of participants".
            if (!empty($result['observation_engine_static'])) {
                $entry['observation_engine_static'] = true;
            }

            $sanitized[] = $entry;
        }

        return $sanitized;
    }

    /**
     * Collapse raw skill details into a safe client detail string.
     *
     * @param array $result
     * @param string $outputlang
     * @return string
     */
    private function sanitize_result_detail(array $result, string $outputlang = ''): string {
        // Diagnosis result: use localized string with option name when available.
        $category = result_payload_summarizer::detect_result_category($result);

        // Docs result: pass skill-authored usermessage through regardless of outputlang,
        // because the content is doc text that must always reach the caller unchanged.
        if ($category === 'docs') {
            $usermessage = trim((string)($result['usermessage'] ?? ''));
            if ($usermessage !== '') {
                return $usermessage;
            }
            $detail = trim((string)($result['detail'] ?? ''));
            return $detail !== '' ? $detail : $this->localized('ai_result_detail_action_executed', null, $outputlang);
        }

        if ($category === 'diagnosis') {
            $optionname = trim((string)($result['diagnosis']['optionname'] ?? ''));
            if ($optionname !== '') {
                return $this->localized('ai_result_detail_diagnosis_with_option', $optionname, $outputlang);
            }
            return $this->localized('ai_result_detail_diagnosis_generic', null, $outputlang);
        }

        // Pass through skill-authored user message when no output-language override is active.
        $usermessage = trim((string)($result['usermessage'] ?? ''));
        if ($usermessage !== '' && $outputlang === '') {
            return $usermessage;
        }

        if ($category === 'users') {
            return $this->localized_list_count_message(
                $result,
                'users',
                'ai_result_detail_users_none',
                'ai_result_detail_users_found',
                $outputlang
            );
        }

        if ($category === 'courses') {
            return $this->localized_list_count_message(
                $result,
                'courses',
                'ai_result_detail_courses_none',
                'ai_result_detail_courses_found',
                $outputlang
            );
        }

        if ($category === 'options') {
            return $this->localized_list_count_message(
                $result,
                'options',
                'ai_result_detail_options_none',
                'ai_result_detail_options_found',
                $outputlang
            );
        }

        if ($category === 'option_details') {
            return result_payload_summarizer::describe_entry($result, 0, 'client_fallback');
        }

        if ($category === 'current_user') {
            return $this->localized('ai_result_detail_current_user', null, $outputlang);
        }

        if ($category === 'capabilities' || $category === 'properties') {
            $summary = trim((string)($result['summary'] ?? ''));
            if ($summary !== '' && $outputlang === '') {
                return $summary;
            }
        }

        $detail = trim((string)($result['detail'] ?? ''));
        if ($detail === '') {
            $detail = $this->localized('ai_result_detail_action_executed', null, $outputlang);
        }

        return $this->append_link_to_message($detail, $this->extract_primary_link_from_result($result), $outputlang);
    }

    /**
     * Deterministic fallback when generating a user-facing result summary.
     *
     * @param array $results
     * @param string $outputlang
     * @return string
     */
    private function fallback_message_for_results(array $results, string $outputlang): string {
        if (empty($results)) {
            return $this->localized('ai_result_feedback_complete', null, $outputlang);
        }

        $first = $results[0] ?? [];
        if (!is_array($first)) {
            return $this->localized('ai_result_feedback_complete', null, $outputlang);
        }

        $category = result_payload_summarizer::detect_result_category($first);

        if ($category === 'users') {
            return $this->localized_list_count_message(
                $first,
                'users',
                'ai_result_feedback_users_none',
                'ai_result_feedback_users_found',
                $outputlang
            );
        }

        if ($category === 'courses') {
            return $this->localized_list_count_message(
                $first,
                'courses',
                'ai_result_feedback_courses_none',
                'ai_result_feedback_courses_found',
                $outputlang
            );
        }

        if ($category === 'options') {
            return $this->localized_list_count_message(
                $first,
                'options',
                'ai_result_feedback_options_none',
                'ai_result_feedback_options_found',
                $outputlang
            );
        }

        if ($category === 'current_user') {
            return $this->localized('ai_result_feedback_current_user', null, $outputlang);
        }

        $detail = trim((string)($first['detail'] ?? ''));
        if ($detail === '') {
            $detail = $this->localized('ai_result_feedback_complete', null, $outputlang);
        }

        return $this->append_link_to_message($detail, $this->extract_primary_link_from_result($first), $outputlang);
    }

    /**
     * Extract a primary link value from a skill result entry.
     *
     * @param array $result
     * @return string
     */
    private function extract_primary_link_from_result(array $result): string {
        foreach (['link', 'url', 'editlink', 'viewlink', 'editurl', 'viewurl'] as $key) {
            if (!isset($result[$key]) || !is_string($result[$key])) {
                continue;
            }
            $candidate = trim((string)$result[$key]);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Extract the first available link from a list of result entries.
     *
     * @param array $results
     * @return string
     */
    private function extract_primary_link_from_results(array $results): string {
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $link = $this->extract_primary_link_from_result($result);
            if ($link !== '') {
                return $link;
            }
        }

        return '';
    }

    /**
     * Resolve a localized plugin string.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $outputlang
     * @return string
     */
    private function localized(string $identifier, $a = null, string $outputlang = ''): string {
        return localized_string_service::get($identifier, 'bookingextension_agent', $a, $outputlang);
    }

    /**
     * Localize a none/found message pair based on list count.
     *
     * @param array $result
     * @param string $listkey
     * @param string $nonekey
     * @param string $foundkey
     * @param string $outputlang
     * @return string
     */
    private function localized_list_count_message(
        array $result,
        string $listkey,
        string $nonekey,
        string $foundkey,
        string $outputlang
    ): string {
        $items = $result[$listkey] ?? [];
        if (!is_array($items) || count($items) === 0) {
            return $this->localized($nonekey, null, $outputlang);
        }

        return $this->localized($foundkey, count($items), $outputlang);
    }

    /**
     * Append a link to a plain-text message once, localized and deterministic.
     *
     * @param string $message
     * @param string $link
     * @param string $outputlang
     * @return string
     */
    private function append_link_to_message(string $message, string $link, string $outputlang): string {
        $message = trim($message);
        $link = trim($link);
        if ($link === '') {
            return $message;
        }

        if ($message !== '' && str_contains($message, $link)) {
            return $message;
        }

        $prefix = 'Link: ';
        if ($message === '') {
            return $prefix . $link;
        }

        return $message . ' ' . $prefix . $link;
    }
}

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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\agent_state;

/**
 * Builds synchronizer input from runtime result and loop state.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class synchronizer_input_builder {
    /**
     * Masked copy of the result fields this builder turns into synchronizer observations.
     *
     * The synchronizer is an LLM: no masked value may reach it in clear text (HARD RULE
     * 2026-09-11). Engine-built texts — clarifications, errors, failed-row user messages —
     * can carry de-anonymized values, so message, errors, phase trace and result rows pass
     * through the anonymizer, the same treatment step observations get at construction.
     * Rows flagged observation_engine_static stay untouched (instructional engine text,
     * threads 286/288), and loop_results are skipped because their observations were masked
     * when the step ran. The caller keeps the unmasked result for display.
     *
     * @param array $result
     * @param privacy_anonymizer $anonymizer
     * @param int $threadid
     * @return array
     */
    public function mask_for_llm(array $result, privacy_anonymizer $anonymizer, int $threadid): array {
        if ($threadid <= 0) {
            return $result;
        }
        foreach (['message', 'errors', 'phase_trace'] as $key) {
            if ($key === 'message' && model_authored_text::is_model_message($result)) {
                // Model text travels one way only: never masked again (George 2026-09-26, N36 thread 13358).
                continue;
            }
            if ($key === 'phase_trace' && is_array($result[$key] ?? null)) {
                // Each phase snapshot keeps a message the model wrote; its other fields are masked as before.
                foreach ($result[$key] as $phase => $snapshot) {
                    if (!is_array($snapshot) || !model_authored_text::is_model_message($snapshot)) {
                        $result[$key][$phase] = $anonymizer->anonymize_value_for_llm($threadid, $snapshot);
                        continue;
                    }
                    $message = (string)$snapshot['message'];
                    $masked = (array)$anonymizer->anonymize_value_for_llm($threadid, $snapshot);
                    $masked['message'] = $message;
                    $masked[model_authored_text::KEY] = $message;
                    $result[$key][$phase] = $masked;
                }
                continue;
            }
            if (array_key_exists($key, $result)) {
                $result[$key] = $anonymizer->anonymize_value_for_llm($threadid, $result[$key]);
            }
        }
        if (is_array($result['results'] ?? null)) {
            foreach ($result['results'] as $index => $entry) {
                if (!is_array($entry) || !empty($entry['observation_engine_static'])) {
                    continue;
                }
                $result['results'][$index] = $anonymizer->anonymize_value_for_llm($threadid, $entry);
            }
        }
        return $result;
    }

    /**
     * Build the observation list for synchronizer finalization.
     *
     * @param array $result
     * @param agent_state|null $state
     * @return array
     */
    public function build_observations(array $result, ?agent_state $state = null): array {
        $observations = [];

        if ($state !== null && $state->has_observations()) {
            $observations = $state->get_observations();
        } else {
            $loopresults = (array)($result['loop_results'] ?? []);
            foreach ($loopresults as $step) {
                if (!is_array($step)) {
                    continue;
                }

                $observation = trim((string)($step['observation'] ?? ''));
                if ($observation !== '') {
                    $observations[] = $observation;
                }
            }
        }

        $sourceobservation = $this->build_source_observation($result);
        if ($sourceobservation !== '') {
            $observations[] = $sourceobservation;
        }

        $phasetraceobservation = $this->build_phase_trace_observation($result);
        if ($phasetraceobservation !== '') {
            $observations[] = $phasetraceobservation;
        }

        $executionfeedbackobservation = $this->build_execution_feedback_observation($result);
        if ($executionfeedbackobservation !== '') {
            $observations[] = $executionfeedbackobservation;
        }

        $errorobservation = $this->build_error_observation($result);
        if ($errorobservation !== '') {
            $observations[] = $errorobservation;
        }

        return $observations;
    }

    /**
     * Collect the detail fields read skills reported as NOT looked up this turn.
     *
     * Generic result contract, no skill knowledge: any executed row may carry
     * detail_capabilities.omitted_fields (supported fields the call deliberately skipped).
     * The union across all rows feeds the synchronizer's OMITTED_FIELDS_POLICY, so the
     * condition is engine state — structured data, never a text match on observations.
     *
     * @param array $result
     * @return string[]
     */
    public function collect_omitted_fields(array $result): array {
        $rows = [];
        foreach ((array)($result['loop_results'] ?? []) as $step) {
            foreach ((array)($step['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }
        foreach ((array)($result['results'] ?? []) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        $omitted = [];
        foreach ($rows as $row) {
            $fields = (array)(($row['detail_capabilities']['omitted_fields'] ?? null) ?: []);
            foreach ($fields as $field) {
                $name = trim((string)$field);
                if ($name !== '') {
                    $omitted[$name] = true;
                }
            }
        }

        return array_keys($omitted);
    }

    /**
     * Build the structured error observation for error presentation.
     *
     * The synchronizer presents errors like any other answer (user language,
     * conversational, sensible next step) — provided it knows the actual cause.
     * This block carries the classified cause (error_class, issue codes, raw
     * errors, failed result details) plus a non-negotiable presentation
     * instruction: never blame the AI provider for non-provider causes, never
     * invent causes, never claim success.
     *
     * @param array $result
     * @return string empty when the result is not an error
     */
    private function build_error_observation(array $result): string {
        if (trim((string)($result['response_type'] ?? '')) !== 'error') {
            return '';
        }

        $issuecodes = array_values(array_filter(array_map('strval', (array)($result['issue_codes'] ?? []))));
        $errorclass = trim((string)($result['error_class'] ?? ''));

        // F3 user_cause channel: causes are the only cause text the synchronizer may explain
        // to the user, so planner vocabulary stays out — the "Command #N:" label is planner
        // orientation and is stripped here (it lives on in the phase trace and retry
        // observations), and failed execute rows prefer their usermessage over the internal
        // detail. repair_hints never enter this block by construction.
        $causes = [];
        foreach ((array)($result['errors'] ?? []) as $error) {
            $error = trim((string)preg_replace('/^\s*Command\s*#\d+\s*:\s*/i', '', (string)$error));
            if ($error !== '') {
                $causes[] = $error;
            }
        }
        foreach ((array)($result['results'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $status = trim((string)($entry['status'] ?? ''));
            $usercause = trim((string)($entry['usermessage'] ?? ''));
            if ($usercause === '') {
                $usercause = trim((string)($entry['detail'] ?? ''));
            }
            if (in_array($status, ['error', 'failed'], true) && $usercause !== '') {
                $causes[] = $usercause;
            }
        }

        // A task blocked because it needs a PRO license or an active Wunderbyte subscription is NOT a
        // malfunction. State the neutral fact only — how to present the upgrade (link, CTA wording) is
        // owned by the synchronizer's conditional PRO_LICENSE_POLICY, which fires only without full access.
        if (!empty($issuecodes) && in_array('REQUIRES_PRO', $issuecodes, true)) {
            return '[UPGRADE_REQUIRED] This task is not available because it requires the Wunderbyte PRO '
                . 'license or an active Wunderbyte subscription. This is NOT an error, bug or malfunction.';
        }

        // A pure governance availability denial is NOT a malfunction. It still travels as
        // response_type=error (so the finalization_classifier humanizes it via the synchronizer,
        // per the flowchart's "safe domain error" path), but the observation must frame it as a
        // neutral availability notice — otherwise the reply calls it an internal error.
        // The reason line below IS the deny-reason-specific user-facing message
        // (skill_contract_validator::get_user_facing_deny_message), so the model must only relay
        // it — issue #2223 produced three different explanations (including a wrong "contact an
        // administrator" for an admin user) because the old framing offered a menu of possible
        // causes instead of binding the reply to the one actual reason.
        if (!empty($issuecodes) && array_values(array_unique($issuecodes)) === ['SKILL_DENIED']) {
            $lines = [];
            $lines[] = '[UNAVAILABLE] The requested capability is not available to this user in this '
                . 'session. This is NOT an error, bug or malfunction.';
            if (!empty($causes)) {
                $lines[] = 'reason: ' . implode(' | ', array_unique($causes));
            }
            $lines[] = 'Rules: Convey ONLY the reason above, translated into the user\'s language — it is '
                . 'the complete, authoritative explanation. Do NOT add advice, workarounds, alternative '
                . 'causes or contact suggestions (e.g. "ask an administrator") that the reason itself does '
                . 'not contain. Do NOT call it an internal error, do NOT apologize for a malfunction, do '
                . 'NOT suggest reloading or waiting. If no reason line is present, state only that this '
                . 'capability is currently not available. Keep it short and factual.';
            return implode("\n", $lines);
        }

        $lines = [];
        $lines[] = '[ERROR] The request FAILED. Compose an honest error reply in the user\'s language:';
        if ($errorclass !== '') {
            $lines[] = 'error_class: ' . $errorclass;
        }
        // F61 (Lauf 8, thread 1463): raw issue codes are engine state, not user material — the
        // synchronizer quoted them. The engine already decided on them (framing above, error_class).
        if (!empty($causes)) {
            $lines[] = 'causes - each entry is ONE independent cause; never merge or reattribute them:';
            $index = 1;
            foreach (array_unique($causes) as $cause) {
                $lines[] = 'cause ' . $index++ . ': ' . $cause;
            }
        }

        $lines[] = 'Rules: explain the cause above and a sensible next step. Do NOT blame the AI provider '
            . 'unless error_class names it. Do NOT invent other causes. Do NOT claim the request succeeded. '
            . 'Do NOT announce that you will now perform, retry or continue the action — nothing more runs '
            . 'after this reply. Speak only about what already happened and what the USER can do next.';

        return implode("\n", $lines);
    }

    /**
     * Build a normalized phase trace observation for synchronization context.
     *
     * @param array $result
     * @return string
     */
    private function build_phase_trace_observation(array $result): string {
        $phasetrace = (array)($result['phase_trace'] ?? []);
        if (empty($phasetrace)) {
            return '';
        }

        $payload = [
            'discovery' => $this->sanitize_phase_trace_snapshot((array)($phasetrace['discovery'] ?? [])),
            'selection' => $this->sanitize_phase_trace_snapshot((array)($phasetrace['selection'] ?? [])),
            'parameter_construction' => $this->sanitize_phase_trace_snapshot(
                (array)($phasetrace['parameter_construction'] ?? [])
            ),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || trim($json) === '') {
            return '';
        }

        return 'PHASE_TRACE' . "\n" . $json;
    }

    /**
     * Keep only minimal phase telemetry and exclude skill-discovery payloads.
     *
     * @param array $snapshot
     * @return array
     */
    private function sanitize_phase_trace_snapshot(array $snapshot): array {
        return [
            'phase' => trim((string)($snapshot['phase'] ?? '')),
            'response_type' => trim((string)($snapshot['response_type'] ?? '')),
            'issue_codes' => issue_code_normalizer::normalize((array)($snapshot['issue_codes'] ?? [])),
            'errors' => $this->normalize_nonempty_string_list((array)($snapshot['errors'] ?? [])),
        ];
    }

    /**
     * Build compact execution feedback observation for synchronizer prompts.
     *
     * @param array $result
     * @return string
     */
    private function build_execution_feedback_observation(array $result): string {
        $results = (array)($result['results'] ?? []);
        if (empty($results)) {
            return '';
        }

        $statuscounts = [];
        $skills = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $status = trim((string)($row['status'] ?? 'unknown'));
            if ($status === '') {
                $status = 'unknown';
            }
            $statuscounts[$status] = (int)($statuscounts[$status] ?? 0) + 1;

            $skill = trim((string)($row['skill'] ?? ''));
            if ($skill !== '') {
                $skills[] = $skill;
            }
        }

        if (empty($statuscounts) && empty($skills)) {
            return '';
        }

        $payload = [
            'result_count' => count($results),
            'status_counts' => $statuscounts,
            'skills' => array_values(array_unique($skills)),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || trim($json) === '') {
            return '';
        }

        return 'EXECUTION_FEEDBACK' . "\n" . $json;
    }

    /**
     * Whether a skill result with status executed exists in this turn (results[] or any loop step).
     *
     * @param array $result
     * @return bool
     */
    private function turn_has_executed_result(array $result): bool {
        $rows = (array)($result['results'] ?? []);
        foreach ((array)($result['loop_results'] ?? []) as $step) {
            if (is_array($step)) {
                $rows = array_merge($rows, (array)($step['results'] ?? []));
            }
        }
        foreach ($rows as $row) {
            if (is_array($row) && trim((string)($row['status'] ?? '')) === 'executed') {
                return true;
            }
        }
        return false;
    }

    /**
     * Build a compact source result observation for finalization.
     *
     * @param array $result
     * @return string
     */
    private function build_source_observation(array $result): string {
        $message = trim((string)($result['message'] ?? ''));
        if ($message === '') {
            return '';
        }

        $responsetype = trim((string)($result['response_type'] ?? ''));
        $attemptedskills = $this->normalize_nonempty_string_list((array)($result['attempted_skills'] ?? []));

        // Wave 32 (frozen prompt spec, code prerequisite 2): a 'sufficient' message is the planner's own text, never a
        // skill result. Relayed as FINAL_SOURCE_RESULT it read as a fact - REM-2: the selector answered "understood, I
        // will keep this in mind" without running remember, and the reply claimed the preference was stored.
        // Questions and confirmation requests stay FINAL_SOURCE_RESULT: they are the pending question to relay.
        if ($responsetype === 'sufficient') {
            // Thread 23502 (2026-09-30): with a skill result in the turn the planner's text is no observation at all.
            // gpt-oss copied "Hier ist die Uebersicht der verfuegbaren Skills." word for word and never rendered the
            // result standing next to it (A/B on the exact live prompt: 0/3 with the text, 3/3 without; three threads).
            // The facts are the executed results; the planner text is kept only when nothing ran (REM-2).
            if ($this->turn_has_executed_result($result)) {
                return '';
            }
            $normalizedplanner = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
            return "PLANNER_TEXT (not a result)\nmessage=" . substr($normalizedplanner, 0, 600);
        }

        $lines = ['FINAL_SOURCE_RESULT'];
        if ($responsetype !== '') {
            $lines[] = 'response_type=' . $responsetype;
        }
        if (!empty($attemptedskills)) {
            $lines[] = 'attempted_skills=' . implode(',', array_slice($attemptedskills, 0, 8));
        }

        $normalizedmessage = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        $lines[] = 'message=' . substr($normalizedmessage, 0, 600);

        return implode("\n", $lines);
    }

    /**
     * Normalize a list to non-empty strings.
     *
     * @param array $values
     * @return array
     */
    private function normalize_nonempty_string_list(array $values): array {
        $normalized = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                $normalized[] = $text;
            }
        }

        return array_values(array_unique($normalized));
    }
}

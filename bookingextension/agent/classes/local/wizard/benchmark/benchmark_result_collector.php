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

namespace bookingextension_agent\local\wizard\benchmark;

use bookingextension_agent\local\wizard\services\shared_json_payload_extractor;

/**
 * Evaluates a raw LLM selector response against a scenario's expected outcome.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_result_collector {
    /**
     * Evaluate one scenario against its actual LLM response.
     *
     * @param benchmark_scenario_interface $scenario
     * @param string  $rawresponse  Raw JSON string from LLM.
     * @param int     $durationms   Wall-clock time for this call.
     * @param int     $tokensprompt
     * @param int     $tokenscompletion
     * @return array  Scenario result record (matches benchmark_scenarios table).
     */
    public function evaluate(
        benchmark_scenario_interface $scenario,
        string $rawresponse,
        int $durationms = 0,
        int $tokensprompt = 0,
        int $tokenscompletion = 0
    ): array {
        $parsed = $this->decode_response_tolerantly($rawresponse);
        $jsonvalid = is_array($parsed);

        $responsetype = '';
        $skillselected = '';
        $plannedstetspresent = 0;
        $contractcompliant   = 0;
        $errors = [];

        if ($jsonvalid) {
            $responsetype = trim((string)($parsed['response_type'] ?? ''));
            $skillselected = trim((string)($parsed['commands'][0]['skill'] ?? ''));
            $plannedstetspresent = isset($parsed['planned_steps']) && is_array($parsed['planned_steps']) ? 1 : 0;
            $contractcompliant = $this->check_contract_compliance($parsed, $errors);
        } else {
            $errors[] = 'JSON parse failed';
        }

        $expectedrt  = $scenario->get_expected_response_type();
        $expectedskill = $scenario->get_expected_skill();

        // Unambiguous-but-multiple acceptance: a scenario may declare a SET of valid response_types
        // (e.g. catalog-gap -> error OR a search_skills skill_call). Empty set falls back to the single
        // expected response_type.
        $acceptablerts = method_exists($scenario, 'get_acceptable_response_types')
            ? array_values(array_filter(array_map('strval', (array)$scenario->get_acceptable_response_types())))
            : [];
        if (empty($acceptablerts) && $expectedrt !== '') {
            $acceptablerts = [$expectedrt];
        }
        $rtmatch   = empty($acceptablerts) || in_array($responsetype, $acceptablerts, true);
        $skillmatch = $expectedskill === '' || $skillselected === $expectedskill;
        $planmatch = !$scenario->expects_planned_steps()
            || ($plannedstetspresent && !empty($parsed['planned_steps']));

        $additional = $scenario->assert_additional($parsed ?? []);
        $addpassed  = empty(array_filter($additional, fn($a) => !$a['passed']));

        // Routing scenarios name confusable sibling skills the selector must NOT pick; track whether the
        // model chose one (a finer routing-quality signal than skill_hit alone). Report-only.
        $forbiddensiblings = method_exists($scenario, 'get_forbidden_siblings')
            ? array_values(array_filter(array_map('strval', (array)$scenario->get_forbidden_siblings())))
            : [];
        $forbiddensiblinghit = ($skillselected !== '' && in_array($skillselected, $forbiddensiblings, true)) ? 1 : 0;

        $passed = $jsonvalid && $contractcompliant && $rtmatch && $skillmatch && $planmatch && $addpassed;

        $errormessage = null;
        if (!$passed) {
            $parts = [];
            if (!$jsonvalid) {
                $parts[] = 'json_invalid';
            }
            if (!$contractcompliant) {
                $parts[] = 'contract: ' . implode('; ', $errors);
            }
            if (!$rtmatch) {
                $parts[] = 'rt: expected={' . implode('|', $acceptablerts) . "} actual={$responsetype}";
            }
            if (!$skillmatch) {
                $parts[] = "skill: expected={$expectedskill} actual={$skillselected}";
            }
            if (!$planmatch) {
                $parts[] = 'planned_steps_missing';
            }
            if (!$addpassed) {
                foreach ($additional as $a) {
                    if (!$a['passed']) {
                        $parts[] = $a['label'] . ': ' . ($a['detail'] ?? '');
                    }
                }
            }
            $errormessage = implode(' | ', $parts);
        }

        return [
            'scenario_key'           => $scenario->get_key(),
            'scenario_class'         => $scenario->get_class(),
            'passed'                 => $passed ? 1 : 0,
            'response_type_expected' => $expectedrt,
            'response_type_actual'   => $responsetype,
            'skill_expected'          => $expectedskill,
            'skill_selected'          => $skillselected,
            'forbidden_siblings_present' => empty($forbiddensiblings) ? 0 : 1,
            'forbidden_sibling_hit'      => $forbiddensiblinghit,
            'json_valid'             => $jsonvalid ? 1 : 0,
            'contract_compliant'     => $contractcompliant ? 1 : 0,
            'planned_steps_present'  => $plannedstetspresent,
            'tokens_prompt'          => $tokensprompt,
            'tokens_completion'      => $tokenscompletion,
            'duration_ms'            => $durationms,
            'step_count'             => 1,
            'error_message'          => $errormessage,
            'result_json'            => $jsonvalid ? json_encode($parsed) : $rawresponse,
        ];
    }

    /**
     * Decode the model response the same way the live agent does.
     *
     * The interpreter ({@see \bookingextension_agent\local\wizard\interpreter}) strips markdown
     * ```json fences and surrounding prose before decoding, so a fenced-but-valid response works
     * fine in production. The benchmark must mirror that tolerance, otherwise it fails a response on
     * its wire format instead of judging its routing decision. Prefer the first candidate that
     * decodes to an object carrying a response_type (the selector payload), then any decodable object.
     *
     * @param string $rawresponse raw model output (plain JSON, fenced, or wrapped in prose)
     * @return array|null decoded object, or null when nothing decodes
     */
    private function decode_response_tolerantly(string $rawresponse): ?array {
        $fallback = null;
        foreach (shared_json_payload_extractor::extract_json_candidates($rawresponse) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (array_key_exists('response_type', $decoded)) {
                return $decoded;
            }
            $fallback ??= $decoded;
        }
        return $fallback;
    }

    /**
     * Basic selector contract compliance check.
     *
     * @param array  $parsed
     * @param array  $errors
     * @return bool
     */
    private function check_contract_compliance(array $parsed, array &$errors): bool {
        $ok = true;
        // The lang / user_lang fields are optional at the selection phase: the live interpreter reads
        // them with a null-coalescing default and never rejects a response for their absence (the
        // construction phase and synchronizer settle language), so the benchmark must not require them.
        $required = ['response_type', 'next_step_intent', 'planned_steps'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $parsed)) {
                $errors[] = "missing field: {$field}";
                $ok = false;
            }
        }
        if (!is_array($parsed['planned_steps'] ?? null)) {
            $errors[] = 'planned_steps not array';
            $ok = false;
        }
        $rt = $parsed['response_type'] ?? '';
        $validtypes = ['skill_call', 'clarification', 'confirm_pending', 'sufficient', 'error'];
        if (!in_array($rt, $validtypes, true)) {
            $errors[] = "invalid response_type: {$rt}";
            $ok = false;
        }
        if ($rt === 'skill_call') {
            if (empty($parsed['commands']) || !is_array($parsed['commands'])) {
                $errors[] = 'skill_call requires non-empty commands';
                $ok = false;
            } else if (count($parsed['commands']) !== 1) {
                $errors[] = 'selector must emit exactly one command';
                $ok = false;
            }
        }
        if (in_array($rt, ['clarification', 'sufficient', 'error'], true)) {
            if (!empty($parsed['commands'])) {
                $errors[] = "{$rt} must have empty commands";
                $ok = false;
            }
        }
        return $ok;
    }
}

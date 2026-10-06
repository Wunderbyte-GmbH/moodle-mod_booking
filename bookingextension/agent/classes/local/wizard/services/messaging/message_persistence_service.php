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
 * Message persistence service for assistant messages.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\messaging;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\model_authored_text;
use bookingextension_agent\local\wizard\services\phase_trace_normalizer;

/**
 * Persists normalized assistant payloads to conversation storage.
 */
class message_persistence_service {
    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Persist one assistant message payload.
     *
     * @param int $threadid
     * @param array $result
     * @return void
     */
    public function persist_assistant_message(int $threadid, array $result): void {
        $structured = [
            'response_type'            => $result['response_type'],
            'runid'                    => $result['runid'] ?? 0,
            'commands'                 => $result['commands'] ?? [],
            'ambiguities'              => $result['ambiguities'] ?? [],
            'ambiguity_options'        => $result['ambiguity_options'] ?? [],
            'errors'                   => $result['errors'] ?? [],
            'attempted_skills'          => $result['attempted_skills'] ?? [],
            'issue_codes'              => $result['issue_codes'] ?? [],
            'pending_confirmation_code' => $result['pending_confirmation_code'] ?? '',
            'results'                  => $result['results'] ?? [],
            'loop_results'             => $result['loop_results'] ?? [],
            'loop_step'                => $result['loop_step'] ?? 0,
            'loop_max_steps'           => $result['loop_max_steps'] ?? 0,
            'lang'                     => $result['lang'] ?? '',
            // Gate telemetry — consistency_gate_fail_rate, postcondition_fail_rate_by_skill.
            'sync_gate_status'         => $result['sync_gate_status'] ?? '',
            'sync_gate_reason'         => $result['sync_gate_reason'] ?? '',
            'postcondition_status'     => $result['postcondition_status'] ?? '',
            'failed_postconditions'    => $result['failed_postconditions'] ?? [],
        ];

        $normalizedphasetrace = [];
        if (isset($result['phase_trace']) && is_array($result['phase_trace'])) {
            $normalizedphasetrace = phase_trace_normalizer::normalize((array)$result['phase_trace']);
            $structured['phase_trace'] = $normalizedphasetrace;
        }
        if (isset($result['planner_result']) && is_array($result['planner_result'])) {
            $structured['planner_result'] = $result['planner_result'];
            $plannertracehistory = (array)($result['planner_result']['planner_trace_history'] ?? []);
            $normalizedhistory = [];
            foreach ($plannertracehistory as $entry) {
                $value = trim((string)$entry);
                if ($value !== '') {
                    $normalizedhistory[] = $value;
                }
            }
            $this->store->set_planner_trace_history($threadid, $normalizedhistory);
        }
        // Normalize next_step_intent — guard against null from constructor LLM output.
        $rawintent = $result['next_step_intent'] ?? '';
        $nextstepintent = is_string($rawintent) ? trim($rawintent) : '';
        if ($nextstepintent === '') {
            // Fall back to planner result's selection-phase intent when construction phase omits it.
            $nextstepintent = trim((string)($result['planner_result']['selection']['next_step_intent'] ?? ''));
        }
        $this->store->set_thread_metadata_value($threadid, 'next_step_intent', $nextstepintent);
        if (!empty($normalizedphasetrace)) {
            $this->store->set_phase_trace($threadid, $normalizedphasetrace);
        }

        // The stored conversation is LLM input for every later turn (history), so it holds masked
        // values only (HARD RULE 2026-09-11); display de-anonymizes on read (ai_poll_thread,
        // ai_send_message). An engine-built text or an LLM reply carrying a de-anonymized word is
        // re-masked here with the thread's token map — tokens are never re-tokenized. Storage masking
        // mints no single-word tokens (Lauf 8 F60): ordinary words stay readable in the history,
        // full names and e-mails of unmapped persons are still masked.
        $anonymizer = new privacy_anonymizer($this->store);
        // A reply that is exactly the model's text is stored as written: it holds placeholders already, and masking it
        // again corrupts words the model saw in clear text (George 2026-09-26, N36 thread 13358).
        $content = model_authored_text::is_model_message($result)
            ? (string)$result['message']
            : (string)$anonymizer->anonymize_value_for_storage($threadid, (string)($result['message'] ?? ''));
        $structured['errors'] = $anonymizer->anonymize_value_for_storage($threadid, (array)$structured['errors']);
        $this->store->add_message($threadid, 'assistant', $content, $structured);
    }
}

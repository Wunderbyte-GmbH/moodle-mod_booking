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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\synchronizer_output_contract;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the ERROR-FAITHFULNESS guard (source_conflict_reason) and its interaction with
 * thread 58 — pinning that the guard's rejection is DELIBERATE, and that the real thread-58 fix is at
 * the source, not in the guard.
 *
 * Flowchart (AGENT_IMPLEMENTATION_FLOWCHART.mmd, SCONTRACT): free message replacement is REJECTED when
 * the source response_type == 'error' OR the latest source result row status == 'error' — UNLESS
 * error_presentation_requested. The abandon guard keeps any-success runs 'sufficient' precisely so this
 * guard applies "instead of free composition". Its purpose is to stop the synchronizer papering over a
 * failed sub-step (see integration_agent_framework_test:
 * test_synchronizer_output_contract_rejects_success_when_latest_result_is_error — a failed
 * update_option_trainer must not be narrated as "all actions completed successfully").
 *
 * Thread 58, correctly understood (aidebugmode trace, llm_debug row 6344): the synchronizer returned a
 * correct 'sufficient' multi-course answer, but the turn carried a TRAILING error result row — a
 * course-less diagnose call that hard-failed with missing_course. That error was SPURIOUS: the person
 * was already resolved, only the course was unnamed. The guard then did its documented job and rejected
 * the free composition, so the user got the "please clarify" template. The defect was the spurious error
 * row, not the guard. Fix 2a makes the course-less diagnose return a soft 'executed' clarification
 * instead of an error row; with no error row the guard no longer fires and the answer flows — which is
 * exactly why turn 2 of the same thread (identical answer, no hard error) was accepted.
 *
 * Conclusion pinned by these tests: the guard is left UNCHANGED by design. Loosening it to tolerate an
 * any-success trailing error would reintroduce the "papered-over failed sub-step" hole the integration
 * test guards against. Any future "faithful partial narration" for reads is a separate flowchart-level
 * design decision, not a guard bug.
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_output_contract
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class synchronizer_error_faithfulness_guard_test extends TestCase {
    /** @var string A composed, user-facing answer — no option ids, no parse/contract markers. */
    private const GOOD_MESSAGE = 'Fortschritt von Maria Huber: Kurs A teilweise abgeschlossen, '
        . 'Kurs B und Kurs C ohne Abschlussverfolgung.';

    /**
     * Build an any-success 'sufficient' source: three successful diagnoses plus one trailing hard
     * error, mirroring thread-58 turn 1 (pre-2a). No option ids in the details (keeps the fact-conflict
     * guard out of the picture) and no error_presentation flag.
     *
     * @return array
     */
    private function any_success_sufficient_source_with_trailing_error(): array {
        return [
            'response_type' => 'sufficient',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course musisprint'],
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course mooduell'],
                ['status' => 'executed', 'detail' => 'Progress diagnosis for course booking'],
                ['status' => 'error', 'detail' => 'missing_course'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // The guard is intended behaviour — pin it, do not loosen it.
    // Separator.

    /**
     * A trailing error result row rejects free composition even on an any-success 'sufficient' turn.
     * This is the ERROR-FAITHFULNESS guard working as designed — it is NOT a bug to fix in the contract.
     * In thread 58 the trailing error was spurious; the fix belongs at the source (fix 2a), which stops
     * emitting it.
     */
    public function test_trailing_error_on_sufficient_turn_is_rejected_by_design(): void {
        $contract = new synchronizer_output_contract();
        $source = $this->any_success_sufficient_source_with_trailing_error();
        $sync = ['response_type' => 'sufficient', 'message' => self::GOOD_MESSAGE];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame('failed', $result['sync_gate_status'] ?? '');
        $this->assertNotSame(self::GOOD_MESSAGE, $result['message'] ?? '');
    }

    /**
     * The thread-58 resolution at the source level: with the spurious trailing error removed (what fix
     * 2a achieves), the SAME any-success source accepts the identical answer. Proves the trailing error
     * row is the sole differentiator between turn 1 (rejected) and turn 2 (accepted).
     */
    public function test_no_trailing_error_accepts_same_answer(): void {
        $contract = new synchronizer_output_contract();
        $source = $this->any_success_sufficient_source_with_trailing_error();
        // Fix 2a's effect: the course-less diagnose no longer yields an error row.
        array_pop($source['results']);
        $sync = ['response_type' => 'sufficient', 'message' => self::GOOD_MESSAGE];

        $result = $contract->merge($source, $sync);

        $this->assertNotContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame(self::GOOD_MESSAGE, $result['message'] ?? '');
    }

    /**
     * The escape hatch: when the error is deliberately being presented (error_presentation_requested),
     * the guard steps aside so the synchronizer can narrate the real cause in the user's language.
     */
    public function test_error_presentation_source_is_accepted(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'error_presentation_requested' => true,
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'error', 'detail' => 'missing_course']],
        ];
        $sync = ['response_type' => 'sufficient', 'message' => 'Bitte nenne den Kurs, den ich prüfen soll.'];

        $result = $contract->merge($source, $sync);

        $this->assertSame('Bitte nenne den Kurs, den ich prüfen soll.', $result['message'] ?? '');
        $this->assertNotContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertNotContains('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * A run where every step failed (abandon guard flips it to response_type 'error') stays rejected on
     * the response-type branch of the guard.
     */
    public function test_all_failed_error_source_stays_rejected(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'error',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'error', 'detail' => 'missing_course']],
        ];
        $sync = ['response_type' => 'sufficient', 'message' => 'Alles bestens.'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * A real parse failure stays rejected regardless of source shape — unrelated to the source-side
     * error-faithfulness branch, but pinned here so the two rejection families are not conflated.
     */
    public function test_parse_failure_stays_rejected(): void {
        $contract = new synchronizer_output_contract();
        $source = $this->any_success_sufficient_source_with_trailing_error();
        $sync = ['message' => 'Failed to parse LLM response as JSON. Raw excerpt: {bro'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_PARSE_FAILURE_REJECTED', (array)($result['issue_codes'] ?? []));
    }
}

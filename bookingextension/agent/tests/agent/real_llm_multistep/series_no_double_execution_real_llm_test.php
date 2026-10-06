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
 * Real-LLM regression for thread 554: a multi-step series must never double-execute a step.
 *
 * Thread 554 created 8 options for a 5-step "Jour 1-5" series (Jour 3/4/5 twice, with drifted
 * dates) because the confirm queue-drain (Driver B) re-animated stale/over-planned items after
 * the planner had already returned 'sufficient'. The fix gives the planner terminal authority.
 * The pre-existing lecture_autoconfirm test asserts only ">= 1 created" and early-exits at ">= 5",
 * so it passes even with duplicates — this test asserts EXACTLY the invariant the bug violates:
 * no created title appears more than once, and never more than the requested count.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\external\ai_send_message;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Live regression: an autoconfirmed multi-step series creates each step exactly once.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class series_no_double_execution_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
        // Drives the webservice path directly; no build_runtime()-based debug enforcement here.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * A five-step autoconfirmed series must create five distinct options, none twice.
     */
    public function test_autoconfirm_series_creates_each_step_exactly_once(): void {
        global $DB;

        $registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();
        if ($registry->get_skill('mod_booking.create_option') === null) {
            $this->fail('mod_booking.create_option is not available in the current skill catalog.');
        }

        $this->setUser($this->teacher);

        $beforeids = array_map(
            'intval',
            array_keys($DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id'))
        );

        // Thread-554-faithful phrasing: no explicit dates at all, the model must derive next
        // week's Mon-Fri itself (the original failure over-planned the later days with drifted
        // dates). Deliberately vague to give the constructor the same room to drift.
        $prompt = 'Ich mache naechste Woche eine Veranstaltungsreihe, bei der die einzelnen Tage immer extra buchbar '
            . 'sein sollen. Nenne sie Jour 1 etc., immer von 10 bis 18 Uhr. Es koennen jeweils 20 Personen teilnehmen. '
            . 'Montag bis Freitag.';

        $trace = [];
        [$store, , $threadid] = $this->build_runtime();
        $contextid = context_module::instance((int)$this->booking->cmid)->id;
        $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$contextid, (int)$threadid);

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute((int)$contextid, $prompt, (int)$threadid);
        $threadid = (int)($response['threadid'] ?? $threadid);
        $this->assertGreaterThan(0, $threadid, 'Thread id must be present.');
        $trace[] = $this->trace('send', 0, $response);

        // Autoconfirm the series to completion, exactly as the live JS/auto-confirm path does.
        $iterations = 0;
        while ((string)($response['response_type'] ?? '') === 'confirmation_request') {
            if (++$iterations > 30) {
                $this->fail('Confirmation loop exceeded safety limit. Trace: ' . implode(' | ', $trace));
            }
            $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$contextid, (int)$threadid);
            $response = $this->confirm_pending_result($response, (int)$threadid, $store, true);
            $trace[] = $this->trace('confirm', $iterations, $response);
        }

        // Collect the options this run created.
        $created = [];
        foreach ($DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text') as $o) {
            if (!in_array((int)$o->id, $beforeids, true)) {
                $created[] = trim((string)$o->text);
            }
        }
        $this->assertGreaterThanOrEqual(
            1,
            count($created),
            'Expected the series to create at least one option. Trace: ' . implode(' | ', $trace)
        );

        // Thread-554 invariant: no step is executed twice, and the series never over-runs.
        $counts = array_count_values($created);
        foreach ($counts as $title => $n) {
            $this->assertSame(
                1,
                $n,
                'Step "' . $title . '" was created ' . $n . ' times — thread-554 double execution. '
                    . 'Created: [' . implode(', ', $created) . ']. Trace: ' . implode(' | ', $trace)
            );
        }
        $this->assertLessThanOrEqual(
            5,
            count($created),
            'The series created MORE than the five requested options (thread-554 over-execution). '
                . 'Created: [' . implode(', ', $created) . ']. Trace: ' . implode(' | ', $trace)
        );
    }

    /**
     * Compact trace line for send/confirm steps.
     *
     * @param string $phase
     * @param int $step
     * @param array $payload
     * @return string
     */
    private function trace(string $phase, int $step, array $payload): string {
        return $phase . '[' . $step . ']: type=' . (string)($payload['response_type'] ?? '')
            . ' issue_codes=' . json_encode((array)($payload['issue_codes'] ?? []), JSON_UNESCAPED_UNICODE)
            . ' msg=' . trim((string)($payload['displaymessage'] ?? $payload['message'] ?? ''));
    }
}

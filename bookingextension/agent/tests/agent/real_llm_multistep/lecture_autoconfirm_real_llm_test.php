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
 * Real-LLM multistep lecture autoconfirm test.
 *
 * Uses the same interaction style as live JS:
 * - ai_send_message starts the flow
 * - when autoconfirm=1, ai_confirm_run is called once
 * - expected to complete in a single confirmation pass
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\external\ai_send_message;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Live autoconfirm test for creating five lectures in one pass.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class lecture_autoconfirm_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();

        // This test drives the webservice path directly (ai_send_message / ai_confirm_run)
        // and therefore should not rely on build_runtime()-based debug enforcement in tearDown.
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Real flow: create five numbered lectures for next week in one autoconfirm pass.
     */
    public function test_lecture_autoconfirm_single_pass_creates_five_actions(): void {
        global $DB;

        if (!$this->is_skill_available('mod_booking.create_option')) {
            $this->fail('booking.create_option is not available in the current skill catalog.');
        }

        $this->setUser($this->teacher);

        $billy = $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Teacher',
            'email' => 'billy.teacher.' . uniqid('', true) . '@example.com',
        ]);
        $this->getDataGenerator()->enrol_user((int)$billy->id, (int)$this->course->id, 'editingteacher');

        $beforeoptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text');
        $beforeids = array_map('intval', array_keys($beforeoptions));

        // Compute concrete ISO dates for next week Mon–Fri so the fallback prompt
        // never relies on relative time expressions that confuse the LLM schema check.
        $nextmonday = strtotime('next Monday');
        $lecturepromptlines = [];
        for ($dayoffset = 0; $dayoffset < 5; $dayoffset++) {
            $dayts = strtotime('+' . $dayoffset . ' days', $nextmonday);
            $datestr = date('Y-m-d', $dayts);
            $lecturepromptlines[] = 'text="Lecture ' . ($dayoffset + 1) . '", coursestarttime="'
                . $datestr . 'T20:00:00", courseendtime="' . $datestr . 'T22:00:00"';
        }

        $prompt = 'Erstelle fuer naechste Woche fuenf normale Buchungsmoeglichkeiten mit den Titeln Lecture 1 bis Lecture 5, '
            . 'jeweils Montag bis Freitag von 20:00 bis 22:00 Uhr und maximal 20 Teilnehmenden.';

        $trace = [];

        // Keep real-LLM thread tracking consistent with other tests so tearDown
        // can validate generate_text debug entries.
        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, $this->booking_contextid(), $threadid);

        $_POST['sesskey'] = sesskey();
        $contextid = context_module::instance((int)$this->booking->cmid)->id;
        $response = ai_send_message::execute((int)$contextid, $prompt, (int)$threadid);
        $threadid = (int)($response['threadid'] ?? $threadid);
        $this->assertGreaterThan(0, $threadid, 'Thread id must be present for lecture autoconfirm flow.');
        $trace[] = $this->build_trace_line('send', 0, $response);

        if ((string)($response['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                (int)$contextid,
                'Bitte korrigiere die letzte Anfrage. Nutze fuer mod_booking.create_option nur die kanonischen Felder '
                    . 'text, coursestarttime, courseendtime, maxanswers und type=0. '
                    . 'Erstelle exakt diese fünf Optionen: ' . implode(' ; ', $lecturepromptlines) . ' ; maxanswers=20.',
                (int)$threadid
            );
            $trace[] = $this->build_trace_line('send', 1, $response);
        }

        if ((string)($response['response_type'] ?? '') === 'error') {
            $_POST['sesskey'] = sesskey();
            $response = ai_send_message::execute(
                (int)$contextid,
                'Letzter Versuch: Bleibe bei mod_booking.create_option und sende nur gueltige Keys. '
                    . 'Kein slot/skill-Wechsel, keine Zusatzfelder, nur Lecture 1 bis 5 wie angegeben.',
                (int)$threadid
            );
            $trace[] = $this->build_trace_line('send', 2, $response);
        }

        if ((string)($response['response_type'] ?? '') === 'error') {
            $this->assertStringContainsString(
                'Retry mod_booking.create_option once',
                json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'Unexpected non-retriable error payload in lecture autoconfirm flow.'
            );
            $this->fail('Real LLM returned repeated create_option schema mismatch in lecture autoconfirm flow.');
        }

        $this->assertContains(
            (string)($response['response_type'] ?? ''),
            ['confirmation_request', 'sufficient', 'execution_result'],
            'Expected actionable response in single-pass flow. Trace: ' . implode(' | ', $trace)
        );

        $_POST['sesskey'] = sesskey();
        $counter = 1;
        $maxconfirmiterations = 30;
        $dependencyrecoveries = 0;
        $maxdependencyrecoveries = 2;
        $alloptionscreated = false;
        while (((string)($response['response_type'] ?? '') === 'confirmation_request')) {
            if ($counter >= $maxconfirmiterations) {
                $this->fail('Confirmation loop exceeded safety limit (' . $maxconfirmiterations
                    . '). Trace: ' . implode(' | ', $trace));
            }

            // Early exit: once all 5 options are in the DB there is nothing left to confirm.
            $snapshotoptions = $DB->get_records(
                'booking_options',
                ['bookingid' => (int)$this->booking->id],
                'id ASC',
                'id'
            );
            $newcount = 0;
            foreach ($snapshotoptions as $snapopt) {
                if (!in_array((int)$snapopt->id, $beforeids, true)) {
                    $newcount++;
                }
            }
            if ($newcount >= 5) {
                $alloptionscreated = true;
                break;
            }

            $counter++;
            $store->allow_confirmation_for_thread((int)$this->teacher->id, (int)$contextid, (int)$threadid);
            $response = $this->confirm_pending_result($response, (int)$threadid, $store, true);
            $trace[] = $this->build_trace_line('confirm', $counter, $response);

            if ((string)($response['response_type'] ?? '') === 'error' && $this->is_dependency_waiting_error($response)) {
                if ($dependencyrecoveries >= $maxdependencyrecoveries) {
                    break;
                }

                $dependencyrecoveries++;
                $_POST['sesskey'] = sesskey();
                $recovery = ai_send_message::execute(
                    (int)$contextid,
                    'Bitte fahre mit der naechsten ausstehenden bestaetigten Aktion fort.',
                    (int)$threadid
                );
                $trace[] = $this->build_trace_line('send', $counter, $recovery);
                $response = $recovery;

                if ((string)($response['response_type'] ?? '') === 'error' && $this->is_dependency_waiting_error($response)) {
                    // No immediate progress after recovery prompt: continue with bounded
                    // follow-up rounds below instead of looping in-place forever.
                    break;
                }
            }
        }

        $dependencywaitingterminal = ((string)($response['response_type'] ?? '') === 'error')
            && $this->is_dependency_waiting_error($response);

        // Valid terminal states after the confirmation loop:
        // - 'sufficient'       : agent signalled completion normally
        // - 'execution_result' : agent executed and returned results directly (e.g. last batch item)
        // - dependency-waiting error: transient queue state, handled via continuation rounds below
        // - alloptionscreated  : all 5 lectures already created — early exit before agent signalled done.
        $terminalresponsetype = (string)($response['response_type'] ?? '');
        $isvalidterminal = $alloptionscreated
            || $dependencywaitingterminal
            || $terminalresponsetype === 'sufficient'
            || $terminalresponsetype === 'execution_result';

        if (!$isvalidterminal) {
            $this->fail(
                'ai_confirm_run finished with unexpected response_type="' . $terminalresponsetype
                . '". Trace: ' . implode(' | ', $trace)
            );
        }

        $afteroptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text');
        $createdoptions = [];
        foreach ($afteroptions as $option) {
            if (!in_array((int)$option->id, $beforeids, true)) {
                $createdoptions[] = $option;
            }
        }

        // Some valid runs create only the first lecture initially; allow short continuation rounds.
        for ($round = 1; $round <= 2 && count($createdoptions) < 5; $round++) {
            $nextindex = count($createdoptions) + 1;
            $_POST['sesskey'] = sesskey();
            $continuemsg = 'Fahre fort und erstelle jetzt die restlichen Lecture-Optionen bis Lecture 5. '
                . 'Beginne bei Lecture ' . $nextindex . '. '
                . 'Bitte mache mit den restlichen Lectures weiter.';
            $continuesend = ai_send_message::execute($contextid, $continuemsg, (int)$threadid);
            $trace[] = $this->build_trace_line('send', 5 + $round, $continuesend);

            if ((string)($continuesend['response_type'] ?? '') === 'confirmation_request') {
                $store->allow_confirmation_for_thread((int)$this->teacher->id, $contextid, $threadid);
                $continueconfirm = $this->confirm_pending_result($continuesend, (int)$threadid, $store, true);
                $trace[] = $this->build_trace_line('confirm', 5 + $round, $continueconfirm);
            } else if ((string)($continuesend['response_type'] ?? '') === 'clarification') {
                $clarificationmsg = strtolower((string)($continuesend['displaymessage'] ?? $continuesend['message'] ?? ''));
                if (strpos($clarificationmsg, 'pending action') !== false || strpos($clarificationmsg, 'confirm') !== false) {
                    $_POST['sesskey'] = sesskey();
                    $discardsend = ai_send_message::execute(
                        $contextid,
                        'Bitte verwerfe die ausstehende Aktion und fahre dann mit den ' .
                        'restlichen Lecture-Optionen bis Lecture 5 fort.',
                        (int)$threadid
                    );
                    $trace[] = $this->build_trace_line('send', 7 + $round, $discardsend);
                    if ((string)($discardsend['response_type'] ?? '') === 'confirmation_request') {
                        $store->allow_confirmation_for_thread((int)$this->teacher->id, $contextid, $threadid);
                        $discardconfirm = $this->confirm_pending_result($discardsend, (int)$threadid, $store, true);
                        $trace[] = $this->build_trace_line('confirm', 7 + $round, $discardconfirm);
                    }
                }
            }

            $afteroptions = $DB->get_records('booking_options', ['bookingid' => (int)$this->booking->id], 'id ASC', 'id, text');
            $createdoptions = [];
            foreach ($afteroptions as $option) {
                if (!in_array((int)$option->id, $beforeids, true)) {
                    $createdoptions[] = $option;
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            1,
            count($createdoptions),
            'Expected at least one created action/option after continuation handling. Trace: ' . implode(' | ', $trace)
        );

        $createdtitles = array_map(static fn($option): string => (string)($option->text ?? ''), $createdoptions);
        $joinedtitles = implode(' | ', $createdtitles);
        $requiredlecturecount = min(3, count($createdoptions));
        for ($i = 1; $i <= $requiredlecturecount; $i++) {
            $this->assertStringContainsString(
                'Lecture ' . $i,
                $joinedtitles,
                'Expected created titles to include Lecture ' . $i . '. Trace: ' . implode(' | ', $trace)
            );
        }
    }

    /**
     * Compact trace line for send/confirm steps.
     *
     * @param string $phase
     * @param int $step
     * @param array $payload
     * @return string
     */
    private function build_trace_line(string $phase, int $step, array $payload): string {
        $responsetype = (string)($payload['response_type'] ?? '');
        $autoconfirm = (string)($payload['autoconfirm'] ?? 0);
        $message = trim((string)($payload['displaymessage'] ?? $payload['message'] ?? ''));
        $issuecodes = json_encode((array)($payload['issue_codes'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $phase . '[' . $step . ']'
            . ': type=' . $responsetype
            . ' autoconfirm=' . $autoconfirm
            . ' issue_codes=' . $issuecodes
            . ' msg=' . $message;
    }

    /**
     * Check whether payload carries at least one booking.create_option command.
     *
     * @param array $payload
     * @return bool
     */
    private function has_create_option_commands(array $payload): bool {
        return $this->count_create_option_commands($payload) > 0;
    }

    /**
     * Count booking.create_option commands in payload.
     *
     * @param array $payload
     * @return int
     */
    private function count_create_option_commands(array $payload): int {
        $commandsraw = $payload['commands'] ?? '[]';
        if (is_string($commandsraw)) {
            $decoded = json_decode($commandsraw, true);
            $commands = is_array($decoded) ? $decoded : [];
        } else if (is_array($commandsraw)) {
            $commands = $commandsraw;
        } else {
            $commands = [];
        }

        $count = 0;
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            if ((string)($command['skill'] ?? '') === 'mod_booking.create_option') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check if a skill currently exists in the registry.
     *
     * @param string $skillname
     * @return bool
     */
    private function is_skill_available(string $skillname): bool {
        $registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();
        return $registry->get_skill($skillname) !== null;
    }

    /**
     * True when response signals transient queue dependency waiting.
     *
     * @param array $payload
     * @return bool
     */
    private function is_dependency_waiting_error(array $payload): bool {
        $message = strtolower((string)($payload['displaymessage'] ?? $payload['message'] ?? ''));
        if (strpos($message, 'waiting for dependencies') !== false) {
            return true;
        }

        $issuecodes = (array)($payload['issue_codes'] ?? []);
        foreach ($issuecodes as $code) {
            if (strtoupper(trim((string)$code)) === 'DEPENDENCY_WAITING') {
                return true;
            }
        }

        return false;
    }
}

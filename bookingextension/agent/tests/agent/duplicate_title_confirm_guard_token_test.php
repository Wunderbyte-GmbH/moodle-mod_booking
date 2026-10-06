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
 * Duplicate-title confirmation path must stage an executable, guarded command (F20).
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\services\confirm_run_service;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\pending_intent_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * Pinning test for Wunderbyte-GmbH/Wunderbyte-GmbH#2239 (baseline finding F20).
 *
 * A create with a title that duplicates existing options is a soft block per the
 * architecture (09 §3: DUPLICATE_TITLE_* are prevalidation-confirmable codes): the engine
 * shows a confirmation, and the confirmed command must then execute. That requires the
 * staged queue item to carry the prepared input and the execution guard token — a mutating
 * command without guard token is refused by the executor (EXECUTION_GUARD_MISSING), which
 * left the user in an endless confirm loop (Lauf 4, thread 861, run 279).
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @covers \bookingextension_agent\local\wizard\services\confirm_run_service
 */
final class duplicate_title_confirm_guard_token_test extends abstract_agent_testcase {
    /**
     * Duplicate-title soft block stages a guarded command; one confirm executes it.
     */
    public function test_duplicate_title_confirmation_is_guarded_and_executes_on_confirm(): void {
        global $DB;

        $this->setUser($this->teacher);
        $this->grant_agent_capabilities_to_editingteacher();

        // Two existing options with the identical title trigger the MULTI duplicate branch
        // (the F20 fixture: "Sprechstunde" existed twice). No dates on the seeds, so only
        // the TITLE duplicate fires — the dated input below never collides by signature.
        for ($i = 0; $i < 2; $i++) {
            $this->gen->create_option([
                'bookingid' => (int)$this->booking->id,
                'text' => 'Sprechstunde',
                'maxanswers' => 5,
                'type' => 0,
            ]);
        }

        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = $this->booking_contextid();
        $userid = (int)$this->teacher->id;

        // The decision service receives the constructed mutating command exactly as the
        // planner hands it over after parameter construction.
        $service = new agent_decision_service(skill_registry::make_default(), $store, new authorization_service());
        $decision = $service->process([
            'response_type' => 'confirmation_request',
            'message' => 'Soll die Sprechstunde angelegt werden?',
            'commands' => [[
                'skill' => 'mod_booking.create_option',
                'version' => 1,
                'input' => [
                    'text' => 'Sprechstunde',
                    'coursestarttime' => time() + (7 * DAYSECS),
                    'courseendtime' => time() + (7 * DAYSECS) + HOURSECS,
                    'maxanswers' => 5,
                ],
            ]],
        ], $threadid, $contextid, $userid, 'en');

        // The duplicate is surfaced as a confirmation, not as a hard error.
        $this->assertSame('confirmation_request', (string)($decision['response_type'] ?? ''), json_encode([
            'issue_codes' => $decision['issue_codes'] ?? null,
            'errors' => $decision['errors'] ?? null,
            'message' => $decision['message'] ?? null,
        ], JSON_PRETTY_PRINT));
        $this->assertContains(
            'DUPLICATE_TITLE_MULTI_CONFIRM_REQUIRED',
            (array)($decision['issue_codes'] ?? [])
        );

        // The staged queue item must be executable: blocked_confirmation WITH the guard
        // token minted from preflight-prepared input. A token-less mutating item can never
        // pass the executor's release gate — that asymmetry is the F20 loop.
        $queuesvc = new queue_manager($store);
        $blocked = array_values(array_filter(
            $queuesvc->get_queue_items($threadid),
            static fn(array $item): bool => (string)($item['status'] ?? '') === 'blocked_confirmation'
        ));
        $this->assertCount(1, $blocked, 'the duplicate soft block must stage one confirmable queue item');
        $queueitem = $blocked[0];
        $queueitemid = (string)($queueitem['queue_item_id'] ?? '');
        $this->assertNotSame('', $queueitemid);
        $this->assertNotSame(
            '',
            trim((string)($queueitem['guard_token'] ?? '')),
            'the staged duplicate-title command must carry the execution guard token'
        );
        $this->assertNotEmpty(
            (array)($queueitem['prepared_input'] ?? []),
            'the staged duplicate-title command must carry the preflight-prepared input'
        );

        // One confirm — as the "Confirm & Execute" button does — must execute the create.
        (new pending_intent_service($store))->set($threadid, $userid, $contextid, [
            'queue_item_ids' => [$queueitemid],
        ]);
        $confirmsvc = new confirm_run_service(skill_registry::make_default(), $store, new authorization_service());
        $payload = $confirmsvc->confirm($contextid, 0, $threadid, $userid, $queueitemid, false);

        $issuecodes = (array)($payload['issue_codes'] ?? []);
        $this->assertNotContains('EXECUTION_GUARD_MISSING', $issuecodes, 'confirm must not trip the release gate');
        $this->assertSame(
            3,
            (int)$DB->count_records('booking_options', ['bookingid' => (int)$this->booking->id, 'text' => 'Sprechstunde']),
            'the confirmed duplicate-title create must actually add the third option'
        );
    }

    /**
     * Mixed case (live repro, thread 13): title duplicate (confirmable) PLUS signature
     * duplicate (hard block, deliberately NOT prevalidation-confirmable) in one preflight.
     *
     * The engine must not stage a confirmation it cannot honor: with the hard block
     * discarding the prepared input, no guard token can exist, so offering a Confirm
     * button guarantees EXECUTION_GUARD_MISSING. Expected instead: the clarification
     * built from the signature question (the user answers in chat, the planner re-issues
     * with the override) — and no token-less blocked_confirmation item in the queue.
     */
    public function test_hard_signature_block_never_stages_tokenless_confirmation(): void {
        $start = time() + (7 * DAYSECS);
        $end = $start + HOURSECS;

        $this->setUser($this->teacher);
        $this->grant_agent_capabilities_to_editingteacher();

        // One existing option with the SAME title and the SAME window as the input below:
        // the title branch fires (confirmable) AND the signature branch fires (hard).
        $this->gen->create_option([
            'bookingid' => (int)$this->booking->id,
            'text' => 'Sprechstunde',
            'maxanswers' => 5,
            'type' => 0,
            'coursestarttime' => $start,
            'courseendtime' => $end,
        ]);

        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = $this->booking_contextid();
        $userid = (int)$this->teacher->id;

        $service = new agent_decision_service(skill_registry::make_default(), $store, new authorization_service());
        $decision = $service->process([
            'response_type' => 'confirmation_request',
            'message' => 'Soll die Sprechstunde angelegt werden?',
            'commands' => [[
                'skill' => 'mod_booking.create_option',
                'version' => 1,
                'input' => [
                    'text' => 'Sprechstunde',
                    'coursestarttime' => $start,
                    'courseendtime' => $end,
                    'maxanswers' => 5,
                ],
            ]],
        ], $threadid, $contextid, $userid, 'en');

        $this->assertSame(
            'clarification',
            (string)($decision['response_type'] ?? ''),
            'a hard signature block must surface as a clarification, never as a Confirm button: '
                . json_encode($decision['issue_codes'] ?? [])
        );

        // No unexecutable staging: every blocked_confirmation item must carry a guard token.
        $queuesvc = new queue_manager($store);
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if ((string)($item['status'] ?? '') !== 'blocked_confirmation') {
                continue;
            }
            $this->assertNotSame(
                '',
                trim((string)($item['guard_token'] ?? '')),
                'the engine must never stage a token-less blocked_confirmation item'
            );
        }
    }
}

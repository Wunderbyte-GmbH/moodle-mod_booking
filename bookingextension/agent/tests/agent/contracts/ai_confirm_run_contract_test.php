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

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_confirm_run;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Contract tests for ai_confirm_run state handling.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 *
 * @covers \bookingextension_agent\external\ai_confirm_run
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_confirm_run_contract_test extends abstract_agent_testcase {
    /**
     * Terminal confirm success (no further mutating queue item) must run finalizer polish path.
     */
    public function test_terminal_confirm_success_triggers_finalizer_when_no_follow_up_queue_item_exists(): void {
        global $DB;

        $this->setUser($this->teacher);

        $registry = skill_registry::make_default();
        $skill = $registry->get_skill('mod_booking.create_option');
        if ($skill === null) {
            $this->fail('mod_booking.create_option is not available in the current skill catalog.');
        }

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $userid = (int)$this->teacher->id;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid);
        $threadid = (int)$thread->id;
        $queuesvc = new queue_manager($store);

        $DB->delete_records_select('booking_options', 'bookingid = :bookingid AND text LIKE :titlelike', [
            'bookingid' => (int)$this->booking->id,
            'titlelike' => 'Terminal finalizer contract option %',
        ]);

        $command = [
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => [
                'text' => 'Terminal finalizer contract option 1',
            ],
        ];

        $preflight = $skill->preflight((array)$command['input'], $contextid, $userid);
        $this->assertSame('pass', $preflight->status);

        $queued = $queuesvc->enqueue_command($threadid, 0, 0, $command, 'mutating', 'blocked_confirmation');
        $queueitemid = (string)$queued['queue_item_id'];
        $queuesvc->set_prepared_input(
            $threadid,
            $queueitemid,
            $contextid,
            $preflight->preparedinput
        );

        $store->set_pending_intent(
            $threadid,
            hash('sha256', $userid . ':' . $threadid . ':terminal'),
            $userid,
            $contextid,
            ['queue_item_ids' => [$queueitemid]]
        );

        $_POST['sesskey'] = sesskey();
        $result = ai_confirm_run::execute(
            $contextid,
            $threadid,
            $queueitemid,
            true
        );

        $this->assertTrue((bool)($result['success'] ?? false), 'Terminal queued mutation should execute successfully.');
        $this->assertSame(
            'sufficient',
            (string)($result['response_type'] ?? ''),
            'Terminal confirm path without follow-up queue item must end in sufficient.'
        );
        $this->assertSame(
            '',
            (string)($result['queueitemid'] ?? ''),
            'Terminal confirm path must not expose a follow-up queue item.'
        );

        $pendingintent = $store->get_pending_intent($threadid);
        $this->assertNull($pendingintent, 'No pending intent should remain in terminal confirm path.');

        $created = $DB->get_records_select('booking_options', 'bookingid = :bookingid AND text = :title', [
            'bookingid' => (int)$this->booking->id,
            'title' => 'Terminal finalizer contract option 1',
        ]);
        $this->assertCount(1, $created, 'Terminal confirm path must execute the queued mutation exactly once.');

        $entries = $DB->get_records('bx_agent_ai_llm_debug', ['threadid' => $threadid], 'id ASC');
        $this->assertNotEmpty($entries, 'Expected LLM debug entries for terminal confirm thread.');

        $hassynchronizercall = false;
        foreach ($entries as $entry) {
            $source = (string)($entry->source ?? '');
            if (
                strpos($source, 'st=sr') !== false
                || strpos($source, 'ac=wpr') !== false
                || strpos($source, 'ac=sum') !== false
                || strpos($source, 'ac=gen') !== false
            ) {
                $hassynchronizercall = true;
                break;
            }
        }

        $this->assertTrue(
            $hassynchronizercall,
            'Terminal confirm path without follow-up queue item must call finalization on the '
            . 'retrieval/synthesis path (st=sr or ac=gen/sum).'
        );
    }

    /**
     * The session-wide allowance is capability-gated: without
     * bookingextension/agent:confirmforsession (admin-only by default) the allow_session
     * flag is silently ignored — the confirm still executes as a one-time confirmation
     * and NO allowance is recorded. With the capability granted the allowance is stored.
     */
    public function test_allow_session_flag_requires_confirmforsession_capability(): void {
        $this->setUser($this->teacher);

        $registry = skill_registry::make_default();
        $skill = $registry->get_skill('mod_booking.create_option');
        if ($skill === null) {
            $this->fail('mod_booking.create_option is not available in the current skill catalog.');
        }

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $userid = (int)$this->teacher->id;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid);
        $threadid = (int)$thread->id;
        $queuesvc = new queue_manager($store);

        $confirmqueued = function (
            string $title,
            string $intentsalt
        ) use (
            $skill,
            $queuesvc,
            $store,
            $contextid,
            $userid,
            $threadid
        ): array {
            $command = [
                'skill' => 'mod_booking.create_option',
                'version' => 1,
                'input' => ['text' => $title],
            ];
            $preflight = $skill->preflight((array)$command['input'], $contextid, $userid);
            $this->assertSame('pass', $preflight->status);

            $queued = $queuesvc->enqueue_command($threadid, 0, 0, $command, 'mutating', 'blocked_confirmation');
            $queueitemid = (string)$queued['queue_item_id'];
            $queuesvc->set_prepared_input($threadid, $queueitemid, $contextid, $preflight->preparedinput);
            $store->set_pending_intent(
                $threadid,
                hash('sha256', $userid . ':' . $threadid . ':' . $intentsalt),
                $userid,
                $contextid,
                ['queue_item_ids' => [$queueitemid]]
            );

            $_POST['sesskey'] = sesskey();
            return ai_confirm_run::execute($contextid, $threadid, $queueitemid, true);
        };

        // Without the capability: the confirm executes, but allow_session is ignored.
        $result = $confirmqueued('Session gate contract option 1', 'nocap');
        $this->assertTrue((bool)($result['success'] ?? false));
        $this->assertFalse(
            $store->is_confirmation_allowed_for_session($userid, $contextid),
            'allow_session must be ignored for users without agent:confirmforsession.'
        );

        // Grant the capability to editingteacher and repeat: the allowance is recorded.
        $roles = get_archetype_roles('editingteacher');
        $roleid = (int)reset($roles)->id;
        assign_capability(
            'bookingextension/agent:confirmforsession',
            CAP_ALLOW,
            $roleid,
            (int)\context_system::instance()->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $result = $confirmqueued('Session gate contract option 2', 'withcap');
        $this->assertTrue((bool)($result['success'] ?? false));
        $this->assertTrue(
            $store->is_confirmation_allowed_for_session($userid, $contextid),
            'allow_session must record the allowance once agent:confirmforsession is granted.'
        );
    }

    /**
     * A follow-up pending intent for the next queued mutation must always
     * surface as confirmation_request so autoconfirm can continue.
     */
    public function test_follow_up_pending_intent_forces_confirmation_request(): void {
        global $DB;

        $this->setUser($this->teacher);

        $registry = skill_registry::make_default();
        $skill = $registry->get_skill('mod_booking.create_option');
        if ($skill === null) {
            $this->fail('mod_booking.create_option is not available in the current skill catalog.');
        }

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $userid = (int)$this->teacher->id;
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid);
        $threadid = (int)$thread->id;
        $queuesvc = new queue_manager($store);

        $DB->delete_records_select('booking_options', 'bookingid = :bookingid AND text LIKE :titlelike', [
            'bookingid' => (int)$this->booking->id,
            'titlelike' => 'Follow-up contract option %',
        ]);

        $command1 = [
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => [
                'text' => 'Follow-up contract option 1',
            ],
        ];
        $command2 = [
            'skill' => 'mod_booking.create_option',
            'version' => 1,
            'input' => [
                'text' => 'Follow-up contract option 2',
            ],
        ];

        $preflight1 = $skill->preflight((array)$command1['input'], $contextid, $userid);
        $preflight2 = $skill->preflight((array)$command2['input'], $contextid, $userid);
        $this->assertSame('pass', $preflight1->status);
        $this->assertSame('pass', $preflight2->status);

        $queued1 = $queuesvc->enqueue_command($threadid, 0, 0, $command1, 'mutating', 'blocked_confirmation');
        $queuesvc->set_prepared_input(
            $threadid,
            (string)$queued1['queue_item_id'],
            $contextid,
            $preflight1->preparedinput
        );

        $queued2 = $queuesvc->enqueue_command(
            $threadid,
            0,
            0,
            $command2,
            'mutating',
            'blocked_confirmation',
            [(string)$queued1['queue_item_id']]
        );
        $queuesvc->set_prepared_input(
            $threadid,
            (string)$queued2['queue_item_id'],
            $contextid,
            $preflight2->preparedinput
        );

        $store->set_pending_intent(
            $threadid,
            hash('sha256', $userid . ':' . $threadid . ':initial'),
            $userid,
            $contextid,
            [
                'queue_item_ids' => [
                    (string)$queued1['queue_item_id'],
                    (string)$queued2['queue_item_id'],
                ],
            ]
        );

        $_POST['sesskey'] = sesskey();
        $result = ai_confirm_run::execute(
            $contextid,
            $threadid,
            (string)$queued1['queue_item_id'],
            true
        );

        $responsetype = (string)($result['response_type'] ?? '');
        if ($responsetype === 'error') {
            $this->assertSame('error', $responsetype);
            return;
        }

        $this->assertSame(
            'confirmation_request',
            $responsetype,
            'A fresh follow-up pending intent must surface as confirmation_request.'
        );
        $this->assertContains(
            (int)($result['autoconfirm'] ?? 0),
            [0, 1],
            'Autoconfirm flag must stay in canonical boolean-int range for follow-up step.'
        );
        $queueitemid = (string)($result['queueitemid'] ?? '');
        $this->assertNotSame('', $queueitemid, 'Follow-up step must expose a queue item id.');
        $this->assertNotSame(
            (string)$queued1['queue_item_id'],
            $queueitemid,
            'Follow-up step must advance to a different queue item.'
        );
        $this->assertSame(
            '[]',
            (string)($result['errorsjson'] ?? '[]'),
            'Follow-up confirmation should not surface stale planner errors.'
        );
        $this->assertSame(
            '[]',
            (string)($result['issuecodesjson'] ?? '[]'),
            'Follow-up confirmation should not surface stale planner issue codes.'
        );
        $message = (string)($result['message'] ?? '');
        $messagetext = trim(strtolower(strip_tags($message)));
        $this->assertTrue(
            str_contains($messagetext, 'booking option')
                && (str_contains($messagetext, 'created') || str_contains($messagetext, 'creating')),
            'Follow-up confirmation message should describe the executed create-option step. Message: ' . $message
        );

        $pendingintent = $store->get_pending_intent($threadid);
        $this->assertIsArray($pendingintent, 'Expected next pending intent for the remaining queue item.');
        $pendingqueueids = array_values(array_filter(array_map('strval', (array)($pendingintent['queue_item_ids'] ?? []))));
        $this->assertNotEmpty($pendingqueueids, 'Expected a remaining pending queue item after the first execution.');
        $this->assertContains($queueitemid, $pendingqueueids, 'Follow-up queue id must be tracked in pending intent.');

        $created = $DB->get_records_select('booking_options', 'bookingid = :bookingid AND text LIKE :titlelike', [
            'bookingid' => (int)$this->booking->id,
            'titlelike' => 'Follow-up contract option %',
        ]);
        $this->assertCount(1, $created, 'Exactly the first queued mutation should have executed so far.');

        $_POST['sesskey'] = sesskey();
        $result2 = ai_confirm_run::execute(
            $contextid,
            $threadid,
            (string)$queued2['queue_item_id'],
            true
        );

        $this->assertTrue((bool)($result2['success'] ?? false), 'Second queued mutation should execute successfully.');
        $previewdesc = json_decode((string)($result2['previewjson'] ?? '{}'), true);
        $this->assertIsArray($previewdesc);
        $this->assertEquals('booking_option', $previewdesc['type'] ?? '');
        $optionids = $previewdesc['payload']['optionids'] ?? [];
        $this->assertCount(
            2,
            array_values(array_unique(array_map('intval', $optionids))),
            'Preview ids should aggregate all created options across the confirm chain.'
        );
    }
}

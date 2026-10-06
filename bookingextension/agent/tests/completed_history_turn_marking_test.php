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
 * Completed commands and observations tell the planner which turn they come from.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use context_system;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\completed_command_history_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;

/**
 * Completed commands and observations tell the planner which turn they come from (#2550).
 *
 * Thread 199 (training, 2026-10-03): the completed commands and observations of the earlier turns
 * (explain_docs on the first question, search_message_templates on the second) reached the selector
 * of the third turn ("nein die booking rule templates") as plain "completed" rows. The selector took
 * the new request as already answered and returned "sufficient" without a new documentation lookup -
 * its decision order reads "completed_commands of this turn". Rows of earlier turns now go to their
 * own sections (earlier_turns_completed_commands / earlier_turns_completed_observations), derived from
 * engine state only: a row belongs to this turn when it was created at or after the latest user
 * message of the thread.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\runtime_context_block_builder
 */
final class completed_history_turn_marking_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Rows of earlier turns are marked earlier, rows of the running turn current.
     */
    public function test_rows_carry_the_turn_they_come_from(): void {
        global $DB;
        $this->setAdminUser();
        $ctxid = (int)context_system::instance()->id;
        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)get_admin()->id, $ctxid)->id;
        $t0 = time() - 300;

        // Three user messages: two earlier turns and the running one.
        foreach ([0, 10, 20] as $offset) {
            $id = $store->add_message($threadid, 'user', 'message at ' . $offset);
            $DB->set_field('bx_agent_ai_messages', 'timecreated', $t0 + $offset, ['id' => $id]);
        }

        $store->set_thread_metadata_value($threadid, '_skill_queue_items', [
            $this->queue_item($threadid, 'q1', 'wizard.explain_docs', $t0 + 2),
            $this->queue_item($threadid, 'q2', 'local_taskflow.search_message_templates', $t0 + 12),
            $this->queue_item($threadid, 'q3', 'core.find_content', $t0 + 22),
        ]);
        $store->set_thread_metadata_value($threadid, '_execution_observations_v1', [
            $this->ledger_entry($threadid, 'wizard.explain_docs', 'Doc: booking_rules/actions.md', $t0 + 3),
            $this->ledger_entry($threadid, 'local_taskflow.search_message_templates', 'Showing 23 templates', $t0 + 13),
            $this->ledger_entry($threadid, 'core.find_content', 'Found 2 pages', $t0 + 23),
        ]);

        $builder = new runtime_context_block_builder(
            $store,
            new completed_command_history_service($store),
            new planner_catalog_service(new assistant_state_guidance_service())
        );
        $volatile = $builder->build($threadid, $ctxid, orchestrator::PHASE_SELECTION)['volatile'];

        foreach (['completed_commands:', 'completed_observations:'] as $section) {
            $this->assertSame(
                ['core.find_content'],
                array_column($this->section_rows($volatile, $section), 'skill'),
                $section . ' lists only what this turn did'
            );
            $this->assertSame(
                ['wizard.explain_docs', 'local_taskflow.search_message_templates'],
                array_column($this->section_rows($volatile, 'earlier_turns_' . $section), 'skill'),
                'earlier_turns_' . $section . ' keeps what earlier turns did'
            );
        }
    }

    /**
     * Before anything ran in this turn, its sections are emitted empty right above the earlier turns' rows.
     *
     * This is the selector call of thread 199, turn 3: only earlier rows exist. The A/B on that recorded call
     * showed that the explicit empty list decides (with it the selector stopped answering "sufficient").
     */
    public function test_current_turn_sections_stay_as_empty_lists(): void {
        global $DB;
        $this->setAdminUser();
        $ctxid = (int)context_system::instance()->id;
        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)get_admin()->id, $ctxid)->id;
        $t0 = time() - 300;
        foreach ([0, 10, 20] as $offset) {
            $id = $store->add_message($threadid, 'user', 'message at ' . $offset);
            $DB->set_field('bx_agent_ai_messages', 'timecreated', $t0 + $offset, ['id' => $id]);
        }
        $store->set_thread_metadata_value($threadid, '_skill_queue_items', [
            $this->queue_item($threadid, 'q1', 'wizard.explain_docs', $t0 + 2),
        ]);
        $store->set_thread_metadata_value($threadid, '_execution_observations_v1', [
            $this->ledger_entry($threadid, 'wizard.explain_docs', 'Doc: booking_rules/actions.md', $t0 + 3),
        ]);

        $builder = new runtime_context_block_builder(
            $store,
            new completed_command_history_service($store),
            new planner_catalog_service(new assistant_state_guidance_service())
        );
        $lines = explode("\n", $builder->build($threadid, $ctxid, orchestrator::PHASE_SELECTION)['volatile']);

        foreach (['completed_commands:', 'completed_observations:'] as $section) {
            $at = array_search($section, $lines, true);
            $this->assertNotFalse($at, $section . ' is emitted even though this turn did nothing yet');
            $this->assertSame('', $lines[$at + 1], $section . ' is an empty list');
            $this->assertSame('earlier_turns_' . $section, $lines[$at + 2]);
        }
    }

    /**
     * Without an earlier turn nothing moves: a thread's first turn lists everything as this turn.
     */
    public function test_first_turn_has_no_earlier_section(): void {
        global $DB;
        $this->setAdminUser();
        $ctxid = (int)context_system::instance()->id;
        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)get_admin()->id, $ctxid)->id;
        $t0 = time() - 60;
        $id = $store->add_message($threadid, 'user', 'first message');
        $DB->set_field('bx_agent_ai_messages', 'timecreated', $t0, ['id' => $id]);
        $store->set_thread_metadata_value($threadid, '_skill_queue_items', [
            $this->queue_item($threadid, 'q1', 'wizard.explain_docs', $t0 + 2),
        ]);
        $store->set_thread_metadata_value($threadid, '_execution_observations_v1', [
            $this->ledger_entry($threadid, 'wizard.explain_docs', 'Doc: booking_rules/actions.md', $t0 + 3),
        ]);

        $builder = new runtime_context_block_builder(
            $store,
            new completed_command_history_service($store),
            new planner_catalog_service(new assistant_state_guidance_service())
        );
        $volatile = $builder->build($threadid, $ctxid, orchestrator::PHASE_SELECTION)['volatile'];

        foreach (['completed_commands:', 'completed_observations:'] as $section) {
            $this->assertSame(['wizard.explain_docs'], array_column($this->section_rows($volatile, $section), 'skill'));
            $this->assertSame([], $this->section_rows($volatile, 'earlier_turns_' . $section));
        }
    }

    /**
     * A succeeded queue item as the queue manager stores it.
     *
     * @param int $threadid
     * @param string $id
     * @param string $skill
     * @param int $createdat
     * @return array
     */
    private function queue_item(int $threadid, string $id, string $skill, int $createdat): array {
        return [
            'queue_item_id' => $id,
            'thread_id' => $threadid,
            'run_id' => 0,
            'skill' => $skill,
            'input' => ['query' => $id],
            'status' => 'succeeded',
            'created_at' => $createdat,
            'updated_at' => $createdat,
        ];
    }

    /**
     * An observation ledger entry as the ledger stores it.
     *
     * @param int $threadid
     * @param string $skill
     * @param string $observation
     * @param int $createdat
     * @return array
     */
    private function ledger_entry(int $threadid, string $skill, string $observation, int $createdat): array {
        return [
            'thread_id' => $threadid,
            'run_id' => $createdat,
            'skill' => $skill,
            'status' => 'executed',
            'input' => [],
            'observation_canonical' => $observation,
            'observation_full' => $observation,
            'created_at' => $createdat,
        ];
    }

    /**
     * The JSON rows listed under a section header of the runtime block.
     *
     * @param string $block
     * @param string $header
     * @return array[]
     */
    private function section_rows(string $block, string $header): array {
        $rows = [];
        $insection = false;
        foreach (explode("\n", $block) as $line) {
            if (trim($line) === $header) {
                $insection = true;
                continue;
            }
            if (!$insection) {
                continue;
            }
            if (strpos($line, '  - ') !== 0) {
                break;
            }
            $rows[] = (array)json_decode(substr($line, 4), true);
        }
        return $rows;
    }
}

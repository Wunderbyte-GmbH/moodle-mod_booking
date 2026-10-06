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

use bookingextension_agent\external\ai_send_message;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\confirm_run_service;
use bookingextension_agent\local\wizard\services\queue_status_policy;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use bookingextension_agent\local\wizard\skill_registry;
use context_module;
use context_system;
use core_reportbuilder\local\models\audience as audience_model;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\models\schedule as schedule_model;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * The report series through the real chat entry with a scripted planner:
 * create → (a clarification) → add a column → audience → schedule.
 *
 * Every step is its own confirmation and its own preview; the queue's planned placeholders carry
 * the plan across turns; a clarification in the middle keeps the plan owed; the live preview of the
 * report replaces the previous one instead of accumulating (the `replace` flag of the preview
 * contract). Pins the multi-step flow of Wunderbyte-GmbH/Wunderbyte-GmbH#2471 (plan §8).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\report\skills\create_report_skill
 * @covers     \bookingextension_agent\local\wizard\report\skills\update_report_skill
 * @covers     \bookingextension_agent\local\wizard\report\skills\set_report_audience_skill
 * @covers     \bookingextension_agent\local\wizard\report\skills\schedule_report_skill
 * @covers     \bookingextension_agent\local\wizard\services\preview_passthrough
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_multistep_series_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** FQCN of the users datasource core ships. */
    private const USERS_SOURCE = 'core_user\\reportbuilder\\datasource\\users';

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        report_source_catalog_service::reset_caches();
        // Install the plugin's capabilities as an upgrade would (the report.* ones are new), then let
        // the teacher act as a manager: core grants moodle/reportbuilder:edit to managers.
        update_capabilities('bookingextension_agent');
        $this->grant_agent_capabilities_to_editingteacher();
        $managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, (int)$this->teacher->id, context_system::instance()->id);
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Confirm the currently blocked command of a thread through the confirm service.
     *
     * @param conversation_store $store
     * @param int $contextid
     * @param int $threadid
     * @param string $skill
     * @return array
     */
    private function confirm_step(conversation_store $store, int $contextid, int $threadid, string $skill): array {
        $queuesvc = new queue_manager($store);
        $item = null;
        foreach ($queuesvc->get_queue_items($threadid) as $candidate) {
            $isblocked = queue_status_policy::is_blocked_confirmation_status((string)($candidate['status'] ?? ''));
            if ((string)($candidate['skill'] ?? '') === $skill && $isblocked) {
                $item = $candidate;
            }
        }
        $this->assertIsArray($item, 'Expected a blocked ' . $skill . ' command to confirm.');
        $confirm = (new confirm_run_service(skill_registry::make_default(), $store, new authorization_service()))
            ->confirm($contextid, 0, $threadid, (int)$this->teacher->id, (string)$item['queue_item_id'], false);
        $this->assertTrue((bool)($confirm['success'] ?? false), $skill . ' must execute on confirm: ' . json_encode($confirm));
        return $confirm;
    }

    /**
     * The accumulated preview of the confirm chain (thread metadata).
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return array
     */
    private function accumulated_preview(conversation_store $store, int $threadid): array {
        $stored = $store->get_thread_metadata_value($threadid, '_confirm_previews');
        return is_array($stored) ? $stored : [];
    }

    /**
     * Create, refine (after one clarification), share and schedule a report as one plan.
     */
    public function test_series_create_update_audience_schedule(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;
        $queuesvc = new queue_manager($store);

        // Turn 1: the plan (four steps) and the create step.
        $this->install_scripted_planner([
            $this->selector_skill_call(
                'report.create_report',
                ['Add the e-mail column', 'Share it with all users', 'Send it weekly'],
                'Create the report'
            ),
            $this->constructor_confirmation_request('report.create_report', [
                'name' => 'Series users',
                'source' => self::USERS_SOURCE,
                'columns' => [['identifier' => 'user:fullname']],
            ]),
        ]);
        $turn1 = ai_send_message::execute(
            $contextid,
            'Create a report of all users, add their e-mail, share it with everyone and send it weekly.',
            $threadid
        );
        $this->assertSame('confirmation_request', (string)($turn1['response_type'] ?? ''));
        $this->assertCount(3, $queuesvc->get_planned_placeholder_intents($threadid), 'three future steps are owed');
        $this->assertSame(0, report::count_records(['name' => 'Series users']), 'nothing is written before confirm');
        $cardjson = (string)($turn1['previewjson'] ?? '');
        $this->assertStringContainsString('proposed_action', $cardjson, 'the confirm card (source B) is shown');

        $this->confirm_step($store, $contextid, $threadid, 'report.create_report');
        $created = report::get_record(['name' => 'Series users']);
        $this->assertNotFalse($created, 'the report exists after the confirmed step');
        $reportid = (int)$created->get('id');
        $this->assertCount(3, $queuesvc->get_planned_placeholder_intents($threadid), 'the future steps stay owed');
        $preview1 = $this->accumulated_preview($store, $threadid);
        $this->assertSame(report_preview_renderer::PREVIEW_TYPE, $preview1['type'] ?? '');
        $this->assertSame(1, substr_count((string)$preview1['html'], 'bx-agent-report-preview-header'));

        // Turn 2a: the update step names a column the source does not have → clarification, plan kept.
        $this->install_scripted_planner([
            $this->selector_skill_call('report.update_report', [], 'Add the e-mail column'),
            $this->constructor_confirmation_request('report.update_report', [
                'reportid' => $reportid,
                'add_columns' => [['identifier' => 'user:electronicmail']],
            ]),
        ]);
        $turn2a = ai_send_message::execute($contextid, 'Now add the e-mail column.', $threadid);
        $this->assertSame('clarification', (string)($turn2a['response_type'] ?? ''));
        $this->assertSame(['user:fullname'], $this->column_identifiers($reportid), 'nothing changed');
        $this->assertGreaterThanOrEqual(
            3,
            count($queuesvc->get_planned_placeholder_intents($threadid)),
            'a clarification keeps the step owed'
        );

        // Turn 2b: the right identifier → confirm → column present; the preview is replaced, not appended.
        $this->install_scripted_planner([
            $this->selector_skill_call('report.update_report', [], 'Add the e-mail column'),
            $this->constructor_confirmation_request('report.update_report', [
                'reportid' => $reportid,
                'add_columns' => [['identifier' => 'user:email']],
            ]),
        ]);
        $turn2b = ai_send_message::execute($contextid, 'I mean user:email.', $threadid);
        $this->assertSame('confirmation_request', (string)($turn2b['response_type'] ?? ''));
        $this->confirm_step($store, $contextid, $threadid, 'report.update_report');
        $this->assertSame(['user:fullname', 'user:email'], $this->column_identifiers($reportid));
        $preview2 = $this->accumulated_preview($store, $threadid);
        $this->assertSame(report_preview_renderer::PREVIEW_TYPE, $preview2['type'] ?? '');
        $this->assertSame(
            1,
            substr_count((string)$preview2['html'], 'bx-agent-report-preview-header'),
            'a state preview replaces the previous one instead of accumulating (replace flag)'
        );
        $this->assertStringContainsString(get_string('email'), (string)$preview2['html']);

        // Turn 3: audience.
        $this->install_scripted_planner([
            $this->selector_skill_call('report.set_report_audience', [], 'Share it with all users'),
            $this->constructor_confirmation_request('report.set_report_audience', [
                'reportid' => $reportid,
                'audience_type' => 'allusers',
            ]),
        ]);
        $turn3 = ai_send_message::execute($contextid, 'Share it with everyone.', $threadid);
        $this->assertSame('confirmation_request', (string)($turn3['response_type'] ?? ''));
        $this->confirm_step($store, $contextid, $threadid, 'report.set_report_audience');
        $this->assertSame(1, audience_model::count_records(['reportid' => $reportid]));

        // Turn 4: schedule (possible only now that an audience exists).
        $this->install_scripted_planner([
            $this->selector_skill_call('report.schedule_report', [], 'Send it weekly'),
            $this->constructor_confirmation_request('report.schedule_report', [
                'reportid' => $reportid,
                'recurrence' => 'weekly',
                'format' => 'csv',
            ]),
        ]);
        $turn4 = ai_send_message::execute($contextid, 'Send it every week.', $threadid);
        $this->assertSame('confirmation_request', (string)($turn4['response_type'] ?? ''));
        $this->confirm_step($store, $contextid, $threadid, 'report.schedule_report');
        $schedules = schedule_model::get_records(['reportid' => $reportid]);
        $this->assertCount(1, $schedules);
        $this->assertSame(schedule_model::RECURRENCE_WEEKLY, (int)reset($schedules)->get('recurrence'));

        // The plan is complete: no placeholder is still owed, every step settled by its execution.
        $this->assertSame([], $queuesvc->get_planned_placeholder_intents($threadid), 'no step stays owed');
        $planned = 0;
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if ((string)($item['status'] ?? '') === queue_status_policy::planned_status()) {
                $planned++;
            }
        }
        $this->assertSame(0, $planned);
        $final = $this->accumulated_preview($store, $threadid);
        $this->assertSame($reportid, (int)($final['payload']['reportid'] ?? 0));
    }

    /**
     * Column identifiers of a report in column order.
     *
     * @param int $reportid
     * @return string[]
     */
    private function column_identifiers(int $reportid): array {
        $identifiers = [];
        foreach (\core_reportbuilder\local\models\column::get_records(['reportid' => $reportid], 'columnorder') as $column) {
            $identifiers[] = (string)$column->get('uniqueidentifier');
        }
        return $identifiers;
    }
}

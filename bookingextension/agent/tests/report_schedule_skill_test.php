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

use advanced_testcase;
use bookingextension_agent\local\wizard\report\skills\get_report_details_skill;
use bookingextension_agent\local\wizard\report\skills\report_skill_base;
use bookingextension_agent\local\wizard\report\skills\schedule_report_skill;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use bookingextension_agent\local\wizard\services\reportbuilder\schedule_service;
use bookingextension_agent\local\wizard\skill_registry_factory;
use context_system;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\models\schedule as schedule_model;
use core_reportbuilder\task\send_schedule;

/**
 * Tests for report.schedule_report, the schedule service and the delivery diagnosis.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\report\skills\schedule_report_skill
 * @covers     \bookingextension_agent\local\wizard\services\reportbuilder\schedule_service
 * @covers     \bookingextension_agent\local\wizard\report\skills\get_report_details_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_schedule_skill_test extends advanced_testcase {
    /** FQCN of the users datasource core ships. */
    private const USERS_SOURCE = 'core_user\\reportbuilder\\datasource\\users';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        report_source_catalog_service::reset_caches();
        skill_registry_factory::reset();
    }

    /**
     * A user holding the manager role in the system context.
     *
     * @return \stdClass
     */
    private function create_manager(): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($roleid, (int)$user->id, context_system::instance()->id);
        return $user;
    }

    /**
     * A report owned by the manager, optionally with an all-users audience.
     *
     * @param \stdClass $manager
     * @param bool $withaudience
     * @return array{report: report, audienceid: int}
     */
    private function create_report(\stdClass $manager, bool $withaudience): array {
        $this->setUser($manager);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Sendable', 'source' => self::USERS_SOURCE]);
        $audienceid = 0;
        if ($withaudience) {
            $audienceid = (int)$generator->create_audience(['reportid' => $report->get('id'), 'configdata' => []])
                ->get_persistent()->get('id');
        }
        return ['report' => $report, 'audienceid' => $audienceid];
    }

    /**
     * System context id.
     *
     * @return int
     */
    private function ctx(): int {
        return (int)context_system::instance()->id;
    }

    /**
     * Card contract: R2, person field, fences mutual with audience, update and details.
     */
    public function test_card_contract(): void {
        $registry = skill_registry_factory::get_default();
        $bykey = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            $bykey[(string)$contract['skill']] = $contract;
        }
        $contract = $bykey[schedule_report_skill::SKILL_NAME];
        $this->assertSame('broad_write', $contract['risk_class']);
        $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['not']));
        $this->assertLessThanOrEqual(180, \core_text::strlen((string)$contract['message_triggers'][0]['description']));
        foreach (['report.set_report_audience', 'report.update_report', 'report.get_report_details'] as $sibling) {
            $this->assertStringContainsString(substr($sibling, 7), (string)$contract['not'], $sibling);
            $this->assertStringContainsString('schedule_report', (string)$bykey[$sibling]['not'], $sibling . ' names back');
        }
        $this->assertSame(['viewas_userquery'], (new schedule_report_skill())->get_person_reference_fields());
        $this->assertSame(['diagnose_userquery'], (new get_report_details_skill())->get_person_reference_fields());
    }

    /**
     * The service exposes enums from core (types, formats, recurrences, empty policies).
     */
    public function test_service_enums(): void {
        $service = new schedule_service();
        $types = $service->types();
        $this->assertNotEmpty($types);
        $this->assertContains('message', array_column($types, 'type'));
        $this->assertArrayHasKey('csv', $service->formats());
        $recurrences = $service->recurrences();
        $this->assertSame(schedule_model::RECURRENCE_WEEKLY, $recurrences['weekly']);
        $this->assertSame(schedule_model::RECURRENCE_NONE, $recurrences['none']);
        $policies = $service->empty_policies($types[0]['classname']);
        $this->assertArrayHasKey('dont_send', $policies);
        $this->assertSame('weekly', $service->recurrence_key(schedule_model::RECURRENCE_WEEKLY));
        $this->assertSame('recipient', $service->viewas_key(schedule_model::REPORT_VIEWAS_RECIPIENT));
        $this->assertSame('user', $service->viewas_key(4711));
    }

    /**
     * Create a weekly xlsx schedule for all audiences: stored as core stores it, next send computed,
     * confirmation card rows, observation with the schedule, replacing preview.
     */
    public function test_create_schedule(): void {
        $manager = $this->create_manager();
        ['report' => $report, 'audienceid' => $audienceid] = $this->create_report($manager, true);
        $skill = new schedule_report_skill();
        $start = date('Y-m-d', time() + 3 * DAYSECS) . ' 07:00';

        $dto = $skill->preflight([
            'reportquery' => 'Sendable',
            'recurrence' => 'weekly',
            'format' => 'xlsx',
            'starttime' => $start,
            'subject' => 'Weekly numbers',
            'if_empty' => 'dont_send',
        ], $this->ctx(), (int)$manager->id);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $record = $dto->preparedinput['record'];
        $this->assertSame('excel', $record['format'], 'the extension resolves to the dataformat plugin');
        $this->assertSame(schedule_model::RECURRENCE_WEEKLY, $record['recurrence']);
        $this->assertSame([$audienceid], json_decode($record['audiences'], true));
        $this->assertSame('Sendable', $record['name']);
        $config = json_decode($record['configdata'], true);
        $this->assertSame('Weekly numbers', $config['subject']);
        $this->assertSame(2, $config['reportempty']);
        $card = $skill->describe_proposed_action($dto->preparedinput);
        $this->assertGreaterThanOrEqual(5, count($card['rows']));

        $result = $skill->execute($dto->preparedinput, $this->ctx(), (int)$manager->id);
        $this->assertSame('executed', $result['status']);
        $scheduleid = (int)$result['produced_outputs']['scheduleid'];
        $stored = new schedule_model($scheduleid);
        $this->assertSame((int)$report->get('id'), (int)$stored->get('reportid'));
        $this->assertTrue((bool)$stored->get('enabled'));
        $this->assertGreaterThan(time(), (int)$stored->get('timenextsend'));
        $this->assertCount(1, $result['report']['schedules']);
        $this->assertStringContainsString('recurrence: ', $result['observation_full']);

        $preview = $skill->get_result_preview($result, $this->ctx(), (int)$manager->id);
        $this->assertSame(report_preview_renderer::PREVIEW_TYPE, $preview['type']);
        $this->assertTrue($preview['replace']);
    }

    /**
     * Update, disable, enable and send now on the single schedule of a report (no id needed).
     */
    public function test_update_toggle_and_send_now(): void {
        $manager = $this->create_manager();
        ['report' => $report] = $this->create_report($manager, true);
        $skill = new schedule_report_skill();
        $rid = (int)$report->get('id');
        $uid = (int)$manager->id;

        $create = $skill->preflight(['reportid' => $rid, 'recurrence' => 'daily'], $this->ctx(), $uid);
        $scheduleid = (int)$skill->execute($create->preparedinput, $this->ctx(), $uid)['produced_outputs']['scheduleid'];

        $update = $skill->preflight(
            ['reportid' => $rid, 'action' => 'update', 'recurrence' => 'monthly', 'format' => 'csv'],
            $this->ctx(),
            $uid
        );
        $this->assertSame('pass', $update->status, json_encode($update->issues));
        $this->assertSame($scheduleid, (int)$update->preparedinput['scheduleid']);
        $skill->execute($update->preparedinput, $this->ctx(), $uid);
        $this->assertSame(schedule_model::RECURRENCE_MONTHLY, (int)(new schedule_model($scheduleid))->get('recurrence'));

        $nochange = $skill->preflight(['reportid' => $rid, 'action' => 'update'], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_MISSING_CHANGES, $nochange->issues[0]['code']);

        $disable = $skill->preflight(['reportid' => $rid, 'action' => 'disable'], $this->ctx(), $uid);
        $skill->execute($disable->preparedinput, $this->ctx(), $uid);
        $this->assertFalse((bool)(new schedule_model($scheduleid))->get('enabled'));

        $blocked = $skill->preflight(['reportid' => $rid, 'action' => 'send_now'], $this->ctx(), $uid);
        $this->assertSame('needs_clarification', $blocked->issues[0]['severity'], 'a paused schedule is not sent');

        $enable = $skill->preflight(['reportid' => $rid, 'action' => 'enable'], $this->ctx(), $uid);
        $skill->execute($enable->preparedinput, $this->ctx(), $uid);
        $this->assertTrue((bool)(new schedule_model($scheduleid))->get('enabled'));

        $send = $skill->preflight(['reportid' => $rid, 'action' => 'send_now'], $this->ctx(), $uid);
        $this->assertSame('pass', $send->status, json_encode($send->issues));
        $result = $skill->execute($send->preparedinput, $this->ctx(), $uid);
        $this->assertSame('executed', $result['status']);
        $tasks = \core\task\manager::get_adhoc_tasks(send_schedule::class);
        $this->assertCount(1, $tasks);
        $this->assertSame($scheduleid, (int)reset($tasks)->get_custom_data()->scheduleid);
    }

    /**
     * Clarifications: no audience, bad format (options = formats), bad recurrence, start in the past,
     * unparsable start, view-as user without the capability, unknown schedule id; nothing written.
     */
    public function test_clarifications(): void {
        $manager = $this->create_manager();
        ['report' => $noaudience] = $this->create_report($manager, false);
        $skill = new schedule_report_skill();
        $uid = (int)$manager->id;
        $rid = (int)$noaudience->get('id');

        $missing = $skill->preflight(['reportid' => $rid], $this->ctx(), $uid);
        $this->assertSame(schedule_report_skill::CODE_AUDIENCE_MISSING, $missing->issues[0]['code']);
        $this->assertSame('needs_clarification', $missing->issues[0]['severity']);

        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $generator->create_audience(['reportid' => $rid, 'configdata' => []]);

        $format = $skill->preflight(['reportid' => $rid, 'format' => 'docx'], $this->ctx(), $uid);
        $this->assertSame(schedule_report_skill::CODE_SETTING, $format->issues[0]['code']);
        $this->assertContains('csv', array_column($format->issues[0]['options'], 'id'));
        $this->assertStringNotContainsString('SCHEDULE_', $format->issues[0]['message']);

        $recurrence = $skill->preflight(['reportid' => $rid, 'recurrence' => 'fortnightly'], $this->ctx(), $uid);
        $this->assertContains('weekly', array_column($recurrence->issues[0]['options'], 'id'));

        $past = $skill->preflight(['reportid' => $rid, 'starttime' => '2020-01-01'], $this->ctx(), $uid);
        $this->assertSame(schedule_report_skill::CODE_START, $past->issues[0]['code']);
        $garbage = $skill->preflight(['reportid' => $rid, 'starttime' => 'next monday'], $this->ctx(), $uid);
        $this->assertSame(schedule_report_skill::CODE_START, $garbage->issues[0]['code']);

        $badaudience = $skill->preflight(['reportid' => $rid, 'audiences' => [999]], $this->ctx(), $uid);
        $this->assertSame(schedule_report_skill::CODE_SETTING, $badaudience->issues[0]['code']);
        $this->assertNotEmpty($badaudience->issues[0]['options']);

        $unknown = $skill->preflight(['reportid' => $rid, 'action' => 'disable', 'scheduleid' => 999], $this->ctx(), $uid);
        $this->assertSame(schedule_report_skill::CODE_SCHEDULE_NOT_FOUND, $unknown->issues[0]['code']);

        // View as a named user needs moodle/reportbuilder:scheduleviewas; an editor without it is stopped.
        $editor = $this->getDataGenerator()->create_user(['firstname' => 'Edi', 'lastname' => 'Tor']);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/reportbuilder:editall', CAP_ALLOW, $roleid, $this->ctx());
        assign_capability('moodle/user:viewalldetails', CAP_ALLOW, $roleid, $this->ctx());
        role_assign($roleid, (int)$editor->id, $this->ctx());
        $this->setUser($editor);
        $viewas = $skill->preflight(
            ['reportid' => $rid, 'viewas_userquery' => (string)$manager->id],
            $this->ctx(),
            (int)$editor->id
        );
        $this->assertSame(schedule_report_skill::CODE_VIEWAS_DENIED, $viewas->issues[0]['code']);

        $this->assertSame(0, schedule_model::count_records(['reportid' => $rid]));
    }

    /**
     * Delivery diagnosis: facts for a person inside and outside the audience, with a paused schedule,
     * as an id only (no name in the observation).
     */
    public function test_delivery_diagnosis(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Diagnosed', 'source' => self::USERS_SOURCE]);
        $rid = (int)$report->get('id');
        $inside = $this->getDataGenerator()->create_user(['firstname' => 'Ines', 'lastname' => 'Insideaudience',
            'email' => 'ines@example.com']);
        $outside = $this->getDataGenerator()->create_user(['firstname' => 'Otto', 'lastname' => 'Outsideaudience',
            'email' => 'otto@example.com']);
        $audience = $generator->create_audience([
            'reportid' => $rid,
            'classname' => \core_reportbuilder\reportbuilder\audience\manual::class,
            'configdata' => ['users' => [(int)$inside->id]],
        ]);
        $schedule = $generator->create_schedule([
            'reportid' => $rid,
            'name' => 'Nightly',
            'audiences' => json_encode([(int)$audience->get_persistent()->get('id')]),
            'recurrence' => schedule_model::RECURRENCE_DAILY,
            'enabled' => 0,
        ]);
        $details = new get_report_details_skill();

        $in = $details->execute(['reportid' => $rid, 'diagnose_userquery' => 'ines@example.com'], $this->ctx(), (int)$manager->id);
        $this->assertSame('executed', $in['status']);
        $facts = $in['diagnosis'];
        $this->assertSame((int)$inside->id, $facts['userid']);
        $this->assertTrue($facts['user_in_report_audiences']);
        $this->assertTrue($facts['user_can_view_report']);
        $this->assertCount(1, $facts['schedules']);
        $this->assertFalse($facts['schedules'][0]['schedule_enabled']);
        $this->assertTrue($facts['schedules'][0]['user_in_schedule_audiences']);
        $this->assertFalse($facts['schedules'][0]['should_send_now']);
        $this->assertSame('daily', $facts['schedules'][0]['recurrence']);
        $this->assertStringContainsString('DELIVERY DIAGNOSIS for user id ' . $inside->id, $in['observation_full']);
        $this->assertStringContainsString('enabled: no', $in['observation_full']);
        $this->assertStringNotContainsString('Insideaudience', $in['observation_full'], 'no person name in the observation');

        $out = $details->execute(['reportid' => $rid, 'diagnose_userquery' => 'otto@example.com'], $this->ctx(), (int)$manager->id);
        $this->assertFalse($out['diagnosis']['user_in_report_audiences']);
        $this->assertFalse($out['diagnosis']['schedules'][0]['user_in_schedule_audiences']);
        $this->assertStringContainsString('person in schedule audiences: no', $out['observation_full']);

        $nobody = $details->execute(
            ['reportid' => $rid, 'diagnose_userquery' => 'nobody@example.com'],
            $this->ctx(),
            (int)$manager->id
        );
        $this->assertSame('error', $nobody['status']);
        $this->assertStringNotContainsString('AUDIENCE_', $nobody['usermessage']);
        unset($schedule);
    }
}

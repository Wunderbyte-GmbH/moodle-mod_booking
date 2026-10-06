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
use bookingextension_agent\local\wizard\report\skills\search_reports_skill;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_resolver;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;
use context_system;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\models\report;
use core_reportbuilder_generator;

/**
 * Tests for report.search_reports, report.get_report_details, the resolver and the live preview.
 *
 * Structural asserts only: visibility per report as core decides it, exact-first resolution with
 * candidates on ambiguity, the stored definition as observation, a live preview that ships its
 * render-time JS and replaces, honest error/clarification paths without codes in user text.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\report\skills\search_reports_skill
 * @covers     \bookingextension_agent\local\wizard\report\skills\get_report_details_skill
 * @covers     \bookingextension_agent\local\wizard\services\reportbuilder\report_resolver
 * @covers     \bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service
 * @covers     \bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_lookup_skills_test extends advanced_testcase {
    /** FQCN of the users datasource core ships. */
    private const USERS_SOURCE = 'core_user\\reportbuilder\\datasource\\users';

    /** FQCN of the courses datasource core ships. */
    private const COURSES_SOURCE = 'core_course\\reportbuilder\\datasource\\courses';

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
     * The Report Builder generator.
     *
     * @return core_reportbuilder_generator
     */
    private function generator(): core_reportbuilder_generator {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        return $generator;
    }

    /**
     * Both skills are registered, fenced against each other and the discovery pair, with WHEN lines.
     */
    public function test_card_contract(): void {
        $registry = skill_registry_factory::get_default();
        $bykey = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            $bykey[(string)$contract['skill']] = $contract;
        }
        $this->assertArrayHasKey(search_reports_skill::SKILL_NAME, $bykey);
        $this->assertArrayHasKey(get_report_details_skill::SKILL_NAME, $bykey);

        foreach ([search_reports_skill::SKILL_NAME, get_report_details_skill::SKILL_NAME] as $name) {
            $contract = $bykey[$name];
            $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['is']));
            $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['not']));
            $when = (string)($contract['message_triggers'][0]['description'] ?? '');
            $this->assertNotSame('', $when, $name . ' has no WHEN line');
            $this->assertLessThanOrEqual(180, \core_text::strlen($when));
            $this->assertStringNotContainsString('report.', (string)$contract['description']);
            $this->assertTrue((bool)$contract['readonly']);
        }
        // Mutual fences across the four read skills of the family.
        $this->assertStringContainsString('get_report_details', (string)$bykey[search_reports_skill::SKILL_NAME]['not']);
        $this->assertStringContainsString('search_reports', (string)$bykey[get_report_details_skill::SKILL_NAME]['not']);
        $this->assertStringContainsString('search_reports', (string)$bykey['report.list_report_sources']['not']);
        $this->assertStringContainsString('get_report_details', (string)$bykey['report.describe_report_source']['not']);
        $this->assertStringContainsString('list_report_sources', (string)$bykey[search_reports_skill::SKILL_NAME]['not']);
        $this->assertStringContainsString('describe_report_source', (string)$bykey[get_report_details_skill::SKILL_NAME]['not']);

        // The details skill needs an id OR a name; search accepts an empty input.
        $groups = (array)$bykey[get_report_details_skill::SKILL_NAME]['required_groups'];
        $this->assertNotEmpty($groups);
        $this->assertTrue((bool)$bykey[search_reports_skill::SKILL_NAME]['accepts_empty_input']);
    }

    /**
     * Visibility follows core: an editor sees own reports; a plain user only reports with an
     * audience covering them; hidden reports are counted, never listed.
     */
    public function test_search_respects_visibility(): void {
        $manager = $this->create_manager();
        $viewer = $this->getDataGenerator()->create_user();
        $this->setUser($manager);
        $generator = $this->generator();
        $own = $generator->create_report(['name' => 'Manager users report', 'source' => self::USERS_SOURCE]);
        $shared = $generator->create_report(['name' => 'Shared courses report', 'source' => self::COURSES_SOURCE]);
        $generator->create_audience(['reportid' => $shared->get('id'), 'configdata' => []]);
        $ctx = (int)context_system::instance()->id;
        $skill = new search_reports_skill();

        $all = $skill->execute([], $ctx, (int)$manager->id);
        $this->assertSame('executed', $all['status']);
        $this->assertEqualsCanonicalizing(
            [(int)$own->get('id'), (int)$shared->get('id')],
            array_map('intval', array_column($all['reports'], 'id'))
        );
        $this->assertSame(0, $all['hidden']);
        $this->assertStringContainsString('(id ' . $own->get('id') . ')', $all['observation_full']);
        $this->assertStringContainsString('can edit: yes', $all['observation_full']);

        $this->setUser($viewer);
        $forviewer = $skill->execute([], $ctx, (int)$viewer->id);
        $this->assertSame('executed', $forviewer['status']);
        $this->assertSame([(int)$shared->get('id')], array_map('intval', array_column($forviewer['reports'], 'id')));
        $this->assertSame(1, $forviewer['hidden']);
        $this->assertStringContainsString('can edit: no', $forviewer['observation_full']);

        $preview = $skill->get_result_preview($forviewer, $ctx, (int)$viewer->id);
        $this->assertSame(report_skill_base::PREVIEW_TYPE_REPORTS, $preview['type']);
        $this->assertTrue($preview['replace']);
        $this->assertStringContainsString('Shared courses report', $preview['html']);
        $this->assertStringNotContainsString('Manager users report', $preview['html']);
    }

    /**
     * Name, source and flag filters narrow the list; an empty result is byte-stable.
     */
    public function test_search_filters(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $generator = $this->generator();
        $generator->create_report(['name' => 'Pilates evening attendance', 'source' => self::USERS_SOURCE]);
        $generator->create_report(['name' => 'Course catalogue', 'source' => self::COURSES_SOURCE]);
        $ctx = (int)context_system::instance()->id;
        $skill = new search_reports_skill();

        $byname = $skill->execute(['reportquery' => 'attendance pilates'], $ctx, (int)$manager->id);
        $this->assertCount(1, $byname['reports'], 'word order does not matter');
        $this->assertSame('Pilates evening attendance', $byname['reports'][0]['name']);

        $bysource = $skill->execute(['source' => get_string('courses')], $ctx, (int)$manager->id);
        $this->assertCount(1, $bysource['reports']);
        $this->assertSame(self::COURSES_SOURCE, $bysource['reports'][0]['source']);

        $none = $skill->execute(['reportquery' => 'nothing like this'], $ctx, (int)$manager->id);
        $this->assertSame('executed', $none['status']);
        $this->assertSame([], $none['reports']);
        $this->assertSame(get_string('agent_report_reports_found', 'bookingextension_agent', 0), $none['usermessage']);
        $this->assertNull($skill->get_result_preview($none, $ctx, (int)$manager->id));

        $badsource = $skill->preflight(['source' => 'no such source'], $ctx, (int)$manager->id);
        $this->assertSame('needs_clarification', $badsource->issues[0]['severity']);
        $this->assertNotEmpty($badsource->issues[0]['options']);
    }

    /**
     * Resolution: id first, exact name before partial, ambiguity yields candidates, never a silent pick.
     */
    public function test_resolver(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $generator = $this->generator();
        $a = $generator->create_report(['name' => 'Completion', 'source' => self::USERS_SOURCE]);
        $b = $generator->create_report(['name' => 'Completion by course', 'source' => self::USERS_SOURCE]);
        $resolver = new report_resolver();
        $uid = (int)$manager->id;

        $this->assertSame((int)$a->get('id'), (int)$resolver->resolve((int)$a->get('id'), '', $uid)['report']->get('id'));
        $this->assertSame((int)$a->get('id'), (int)$resolver->resolve(0, 'completion', $uid)['report']->get('id'), 'exact wins');
        $this->assertSame((int)$b->get('id'), (int)$resolver->resolve(0, 'by course', $uid)['report']->get('id'), 'single partial');

        $ambiguous = $resolver->resolve(0, 'Completion by', $uid);
        // The query matches only report b word-wise (both words must occur), so the single hit resolves.
        $this->assertSame((int)$b->get('id'), (int)$ambiguous['report']->get('id'));

        $generator->create_report(['name' => 'Completion by category', 'source' => self::USERS_SOURCE]);
        $ambiguous = $resolver->resolve(0, 'Completion by', $uid);
        $this->assertNull($ambiguous['report']);
        $this->assertSame('ambiguous', $ambiguous['reason']);
        $this->assertCount(2, $ambiguous['candidates']);

        $this->assertSame('not_found', $resolver->resolve(0, 'zzz', $uid)['reason']);
        $this->assertSame('missing', $resolver->resolve(0, '', $uid)['reason']);
        $this->assertSame('not_found', $resolver->resolve(999999, '', $uid)['reason']);

        $other = $this->getDataGenerator()->create_user();
        $this->assertSame('no_access', $resolver->resolve((int)$a->get('id'), '', (int)$other->id)['reason']);
    }

    /**
     * The details skill returns the stored definition (columns, conditions with values, filters,
     * audiences, schedules, row count) and a live preview that ships its render-time JS and replaces.
     */
    public function test_details_snapshot_and_live_preview(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $generator = $this->generator();
        $report = $generator->create_report([
            'name' => 'Users with conditions',
            'source' => self::USERS_SOURCE,
            'default' => false,
        ]);
        $reportid = (int)$report->get('id');
        $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:fullname', 'heading' => 'Person']);
        $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:email', 'sortenabled' => 1,
            'sortdirection' => SORT_DESC]);
        $generator->create_condition(['reportid' => $reportid, 'uniqueidentifier' => 'user:username']);
        $generator->create_filter(['reportid' => $reportid, 'uniqueidentifier' => 'user:email']);
        $generator->create_audience(['reportid' => $reportid, 'configdata' => []]);
        $generator->create_schedule(['reportid' => $reportid, 'name' => 'Weekly']);

        // Store a condition value the way the editor does.
        $instance = \core_reportbuilder\manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:username_operator' => text::IS_NOT_EMPTY,
            'user:username_value' => '',
        ]);

        $ctx = (int)context_system::instance()->id;
        $skill = new get_report_details_skill();

        $dto = $skill->preflight(['reportquery' => 'Users with conditions'], $ctx, (int)$manager->id);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $this->assertSame($reportid, (int)$dto->preparedinput['reportid']);

        $result = $skill->execute($dto->preparedinput, $ctx, (int)$manager->id);
        $this->assertSame('executed', $result['status']);
        $snapshot = $result['report'];
        $this->assertSame($reportid, $snapshot['id']);
        $this->assertSame(['user:fullname', 'user:email'], array_column($snapshot['columns'], 'identifier'));
        $this->assertSame('Person', $snapshot['columns'][0]['heading']);
        $this->assertTrue($snapshot['columns'][1]['sortenabled']);
        $this->assertSame('desc', $snapshot['columns'][1]['sortdirection']);
        $this->assertSame('user:username', $snapshot['conditions'][0]['identifier']);
        $this->assertSame(text::IS_NOT_EMPTY, (int)$snapshot['conditions'][0]['values']['operator']);
        $this->assertSame('IS_NOT_EMPTY', $snapshot['conditions'][0]['values']['operator_key']);
        $this->assertSame(['user:email'], array_column($snapshot['filters'], 'identifier'));
        $this->assertCount(1, $snapshot['audiences']);
        $this->assertSame('allusers', $snapshot['audiences'][0]['type']);
        $this->assertCount(1, $snapshot['schedules']);
        $this->assertSame('Weekly', $snapshot['schedules'][0]['name']);
        $this->assertGreaterThan(0, $snapshot['schedules'][0]['timenextsend']);
        $this->assertIsInt($snapshot['rowcount']);
        $this->assertGreaterThan(0, $snapshot['rowcount']);
        $this->assertStringContainsString('operator_key=IS_NOT_EMPTY', $result['observation_full']);
        $this->assertStringContainsString('SCHEDULES (1):', $result['observation_full']);

        $preview = $skill->get_result_preview($result, $ctx, (int)$manager->id);
        $this->assertSame(report_preview_renderer::PREVIEW_TYPE, $preview['type']);
        $this->assertTrue($preview['replace']);
        $this->assertSame($reportid, $preview['payload']['reportid']);
        $this->assertStringContainsString('data-region="core_reportbuilder/report"', $preview['html']);
        $this->assertStringContainsString('Users with conditions', $preview['html']);
        $this->assertNotSame('', trim((string)$preview['js']), 'the live view must ship its render-time JS');
        $this->assertStringContainsString('core_reportbuilder/report', $preview['js']);

        // A user outside every audience of a report gets no preview and an honest error. The report
        // above has an all-users audience, so a second report without any audience is used here.
        $this->setUser($manager);
        $private = $generator->create_report(['name' => 'Private report', 'source' => self::USERS_SOURCE]);
        $privateresult = $skill->execute(['reportid' => (int)$private->get('id')], $ctx, (int)$manager->id);
        $this->assertSame('executed', $privateresult['status']);
        $other = $this->getDataGenerator()->create_user();
        $this->assertNull($skill->get_result_preview($privateresult, $ctx, (int)$other->id));
        $this->setUser($other);
        $denied = $skill->execute(['reportid' => (int)$private->get('id')], $ctx, (int)$other->id);
        $this->assertSame('error', $denied['status']);
        $this->assertStringNotContainsString('REPORT_', $denied['usermessage']);
    }

    /**
     * Ambiguous, missing and unknown targets are clarifications with candidates (preflight) and
     * honest errors naming candidates (chat path); no codes or field names reach the user text.
     */
    public function test_details_target_clarifications(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $generator = $this->generator();
        $generator->create_report(['name' => 'Sales north', 'source' => self::USERS_SOURCE]);
        $generator->create_report(['name' => 'Sales south', 'source' => self::USERS_SOURCE]);
        $ctx = (int)context_system::instance()->id;
        $skill = new get_report_details_skill();

        $ambiguous = $skill->preflight(['reportquery' => 'Sales'], $ctx, (int)$manager->id);
        $issue = $ambiguous->issues[0];
        $this->assertSame(report_skill_base::CODE_REPORT_AMBIGUOUS, $issue['code']);
        $this->assertSame('needs_clarification', $issue['severity']);
        $this->assertCount(2, $issue['options']);
        $this->assertSame(report_skill_base::PREVIEW_TYPE_REPORTS, $issue['preview']['type']);
        $this->assertStringNotContainsString('REPORT_', $issue['message']);
        $this->assertStringNotContainsString('reportquery', $issue['message']);

        $missing = $skill->preflight([], $ctx, (int)$manager->id);
        $this->assertSame(report_skill_base::CODE_MISSING_REPORT, $missing->issues[0]['code']);
        $this->assertCount(2, $missing->issues[0]['options'], 'all visible reports offered');

        $unknown = $skill->preflight(['reportquery' => 'Marketing'], $ctx, (int)$manager->id);
        $this->assertSame(report_skill_base::CODE_REPORT_NOT_FOUND, $unknown->issues[0]['code']);
        $this->assertCount(2, $unknown->issues[0]['options']);

        $chat = $skill->execute(['reportquery' => 'Sales'], $ctx, (int)$manager->id);
        $this->assertSame('error', $chat['status']);
        $this->assertStringContainsString('Sales north', $chat['observation_full']);
        $this->assertStringContainsString('Sales south', $chat['observation_full']);
        $this->assertStringNotContainsString('REPORT_', $chat['usermessage']);
    }
}

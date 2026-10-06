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
use bookingextension_agent\local\wizard\report\skills\create_report_skill;
use bookingextension_agent\local\wizard\report\skills\report_skill_base;
use bookingextension_agent\local\wizard\report\skills\update_report_skill;
use bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;
use context_system;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\models\report;

/**
 * Tests for report.create_report and report.update_report.
 *
 * Structural asserts only: the stored definition after each write, clarifications with options
 * for every recoverable input problem (no codes or field lists in the user text), the confirmable
 * name conflict with its override token, honest permission handling, transactional writes, the
 * replacing live preview and the confirmation card.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\report\skills\create_report_skill
 * @covers     \bookingextension_agent\local\wizard\report\skills\update_report_skill
 * @covers     \bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_authoring_skills_test extends advanced_testcase {
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
     * System context id.
     *
     * @return int
     */
    private function ctx(): int {
        return (int)context_system::instance()->id;
    }

    /**
     * Card contract: R2, system scope, fences mutual with the read skills, WHEN lines, required inputs.
     */
    public function test_card_contract(): void {
        $registry = skill_registry_factory::get_default();
        $bykey = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            $bykey[(string)$contract['skill']] = $contract;
        }
        foreach ([create_report_skill::SKILL_NAME, update_report_skill::SKILL_NAME] as $name) {
            $this->assertArrayHasKey($name, $bykey);
            $contract = $bykey[$name];
            $this->assertFalse((bool)$contract['readonly']);
            $this->assertSame('broad_write', $contract['risk_class']);
            $this->assertContains('system', (array)$contract['context_scopes']);
            $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['is']));
            $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['not']));
            $when = (string)($contract['message_triggers'][0]['description'] ?? '');
            $this->assertNotSame('', $when);
            $this->assertLessThanOrEqual(180, \core_text::strlen($when));
            $this->assertStringNotContainsString('report.', (string)$contract['description']);
        }
        $this->assertEqualsCanonicalizing(['name', 'source'], (array)$bykey[create_report_skill::SKILL_NAME]['required_input']);
        $this->assertNotEmpty((array)$bykey[update_report_skill::SKILL_NAME]['required_groups']);
        // Every fence the two authoring cards draw is answered (wave 20 rule, within the family).
        $this->assertStringContainsString('update_report', (string)$bykey[create_report_skill::SKILL_NAME]['not']);
        $this->assertStringContainsString('create_report', (string)$bykey[update_report_skill::SKILL_NAME]['not']);
        $this->assertStringContainsString('create_report', (string)$bykey['report.list_report_sources']['not']);
        $this->assertStringContainsString('create_report', (string)$bykey['report.describe_report_source']['not']);
    }

    /**
     * Create with the source defaults: report exists, default columns present, live preview replaces,
     * produced outputs carry the id, observation is the stored definition.
     */
    public function test_create_with_defaults(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new create_report_skill();

        $dto = $skill->preflight(['name' => 'All users', 'source' => get_string('users')], $this->ctx(), (int)$manager->id);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $this->assertSame(self::USERS_SOURCE, $dto->preparedinput['source']);
        $this->assertTrue($dto->preparedinput['resolved']['use_defaults']);

        $card = $skill->describe_proposed_action($dto->preparedinput);
        $this->assertNotEmpty($card['rows']);
        $this->assertNotSame('', $card['title']);
        $this->assertNotSame('', $card['summary'], 'the card states the visibility until an audience exists');

        $result = $skill->execute($dto->preparedinput, $this->ctx(), (int)$manager->id);
        $this->assertSame('executed', $result['status']);
        $reportid = (int)$result['produced_outputs']['reportid'];
        $this->assertGreaterThan(0, $reportid);
        $stored = report::get_record(['id' => $reportid]);
        $this->assertSame('All users', $stored->get('name'));
        $this->assertSame((int)$manager->id, (int)$stored->get('usercreated'));
        $this->assertContains('user:fullname', array_column($result['report']['columns'], 'identifier'));
        $this->assertSame([], $result['report']['audiences']);
        $this->assertStringContainsString('audiences: 0', $result['observation_full']);

        $preview = $skill->get_result_preview($result, $this->ctx(), (int)$manager->id);
        $this->assertSame(report_preview_renderer::PREVIEW_TYPE, $preview['type']);
        $this->assertTrue($preview['replace']);
        $this->assertStringContainsString('data-region="core_reportbuilder/report"', $preview['html']);
        $this->assertNotSame('', trim((string)$preview['js']));
    }

    /**
     * Create with explicit columns (heading, aggregation, sort), a condition with a value and a filter:
     * the stored definition matches the request exactly, defaults are not added.
     */
    public function test_create_with_explicit_definition(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new create_report_skill();

        $dto = $skill->preflight([
            'name' => 'Users by name',
            'source' => self::USERS_SOURCE,
            'columns' => [
                ['identifier' => 'user:fullname', 'heading' => 'Person', 'sort' => 'desc'],
                ['identifier' => 'user:email', 'aggregation' => 'count'],
            ],
            'conditions' => [
                ['identifier' => 'user:suspended', 'operator' => 'NOT_CHECKED'],
                ['identifier' => 'user:fullname', 'operator' => 'CONTAINS', 'value' => 'a'],
            ],
            'filters' => ['user:email'],
            'uniquerows' => true,
        ], $this->ctx(), (int)$manager->id);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $this->assertFalse($dto->preparedinput['resolved']['use_defaults']);

        $result = $skill->execute($dto->preparedinput, $this->ctx(), (int)$manager->id);
        $this->assertSame('executed', $result['status']);
        $snapshot = $result['report'];
        $this->assertSame(['user:fullname', 'user:email'], array_column($snapshot['columns'], 'identifier'));
        $this->assertSame('Person', $snapshot['columns'][0]['heading']);
        $this->assertTrue($snapshot['columns'][0]['sortenabled']);
        $this->assertSame('desc', $snapshot['columns'][0]['sortdirection']);
        $this->assertSame('count', $snapshot['columns'][1]['aggregation']);
        $this->assertTrue($snapshot['uniquerows']);
        $conditions = array_column($snapshot['conditions'], null, 'identifier');
        $this->assertSame(boolean_select::NOT_CHECKED, (int)$conditions['user:suspended']['values']['operator']);
        $this->assertSame(text::CONTAINS, (int)$conditions['user:fullname']['values']['operator']);
        $this->assertSame('a', $conditions['user:fullname']['values']['value']);
        $this->assertSame(['user:email'], array_column($snapshot['filters'], 'identifier'));
        $this->assertIsInt($snapshot['rowcount']);
    }

    /**
     * Every recoverable input problem is a clarification with structural options: unknown source,
     * unknown column (narrowed to the entity), bad aggregation, unknown operator, missing name.
     */
    public function test_create_clarifications(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new create_report_skill();
        $uid = (int)$manager->id;

        $noname = $skill->preflight(['source' => self::USERS_SOURCE], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_MISSING_NAME, $noname->issues[0]['code']);
        $this->assertSame('needs_clarification', $noname->issues[0]['severity']);

        $nosource = $skill->preflight(['name' => 'X', 'source' => 'nope'], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_SOURCE_VALIDATION_ERROR, $nosource->issues[0]['code']);
        $this->assertNotEmpty($nosource->issues[0]['options']);

        $badcolumn = $skill->preflight([
            'name' => 'X', 'source' => self::USERS_SOURCE, 'columns' => [['identifier' => 'user:nonexistent']],
        ], $this->ctx(), $uid);
        $issue = $badcolumn->issues[0];
        $this->assertSame(report_skill_base::CODE_COLUMN_VALIDATION_ERROR, $issue['code']);
        $this->assertNotEmpty($issue['options']);
        foreach ($issue['options'] as $option) {
            $this->assertStringStartsWith('user:', $option['id'], 'narrowed to the named entity');
        }
        $this->assertStringNotContainsString('REPORT_', $issue['message']);
        $this->assertStringNotContainsString('identifier', $issue['message']);

        $badaggregation = $skill->preflight([
            'name' => 'X', 'source' => self::USERS_SOURCE,
            'columns' => [['identifier' => 'user:fullname', 'aggregation' => 'sum']],
        ], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_COLUMN_SETTING_VALIDATION_ERROR, $badaggregation->issues[0]['code']);
        $this->assertContains('count', array_column($badaggregation->issues[0]['options'], 'id'));

        $badoperator = $skill->preflight([
            'name' => 'X', 'source' => self::USERS_SOURCE,
            'conditions' => [['identifier' => 'user:fullname', 'operator' => 'LOOKS_LIKE']],
        ], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_CONDITION_VALUE_VALIDATION_ERROR, $badoperator->issues[0]['code']);
        $this->assertContains('CONTAINS', array_column($badoperator->issues[0]['options'], 'id'));

        $badfilter = $skill->preflight([
            'name' => 'X', 'source' => self::USERS_SOURCE, 'filters' => ['user:nonexistent'],
        ], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_FILTER_VALIDATION_ERROR, $badfilter->issues[0]['code']);

        $this->assertSame(0, report::count_records(), 'no report is written by any clarification');
    }

    /**
     * A second report with the same name on the same source soft-blocks (confirmable) and passes with
     * the override token; a plain user is stopped before anything else.
     */
    public function test_name_conflict_and_permission(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new create_report_skill();
        $uid = (int)$manager->id;
        $input = ['name' => 'Twice', 'source' => self::USERS_SOURCE];

        $first = $skill->preflight($input, $this->ctx(), $uid);
        $this->assertSame('pass', $first->status);
        $skill->execute($first->preparedinput, $this->ctx(), $uid);

        $blocked = $skill->preflight($input, $this->ctx(), $uid);
        $this->assertSame('soft_block', $blocked->status);
        $this->assertSame(report_skill_base::CODE_NAME_CONFLICT, $blocked->issues[0]['code']);
        $this->assertSame('needs_confirmation', $blocked->issues[0]['severity']);
        $this->assertNotEmpty($blocked->preparedinput, 'a soft block carries the prepared input');

        $confirmed = $skill->preflight($input + ['override' => [report_skill_base::OVERRIDE_DUPLICATE_NAME]], $this->ctx(), $uid);
        $this->assertSame('pass', $confirmed->status);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $denied = $skill->preflight($input, $this->ctx(), (int)$user->id);
        $this->assertSame(report_skill_base::CODE_PERMISSION_DENIED, $denied->issues[0]['code']);
        $this->assertSame('needs_clarification', $denied->issues[0]['severity']);
        $error = $skill->execute($first->preparedinput, $this->ctx(), (int)$user->id);
        $this->assertSame('error', $error['status']);
        $this->assertSame(1, report::count_records());
    }

    /**
     * A write that fails half-way leaves nothing behind (single transaction).
     */
    public function test_create_is_transactional(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $service = new report_definition_service();
        try {
            $service->create([
                'name' => 'Broken',
                'source' => self::USERS_SOURCE,
                'use_defaults' => false,
                'columns' => [['identifier' => 'user:fullname'], ['identifier' => 'user:nonexistent']],
                'conditions' => [],
                'filters' => [],
            ], (int)$manager->id);
            $this->fail('the second column must fail');
        } catch (\Throwable $e) {
            $this->assertSame(0, report::count_records(['name' => 'Broken']));
        }
    }

    /**
     * Update: add/remove/set columns, conditions with values, filters, rename; every operation
     * validated against the report's source; the observation shows before and after.
     */
    public function test_update_operations(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $uid = (int)$manager->id;
        $create = new create_report_skill();
        $dto = $create->preflight([
            'name' => 'Editable', 'source' => self::USERS_SOURCE,
            'columns' => [['identifier' => 'user:fullname'], ['identifier' => 'user:username']],
            'filters' => ['user:email'],
        ], $this->ctx(), $uid);
        $reportid = (int)$create->execute($dto->preparedinput, $this->ctx(), $uid)['produced_outputs']['reportid'];

        $skill = new update_report_skill();
        $dto = $skill->preflight([
            'reportquery' => 'Editable',
            'name' => 'Edited',
            'add_columns' => [['identifier' => 'user:email', 'heading' => 'Mail', 'sort' => 'asc']],
            'remove_columns' => ['user:username'],
            'set_columns' => [['identifier' => 'user:fullname', 'aggregation' => 'count']],
            'add_conditions' => [['identifier' => 'user:suspended', 'operator' => 'NOT_CHECKED']],
            'remove_filters' => ['user:email'],
            'add_filters' => ['user:fullname'],
            'uniquerows' => true,
        ], $this->ctx(), $uid);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $this->assertSame($reportid, (int)$dto->preparedinput['reportid']);
        $ops = array_column($dto->preparedinput['resolved']['operations'], 'op');
        $this->assertEqualsCanonicalizing(
            ['set_name', 'set_uniquerows', 'remove_column', 'add_column', 'set_column', 'add_condition', 'remove_filter',
                'add_filter'],
            $ops
        );
        $card = $skill->describe_proposed_action($dto->preparedinput);
        $this->assertCount(count($ops) + 1, $card['rows']);

        $result = $skill->execute($dto->preparedinput, $this->ctx(), $uid);
        $this->assertSame('executed', $result['status']);
        $snapshot = $result['report'];
        $this->assertSame('Edited', $snapshot['name']);
        $this->assertTrue($snapshot['uniquerows']);
        $columns = array_column($snapshot['columns'], null, 'identifier');
        $this->assertEqualsCanonicalizing(['user:fullname', 'user:email'], array_keys($columns));
        $this->assertSame('count', $columns['user:fullname']['aggregation']);
        $this->assertSame('Mail', $columns['user:email']['heading']);
        $this->assertTrue($columns['user:email']['sortenabled']);
        $this->assertSame(['user:suspended'], array_column($snapshot['conditions'], 'identifier'));
        $this->assertSame(boolean_select::NOT_CHECKED, (int)$snapshot['conditions'][0]['values']['operator']);
        $this->assertSame(['user:fullname'], array_column($snapshot['filters'], 'identifier'));
        $this->assertStringContainsString('columns before: user:fullname, user:username', $result['observation_full']);
        $this->assertStringContainsString('applied: ', $result['observation_full']);

        $preview = $skill->get_result_preview($result, $this->ctx(), $uid);
        $this->assertSame(report_preview_renderer::PREVIEW_TYPE, $preview['type']);
        $this->assertTrue($preview['replace']);

        // Removing a condition also drops its stored values; replace_columns swaps the list.
        $dto = $skill->preflight([
            'reportid' => $reportid,
            'remove_conditions' => ['user:suspended'],
            'add_columns' => [['identifier' => 'user:idnumber']],
            'replace_columns' => true,
        ], $this->ctx(), $uid);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $after = $skill->execute($dto->preparedinput, $this->ctx(), $uid)['report'];
        $this->assertSame([], $after['conditions']);
        $this->assertSame(['user:idnumber'], array_column($after['columns'], 'identifier'));
        $instance = \core_reportbuilder\manager::get_report_from_id($reportid);
        $this->assertArrayNotHasKey('user:suspended_operator', $instance->get_condition_values());
    }

    /**
     * Update clarifications: no change, unknown report, unknown column to remove (options = the
     * report's own columns), foreign report without edit right.
     */
    public function test_update_clarifications(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $uid = (int)$manager->id;
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Fixed', 'source' => self::USERS_SOURCE]);
        $skill = new update_report_skill();

        $nochange = $skill->preflight(['reportid' => (int)$report->get('id')], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_MISSING_CHANGES, $nochange->issues[0]['code']);
        $this->assertSame('needs_clarification', $nochange->issues[0]['severity']);

        $unknown = $skill->preflight(['reportquery' => 'Nothing', 'name' => 'Y'], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_REPORT_NOT_FOUND, $unknown->issues[0]['code']);
        $this->assertNotEmpty($unknown->issues[0]['options']);

        $badremove = $skill->preflight([
            'reportid' => (int)$report->get('id'), 'remove_columns' => ['user:nonexistent'],
        ], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_COLUMN_VALIDATION_ERROR, $badremove->issues[0]['code']);
        $this->assertContains('user:fullname', array_column($badremove->issues[0]['options'], 'id'));

        // An editor without editall may not change someone else's report.
        $editor = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/reportbuilder:edit', CAP_ALLOW, $roleid, $this->ctx());
        role_assign($roleid, (int)$editor->id, $this->ctx());
        $this->setUser($editor);
        $foreign = $skill->preflight(['reportid' => (int)$report->get('id'), 'name' => 'Mine now'], $this->ctx(), (int)$editor->id);
        $this->assertSame('needs_clarification', $foreign->issues[0]['severity']);
        $this->assertStringNotContainsString('REPORT_', $foreign->issues[0]['message']);
        $this->assertSame('Fixed', report::get_record(['id' => (int)$report->get('id')])->get('name'));
    }
}

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
use bookingextension_agent\local\wizard\report\skills\set_report_audience_skill;
use bookingextension_agent\local\wizard\services\reportbuilder\audience_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;
use context_system;
use core_reportbuilder\local\models\report;
use core_reportbuilder\permission;

/**
 * Tests for report.set_report_audience and the audience service.
 *
 * Structural asserts only: registered types from core discovery, configuration resolved from
 * structured input (roles by short name, cohorts by id number, persons by address / single name),
 * visibility that actually changes for a viewer, person data kept out of the observation,
 * clarifications with options, honest permission handling, replacing preview.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\report\skills\set_report_audience_skill
 * @covers     \bookingextension_agent\local\wizard\services\reportbuilder\audience_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_audience_skill_test extends advanced_testcase {
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
     * A report owned by the given manager.
     *
     * @param \stdClass $manager
     * @param string $name
     * @return report
     */
    private function create_report(\stdClass $manager, string $name = 'Shared later'): report {
        $this->setUser($manager);
        return $this->getDataGenerator()->get_plugin_generator('core_reportbuilder')
            ->create_report(['name' => $name, 'source' => self::USERS_SOURCE]);
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
     * Card contract: R2, person field declared, fences mutual with update and details.
     */
    public function test_card_contract(): void {
        $registry = skill_registry_factory::get_default();
        $bykey = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            $bykey[(string)$contract['skill']] = $contract;
        }
        $this->assertArrayHasKey(set_report_audience_skill::SKILL_NAME, $bykey);
        $contract = $bykey[set_report_audience_skill::SKILL_NAME];
        $this->assertFalse((bool)$contract['readonly']);
        $this->assertSame('broad_write', $contract['risk_class']);
        $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['not']));
        $this->assertLessThanOrEqual(180, \core_text::strlen((string)$contract['message_triggers'][0]['description']));
        $this->assertStringContainsString('update_report', (string)$contract['not']);
        $this->assertStringContainsString('set_report_audience', (string)$bykey['report.update_report']['not']);
        $this->assertStringContainsString('get_report_details', (string)$contract['not']);
        $this->assertStringContainsString('set_report_audience', (string)$bykey['report.get_report_details']['not']);
        $this->assertSame(['userqueries'], (new set_report_audience_skill())->get_person_reference_fields());
    }

    /**
     * The service lists core's audience types from component discovery and resolves references.
     */
    public function test_service_types_and_config(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $service = new audience_service();
        // The cohort audience reports itself unavailable until the site has a cohort.
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Trainers', 'idnumber' => 'TRN']);

        $types = array_column($service->types(), null, 'type');
        foreach (['allusers', 'admins', 'manual', 'systemrole', 'cohortmember'] as $type) {
            $this->assertArrayHasKey($type, $types, $type);
        }
        $this->assertSame($types['systemrole']['classname'], $service->resolve_type('systemrole')['classname']);
        $this->assertSame($types['allusers']['classname'], $service->resolve_type($types['allusers']['name'])['classname']);
        $this->assertNull($service->resolve_type('everybody-ish')['classname']);
        $this->assertNotEmpty($service->resolve_type('everybody-ish')['candidates']);

        $roles = $service->build_config($types['systemrole']['classname'], ['roles' => ['manager']], (int)$manager->id);
        $this->assertNull($roles['problem']);
        $this->assertCount(1, $roles['configdata']['roles']);
        $badrole = $service->build_config($types['systemrole']['classname'], ['roles' => ['ceo']], (int)$manager->id);
        $this->assertSame(audience_service::PROBLEM_ROLE, $badrole['problem']['kind']);
        $this->assertContains('manager', array_column($badrole['problem']['options'], 'id'));

        $cohorts = $service->build_config($types['cohortmember']['classname'], ['cohorts' => ['TRN']], (int)$manager->id);
        $this->assertNull($cohorts['problem']);
        $this->assertSame([(int)$cohort->id], $cohorts['configdata']['cohorts']);
        $byname = $service->build_config($types['cohortmember']['classname'], ['cohorts' => ['trainers']], (int)$manager->id);
        $this->assertSame([(int)$cohort->id], $byname['configdata']['cohorts']);

        $anna = $this->getDataGenerator()->create_user([
            'firstname' => 'Anna', 'lastname' => 'Zeta', 'email' => 'anna@example.com',
        ]);
        $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Ypsilon']);
        $byaddress = $service->build_config(
            $types['manual']['classname'],
            ['userqueries' => ['anna@example.com']],
            (int)$manager->id
        );
        $this->assertNull($byaddress['problem']);
        $this->assertSame([(int)$anna->id], $byaddress['configdata']['users']);
        $this->assertSame(['count' => 1], $byaddress['summary'], 'no names in the summary');
        $ambiguous = $service->build_config($types['manual']['classname'], ['userqueries' => ['Anna']], (int)$manager->id);
        $this->assertSame(audience_service::PROBLEM_USER_AMBIGUOUS, $ambiguous['problem']['kind']);
        $this->assertCount(2, $ambiguous['problem']['options']);
        $nobody = $service->build_config($types['manual']['classname'], ['userqueries' => ['Nobody Here']], (int)$manager->id);
        $this->assertSame(audience_service::PROBLEM_USER_NOT_FOUND, $nobody['problem']['kind']);

        $all = $service->build_config($types['allusers']['classname'], [], (int)$manager->id);
        $this->assertNull($all['problem']);
        $this->assertSame([], $all['configdata']);
    }

    /**
     * Adding an all-users audience makes the report visible to a plain user; the observation
     * reports the covered users as a count; the preview replaces.
     */
    public function test_add_audience_changes_visibility(): void {
        $manager = $this->create_manager();
        $report = $this->create_report($manager);
        $viewer = $this->getDataGenerator()->create_user();
        $this->assertFalse(permission::can_view_report($report, (int)$viewer->id));

        $skill = new set_report_audience_skill();
        $dto = $skill->preflight(['reportquery' => 'Shared later', 'audience_type' => 'allusers'], $this->ctx(), (int)$manager->id);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $this->assertSame('add', $dto->preparedinput['action']);
        $card = $skill->describe_proposed_action($dto->preparedinput);
        $this->assertNotSame('', $card['title']);

        $result = $skill->execute($dto->preparedinput, $this->ctx(), (int)$manager->id);
        $this->assertSame('executed', $result['status']);
        $this->assertCount(1, $result['report']['audiences']);
        $this->assertSame('allusers', $result['report']['audiences'][0]['type']);
        $this->assertGreaterThan(0, $result['report']['audiences'][0]['usercount']);
        $this->assertStringContainsString('users covered:', $result['observation_full']);
        $this->assertSame([(int)$result['report']['audiences'][0]['id']], $result['produced_outputs']['audienceids']);

        \core_reportbuilder\local\helpers\audience::purge_caches();
        $this->assertTrue(permission::can_view_report(report::get_record(['id' => $report->get('id')]), (int)$viewer->id));

        $preview = $skill->get_result_preview($result, $this->ctx(), (int)$manager->id);
        $this->assertSame(report_preview_renderer::PREVIEW_TYPE, $preview['type']);
        $this->assertTrue($preview['replace']);
    }

    /**
     * A manual audience keeps the persons out of the observation (count only) while the report
     * details still show the audience with its count; removing works by audience id.
     */
    public function test_manual_audience_privacy_and_remove(): void {
        $manager = $this->create_manager();
        $report = $this->create_report($manager, 'Private circle');
        $person = $this->getDataGenerator()->create_user(['firstname' => 'Quirin', 'lastname' => 'Unusualname',
            'email' => 'quirin@example.com']);
        $skill = new set_report_audience_skill();

        $dto = $skill->preflight([
            'reportid' => (int)$report->get('id'),
            'audience_type' => 'manual',
            'userqueries' => ['quirin@example.com'],
        ], $this->ctx(), (int)$manager->id);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $this->assertSame([(int)$person->id], $dto->preparedinput['configdata']['users']);

        $result = $skill->execute($dto->preparedinput, $this->ctx(), (int)$manager->id);
        $this->assertSame('executed', $result['status']);
        $this->assertStringNotContainsString('Unusualname', $result['observation_full']);
        $this->assertStringNotContainsString('quirin@', $result['observation_full']);
        $this->assertStringContainsString('users covered: 1', $result['observation_full']);
        $audienceid = (int)$result['report']['audiences'][0]['id'];

        $details = (new get_report_details_skill())
            ->execute(['reportid' => (int)$report->get('id')], $this->ctx(), (int)$manager->id);
        $this->assertStringContainsString('users covered: 1', $details['observation_full']);
        $this->assertStringNotContainsString('Unusualname', $details['observation_full']);

        $remove = $skill->preflight([
            'reportid' => (int)$report->get('id'), 'action' => 'remove', 'audienceid' => $audienceid,
        ], $this->ctx(), (int)$manager->id);
        $this->assertSame('pass', $remove->status, json_encode($remove->issues));
        $removed = $skill->execute($remove->preparedinput, $this->ctx(), (int)$manager->id);
        $this->assertSame('executed', $removed['status']);
        $this->assertSame([], $removed['report']['audiences']);
    }

    /**
     * Clarifications: unknown type (options = types), unknown role (options = roles), ambiguous
     * person (options = candidates), unknown audience id on remove (options = the report's
     * audiences), missing type; nothing is written; no codes in the user text.
     */
    public function test_clarifications(): void {
        $manager = $this->create_manager();
        $report = $this->create_report($manager, 'Clarify me');
        $this->getDataGenerator()->create_user(['firstname' => 'Bo', 'lastname' => 'One']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bo', 'lastname' => 'Two']);
        $skill = new set_report_audience_skill();
        $rid = (int)$report->get('id');
        $uid = (int)$manager->id;

        $type = $skill->preflight(['reportid' => $rid, 'audience_type' => 'friends'], $this->ctx(), $uid);
        $this->assertSame(set_report_audience_skill::CODE_AUDIENCE_TYPE, $type->issues[0]['code']);
        $this->assertContains('allusers', array_column($type->issues[0]['options'], 'id'));

        $missing = $skill->preflight(['reportid' => $rid], $this->ctx(), $uid);
        $this->assertSame('needs_clarification', $missing->issues[0]['severity']);

        $role = $skill->preflight(['reportid' => $rid, 'audience_type' => 'systemrole', 'roles' => ['boss']], $this->ctx(), $uid);
        $this->assertSame(set_report_audience_skill::CODE_AUDIENCE_MEMBER, $role->issues[0]['code']);
        $this->assertContains('manager', array_column($role->issues[0]['options'], 'id'));
        $this->assertStringNotContainsString('AUDIENCE_', $role->issues[0]['message']);

        $person = $skill->preflight(['reportid' => $rid, 'audience_type' => 'manual', 'userqueries' => ['Bo']], $this->ctx(), $uid);
        $this->assertSame(set_report_audience_skill::CODE_AUDIENCE_MEMBER_AMBIGUOUS, $person->issues[0]['code']);
        $this->assertCount(2, $person->issues[0]['options']);

        $remove = $skill->preflight(['reportid' => $rid, 'action' => 'remove', 'audienceid' => 999], $this->ctx(), $uid);
        $this->assertSame(set_report_audience_skill::CODE_AUDIENCE_NOT_FOUND, $remove->issues[0]['code']);

        $this->assertSame(0, \core_reportbuilder\local\models\audience::count_records(['reportid' => $rid]));

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $denied = $skill->preflight(['reportid' => $rid, 'audience_type' => 'allusers'], $this->ctx(), (int)$user->id);
        $this->assertSame(report_skill_base::CODE_PERMISSION_DENIED, $denied->issues[0]['code']);
    }
}

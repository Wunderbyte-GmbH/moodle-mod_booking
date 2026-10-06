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
use bookingextension_agent\local\wizard\report\skills\query_report_skill;
use bookingextension_agent\local\wizard\report\skills\report_skill_base;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;
use context_system;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\user_filter_manager;

/**
 * Tests for report.query_report.
 *
 * Structural asserts only: the observation carries the row count, headers and applied filters and
 * NEVER a cell value (D9); filters are applied as the acting user's own filter values and change
 * the count; the rows appear in the live preview; unknown filters are clarifications/honest errors;
 * visibility follows core; the skill is MCP-exposable by default (D13).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\report\skills\query_report_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_query_skill_test extends advanced_testcase {
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
     * Card contract: R0, fences mutual with details and search, MCP-exposable by default.
     */
    public function test_card_contract_and_exposure(): void {
        $registry = skill_registry_factory::get_default();
        $bykey = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            $bykey[(string)$contract['skill']] = $contract;
        }
        $contract = $bykey[query_report_skill::SKILL_NAME];
        $this->assertTrue((bool)$contract['readonly']);
        $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['not']));
        $this->assertLessThanOrEqual(180, \core_text::strlen((string)$contract['message_triggers'][0]['description']));
        $this->assertStringContainsString('get_report_details', (string)$contract['not']);
        $this->assertStringContainsString('query_report', (string)$bykey['report.get_report_details']['not']);
        $this->assertStringContainsString('search_reports', (string)$contract['not']);
        $this->assertStringContainsString('query_report', (string)$bykey['report.search_reports']['not']);

        $skill = new query_report_skill();
        $this->assertTrue($skill->is_mcp_exposable(), 'exposed to MCP by default (decision D13)');
    }

    /**
     * The count is right, headers are listed, no cell value reaches the observation, the rows are
     * in the live preview; filters change the count and are stored as the user's filter values.
     */
    public function test_count_headers_and_no_rows(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $unusual = $this->getDataGenerator()->create_user(['firstname' => 'Xaver', 'lastname' => 'Rarename',
            'email' => 'xaver.rarename@example.com']);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'People', 'source' => self::USERS_SOURCE, 'default' => false]);
        $rid = (int)$report->get('id');
        $generator->create_column(['reportid' => $rid, 'uniqueidentifier' => 'user:fullname', 'heading' => 'Who']);
        $generator->create_column(['reportid' => $rid, 'uniqueidentifier' => 'user:email']);
        $generator->create_filter(['reportid' => $rid, 'uniqueidentifier' => 'user:fullname']);
        $skill = new query_report_skill();
        $uid = (int)$manager->id;

        $all = $skill->execute(['reportquery' => 'People'], $this->ctx(), $uid);
        $this->assertSame('executed', $all['status']);
        $total = (int)$all['rowcount'];
        $this->assertGreaterThanOrEqual(3, $total, 'admin, guest, manager, the unusual user');
        $this->assertSame(['Who', get_string('email')], $all['headers']);
        $this->assertSame([], $all['filters_applied']);
        $this->assertStringContainsString('rows: ' . $total, $all['observation_full']);
        $this->assertStringNotContainsString('Rarename', $all['observation_full'], 'no cell value in the observation');
        $this->assertStringNotContainsString('rarename@', $all['observation_full']);
        $this->assertStringNotContainsString('Rarename', json_encode($all['report']));

        $preview = $skill->get_result_preview($all, $this->ctx(), $uid);
        $this->assertSame(report_preview_renderer::PREVIEW_TYPE, $preview['type']);
        $this->assertTrue($preview['replace']);
        $this->assertStringContainsString('Rarename', $preview['html'], 'the rows live in the panel');

        $filtered = $skill->execute([
            'reportid' => $rid,
            'filters' => [['identifier' => 'user:fullname', 'operator' => 'CONTAINS', 'value' => 'Rarename']],
        ], $this->ctx(), $uid);
        $this->assertSame('executed', $filtered['status']);
        $this->assertSame(1, (int)$filtered['rowcount']);
        $this->assertSame('CONTAINS', $filtered['filters_applied'][0]['operator_key']);
        $stored = user_filter_manager::get($rid, $uid);
        $this->assertSame(text::CONTAINS, (int)$stored['user:fullname_operator']);
        $this->assertStringNotContainsString($unusual->email, $filtered['observation_full']);

        // Without a filters key the stored values stay and are described; an empty list clears them.
        $again = $skill->execute(['reportid' => $rid], $this->ctx(), $uid);
        $this->assertSame(1, (int)$again['rowcount']);
        $this->assertSame('CONTAINS', $again['filters_applied'][0]['operator_key']);
        $cleared = $skill->execute(['reportid' => $rid, 'filters' => []], $this->ctx(), $uid);
        $this->assertSame($total, (int)$cleared['rowcount']);
        $this->assertSame([], user_filter_manager::get($rid, $uid));
    }

    /**
     * Unknown filter identifiers and operators: clarification with options in preflight, honest
     * error naming the offered filters on the chat path; a user outside the audience gets nothing.
     */
    public function test_filter_validation_and_visibility(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Guarded', 'source' => self::USERS_SOURCE]);
        $rid = (int)$report->get('id');
        $skill = new query_report_skill();
        $uid = (int)$manager->id;

        $unknown = $skill->preflight(['reportid' => $rid, 'filters' => [['identifier' => 'user:nothing']]], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_FILTER_VALIDATION_ERROR, $unknown->issues[0]['code']);
        $this->assertContains('user:fullname', array_column($unknown->issues[0]['options'], 'id'));

        $badoperator = $skill->preflight([
            'reportid' => $rid, 'filters' => [['identifier' => 'user:fullname', 'operator' => 'SOUNDS_LIKE']],
        ], $this->ctx(), $uid);
        $this->assertSame(report_skill_base::CODE_CONDITION_VALUE_VALIDATION_ERROR, $badoperator->issues[0]['code']);
        $this->assertContains('CONTAINS', array_column($badoperator->issues[0]['options'], 'id'));

        $chat = $skill->execute(['reportid' => $rid, 'filters' => [['identifier' => 'user:nothing']]], $this->ctx(), $uid);
        $this->assertSame('error', $chat['status']);
        $this->assertStringContainsString('user:fullname', $chat['observation_full']);
        $this->assertStringNotContainsString('REPORT_', $chat['usermessage']);

        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $denied = $skill->execute(['reportid' => $rid], $this->ctx(), (int)$outsider->id);
        $this->assertSame('error', $denied['status']);
        $this->assertNull($skill->get_result_preview(['report' => ['id' => $rid]], $this->ctx(), (int)$outsider->id));
    }
}

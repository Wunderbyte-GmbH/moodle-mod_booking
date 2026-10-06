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
use bookingextension_agent\local\wizard\report\skills\describe_report_source_skill;
use bookingextension_agent\local\wizard\report\skills\list_report_sources_skill;
use bookingextension_agent\local\wizard\report\skills\report_skill_base;
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;
use context_system;

/**
 * Tests for the report discovery skills (report.list_report_sources, report.describe_report_source).
 *
 * Structural asserts only: registry membership, card contract, result shape, honest error and
 * clarification paths, no issue codes in user text, budget.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\report\skills\list_report_sources_skill
 * @covers     \bookingextension_agent\local\wizard\report\skills\describe_report_source_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_discovery_skills_test extends advanced_testcase {
    /** FQCN of the users datasource core ships. */
    private const USERS_SOURCE = 'core_user\\reportbuilder\\datasource\\users';

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        report_source_catalog_service::reset_caches();
        skill_registry_factory::reset();
    }

    /**
     * A user holding the manager role in the system context (core grants moodle/reportbuilder:* to managers).
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
     * Both skills are registered under the reserved namespace and have their capability declared.
     */
    public function test_skills_are_registered_with_declared_capabilities(): void {
        $registry = skill_registry_factory::get_default();
        $names = $registry->get_skill_names();
        $this->assertContains(list_report_sources_skill::SKILL_NAME, $names);
        $this->assertContains(describe_report_source_skill::SKILL_NAME, $names);

        $capabilities = load_capability_def('bookingextension_agent');
        $this->assertArrayHasKey('bookingextension/agent:skill_report_list_report_sources', $capabilities);
        $this->assertArrayHasKey('bookingextension/agent:skill_report_describe_report_source', $capabilities);
        $this->assertSame(CONTEXT_SYSTEM, $capabilities['bookingextension/agent:skill_report_list_report_sources']['contextlevel']);
    }

    /**
     * The card contract of the family: clauses within budget, a WHEN line, mutual fences, no sibling
     * name in the embedded description, English utterances present.
     */
    public function test_card_contract(): void {
        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();
        $bykey = [];
        foreach ($contracts as $contract) {
            $bykey[(string)$contract['skill']] = $contract;
        }
        $diagnostics = method_exists($registry, 'get_contract_diagnostics') ? (array)$registry->get_contract_diagnostics() : [];
        $this->assertArrayHasKey(
            list_report_sources_skill::SKILL_NAME,
            $bykey,
            'diagnostics: ' . implode(
                ' || ',
                array_filter($diagnostics, static fn($d): bool => stripos((string)$d, 'report') !== false)
            )
        );
        $this->assertArrayHasKey(describe_report_source_skill::SKILL_NAME, $bykey);
        $list = $bykey[list_report_sources_skill::SKILL_NAME];
        $describe = $bykey[describe_report_source_skill::SKILL_NAME];

        foreach ([$list, $describe] as $contract) {
            $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['is']));
            $this->assertLessThanOrEqual(160, \core_text::strlen((string)$contract['not']));
            $when = (string)($contract['message_triggers'][0]['description'] ?? '');
            $this->assertNotSame('', $when, $contract['skill'] . ' has no WHEN line');
            $this->assertLessThanOrEqual(180, \core_text::strlen($when));
            $this->assertNotEmpty($contract['example_utterances']);
            $this->assertTrue((bool)$contract['readonly']);
            $this->assertContains('system', (array)$contract['context_scopes']);
            $this->assertStringNotContainsString('report.', (string)$contract['description'], 'no sibling name in the description');
        }

        // Mutual fence between the two discovery skills (wave 20 rule).
        $this->assertStringContainsString('describe_report_source', (string)$list['not']);
        $this->assertStringContainsString('list_report_sources', (string)$describe['not']);

        // The describe skill requires a source; the list skill accepts an empty input.
        $this->assertContains('source', (array)$describe['required_input']);
        $this->assertTrue((bool)$list['accepts_empty_input']);
    }

    /**
     * A manager lists the sources: structured result, observation with exact identifiers, replacing preview.
     */
    public function test_list_sources_as_manager(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new list_report_sources_skill();

        $result = $skill->execute([], (int)context_system::instance()->id, (int)$manager->id);

        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($result['sources']);
        $this->assertContains(self::USERS_SOURCE, array_column($result['sources'], 'source'));
        $this->assertStringContainsString('[source: ' . self::USERS_SOURCE . ']', $result['observation_full']);
        $this->assertStringNotContainsString('entities:', $result['observation_full'], 'plain list does not instantiate');

        $detailed = $skill->execute(
            ['component' => 'core_user', 'include_entities' => true],
            (int)context_system::instance()->id,
            (int)$manager->id
        );
        $this->assertStringContainsString('entities: user', $detailed['observation_full']);
        $this->assertStringContainsString('columns: ', $detailed['observation_full']);

        $preview = $skill->get_result_preview($result, (int)context_system::instance()->id, (int)$manager->id);
        $this->assertSame(report_skill_base::PREVIEW_TYPE_SOURCES, $preview['type']);
        $this->assertTrue($preview['replace']);
        $this->assertStringContainsString(self::USERS_SOURCE, $preview['html']);
    }

    /**
     * The component filter narrows to one plugin; an unknown component yields an honest empty result.
     */
    public function test_list_sources_component_filter(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new list_report_sources_skill();
        $ctx = (int)context_system::instance()->id;

        $result = $skill->execute(['component' => 'core_user'], $ctx, (int)$manager->id);
        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($result['sources']);
        foreach ($result['sources'] as $source) {
            $this->assertSame('core_user', $source['component']);
        }

        $empty = $skill->execute(['component' => 'local_nosuchplugin'], $ctx, (int)$manager->id);
        $this->assertSame('executed', $empty['status']);
        $this->assertSame([], $empty['sources']);
        $this->assertNull($skill->get_result_preview($empty, $ctx, (int)$manager->id));
    }

    /**
     * Without Report Builder access the chat path ends as an honest error without codes, and the
     * preflight path (MCP) as a clarification carrying the permission code only in the structure.
     */
    public function test_without_access_is_honest(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $ctx = (int)context_system::instance()->id;

        foreach ([new list_report_sources_skill(), new describe_report_source_skill()] as $skill) {
            $result = $skill->execute(['source' => self::USERS_SOURCE], $ctx, (int)$user->id);
            $this->assertSame('error', $result['status'], $skill->get_name());
            $this->assertNotSame('', $result['usermessage']);
            $this->assertStringNotContainsString('REPORT_', $result['usermessage']);
            $this->assertStringNotContainsString('report.', $result['usermessage']);

            $dto = $skill->preflight(['source' => self::USERS_SOURCE], $ctx, (int)$user->id);
            $this->assertNotSame('pass', $dto->status, $skill->get_name());
            $this->assertSame(report_skill_base::CODE_PERMISSION_DENIED, $dto->issues[0]['code']);
            $this->assertSame('needs_clarification', $dto->issues[0]['severity']);
            $this->assertStringNotContainsString('REPORT_', $dto->issues[0]['message']);
        }
    }

    /**
     * An unknown source is a clarification with the candidate list as options and as a preview
     * card list (source C); a missing source likewise. No code or field list reaches the message.
     */
    public function test_describe_unknown_source_asks_with_candidates(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new describe_report_source_skill();
        $ctx = (int)context_system::instance()->id;

        $dto = $skill->preflight(['source' => 'no such source'], $ctx, (int)$manager->id);
        $this->assertNotSame('pass', $dto->status);
        $issue = $dto->issues[0];
        $this->assertSame(report_skill_base::CODE_SOURCE_VALIDATION_ERROR, $issue['code']);
        $this->assertSame('needs_clarification', $issue['severity']);
        $this->assertNotEmpty($issue['options']);
        $this->assertContains(self::USERS_SOURCE, array_column($issue['options'], 'id'));
        $this->assertSame(report_skill_base::PREVIEW_TYPE_SOURCES, $issue['preview']['type']);
        $this->assertStringContainsString(self::USERS_SOURCE, $issue['preview']['html']);
        $this->assertStringNotContainsString('REPORT_', $issue['message']);
        $this->assertStringNotContainsString('section', $issue['message'], 'no schema field list in the user text');

        $missing = $skill->preflight([], $ctx, (int)$manager->id);
        $this->assertSame(report_skill_base::CODE_MISSING_SOURCE, $missing->issues[0]['code']);
        $this->assertNotEmpty($missing->issues[0]['options']);

        // The chat path (no preflight) is an honest error naming the available sources.
        $result = $skill->execute(['source' => 'no such source'], $ctx, (int)$manager->id);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString(get_string('users'), $result['observation_full']);
        $this->assertStringNotContainsString('REPORT_', $result['usermessage']);
    }

    /**
     * Preflight resolves the localised name to the FQCN in the prepared input; execute returns
     * the identifiers the authoring skills will accept, plus a replacing detail preview.
     */
    public function test_describe_resolves_and_executes(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new describe_report_source_skill();
        $ctx = (int)context_system::instance()->id;

        $dto = $skill->preflight(['source' => get_string('users'), 'section' => 'columns'], $ctx, (int)$manager->id);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $this->assertSame(self::USERS_SOURCE, $dto->preparedinput['source']);

        $start = microtime(true);
        $result = $skill->execute($dto->preparedinput, $ctx, (int)$manager->id);
        $elapsed = (microtime(true) - $start) * 1000;
        $this->assertLessThan(2000, $elapsed);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(self::USERS_SOURCE, $result['source']);
        $this->assertNotEmpty($result['columns']);
        $this->assertSame([], $result['filters'], 'section=columns returns no filters');
        $this->assertStringContainsString('- user:fullname |', $result['observation_full']);
        $this->assertStringContainsString('default columns:', $result['observation_full']);

        $preview = $skill->get_result_preview($result, $ctx, (int)$manager->id);
        $this->assertSame(describe_report_source_skill::PREVIEW_TYPE_DETAIL, $preview['type']);
        $this->assertTrue($preview['replace']);
        $this->assertStringContainsString('user:fullname', $preview['html']);

        // Full description lists operators with their enum keys in the observation.
        $full = $skill->execute(['source' => self::USERS_SOURCE], $ctx, (int)$manager->id);
        $this->assertStringContainsString('operators: ', $full['observation_full']);
        $this->assertStringContainsString('IS_EQUAL_TO', $full['observation_full']);
    }

    /**
     * An invalid section is a structural error the engine turns into a clarification, not an error.
     */
    public function test_invalid_section_is_a_clarification(): void {
        $manager = $this->create_manager();
        $this->setUser($manager);
        $skill = new describe_report_source_skill();

        $structure = $skill->check_structure(['source' => self::USERS_SOURCE, 'section' => 'everything']);
        $this->assertFalse($structure['valid']);

        $dto = $skill->preflight(
            ['source' => self::USERS_SOURCE, 'section' => 'everything'],
            (int)context_system::instance()->id,
            (int)$manager->id
        );
        $this->assertNotSame('pass', $dto->status);
        $this->assertSame('needs_clarification', $dto->issues[0]['severity']);
    }

    /**
     * The construction hints of describe name real sources of this site; list has none.
     */
    public function test_dynamic_construction_hints(): void {
        $manager = $this->create_manager();
        $ctx = (int)context_system::instance()->id;

        $hints = (new describe_report_source_skill())->get_dynamic_construction_hints($ctx, (int)$manager->id);
        $this->assertNotEmpty($hints['guidance']);
        $this->assertStringContainsString(self::USERS_SOURCE, implode(' ', $hints['guidance']));
        $this->assertArrayHasKey('source', $hints['example_parameters']);

        $this->assertSame([], (new list_report_sources_skill())->get_dynamic_construction_hints($ctx, (int)$manager->id));
    }
}

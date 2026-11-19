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
use bookingextension_agent\local\wizard\services\reportbuilder\report_source_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;
use context_system;

/**
 * update_report resolves a close column identifier itself and offers candidates when it cannot; the source
 * clarification offers its choice the same way.
 *
 * demo2 thread 21 (2026-11-04, #2763): the constructor wrote "enrol:enrolmentdate", the clarification said "pick one
 * of the available columns" and offered none, because the skill stored its choices under `options` while the engine
 * (agent_decision_service::offered_choices, wave 30) reads `candidates`. Structural checks only: status, issue code,
 * candidate count and shape, the resolved identifier.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\report\skills\update_report_skill
 * @covers     \bookingextension_agent\local\wizard\report\skills\report_skill_base
 * @covers     \bookingextension_agent\local\wizard\report\skills\create_report_skill
 */
final class report_update_column_resolution_test extends advanced_testcase {
    /** FQCN of the users datasource core ships. */
    private const USERS_SOURCE = 'core_user\\reportbuilder\\datasource\\users';

    /** @var int Id of the report under test. */
    private int $reportid = 0;

    /** @var int Id of the manager editing it. */
    private int $userid = 0;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        report_source_catalog_service::reset_caches();
        skill_registry_factory::reset();
        $user = $this->getDataGenerator()->create_user();
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($roleid, (int)$user->id, context_system::instance()->id);
        $this->setUser($user);
        $this->userid = (int)$user->id;
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Plattformuser', 'source' => self::USERS_SOURCE]);
        $this->reportid = (int)$report->get('id');
    }

    /**
     * Preflight of update_report with the given changes.
     *
     * @param array $changes
     * @return object
     */
    private function preflight(array $changes): object {
        return (new update_report_skill())->preflight(
            array_merge(['reportid' => $this->reportid], $changes),
            (int)context_system::instance()->id,
            $this->userid
        );
    }

    /**
     * An identifier whose tokens all point at one available column is taken as that column.
     */
    public function test_close_identifier_is_resolved_to_the_one_matching_column(): void {
        $dto = $this->preflight(['add_columns' => [['identifier' => 'user:email_address']]]);
        $this->assertSame('pass', $dto->status, json_encode($dto->issues));
        $adds = array_values(array_filter(
            $dto->preparedinput['resolved']['operations'],
            static fn(array $op): bool => $op['op'] === 'add_column'
        ));
        $this->assertCount(1, $adds);
        $this->assertSame('user:email', $adds[0]['item']['identifier']);
    }

    /**
     * An identifier the source does not offer and cannot resolve ends in a clarification that carries candidates
     * under the engine's key, the same rows as the skill's own options, capped.
     */
    public function test_unknown_identifier_offers_candidates(): void {
        $dto = $this->preflight(['add_columns' => [['identifier' => 'enrol:enrolmentdate']]]);
        $this->assertNotSame('pass', $dto->status);
        $issue = $dto->issues[0];
        $this->assertSame(report_skill_base::CODE_COLUMN_VALIDATION_ERROR, $issue['code']);
        $this->assertSame('needs_clarification', $issue['severity']);
        $this->assertNotEmpty($issue['candidates'] ?? [], 'the clarification must offer a choice');
        $this->assertLessThanOrEqual(50, count($issue['candidates']));
        $optionids = array_column($issue['options'], 'id');
        foreach ($issue['candidates'] as $candidate) {
            $this->assertIsArray($candidate);
            $this->assertNotSame('', (string)($candidate['id'] ?? ''));
            $this->assertNotSame('', (string)($candidate['label'] ?? ''));
            $this->assertContains($candidate['id'], $optionids);
        }
    }

    /**
     * A value with tokens the columns carry narrows the candidates to those columns, best match first.
     */
    public function test_candidates_are_narrowed_to_the_tokens_of_the_value(): void {
        $dto = $this->preflight(['add_columns' => [['identifier' => 'user:phone_or_mobile_number']]]);
        $this->assertNotSame('pass', $dto->status);
        $candidates = array_column($dto->issues[0]['candidates'] ?? [], 'id');
        $this->assertNotEmpty($candidates);
        $this->assertSame('user:phone2', $candidates[0], 'mobile phone carries three of the four tokens');
        $this->assertContains('user:phone1', $candidates);
        $this->assertContains('user:idnumber', $candidates);
        $this->assertNotContains('user:fullname', $candidates);
    }

    /**
     * The source clarification carries its choice under the engine's key as well (live probe 2026-11-09: "Datenquelle
     * Kursteilnehmer" ended in "pick one of the available sources" with nothing to pick).
     */
    public function test_unknown_source_offers_candidates(): void {
        $dto = (new create_report_skill())->preflight(
            ['name' => 'Plattformuser', 'source' => 'Kursteilnehmer'],
            (int)context_system::instance()->id,
            $this->userid
        );
        $this->assertNotSame('pass', $dto->status);
        $issue = $dto->issues[0];
        $this->assertSame(report_skill_base::CODE_SOURCE_VALIDATION_ERROR, $issue['code']);
        $this->assertNotEmpty($issue['candidates'] ?? [], 'the source clarification must offer a choice');
        $this->assertLessThanOrEqual(50, count($issue['candidates']));
        $optionids = array_column($issue['options'], 'id');
        foreach ($issue['candidates'] as $candidate) {
            $this->assertContains($candidate['id'], $optionids);
        }
    }

    /**
     * The shared clarification path serves the remove side too.
     */
    public function test_removing_an_unknown_column_offers_candidates(): void {
        $dto = $this->preflight(['remove_columns' => ['user:nonexistent']]);
        $this->assertSame(report_skill_base::CODE_COLUMN_VALIDATION_ERROR, $dto->issues[0]['code']);
        $this->assertNotEmpty($dto->issues[0]['candidates'] ?? []);
    }
}

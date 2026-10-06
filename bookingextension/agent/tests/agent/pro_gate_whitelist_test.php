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
 * PRO-gate whitelist policy: only Wunderbyte's own write skills are gated, third-party ones never.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\agent_access_service;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\skill_contract_validator;

/**
 * Deterministic coverage for the full-access (PRO) whitelist.
 *
 * These assertions are license-independent: the predicate under test is pure, and the catalog
 * split operates on a hand-built contract array. That matters because in PHPUnit the license
 * check always reports "activated" (wb_license), so the gate branch is otherwise never exercised.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\agent_access_service::skill_requires_full_access
 */
final class pro_gate_whitelist_test extends \advanced_testcase {
    /**
     * Read-only skills are never gated, regardless of component.
     *
     * @dataProvider component_provider
     * @param string $component Any component identifier.
     */
    public function test_readonly_skill_never_requires_full_access(string $component): void {
        $this->assertFalse(
            agent_access_service::skill_requires_full_access(true, $component),
            "Read-only skill of '$component' must never be PRO-gated."
        );
    }

    /**
     * Every component identifier that must NOT change the read-only verdict.
     *
     * @return array<string, array{0: string}>
     */
    public static function component_provider(): array {
        return [
            'mod_booking path' => ['mod/booking'],
            'mod_booking frankenstyle' => ['mod_booking'],
            'local_shopping_cart' => ['local/shopping_cart'],
            'local_entities' => ['local/entities'],
            'third-party plugin' => ['local/foo'],
            'core mod' => ['mod/quiz'],
            'empty' => [''],
        ];
    }

    /**
     * The write skills of Wunderbyte's own gated components require full access.
     *
     * Path form and frankenstyle are equivalent, and matching is case/whitespace insensitive.
     *
     * @dataProvider gated_write_component_provider
     * @param string $component A gated Wunderbyte component identifier.
     */
    public function test_wunderbyte_write_skills_require_full_access(string $component): void {
        $this->assertTrue(
            agent_access_service::skill_requires_full_access(false, $component),
            "Write skill of Wunderbyte component '$component' must be PRO-gated."
        );
    }

    /**
     * The three gated Wunderbyte components in their real and normalisation-edge forms.
     *
     * @return array<string, array{0: string}>
     */
    public static function gated_write_component_provider(): array {
        return [
            'mod_booking path' => ['mod/booking'],
            'mod_booking frankenstyle' => ['mod_booking'],
            'mod_booking uppercase' => ['MOD/Booking'],
            'mod_booking padded' => ['  mod/booking  '],
            'shopping_cart path' => ['local/shopping_cart'],
            'shopping_cart frankenstyle' => ['local_shopping_cart'],
            'entities path' => ['local/entities'],
            'entities frankenstyle' => ['local_entities'],
        ];
    }

    /**
     * Third-party write skills are NEVER gated — this is the core guarantee of the change.
     *
     * @dataProvider third_party_write_component_provider
     * @param string $component A non-Wunderbyte component identifier.
     */
    public function test_third_party_write_skills_never_require_full_access(string $component): void {
        $this->assertFalse(
            agent_access_service::skill_requires_full_access(false, $component),
            "Write skill of third-party component '$component' must NOT be PRO-gated."
        );
    }

    /**
     * A spread of components outside the allow-list, including near-misses and the engine itself.
     *
     * @return array<string, array{0: string}>
     */
    public static function third_party_write_component_provider(): array {
        return [
            'unrelated local plugin' => ['local/foo'],
            'a course module' => ['mod/quiz'],
            'local_musi' => ['local/musi'],
            'the agent engine itself' => ['bookingextension/agent'],
            'a substring near-miss' => ['local/shopping_cart_extra'],
            'empty component' => [''],
        ];
    }

    /**
     * The catalog partition routes contracts by their executability verdicts: allowed skills stay
     * selectable, PRO-denied ones land in "locked" with the upgrade notice, every other denied
     * skill (inactive, missing capability, …) is hidden from both lists, and a contract without a
     * verdict is treated as denied (fail closed).
     *
     * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service::partition_prompt_contracts_by_executability
     */
    public function test_catalog_partition_routes_by_verdicts(): void {
        $service = new planner_catalog_service(new assistant_state_guidance_service());

        $contracts = [
            ['skill' => 'mod_booking.read_only', 'readonly' => true, 'component' => 'mod/booking', 'description' => 'r'],
            ['skill' => 'mod_booking.create_option', 'readonly' => false, 'component' => 'mod/booking', 'description' => 'w'],
            ['skill' => 'thirdparty.do_write', 'readonly' => false, 'component' => 'local/foo', 'description' => 'w'],
            ['skill' => 'course.enrol_user', 'readonly' => false, 'component' => 'bookingextension/agent', 'description' => 'w'],
            ['skill' => 'core.no_permission', 'readonly' => true, 'component' => 'bookingextension/agent', 'description' => 'r'],
            ['skill' => 'wizard.no_verdict', 'readonly' => true, 'component' => 'bookingextension/agent', 'description' => 'r'],
        ];

        $verdicts = [
            'mod_booking.read_only' => ['executable_state' => 'allow', 'deny_reason' => ''],
            'mod_booking.create_option' => [
                'executable_state' => 'deny',
                'deny_reason' => skill_contract_validator::DENY_REQUIRES_PRO,
            ],
            'thirdparty.do_write' => ['executable_state' => 'allow', 'deny_reason' => ''],
            'course.enrol_user' => [
                'executable_state' => 'deny',
                'deny_reason' => skill_contract_validator::DENY_INACTIVE,
            ],
            'core.no_permission' => [
                'executable_state' => 'deny',
                'deny_reason' => skill_contract_validator::DENY_MISSING_CAPABILITY,
            ],
        ];

        [$available, $locked] = $service->partition_prompt_contracts_by_executability($contracts, $verdicts);

        $availableskills = array_column($available, 'skill');
        $lockedskills = array_column($locked, 'skill');

        $this->assertContains('mod_booking.read_only', $availableskills, 'Allowed read-only skill must stay selectable.');
        $this->assertContains('thirdparty.do_write', $availableskills, 'Allowed third-party write skill must stay selectable.');
        $this->assertContains('mod_booking.create_option', $lockedskills, 'PRO-denied write skill must be locked.');
        $this->assertNotContains('mod_booking.create_option', $availableskills);

        // Issue #2223: a skill denied for any non-PRO reason is hidden entirely — never selectable,
        // never advertised as merely "locked".
        foreach (['course.enrol_user', 'core.no_permission', 'wizard.no_verdict'] as $hidden) {
            $this->assertNotContains($hidden, $availableskills, "$hidden must not be selectable.");
            $this->assertNotContains($hidden, $lockedskills, "$hidden must not appear in the locked list.");
        }

        // The locked contract carries the upgrade notice so the truncating renderer keeps it.
        $lockedcontract = array_values(array_filter(
            $locked,
            static fn(array $c): bool => $c['skill'] === 'mod_booking.create_option'
        ))[0];
        $this->assertStringContainsString('Locked', (string)$lockedcontract['description']);
    }

    /**
     * Catalog rows from availability-agnostic sources (embeddings top-k, discovery caches) are
     * intersected with the selectable skill set: rows for unknown/denied skills are dropped.
     *
     * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service::filter_catalog_rows_to_skills
     */
    public function test_filter_catalog_rows_to_skills(): void {
        $service = new planner_catalog_service(new assistant_state_guidance_service());

        $rows = [
            ['skill' => 'course.create_course', 'score' => 0.9],
            ['skill' => 'course.enrol_user', 'score' => 0.8],
            ['skill' => '', 'score' => 0.7],
            'not-an-array',
            ['noskillkey' => true],
        ];

        $filtered = $service->filter_catalog_rows_to_skills($rows, ['course.create_course', 'wizard.search_skills']);

        $this->assertSame(['course.create_course'], array_column($filtered, 'skill'));

        // The allowed set also accepts key-indexed sets (skill => true).
        $filtered = $service->filter_catalog_rows_to_skills($rows, ['course.enrol_user' => true]);
        $this->assertSame(['course.enrol_user'], array_column($filtered, 'skill'));

        $this->assertSame([], $service->filter_catalog_rows_to_skills($rows, []));
    }
}

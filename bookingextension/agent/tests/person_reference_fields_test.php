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
 * Person-reference fields of skills (anonymizer collision gate input, #2363 / F23).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\core\skills\search_users_skill;
use bookingextension_agent\local\wizard\course\skills\enrol_user_skill;
use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * The collision gate triggers on the FIELD a suspect token is bound to (#2363): a field
 * resolves persons by the naming convention (userquery, teacherquery, targetuserquery,
 * *userquery) or because the skill declares it via the duck-typed
 * get_person_reference_fields(). The former skill attribute is_person_centric_readonly()
 * is retired — nothing in the engine read it since #2363.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\core\skills\search_users_skill
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class person_reference_fields_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Fields a skill declares as person lookups (empty when it declares none).
     *
     * @param object $skill
     * @return string[]
     */
    private function declared(object $skill): array {
        return method_exists($skill, 'get_person_reference_fields')
            ? array_values(array_map('strval', (array)$skill->get_person_reference_fields()))
            : [];
    }

    /**
     * core.search_users declares its free-text query — the F23 leak path (thread 1295).
     */
    public function test_search_users_declares_its_query(): void {
        $skill = new search_users_skill();

        $this->assertSame(['query'], $this->declared($skill));
        $this->assertArrayHasKey('query', (array)($skill->get_schema()['properties'] ?? []));
        $this->assertFalse(
            method_exists($skill, 'is_person_centric_readonly'),
            'The retired attribute is no gate input since #2363 and must not come back as dead code.'
        );
    }

    /**
     * The booking diagnose skills carry their person in userquery — covered by the convention.
     */
    public function test_diagnose_skills_person_field_follows_the_convention(): void {
        $anonymizer = new privacy_anonymizer(new conversation_store());
        $this->assertTrue($anonymizer->is_person_reference_field('userquery'));

        $classes = [
            \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill::class,
            \mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill::class,
            \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill::class,
        ];
        foreach ($classes as $class) {
            $properties = (array)((new $class())->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey('userquery', $properties, $class . ' must expose its person as userquery.');
        }
    }

    /**
     * Non-person searches and person mutations declare nothing; a bare "query" is no person field.
     */
    public function test_nonperson_search_and_mutation_declare_nothing(): void {
        $this->assertSame([], $this->declared(new \mod_booking\local\wizard\options\skills\search_options_skill()));
        $this->assertSame([], $this->declared(new enrol_user_skill()));

        $anonymizer = new privacy_anonymizer(new conversation_store());
        $this->assertFalse($anonymizer->is_person_reference_field('query'));
        $this->assertFalse($anonymizer->is_person_reference_field('optionquery'));
    }
}

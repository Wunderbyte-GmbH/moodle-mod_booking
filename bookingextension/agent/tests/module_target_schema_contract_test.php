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
 * Contract between module_targeted_skill and the skill schema (#2364).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\construction\parameter_contract_validator;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * module_targeted_skill::get_target_selector() reads cmid and activityquery from the command
 * input. Since #2364 the engine rejects every input key the schema does not declare, so a skill
 * that uses the trait but omits one of the two from its schema silently loses that targeting
 * path — thread 1304: all six booking skills with the trait lacked cmid, the constructor built
 * {"cmid":4} twice and the validator dropped it. Generic over the registry: every skill whose
 * target selector IS the trait implementation must declare both keys.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class module_target_schema_contract_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Every skill whose target selector comes from module_targeted_skill declares cmid and
     * activityquery, and the engine key check accepts both.
     */
    public function test_trait_selector_keys_are_declared_in_the_schema(): void {
        $this->resetAfterTest();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();

        $checked = [];
        foreach (skill_registry::make_default()->get_skills() as $skill) {
            if (!is_object($skill) || !method_exists($skill, 'get_target_selector')) {
                continue;
            }
            $file = (string)(new \ReflectionMethod($skill, 'get_target_selector'))->getFileName();
            if (basename($file) !== 'module_targeted_skill.php') {
                continue;
            }
            $name = (string)$skill->get_name();
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            foreach (['cmid', 'activityquery'] as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $properties,
                    "$name reads $key through module_targeted_skill but its schema lacks it"
                );
            }
            $check = parameter_contract_validator::check_input_keys($skill, ['cmid' => 4, 'activityquery' => 'booking']);
            $this->assertSame([], $check['unknown'], "$name: the engine key check must accept the target keys");
            $checked[] = $name;
        }

        $this->assertGreaterThanOrEqual(
            6,
            count($checked),
            'The six booking skills with the trait are covered: ' . implode(', ', $checked)
        );
    }
}

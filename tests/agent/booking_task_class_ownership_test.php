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
 * Tests that booking task classes own schema/validation responsibilities.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\booking\tasks\bulk_update_options_task;
use mod_booking\local\wbagent\booking\tasks\create_option_task;
use mod_booking\local\wbagent\booking\tasks\get_current_user_task;
use mod_booking\local\wbagent\booking\tasks\list_actions_task;
use mod_booking\local\wbagent\booking\tasks\list_option_properties_task;
use mod_booking\local\wbagent\booking\tasks\search_courses_task;
use mod_booking\local\wbagent\booking\tasks\search_options_task;
use mod_booking\local\wbagent\booking\tasks\search_users_task;
use mod_booking\local\wbagent\booking\tasks\update_option_task;

/**
 * Ensures task classes own schema and validation behavior.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
 */
final class booking_task_class_ownership_test extends advanced_testcase {
    /**
     * Data provider for tasks expected to declare get_schema() directly.
     *
     * @return array
     */
    public static function schema_owner_provider(): array {
        return [
            [create_option_task::class],
            [update_option_task::class],
            [bulk_update_options_task::class],
            [search_options_task::class],
            [search_users_task::class],
            [search_courses_task::class],
            [list_option_properties_task::class],
            [list_actions_task::class],
            [get_current_user_task::class],
        ];
    }

    /**
     * Data provider for tasks expected to declare validate() directly.
     *
     * @return array
     */
    public static function validate_owner_provider(): array {
        return self::schema_owner_provider();
    }

    /**
     * Task classes should define get_schema() in their own class.
     *
     * @dataProvider schema_owner_provider
     * @param string $classname
     */
    public function test_task_declares_own_schema_method(string $classname): void {
        $reflection = new \ReflectionClass($classname);
        $method = $reflection->getMethod('get_schema');

        $this->assertSame(
            $classname,
            $method->getDeclaringClass()->getName(),
            'Expected get_schema() to be declared on task class itself for ' . $classname
        );
    }

    /**
     * Task classes should define validate() in their own class.
     *
     * @dataProvider validate_owner_provider
     * @param string $classname
     */
    public function test_task_declares_own_validate_method(string $classname): void {
        $reflection = new \ReflectionClass($classname);
        $method = $reflection->getMethod('validate');

        $this->assertSame(
            $classname,
            $method->getDeclaringClass()->getName(),
            'Expected validate() to be declared on task class itself for ' . $classname
        );
    }

    /**
     * Data provider for tasks expected to declare check_structure().
     *
     * @return array
     */
    public static function check_structure_owner_provider(): array {
        return [
            [create_option_task::class],
            [update_option_task::class],
            [bulk_update_options_task::class],
        ];
    }

    /**
     * Mutating task classes should define check_structure() in their own class.
     *
     * @dataProvider check_structure_owner_provider
     * @param string $classname
     */
    public function test_mutating_task_declares_own_check_structure_method(string $classname): void {
        $reflection = new \ReflectionClass($classname);
        $method = $reflection->getMethod('check_structure');

        $this->assertSame(
            $classname,
            $method->getDeclaringClass()->getName(),
            'Expected check_structure() to be declared on task class itself for ' . $classname
        );
    }

    /**
     * Data provider for tasks expected to declare preflight().
     *
     * @return array
     */
    public static function preflight_owner_provider(): array {
        return [
            [create_option_task::class],
            [update_option_task::class],
            [bulk_update_options_task::class],
        ];
    }

    /**
     * Mutating task classes should define preflight() in their own class.
     *
     * @dataProvider preflight_owner_provider
     * @param string $classname
     */
    public function test_mutating_task_declares_own_preflight_method(string $classname): void {
        $reflection = new \ReflectionClass($classname);
        $method = $reflection->getMethod('preflight');

        $this->assertSame(
            $classname,
            $method->getDeclaringClass()->getName(),
            'Expected preflight() to be declared on task class itself for ' . $classname
        );
    }
}

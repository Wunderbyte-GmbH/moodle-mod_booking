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
use ReflectionMethod;
use mod_booking\local\wizard\options\skills\update_option_trainer_skill;

/**
 * Robustness: a query field the planner sends as an array must not become the literal "Array".
 *
 * Full-run 2026-07-14 (all_skills real-LLM, update_option_trainer): the model emitted optionquery as
 * an array; casting it with (string) raised an "Array to string conversion" warning and produced the
 * string "Array", which never resolved ("No option matched optionquery 'Array'"). scalar_string()
 * coerces non-scalars to an empty string so the skill clarifies cleanly instead.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\booking_skill_base::scalar_string
 */
final class update_option_trainer_input_robustness_test extends advanced_testcase {
    /**
     * scalar_string coerces non-strings to '' and passes strings through.
     */
    public function test_scalar_string_coerces_non_strings(): void {
        $method = new ReflectionMethod(update_option_trainer_skill::class, 'scalar_string');
        $method->setAccessible(true);

        $this->assertSame('', $method->invoke(null, ['unexpected', 'array']));
        $this->assertSame('', $method->invoke(null, null));
        $this->assertSame('foo', $method->invoke(null, 'foo'));
        $this->assertSame('5', $method->invoke(null, 5));
    }

    /**
     * An array optionquery/teacherquery must not surface as the string "Array" (nor warn).
     */
    public function test_array_query_does_not_leak_the_string_array(): void {
        $identity = (new update_option_trainer_skill())->build_queue_business_identity([
            'optionquery' => ['unexpected', 'array'],
            'teacherquery' => ['also', 'array'],
        ]);

        $this->assertStringNotContainsString(
            'Array',
            (string)json_encode($identity),
            'An array query value must be coerced to empty, never rendered as the literal "Array".'
        );
    }
}

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
 * No skill's example input shows a concrete date or time the constructor could copy.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\skill_registry;

/**
 * Wave 30 (UO-3, Nachlauf 32 threads 11970/11996, George 2026-09-25): update_option's example session "18:00-20:00"
 * became the time of a session the user never gave. Examples anchor the SHAPE; a date or time is shown as a
 * placeholder (YYYY-MM-DD, HH:MM). This scans the example inputs of every registered skill - engine data, not user text.
 *
 * @group bookingextension_agent
 * @covers \bookingextension_agent\local\wizard\skill_registry
 */
final class example_inputs_show_no_copyable_time_test extends \advanced_testcase {
    /**
     * Collect every scalar of a nested example.
     *
     * @param mixed $value
     * @param array $out
     */
    private static function scalars($value, array &$out): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::scalars($item, $out);
            }
        } else if (is_string($value)) {
            $out[] = $value;
        }
    }

    /**
     * No example value is a concrete date or clock time.
     */
    public function test_no_example_shows_a_concrete_date_or_time(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $registry = skill_registry::make_default();
        $offenders = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            $name = (string)($contract['skill'] ?? ($contract['name'] ?? ''));
            $skill = $registry->get_skill($name);
            if ($skill === null) {
                continue;
            }
            $values = [];
            self::scalars($skill->get_example_input(), $values);
            foreach ($values as $value) {
                if (preg_match('/\d{4}-\d{2}-\d{2}|\b\d{1,2}:\d{2}\b/', $value)) {
                    $offenders[] = $name . ': ' . $value;
                }
            }
        }
        $this->assertSame([], $offenders, "concrete dates/times in example inputs:\n" . implode("\n", $offenders));
    }
}

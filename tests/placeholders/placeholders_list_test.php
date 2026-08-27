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
 * Tests for the localized placeholder lists of placeholders_info.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\placeholders\placeholders_info;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The lists of placeholders (MOD_BOOKING_PLACEHOLDERS_*) are built and cached separately.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\placeholders\placeholders_info::return_list_of_placeholders
 */
final class placeholders_list_test extends advanced_testcase {
    /**
     * All placeholder classes (mod_booking and bookingextension plugins) by their tag.
     *
     * @return array tag => fully qualified classname
     */
    private function placeholder_classes(): array {
        $classes = \core_component::get_component_classes_in_namespace('mod_booking', 'placeholders\placeholders');
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $classes += \core_component::get_component_classes_in_namespace(
                "bookingextension_{$plugin->name}",
                'placeholders'
            );
        }
        $bytag = [];
        foreach (array_keys($classes) as $class) {
            $bytag[substr(strrchr($class, '\\'), 1)] = $class;
        }
        return $bytag;
    }
    /**
     * Building the reduced poll url list first must not shrink the full list served afterwards
     * (the static cache used to ignore the pollurl flag).
     */
    public function test_pollurl_list_does_not_replace_full_list(): void {
        $this->resetAfterTest();

        $pollurllist = placeholders_info::return_list_of_placeholders(MOD_BOOKING_PLACEHOLDERS_POLLURL);
        $fulllist = placeholders_info::return_list_of_placeholders(MOD_BOOKING_PLACEHOLDERS_ALL);

        $pollurlcount = substr_count($pollurllist, '<li ');
        $fullcount = substr_count($fulllist, '<li ');
        $this->assertGreaterThan(0, $pollurlcount);
        $this->assertGreaterThan($pollurlcount, $fullcount);
        $this->assertStringContainsString("data-id='bookingoptionname'", $fulllist);

        // The poll url list is a subset of the full list.
        preg_match_all("/data-id='([^']+)'/", $pollurllist, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $tag) {
            $this->assertStringContainsString("data-id='$tag'", $fulllist);
        }

        // The sign-in sheet list contains exactly the applicable placeholders opting in with
        // for_signinsheet() (default false): option related placeholders and the custom fields, no
        // user or event related ones.
        $signinsheetlist = placeholders_info::return_list_of_placeholders(MOD_BOOKING_PLACEHOLDERS_SIGNINSHEET);
        $signinsheetcount = substr_count($signinsheetlist, '<li ');
        $this->assertGreaterThan(0, $signinsheetcount);
        $this->assertLessThan($fullcount, $signinsheetcount);
        $this->assertStringContainsString("data-id='customfields'", $signinsheetlist);
        $this->assertStringContainsString("data-id='bookingoptionname'", $signinsheetlist);
        $this->assertStringNotContainsString("data-id='firstname'", $signinsheetlist);
        $this->assertStringNotContainsString("data-id='email'", $signinsheetlist);
        preg_match_all("/data-id='([^']+)'/", $signinsheetlist, $matches);
        $listed = $matches[1];
        sort($listed);
        $expected = [];
        foreach ($this->placeholder_classes() as $tag => $class) {
            if ($class::is_applicable() && $class::for_signinsheet()) {
                $expected[] = $tag;
            }
        }
        sort($expected);
        $this->assertSame($expected, $listed);

        // The lists are stable when they are requested again in another order; the default is the
        // full list.
        $this->assertSame($fulllist, placeholders_info::return_list_of_placeholders());
        $this->assertSame($pollurllist, placeholders_info::return_list_of_placeholders(MOD_BOOKING_PLACEHOLDERS_POLLURL));
        $this->assertSame($signinsheetlist, placeholders_info::return_list_of_placeholders(MOD_BOOKING_PLACEHOLDERS_SIGNINSHEET));
    }
}

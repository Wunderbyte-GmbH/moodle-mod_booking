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
 * Tests for the check of additional full text search columns of booking options tables.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_system;

/**
 * Tests for the check of additional full text search columns of booking options tables.
 *
 * booking::get_valid_fulltextsearchcolumns() is public API that other plugins (e.g. local_musi) call
 * for their own booking options tables, so its contract is tested here and not only through the view.
 *
 * @covers \mod_booking\booking::get_valid_fulltextsearchcolumns
 */
final class fulltextsearchcolumns_test extends advanced_testcase {
    /**
     * Columns of booking_options and shortnames of booking customfields pass, cleaned and without duplicates.
     */
    public function test_known_columns_and_customfields_are_accepted(): void {
        $this->resetAfterTest();
        $this->create_booking_customfield('keywords');

        $this->assertSame([], booking::get_valid_fulltextsearchcolumns([]));
        $this->assertSame(
            ['description', 'keywords', 'location'],
            booking::get_valid_fulltextsearchcolumns(['description', ' keywords', 'location ', 'description', ''])
        );
        $this->assertDebuggingNotCalled();
    }

    /**
     * Everything that is neither a column nor a customfield is dropped, as it would end up unescaped in the SQL.
     */
    public function test_unknown_columns_are_ignored_with_a_debugging_message(): void {
        $this->resetAfterTest();
        $this->create_booking_customfield('keywords');

        $this->assertSame(
            ['keywords'],
            booking::get_valid_fulltextsearchcolumns(['doesnotexist', 'keywords', "text) FROM {user} --"])
        );
        $this->assertDebuggingCalledCount(2);

        // A customfield of another component is not a booking customfield.
        $this->create_booking_customfield('coursefield', 'core_course', 'course');
        $this->assertSame([], booking::get_valid_fulltextsearchcolumns(['coursefield']));
        $this->assertDebuggingCalled("Invalid column 'coursefield' in fulltextsearchcolumns shortcode argument was ignored.");
    }

    /**
     * Creates a text customfield, by default for booking options.
     *
     * @param string $shortname
     * @param string $component
     * @param string $area
     */
    private function create_booking_customfield(
        string $shortname,
        string $component = 'mod_booking',
        string $area = 'booking'
    ): void {
        $category = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'Category of ' . $shortname,
            'component' => $component,
            'area' => $area,
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => $shortname,
            'shortname' => $shortname,
            'type' => 'text',
            'configdata' => '',
        ]);
        // The list of booking customfields is cached.
        \cache_helper::purge_by_event('setbackcustomfields');
    }
}

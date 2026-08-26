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

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\placeholders\placeholders_info;
use mod_booking\placeholders\placeholders\titlewithoutprefix;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the {titlewithoutprefix} placeholder.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class titlewithoutprefix_test extends booking_advanced_testcase {
    /**
     * Create a booking option with the given title and - optionally - a title prefix.
     *
     * @param string $text the booking option title
     * @param string $titleprefix the prefix, empty for none
     * @return stdClass the created option
     */
    private function create_option_with_prefix(string $text, string $titleprefix = ''): stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $bookingmodule = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
        ]);

        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $bookingmodule->id;
        $record->text = $text;
        $record->titleprefix = $titleprefix;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 2;
        $record->useprice = 0;
        $record->importing = 1;

        return $plugingenerator->create_option($record);
    }

    /**
     * The prefix is stripped, while {title} keeps it.
     *
     * @covers \mod_booking\placeholders\placeholders\titlewithoutprefix::return_value
     * @return void
     */
    public function test_prefix_is_stripped(): void {
        global $USER;

        $option = $this->create_option_with_prefix('Beginners course', 'BB42');
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // Guard the fixture: the prefix must really have been stored, otherwise
        // this test would pass for the wrong reason.
        $this->assertSame('BB42', $settings->titleprefix);

        placeholders_info::$placeholders = [];
        $withoutprefix = placeholders_info::render_text(
            '{titlewithoutprefix}',
            $settings->cmid,
            $option->id,
            (int) $USER->id
        );

        placeholders_info::$placeholders = [];
        $withprefix = placeholders_info::render_text('{title}', $settings->cmid, $option->id, (int) $USER->id);

        $this->assertSame('Beginners course', $withoutprefix);
        $this->assertSame('BB42 - Beginners course', $withprefix);
        $this->assertNotSame($withprefix, $withoutprefix);
    }

    /**
     * Without a prefix on the option, both placeholders render the same value.
     *
     * @covers \mod_booking\placeholders\placeholders\titlewithoutprefix::return_value
     * @return void
     */
    public function test_matches_title_when_option_has_no_prefix(): void {
        global $USER;

        $option = $this->create_option_with_prefix('Advanced course');
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        placeholders_info::$placeholders = [];
        $withoutprefix = placeholders_info::render_text(
            '{titlewithoutprefix}',
            $settings->cmid,
            $option->id,
            (int) $USER->id
        );

        placeholders_info::$placeholders = [];
        $withprefix = placeholders_info::render_text('{title}', $settings->cmid, $option->id, (int) $USER->id);

        $this->assertSame('Advanced course', $withoutprefix);
        $this->assertSame($withprefix, $withoutprefix);
    }

    /**
     * The placeholder combines with {optionid}, which is how connected courses
     * get a shortname that is unique by construction.
     *
     * @covers \mod_booking\placeholders\placeholders\titlewithoutprefix::return_value
     * @return void
     */
    public function test_combines_with_optionid(): void {
        global $USER;

        $option = $this->create_option_with_prefix('Aerial Yoga', 'PF1');
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        placeholders_info::$placeholders = [];
        $result = placeholders_info::render_text(
            '{titlewithoutprefix}_{optionid}',
            $settings->cmid,
            $option->id,
            (int) $USER->id
        );

        $this->assertSame('Aerial Yoga_' . $option->id, $result);
    }

    /**
     * The title does not depend on the user, so the placeholder must also render
     * where there is no logged in user - adhoc tasks and restore steps run that way.
     *
     * Note that {title} / {bookingoptionname} deliberately behave differently here:
     * they bail out without a userid. Do not "harmonise" this placeholder with them.
     *
     * @covers \mod_booking\placeholders\placeholders\titlewithoutprefix::return_value
     * @return void
     */
    public function test_renders_without_logged_in_user(): void {
        $option = $this->create_option_with_prefix('Course without user', 'PF2');
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // Drop back to "not logged in".
        $this->setUser(null);

        placeholders_info::$placeholders = [];
        $result = placeholders_info::render_text('{titlewithoutprefix}', $settings->cmid, $option->id);

        $this->assertSame('Course without user', $result);
    }

    /**
     * Without an optionid there is nothing to render and we get the generic fallback.
     *
     * @covers \mod_booking\placeholders\placeholders\titlewithoutprefix::return_value
     * @return void
     */
    public function test_returns_fallback_without_optionid(): void {
        $text = '';
        $params = [];

        placeholders_info::$placeholders = [];
        $value = titlewithoutprefix::return_value(0, 0, 0, 0, 0, 0, $text, $params);

        $this->assertSame(
            get_string('sthwentwrongwithplaceholder', 'mod_booking', 'titlewithoutprefix'),
            $value
        );
    }
}

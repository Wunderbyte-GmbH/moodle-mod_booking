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
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The date related placeholders render an empty value for self-learning courses.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selflearningcourse_dates_placeholders_test extends booking_advanced_testcase {
    /**
     * Self-learning courses have no option dates and no official start or end, so the date placeholders
     * render nothing for them - even if dates are left over in the DB from before a type change.
     *
     * @covers \mod_booking\placeholders\placeholders\dates
     * @covers \mod_booking\placeholders\placeholders\datescompact
     * @covers \mod_booking\placeholders\placeholders\startdate
     * @covers \mod_booking\placeholders\placeholders\starttime
     * @covers \mod_booking\placeholders\placeholders\enddate
     * @covers \mod_booking\placeholders\placeholders\endtime
     * @covers \mod_booking\placeholders\placeholders\pollstartdate
     * @covers \mod_booking\booking_option_settings::is_selflearningcourse
     * @return void
     */
    public function test_date_placeholders_are_empty_for_selflearningcourses(): void {
        global $DB, $USER;

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
        $record->text = 'Option with dates';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 2;
        $record->useprice = 0;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 August 2050 10:00');
        $record->courseendtime_0 = strtotime('20 August 2050 12:00');
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('21 August 2050 10:00');
        $record->courseendtime_1 = strtotime('21 August 2050 12:00');
        $option = $plugingenerator->create_option($record);

        $placeholders = ['dates', 'datescompact', 'startdate', 'starttime', 'enddate', 'endtime', 'pollstartdate'];

        // Control: a regular option with dates renders every one of them.
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->assertFalse($settings->is_selflearningcourse());
        foreach ($placeholders as $placeholder) {
            placeholders_info::$placeholders = [];
            $result = placeholders_info::render_text('{' . $placeholder . '}', $settings->cmid, $option->id, (int) $USER->id);
            $this->assertNotSame('', trim($result), "{{$placeholder}} of a regular option must not be empty");
        }

        // Switch the type to self-learning course. The dates stay in the DB, like after a type change of an
        // existing option - they must not be rendered anymore.
        $DB->set_field('booking_options', 'type', MOD_BOOKING_OPTIONTYPE_SELFLEARNINGCOURSE, ['id' => $option->id]);
        booking_option::purge_cache_for_option($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->assertTrue($settings->is_selflearningcourse());
        $this->assertCount(2, $settings->sessions);

        foreach ($placeholders as $placeholder) {
            placeholders_info::$placeholders = [];
            $result = placeholders_info::render_text('{' . $placeholder . '}', $settings->cmid, $option->id, (int) $USER->id);
            $this->assertSame('', $result, "{{$placeholder}} of a self-learning course must be empty");
        }

        // Text depending on the placeholder ({#dates}...{/dates}) is dropped as well.
        placeholders_info::$placeholders = [];
        $result = placeholders_info::render_text(
            'Start{#dates}Dates: {dates}{/dates}End',
            $settings->cmid,
            $option->id,
            (int) $USER->id
        );
        $this->assertSame('StartEnd', $result);
        placeholders_info::$placeholders = [];
    }
}

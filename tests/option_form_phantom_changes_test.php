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

use mod_booking\option\fields\annotation;
use mod_booking\option\fields\description;
use mod_booking\option\optiondate;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The first form save of an option created outside the form must not report phantom changes.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\option\field_base::check_for_changes
 * @covers \mod_booking\option\optiondate::compare_optiondates
 */
final class option_form_phantom_changes_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Creates a course, a booking instance and one option with the given fields.
     *
     * @param array $fields
     * @return int optionid
     */
    private function create_option(array $fields): int {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $manager->username,
        ]);
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)array_merge([
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Phantom changes option',
            'chooseorcreatecourse' => 1,
        ], $fields));
        return (int)$option->id;
    }

    /**
     * The form's date selector truncates seconds: that is not a change of the date.
     *
     * @return void
     */
    public function test_seconds_are_not_a_date_change(): void {
        $start = strtotime('+5 days 10:00:11');
        $end = strtotime('+5 days 12:00:11');
        $old = ['optiondateid' => 5, 'coursestarttime' => $start, 'courseendtime' => $end, 'daystonotify' => 0];
        $new = ['optiondateid' => 5, 'coursestarttime' => $start - 11, 'courseendtime' => $end - 11, 'daystonotify' => 0];
        $this->assertTrue(optiondate::compare_optiondates($old, $new, 1));

        // A real change of one minute is still detected.
        $new['coursestarttime'] = $start - 11 - MINSECS;
        $this->assertFalse(optiondate::compare_optiondates($old, $new, 1));
    }

    /**
     * The editor wraps plain text in a paragraph: that is not a change of the description.
     *
     * @return void
     */
    public function test_editor_wrapping_is_not_a_description_change(): void {
        $optionid = $this->create_option(['description' => 'Deskr2']);

        $formdata = (object)['id' => $optionid, 'description' => ['text' => '<p>Deskr2</p>', 'format' => FORMAT_HTML]];
        $instance = new description();
        $this->assertSame([], $instance->check_for_changes($formdata, $instance, null, 'description', $formdata->description));

        // A real change of the text is still detected.
        $formdata->description = ['text' => '<p>Deskr3</p>', 'format' => FORMAT_HTML];
        $changes = $instance->check_for_changes($formdata, $instance, null, 'description', $formdata->description);
        $this->assertSame('Deskr2', $changes['changes']['oldvalue']);
        $this->assertSame('<p>Deskr3</p>', $changes['changes']['newvalue']);
    }

    /**
     * A never filled editor field (text null) submitted empty is not a change.
     *
     * @return void
     */
    public function test_null_text_and_empty_string_are_not_a_change(): void {
        global $DB;
        $optionid = $this->create_option([]);
        // An option created by an import or a web service has never had its annotation set.
        $DB->set_field('booking_options', 'annotation', null, ['id' => $optionid]);
        singleton_service::destroy_booking_option_singleton($optionid);
        booking_option::purge_cache_for_option($optionid);

        $formdata = (object)['id' => $optionid, 'annotation' => ''];
        $instance = new annotation();
        $this->assertSame([], $instance->check_for_changes($formdata, $instance, null, 'annotation', ''));

        $formdata->annotation = ['text' => '', 'format' => FORMAT_HTML];
        $this->assertSame([], $instance->check_for_changes($formdata, $instance, null, 'annotation', $formdata->annotation));

        // A real annotation is still detected.
        $formdata->annotation = ['text' => 'Note', 'format' => FORMAT_HTML];
        $changes = $instance->check_for_changes($formdata, $instance, null, 'annotation', $formdata->annotation);
        $this->assertSame('Note', $changes['changes']['newvalue']);
    }
}

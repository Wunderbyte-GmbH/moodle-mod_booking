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
 * Tests for the info line data of the bookings tracker option scope.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\bookingstracker\report2_infobox;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The info line shows description, teachers, responsible contacts, the
 * associated course (linked with its full name) and the optiondates.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report2_infobox_test extends booking_advanced_testcase {
    /**
     * A fully equipped option exports description, teacher, responsible
     * contact, the associated course (full name + course link) and the single
     * session date.
     *
     * @covers \mod_booking\local\bookingstracker\report2_infobox::export_for_option
     * @covers \mod_booking\local\bookingstracker\report2_infobox::has_content
     */
    public function test_export_contains_all_info_parts(): void {
        global $DB;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $targetcourse = $this->getDataGenerator()->create_course(['fullname' => 'Associated target course']);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Infobox test booking',
            'course' => $course->id,
        ]);

        $teacher = $this->getDataGenerator()->create_user(['firstname' => 'Tina', 'lastname' => 'Teacher']);
        $contact = $this->getDataGenerator()->create_user(['firstname' => 'Conny', 'lastname' => 'Contact']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($contact->id, $course->id, 'student');

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option for infobox';
        $record->description = 'An interesting course description.';
        $record->useprice = 0;
        $record->maxanswers = 5;
        $record->courseid = $targetcourse->id;
        $record->chooseorcreatecourse = 1;
        $record->teachersforoption = $teacher->username;
        $record->responsiblecontact = $contact->username;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = strtotime('20 May 2050 15:00');
        $record->courseendtime_1 = strtotime('20 May 2050 16:00');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $data = report2_infobox::export_for_option((int)$option->id);

        $this->assertTrue(report2_infobox::has_content($data));
        $this->assertStringContainsString('An interesting course description.', $data['description'] ?? '');

        $this->assertTrue($data['teachersexist']);
        $this->assertEquals('Tina Teacher', $data['teachers'][0]['name']);
        $this->assertStringContainsString('/user/profile.php', $data['teachers'][0]['profileurl']);

        $this->assertTrue($data['contactsexist']);
        $this->assertEquals('Conny Contact', $data['contacts'][0]['name']);

        $this->assertNotEmpty($data['associatedcourse'] ?? null, 'The associated course must be exported.');
        $this->assertEquals('Associated target course', $data['associatedcourse']['name']);
        $this->assertStringContainsString('/course/view.php', $data['associatedcourse']['url']);
        $this->assertStringContainsString('id=' . $targetcourse->id, $data['associatedcourse']['url']);

        $this->assertNotEmpty($data['singledate'] ?? '', 'The single session date must be shown directly.');
    }

    /**
     * Without a connected course there is no associatedcourse entry; an option
     * without any info parts reports no content.
     *
     * @covers \mod_booking\local\bookingstracker\report2_infobox::export_for_option
     * @covers \mod_booking\local\bookingstracker\report2_infobox::has_content
     */
    public function test_export_without_course_and_empty_option(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Infobox test booking',
            'course' => $course->id,
        ]);

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Bare option';
        $record->useprice = 0;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $data = report2_infobox::export_for_option((int)$option->id);

        $this->assertArrayNotHasKey('associatedcourse', $data);
        $this->assertFalse($data['teachersexist']);
        $this->assertFalse($data['contactsexist']);
        $this->assertFalse(
            report2_infobox::has_content($data),
            'A bare option without description/teachers/contacts/course/dates has no info line content.'
        );
    }
}

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
 * Tests for the button label of the onwaitinglist condition while waiting for confirmation.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use advanced_testcase;
use mod_booking\bo_availability\conditions\onwaitinglist;
use mod_booking\booking_bookit;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Users waiting for confirmation see their place on the waiting list in the booking button,
 * but only if the ranked waiting list (waitinglistshowplaceonwaitinglist) is enabled.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\bo_availability\conditions\onwaitinglist
 */
final class condition_onwaitinglist_button_label_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * With the ranked waiting list enabled, the button shows the place on the waiting list.
     */
    public function test_button_shows_place_when_ranked_waitinglist_enabled(): void {
        set_config('waitinglistshowplaceonwaitinglist', 1, 'booking');
        [$settings, $student1, $student2] = $this->create_option_with_two_waiting_users();

        $condition = new onwaitinglist();

        $this->assertSame(
            get_string('yourplaceonwaitinglist', 'mod_booking', 1),
            $this->get_button_label($condition, $settings, (int) $student1->id)
        );
        $this->assertSame(
            get_string('yourplaceonwaitinglist', 'mod_booking', 2),
            $this->get_button_label($condition, $settings, (int) $student2->id)
        );

        // The description (outside of the button) is not changed.
        $this->assertSame(
            get_string('bocondonwaitinglistwaitforconfirmation', 'mod_booking'),
            $condition->get_description_string(false, false, (int) $student2->id, $settings)
        );
    }

    /**
     * Without the ranked waiting list, the button keeps the "Wait for confirmation" label.
     */
    public function test_button_keeps_wait_for_confirmation_when_ranked_waitinglist_disabled(): void {
        set_config('waitinglistshowplaceonwaitinglist', 0, 'booking');
        [$settings, $student1, $student2] = $this->create_option_with_two_waiting_users();

        $condition = new onwaitinglist();
        $expected = get_string('bocondonwaitinglistwaitforconfirmation', 'mod_booking');

        $this->assertSame($expected, $this->get_button_label($condition, $settings, (int) $student1->id));
        $this->assertSame($expected, $this->get_button_label($condition, $settings, (int) $student2->id));
        $this->assertSame(
            $expected,
            $condition->get_description_string(false, false, (int) $student2->id, $settings)
        );
    }

    /**
     * Returns the main label of the rendered button for the given user.
     *
     * @param onwaitinglist $condition
     * @param booking_option_settings $settings
     * @param int $userid
     * @return string
     */
    private function get_button_label(onwaitinglist $condition, booking_option_settings $settings, int $userid): string {
        $this->setUser($userid);
        [, $data] = $condition->render_button($settings, $userid);
        return $data['main']['label'];
    }

    /**
     * Creates an option that requires confirmation and puts two students on its waiting list.
     *
     * @return array [booking_option_settings, student1, student2]
     */
    private function create_option_with_two_waiting_users(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Test Booking waitinglist button label',
            'eventtype' => 'Test event',
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ]);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id);
        $this->getDataGenerator()->enrol_user($student2->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Waitinglist button label test';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->maxoverbooking = 0;
        $record->waitforconfirmation = 1; // Forces new bookings to land on the waiting list.
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        foreach ([$student1, $student2] as $student) {
            $this->setUser($student);
            singleton_service::destroy_user($student->id);
            booking_bookit::bookit('option', $settings->id, $student->id); // Shows confirmation prompt.
            booking_bookit::bookit('option', $settings->id, $student->id); // Submits → WAITINGLIST.
        }

        singleton_service::destroy_booking_answers($option->id);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(2, $answers->get_usersonwaitinglist(), 'both students are on the waiting list');

        return [$settings, $student1, $student2];
    }
}

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
 * Tests for booking option availability conditions - previouslybooked with completion requirement.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking_generator;
use mod_booking\singleton_service;
use mod_booking\bo_availability\bo_info;
use tool_mocktesttime\time_mock;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

final class condition_previouslybooked_completion_test extends advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Verify that previouslybooked with requirecompletion blocks until completion is toggled.
     *
     * @covers \mod_booking\bo_availability\conditions\previouslybooked::is_available
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_previouslybooked_requires_completion(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Users.
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        // Booking instance.
        $bdata = [
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
            'cancancelbook' => 1,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create referenced option A the user needs to have completed.
        $recorda = new stdClass();
        $recorda->bookingid = $booking->id;
        $recorda->text = 'Referenced Option A';
        $recorda->chooseorcreatecourse = 1;
        $recorda->courseid = $course->id;
        $recorda->maxanswers = 10;
        $optiona = $plugingenerator->create_option($recorda);

        $recordb = new stdClass();
        $recordb->bookingid = $booking->id;
        $recordb->text = 'Target Option B';
        $recordb->chooseorcreatecourse = 1;
        $recordb->courseid = $course->id;
        $recordb->maxanswers = 10;

        $recordb->bo_cond_previouslybooked_restrict = 1;
        $recordb->bo_cond_previouslybooked_optionid = $optiona->id;
        $recordb->bo_cond_previouslybooked_requirecompletion = 1;

        $optionb = $plugingenerator->create_option($recordb);

        // Book the student into option A (but not completed yet).
        $plugingenerator->create_answer(['optionid' => $optiona->id, 'userid' => $student->id]);
        singleton_service::destroy_booking_answers($optiona->id);

        $this->setUser($student);

        // Check availability for option B: should be blocked until completion.
        $settingsb = singleton_service::get_instance_of_booking_option_settings($optionb->id);
        $boinfo = new bo_info($settingsb);
        [$id, $isavailable] = $boinfo->is_available($settingsb->id, $student->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_JSON_PREVIOUSLYBOOKED, $id);
        $this->assertFalse($isavailable);

        // Toggle completion for the student on option A.
        $settingsa = singleton_service::get_instance_of_booking_option_settings($optiona->id);
        $optionaobj = singleton_service::get_instance_of_booking_option($settingsa->cmid, $settingsa->id);
        $optionaobj->toggle_user_completion($student->id);
        singleton_service::destroy_booking_answers($optiona->id);

        // Re-check availability: now allowed.
        [$id, $isavailable] = $boinfo->is_available($settingsb->id, $student->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
    }
}

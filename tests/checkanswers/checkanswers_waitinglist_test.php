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
 * Tests for the waiting list after the cleanup of booking answers without access.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\event\bookingoption_booked;
use mod_booking\local\checkanswers\checkanswers;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * When checkanswers deletes the booking of a user who lost access, the freed
 * place goes to the waiting list - but only to users who passed the checks.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checkanswers_waitinglist_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * The booked user is not enrolled in the course any more: her booking is
     * deleted and the enrolled user on the waiting list moves up.
     *
     * @covers \mod_booking\local\checkanswers\checkanswers::process_booking_option
     */
    public function test_user_on_waitinglist_moves_up_after_cleanup(): void {
        $this->setAdminUser();

        $booked = $this->getDataGenerator()->create_user();
        $waiting = $this->getDataGenerator()->create_user();
        // Only the user on the waiting list is enrolled in the course.
        $optionid = $this->create_single_place_option([$booked, $waiting], [$waiting]);

        $log = checkanswers::process_booking_option(
            $optionid,
            checkanswers::CHECK_COURSE_ENROLLMENT,
            checkanswers::ACTION_DELETE
        );

        $this->assertEquals([checkanswers::ACTION_DELETE], $log[$booked->id]['action']);
        // The waiting user passed the check: no action must be logged for her.
        $this->assertFalse($log[$waiting->id]['action']);

        $this->assertEquals(MOD_BOOKING_STATUSPARAM_DELETED, $this->status_of($optionid, $booked));
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            $this->status_of($optionid, $waiting),
            'The user on the waiting list must move up to the freed place.'
        );
    }

    /**
     * The first user on the waiting list lost access as well: she must not move
     * up for a moment (and be notified) before her own answer is deleted. The
     * place goes to the next user who is still enrolled.
     *
     * @covers \mod_booking\local\checkanswers\checkanswers::process_booking_option
     */
    public function test_user_without_access_never_moves_up(): void {
        $this->setAdminUser();

        $booked = $this->getDataGenerator()->create_user();
        $waitingnoaccess = $this->getDataGenerator()->create_user();
        $waitingenrolled = $this->getDataGenerator()->create_user();
        $optionid = $this->create_single_place_option(
            [$booked, $waitingnoaccess, $waitingenrolled],
            [$waitingenrolled]
        );

        $sink = $this->redirectEvents();
        checkanswers::process_booking_option(
            $optionid,
            checkanswers::CHECK_COURSE_ENROLLMENT,
            checkanswers::ACTION_DELETE
        );
        $bookedusers = [];
        foreach ($sink->get_events() as $event) {
            if ($event instanceof bookingoption_booked) {
                $bookedusers[] = (int)$event->relateduserid;
            }
        }
        $sink->close();

        $this->assertEquals(
            [(int)$waitingenrolled->id],
            $bookedusers,
            'Only the enrolled user may be booked, the user without access must never move up.'
        );
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_DELETED, $this->status_of($optionid, $booked));
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_DELETED, $this->status_of($optionid, $waitingnoaccess));
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, $this->status_of($optionid, $waitingenrolled));
    }

    /**
     * Helper: free option with a single place. The first user gets the place,
     * the others land on the waiting list in the given order.
     *
     * @param stdClass[] $users users who answer, in this order
     * @param stdClass[] $enrolledusers users who are enrolled in the course
     * @return int option id
     */
    private function create_single_place_option(array $users, array $enrolledusers): int {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Check answers waiting list booking',
        ]);
        foreach ($enrolledusers as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Single place option',
            'description' => 'Single place option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => 5,
            'waitforconfirmation' => 0,
        ]);

        $optionobj = singleton_service::get_instance_of_booking_option((int)$booking->cmid, (int)$option->id);
        foreach ($users as $index => $user) {
            $optionobj->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
            $this->assertEquals(
                $index === 0 ? MOD_BOOKING_STATUSPARAM_BOOKED : MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                $this->status_of((int)$option->id, $user),
                'Precondition: the first user is booked, the others wait.'
            );
        }

        singleton_service::destroy_instance();

        return (int)$option->id;
    }

    /**
     * Status (booking_answers.waitinglist) of the latest answer of a user.
     *
     * @param int $optionid
     * @param stdClass $user
     * @return int
     */
    private function status_of(int $optionid, stdClass $user): int {
        global $DB;

        $answers = $DB->get_records(
            'booking_answers',
            ['optionid' => $optionid, 'userid' => $user->id],
            'id DESC',
            'id, waitinglist',
            0,
            1
        );
        return (int)reset($answers)->waitinglist;
    }
}

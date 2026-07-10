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
 * Tests for the reported bug: a user who already bought one slot (out of several allowed via
 * slot_max_slots_per_user, e.g. purchasing separate "phases" over time) had no way to buy an
 * additional slot - the generic alreadybooked condition locked the option to a plain "Booked"
 * alert button as soon as one slot was booked, regardless of remaining slot capacity.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\bo_availability\conditions\alreadybooked::get_description
 * @covers     \mod_booking\bo_availability\conditions\cancelmyself::hard_block
 * @covers     \mod_booking\local\slotbooking\slot_availability::has_remaining_slot_capacity
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\bo_availability\conditions\alreadybooked;
use mod_booking\bo_availability\conditions\cancelmyself;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/bo_availability/bo_info.php');

/**
 * PHPUnit tests for the slot-capacity-aware step-back in alreadybooked::get_description().
 *
 * @covers \mod_booking\bo_availability\conditions\alreadybooked
 */
final class slot_alreadybooked_capacity_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * has_remaining_slot_capacity() compares booked slot count against max_slots_per_user.
     *
     * @return void
     */
    public function test_has_remaining_slot_capacity(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 2]);

        $this->assertTrue(slot_availability::has_remaining_slot_capacity($optionid, $userid));

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'), MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();
        $this->assertTrue(slot_availability::has_remaining_slot_capacity($optionid, $userid));

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 10:00:00 UTC'), MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();
        $this->assertFalse(slot_availability::has_remaining_slot_capacity($optionid, $userid));
    }

    /**
     * With one of several allowed slots already booked, alreadybooked must step back to
     * INDIFFERENT (not JUSTMYALERT) so the slotbooking condition can still offer buying another
     * slot - this is the regression guard for the reported bug.
     *
     * @return void
     */
    public function test_get_description_steps_back_when_slot_capacity_remains(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 10]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'), MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        [$isavailable, , , $buttontype] = (new alreadybooked())->get_description($settings, $userid);

        $this->assertFalse($isavailable);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_INDIFFERENT, $buttontype);
    }

    /**
     * Once the user has booked as many slots as max_slots_per_user allows, alreadybooked must
     * fall back to its normal JUSTMYALERT "Booked" alert - there is no remaining capacity to
     * offer another purchase for.
     *
     * @return void
     */
    public function test_get_description_still_blocks_when_slot_capacity_exhausted(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 1]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'), MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        [$isavailable, , , $buttontype] = (new alreadybooked())->get_description($settings, $userid);

        $this->assertFalse($isavailable);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_JUSTMYALERT, $buttontype);
    }

    /**
     * hard_block() gates the actual booking commit (see booking_bookit::bookit()) and must also
     * step back while slot capacity remains - this is the regression guard for the reported bug
     * where a second slot could be selected and "Continue" clicked, but the commit silently
     * no-op'd (no new answer created) while the post-click page still showed a false "success".
     *
     * @return void
     */
    public function test_hard_block_steps_back_when_slot_capacity_remains(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 10]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'), MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertFalse((new alreadybooked())->hard_block($settings, $userid));
    }

    /**
     * Once slot capacity is exhausted, hard_block() must still block, same as before this fix.
     *
     * @return void
     */
    public function test_hard_block_still_blocks_when_slot_capacity_exhausted(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 1]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'), MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertTrue((new alreadybooked())->hard_block($settings, $userid));
    }

    /**
     * cancelmyself::hard_block() must step back the same way as alreadybooked's - this is the
     * regression guard for the actual reported cause: with alreadybooked and slotbooking both
     * fixed, cancelmyself (id 105, the "Cancel purchase" condition) surfaced as the next
     * hard-blocker for a second slot purchase, since it was unconditionally `return true;` and
     * is not in local_shopping_cart's allow-list, producing a generic "cannot add to cart" error.
     *
     * @return void
     */
    public function test_cancelmyself_hard_block_steps_back_when_slot_capacity_remains(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 10]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'), MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertFalse((new cancelmyself())->hard_block($settings, $userid));
    }

    /**
     * Once slot capacity is exhausted, cancelmyself::hard_block() must still block, same as
     * before this fix - cancelling remains the only action once no more slots can be bought.
     *
     * @return void
     */
    public function test_cancelmyself_hard_block_still_blocks_when_slot_capacity_exhausted(): void {
        [$optionid, $userid] = $this->create_slot_option_and_user(['slot_max_slots_per_user' => 1]);

        $this->book_slot($optionid, $userid, strtotime('2050-01-07 09:00:00 UTC'), MOD_BOOKING_STATUSPARAM_BOOKED);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertTrue((new cancelmyself())->hard_block($settings, $userid));
    }

    /**
     * A plain (non-slotbooking) option is unaffected: no slotconfig means the new step-back never
     * applies, so the pre-existing JUSTMYALERT behaviour is preserved.
     *
     * @return void
     */
    public function test_get_description_unaffected_for_non_slot_option(): void {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Non-slot option ' . uniqid('', true),
            'course' => $course->id,
            'maxanswers' => 5,
        ]);
        $user = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $user->id]);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $condition = new alreadybooked();
        [$isavailable, , , $buttontype] = $condition->get_description($settings, $user->id);

        $this->assertFalse($isavailable);
        $this->assertSame(MOD_BOOKING_BO_BUTTON_JUSTMYALERT, $buttontype);
        $this->assertTrue($condition->hard_block($settings, $user->id));
        $this->assertTrue((new cancelmyself())->hard_block($settings, $user->id));
    }

    /**
     * Create a simple fixed slotbooking option and a student.
     *
     * @param array $overrides option-form field overrides
     * @return array{0:int,1:int} [optionid, userid]
     */
    private function create_slot_option_and_user(array $overrides = []): array {
        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = array_merge([
            'bookingid' => $booking->id,
            'text' => 'Capacity test option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'slot_custom_max_duration' => 60 * MINSECS,
            'slot_custom_min_duration' => 30 * MINSECS,
            'slot_custom_max_days' => DAYSECS,
            'slot_custom_start_interval_minutes' => 15,
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '18:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 5,
            'slot_max_slots_per_user' => 1,
            'slot_booking_view_mode' => 'list',
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
            'slot_allow_self_rebooking' => 0,
            'slot_change_deadline_minutes' => '',
        ], $overrides);
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }

        $option = $plugingenerator->create_option((object) $record);
        singleton_service::destroy_instance();

        return [(int) $option->id, (int) $student->id];
    }

    /**
     * Insert a booking answer directly (bypassing the full checkout flow), and purge the
     * option's cached answers so the direct insert is visible to the next read.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @param int $start slot start timestamp
     * @param int $waitinglist booking status (MOD_BOOKING_STATUSPARAM_*)
     * @return int booking answer id
     */
    private function book_slot(int $optionid, int $userid, int $start, int $waitinglist): int {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $end = $start + (30 * MINSECS);

        $answer = (object) [
            'bookingid' => (int)$settings->bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => $waitinglist,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'startdate' => $start,
            'enddate' => $end,
            'json' => '',
        ];
        slot_answer::set_slot_data($answer, ['slots' => [['start' => $start, 'end' => $end]], 'teachers' => []]);

        $baid = (int) $DB->insert_record('booking_answers', $answer);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);

        return $baid;
    }
}

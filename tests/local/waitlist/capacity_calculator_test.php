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
 * Tests for capacity_calculator (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §5, K2). Direct
 * booking_answers inserts are used (no booking_bookit() choreography needed) since
 * free_capacity() only reads booking_answers/booking_option_settings and open offers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * K2 formula tests for capacity_calculator.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\capacity_calculator::free_capacity
 */
final class capacity_calculator_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        // Avoid the booking_answers MUC cache masking direct DB inserts made by these tests.
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Creates a course + booking + one option with the given maxanswers.
     *
     * @param int $maxanswers
     * @return int the new option's id
     */
    private function create_option(int $maxanswers): int {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bdata = [
            'name' => 'Capacity Test',
            'eventtype' => 'Test',
            'enablecompletion' => 1,
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
            'course' => $course->id,
            'bookingmanager' => $teacher->username,
        ];
        $this->setAdminUser();
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'capacity-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = $maxanswers;
        $record->maxoverbooking = 10;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return (int) $option->id;
    }

    /**
     * Inserts one raw booking_answers row.
     *
     * @param int $optionid
     * @param int $waitinglist one of the MOD_BOOKING_STATUSPARAM_* constants
     * @param int $places
     * @return void
     */
    private function insert_answer(int $optionid, int $waitinglist, int $places = 1): void {
        global $DB;
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => (int) $this->getDataGenerator()->create_user()->id,
            'optionid' => $optionid,
            'timemodified' => 1000,
            'timecreated' => 1000,
            'waitinglist' => $waitinglist,
            'status' => 0,
            'places' => $places,
        ]);
    }

    /**
     * K2 basic formula: free = maxanswers - booked, with no open offers.
     */
    public function test_free_capacity_basic_formula(): void {
        $optionid = $this->create_option(5);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);

        $repository = new db_waitlist_offer_repository();
        $calculator = new capacity_calculator($repository);
        $this->assertEquals(2, $calculator->free_capacity($optionid));
    }

    /**
     * K2: an OPEN offer must count against free capacity, exactly like a booked seat.
     */
    public function test_open_offers_count_against_capacity(): void {
        $optionid = $this->create_option(5);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);

        $repository = new db_waitlist_offer_repository();
        $offereduserid = (int) $this->getDataGenerator()->create_user()->id;
        $repository->create_offer($optionid, $offereduserid, 1, 1, new offer_statuses\offered());

        $calculator = new capacity_calculator($repository);
        $this->assertEquals(1, $calculator->free_capacity($optionid));
    }

    /**
     * K2: a TERMINAL offer (e.g. declined) must NOT count against free capacity.
     */
    public function test_terminal_offers_do_not_count_against_capacity(): void {
        $optionid = $this->create_option(5);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);

        $repository = new db_waitlist_offer_repository();
        $declineduserid = (int) $this->getDataGenerator()->create_user()->id;
        $repository->create_offer($optionid, $declineduserid, 1, 1, new offer_statuses\declined());

        $calculator = new capacity_calculator($repository);
        $this->assertEquals(
            2,
            $calculator->free_capacity($optionid),
            'A terminal (declined) offer must not reduce free capacity.'
        );
    }

    /**
     * K2: the `places` field on a booking_answers row must be weighted, not counted as 1
     * regardless of its actual value.
     */
    public function test_places_field_is_weighted(): void {
        $optionid = $this->create_option(5);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED, 3);

        $repository = new db_waitlist_offer_repository();
        $calculator = new capacity_calculator($repository);
        $this->assertEquals(2, $calculator->free_capacity($optionid));
    }

    /**
     * K2: a RESERVED answer occupies a seat too (not just BOOKED), a DELETED one does not.
     */
    public function test_reserved_counts_deleted_does_not(): void {
        $optionid = $this->create_option(5);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_RESERVED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_DELETED);

        $repository = new db_waitlist_offer_repository();
        $calculator = new capacity_calculator($repository);
        $this->assertEquals(4, $calculator->free_capacity($optionid));
    }

    /**
     * K2: free capacity must never go negative, even if booked + open offers exceed maxanswers.
     */
    public function test_free_capacity_never_negative(): void {
        $optionid = $this->create_option(1);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->insert_answer($optionid, MOD_BOOKING_STATUSPARAM_BOOKED);

        $repository = new db_waitlist_offer_repository();
        $calculator = new capacity_calculator($repository);
        $this->assertEquals(0, $calculator->free_capacity($optionid));
    }
}

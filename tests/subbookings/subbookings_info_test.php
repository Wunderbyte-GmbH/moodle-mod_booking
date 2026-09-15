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

namespace mod_booking\subbookings;

use advanced_testcase;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the status transitions of subbooking answers.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\subbookings\subbookings_info
 */
final class subbookings_info_test extends advanced_testcase {
    /**
     * Create a booking instance with one option and one additional item subbooking.
     *
     * @return array [int $subbookingid, int $userid]
     */
    private function create_subbooking_fixture(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option([
            'bookingid' => $booking->id,
            'text' => 'Option with subbooking',
            'description' => 'Option with subbooking',
        ]);
        $json = json_encode((object)[
            'name' => 'Extra item',
            'type' => 'subbooking_additionalitem',
            'data' => (object)['description' => 'Extra item', 'descriptionformat' => 1, 'useprice' => 0],
        ]);
        $subbooking = $plugingenerator->create_subbooking((object)[
            'name' => 'Extra item',
            'type' => 'subbooking_additionalitem',
            'block' => 0,
            'optionid' => $option->id,
            'json' => $json,
        ]);
        return [(int)$subbooking->id, (int)$user->id];
    }

    /**
     * All answer rows of the user for the subbooking, keyed by id.
     *
     * @param int $subbookingid
     * @param int $userid
     * @return stdClass[]
     */
    private function answer_rows(int $subbookingid, int $userid): array {
        global $DB;
        return $DB->get_records('booking_subbooking_answers', ['sboptionid' => $subbookingid, 'userid' => $userid]);
    }

    /**
     * Unloading a reservation deletes the row; unloading again, or deleting a subbooking that was
     * never booked, must not create a NOTBOOKED (4) or DELETED (5) row.
     *
     * Regression test for GH-1584: the fallback in update_or_insert_answer() inserted a new row for
     * every status, so a second unload left a status 4 artefact in booking_subbooking_answers.
     */
    public function test_unload_and_delete_without_answer_create_no_rows(): void {
        $this->resetAfterTest();
        [$subbookingid, $userid] = $this->create_subbooking_fixture();

        subbookings_info::save_response('subbooking', $subbookingid, MOD_BOOKING_STATUSPARAM_RESERVED, $userid);
        $rows = $this->answer_rows($subbookingid, $userid);
        $this->assertCount(1, $rows);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_RESERVED, (int)reset($rows)->status);

        // Unloading from the cart removes the reservation row.
        subbookings_info::save_response('subbooking', $subbookingid, MOD_BOOKING_STATUSPARAM_NOTBOOKED, $userid);
        $this->assertCount(0, $this->answer_rows($subbookingid, $userid));

        // Unloading again (expired cart, unload after purchase) must not leave a status 4 row.
        subbookings_info::save_response('subbooking', $subbookingid, MOD_BOOKING_STATUSPARAM_NOTBOOKED, $userid);
        $this->assertCount(0, $this->answer_rows($subbookingid, $userid));

        // Deleting a subbooking that was never booked must not leave a status 5 row.
        subbookings_info::save_response('subbooking', $subbookingid, MOD_BOOKING_STATUSPARAM_DELETED, $userid);
        $this->assertCount(0, $this->answer_rows($subbookingid, $userid));
    }

    /**
     * The regular flow reserve -> book -> delete transitions one and the same row through
     * RESERVED (2), BOOKED (0) and DELETED (5).
     */
    public function test_reserve_book_delete_transitions_single_row(): void {
        $this->resetAfterTest();
        [$subbookingid, $userid] = $this->create_subbooking_fixture();

        subbookings_info::save_response('subbooking', $subbookingid, MOD_BOOKING_STATUSPARAM_RESERVED, $userid);
        $rows = $this->answer_rows($subbookingid, $userid);
        $this->assertCount(1, $rows);
        $rowid = (int)reset($rows)->id;

        subbookings_info::save_response('subbooking', $subbookingid, MOD_BOOKING_STATUSPARAM_BOOKED, $userid);
        $rows = $this->answer_rows($subbookingid, $userid);
        $this->assertCount(1, $rows);
        $this->assertSame($rowid, (int)reset($rows)->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)reset($rows)->status);

        subbookings_info::save_response('subbooking', $subbookingid, MOD_BOOKING_STATUSPARAM_DELETED, $userid);
        $rows = $this->answer_rows($subbookingid, $userid);
        $this->assertCount(1, $rows);
        $this->assertSame($rowid, (int)reset($rows)->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_DELETED, (int)reset($rows)->status);

        // Unloading a booked subbooking is not a reservation and must not add a row either.
        subbookings_info::save_response('subbooking', $subbookingid, MOD_BOOKING_STATUSPARAM_NOTBOOKED, $userid);
        $this->assertCount(1, $this->answer_rows($subbookingid, $userid));
    }
}

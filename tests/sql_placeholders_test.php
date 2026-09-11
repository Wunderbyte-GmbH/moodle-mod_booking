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
 * Tests for the queries which were migrated to SQL parameter placeholders.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for the queries which used to interpolate variables directly into
 * the SQL and now bind them as parameter placeholders. The tests pin down that the
 * queries still select exactly the intended rows.
 *
 * @covers \mod_booking\coursecategories::return_course_categories
 * @covers \mod_booking\elective::return_credits_booked
 * @covers \mod_booking\elective::return_credits_left
 * @covers \mod_booking\booking::get_all_optionids_of_teacher
 */
final class sql_placeholders_test extends booking_advanced_testcase {
    /**
     * Creates two booking options with credits and one answer per requested state.
     *
     * @param int $bookingid
     * @param int $userid
     * @param int $waitinglist status of the answers to create
     * @return void
     */
    private function create_options_with_answers(int $bookingid, int $userid, int $waitinglist): void {
        global $DB;

        foreach ([3, 2] as $credits) {
            $optionid = $DB->insert_record('booking_options', (object)[
                'bookingid' => $bookingid,
                'text' => 'Elective with ' . $credits . ' credits',
                'description' => '',
                'credits' => $credits,
            ]);
            $DB->insert_record('booking_answers', (object)[
                'bookingid' => $bookingid,
                'optionid' => $optionid,
                'userid' => $userid,
                'waitinglist' => $waitinglist,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Function return_course_categories filters by category id and parent flag via placeholders.
     */
    public function test_return_course_categories_filters_by_id_and_parent(): void {
        $this->setAdminUser();

        $parent = $this->getDataGenerator()->create_category(['name' => 'Parent category']);
        $child = $this->getDataGenerator()->create_category(['name' => 'Child category', 'parent' => $parent->id]);

        // All parent categories: contains the new parent, but not the child.
        $records = coursecategories::return_course_categories(0);
        $this->assertArrayHasKey($parent->id, $records);
        $this->assertArrayNotHasKey($child->id, $records);
        $this->assertSame('Parent category', $records[$parent->id]->name);
        $this->assertNotEmpty($records[$parent->id]->contextid);

        // A specific parent category id returns exactly this one.
        $records = coursecategories::return_course_categories((int)$parent->id);
        $this->assertCount(1, $records);
        $this->assertArrayHasKey($parent->id, $records);

        // A child category id yields nothing as long as only parents are requested.
        $this->assertCount(0, coursecategories::return_course_categories((int)$child->id));
        // Without the parent restriction, the child is found.
        $this->assertCount(1, coursecategories::return_course_categories((int)$child->id, false));
    }

    /**
     * Function return_credits_booked sums the credits of the current user in the given instance only.
     */
    public function test_return_credits_booked_sums_only_own_answers(): void {
        $user = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();

        // Two booked options (3 + 2 credits) for the user in booking 100,
        // plus noise: another user in the same booking and the same user in another booking.
        $this->create_options_with_answers(100, (int)$user->id, 0);
        $this->create_options_with_answers(100, (int)$otheruser->id, 0);
        $this->create_options_with_answers(200, (int)$user->id, 0);

        $booking = new stdClass();
        $booking->id = 100;

        $this->setUser($user);
        $this->assertEquals(5, elective::return_credits_booked($booking));
    }

    /**
     * Function return_credits_left subtracts the reserved credits of the current user from maxcredits.
     */
    public function test_return_credits_left_subtracts_reserved_credits(): void {
        $user = $this->getDataGenerator()->create_user();

        // Two reserved options (3 + 2 credits) for the user in booking 300.
        $this->create_options_with_answers(300, (int)$user->id, MOD_BOOKING_STATUSPARAM_RESERVED);

        $booking = new stdClass();
        $booking->id = 300;
        $booking->maxcredits = 12;

        $this->setUser($user);
        $this->assertEquals(7, elective::return_credits_left($booking));
    }

    /**
     * Function get_all_optionids_of_teacher returns the current user's options of the given instance only.
     */
    public function test_get_all_optionids_of_teacher_scopes_by_user_and_instance(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_user();
        $otherteacher = $this->getDataGenerator()->create_user();

        $DB->insert_record('booking_teachers', (object)['bookingid' => 400, 'optionid' => 41, 'userid' => $teacher->id]);
        $DB->insert_record('booking_teachers', (object)['bookingid' => 400, 'optionid' => 42, 'userid' => $teacher->id]);
        $DB->insert_record('booking_teachers', (object)['bookingid' => 500, 'optionid' => 51, 'userid' => $teacher->id]);
        $DB->insert_record('booking_teachers', (object)['bookingid' => 400, 'optionid' => 43, 'userid' => $otherteacher->id]);

        $this->setUser($teacher);
        $optionids = booking::get_all_optionids_of_teacher(400);
        sort($optionids);
        $this->assertEquals([41, 42], array_map('intval', $optionids));
    }
}

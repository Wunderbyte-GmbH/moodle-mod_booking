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
 * G2 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Teil 3 - Typ 2 "offen nach Durchlauf"):
 * once an option's Typ-2 open-mode seat is actually taken (free capacity back to 0), the next
 * heartbeat run must deactivate open mode again - db_waitlist_offer_repository::
 * find_open_mode_options_to_deactivate()/deactivate_open_mode(). A second option, still genuinely
 * open (free capacity > 0), must be left untouched by the same heartbeat run - proving the
 * detection is capacity-driven, not a blanket "reset everything in open mode" sweep.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;
use mod_booking\task\waitlist_heartbeat_task;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * Open mode: the real heartbeat deactivates it once the seat is taken, but leaves a still-open
 * option's open mode untouched.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::find_open_mode_options_to_deactivate
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::deactivate_open_mode
 * @covers \mod_booking\task\waitlist_heartbeat_task::execute
 */
final class waitlist_openmode_heartbeat_deactivation_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Two options in open mode: one whose single seat was just taken by a direct (non-waitlist)
     * booking, one still genuinely free. Only the taken one must be deactivated.
     */
    public function test_heartbeat_deactivates_only_the_option_whose_seat_was_taken(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);

        $takenoptionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $DB->set_field('booking_options', 'waitlistrecycling', 2, ['id' => $takenoptionid]);
        $DB->set_field('booking_options', 'waitlistopenmode', 1, ['id' => $takenoptionid]);

        $stillopenoptionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $DB->set_field('booking_options', 'waitlistrecycling', 2, ['id' => $stillopenoptionid]);
        $DB->set_field('booking_options', 'waitlistopenmode', 1, ['id' => $stillopenoptionid]);

        // Someone books the seat directly (not through the waitlist) - free capacity drops to 0.
        $booker = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $this->getDataGenerator()->enrol_user($booker->id, $course->id, 'student');
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $booker->id,
            'optionid' => $takenoptionid,
            'timemodified' => 100,
            'timecreated' => 100,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'status' => 0,
        ]);
        singleton_service::destroy_booking_answers($takenoptionid);

        $repository = new db_waitlist_offer_repository();

        // Preconditions match what the real heartbeat's own detection query looks for.
        $this->assertContains(
            $takenoptionid,
            $repository->find_open_mode_options_to_deactivate(),
            'Precondition: the option whose seat was taken must be detected for deactivation.'
        );
        $this->assertNotContains(
            $stillopenoptionid,
            $repository->find_open_mode_options_to_deactivate(),
            'Precondition: the still genuinely open option must NOT be flagged for deactivation.'
        );

        $this->setAdminUser();
        (new waitlist_heartbeat_task())->execute();

        $this->assertFalse(
            $repository->is_open_mode_active($takenoptionid),
            'G2: the real heartbeat run must have deactivated open mode once the seat was taken.'
        );
        $this->assertTrue(
            $repository->is_open_mode_active($stillopenoptionid),
            'G2: a still genuinely open option must be left untouched by the same heartbeat run.'
        );
    }
}

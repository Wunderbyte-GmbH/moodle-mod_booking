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
 * C2 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie C - Verschachtelte
 * Mischfälle): a waiting list with BOTH kinds of lock present at once - one user permanently
 * declined (K7, reason=3) and one user whose offer expired (K4, reason=4) - with
 * waitlistrecycling enabled. db_waitlist_offer_repository_test.php already covers
 * reset_expired_locks()/find_recyclable_options() in isolation; this test goes one level further
 * and verifies the actual end-to-end reconcile() outcome after the reset: only the expired-lock
 * user must become eligible again, the declined-lock user must remain excluded forever, even
 * though both looked identically "locked out" before the reset (find_recyclable_options() itself
 * does not distinguish the two reasons when deciding a list is "fully flagged").
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\local\waitlist\offer_statuses\declined;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * C2: recycling must reset only the K4-locked candidate, never the K7-locked one, even on a
 * waiting list where both are present together.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::find_recyclable_options
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::reset_expired_locks
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class c2_mixed_k7_k4_recycling_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * One declined (K7) and one expired (K4) user on the same, fully-flagged, recycling-enabled
     * waiting list. After the recycling cycle: the K4 user gets a fresh offer/autobook, the K7
     * user is still excluded, and the K7 decline row itself is untouched.
     */
    public function test_recycling_resets_only_the_expired_candidate(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $DB->set_field('booking_options', 'waitlistrecycling', 1, ['id' => $optionid]);
        singleton_service::destroy_booking_option_singleton($optionid);
        $this->create_interval_rule(0); // ALWAYS.

        $declineduser = $this->waitlist_user($course, $optionid, 'freecat', 100);
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $declineduser->id,
            'timecreated' => 100,
            'reason' => (new declined())->get_code(),
        ]);

        $expireduser = $this->waitlist_user($course, $optionid, 'freecat', 200);
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $expireduser->id,
            'timecreated' => 200,
            'reason' => (new expired())->get_code(),
        ]);

        $repository = new db_waitlist_offer_repository();

        // Both users locked out, list "fully flagged" - the option must be recyclable, matching
        // the real waitlist_heartbeat_task's own detection.
        $this->assertContains(
            $optionid,
            $repository->find_recyclable_options(),
            'C2: a list with a mix of declined and expired locks must still count as recyclable.'
        );

        $repository->reset_expired_locks($optionid);

        $this->assertTrue(
            $DB->record_exists('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'C2: the K7 decline row must survive the reset untouched.'
        );
        $this->assertFalse(
            $DB->record_exists('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $expireduser->id]),
            'C2: the K4 expiry row must be gone after the reset.'
        );

        $this->setAdminUser();
        $this->build_progression()->reconcile($optionid, 'waitlist:recycled');

        // The K4 user is eligible again and gets processed (freecat -> autobooked).
        $expiredanswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $expireduser->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            (int) $expiredanswer->waitinglist,
            'C2: the previously-expired candidate must actually get booked after recycling.'
        );

        // The K7 user must still be completely excluded - reconcile() never even considered them.
        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'C2: the permanently declined candidate must never receive an offer, recycling or not.'
        );
        $declinedanswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $declineduser->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $declinedanswer->waitinglist,
            'C2: the K7 candidate must still just be sitting on the waiting list, untouched.'
        );
    }
}

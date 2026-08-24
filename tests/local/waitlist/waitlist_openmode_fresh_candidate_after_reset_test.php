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
 * G4 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Teil 3 - Typ 2 "offen nach Durchlauf"):
 * once open mode has been deactivated again (the freed seat was taken, see G2), a genuinely NEW
 * candidate joining the waiting list afterwards must be processed completely normally by
 * reconcile() (K1/K3/K4, an ordinary offer) - while the OLD, already-exhausted cohort from the
 * round that triggered open mode (one K4-expired, one K7-declined) must NOT be reconsidered, even
 * though the K4 one was allowed to book directly during open mode itself (see 2026-08-24
 * clarification: the ordered offer mechanism only ever looks at candidates without an existing
 * terminal offer/lock row - open mode's direct-grab bypass never resurrects them into the ordered
 * queue).
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
 * Open mode: after deactivation, a fresh candidate is offered normally; the old, already-
 * exhausted K4/K7 cohort is never reconsidered by the ordered mechanism.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class waitlist_openmode_fresh_candidate_after_reset_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Old K4 + K7 cohort already locked from a previous, now-consumed open-mode round. Open mode
     * is already deactivated (normal state). A brand-new candidate joins and reconcile() must
     * offer them normally, without ever touching the old cohort.
     */
    public function test_fresh_candidate_offered_normally_old_cohort_never_reconsidered(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $DB->set_field('booking_options', 'waitlistrecycling', 2, ['id' => $optionid]);
        // The waitlistopenmode field is intentionally left at its default 0 - open mode already
        // deactivated, exactly the state left behind after G2's own scenario played out.
        $this->create_interval_rule(0); // ALWAYS.

        $expireduser = $this->waitlist_user($course, $optionid, 'paidcat', 100);
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $expireduser->id,
            'timecreated' => 100,
            'reason' => (new expired())->get_code(),
        ]);

        $declineduser = $this->waitlist_user($course, $optionid, 'paidcat', 200);
        $DB->insert_record('booking_waitlist_declines', (object) [
            'optionid' => $optionid,
            'userid' => $declineduser->id,
            'timecreated' => 200,
            'reason' => (new declined())->get_code(),
        ]);

        $freshcandidate = $this->waitlist_user($course, $optionid, 'paidcat', 300);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'freetobookagain');
        $sink->close();

        $repository = new db_waitlist_offer_repository();
        $openoffers = $repository->get_open_offers($optionid);

        $this->assertCount(
            1,
            $openoffers,
            'G4: exactly one offer must be created - for the fresh candidate only.'
        );
        $this->assertEquals(
            (int) $freshcandidate->id,
            (int) $openoffers[0]->userid,
            'G4: the fresh candidate must receive the ordinary offer.'
        );
        $this->assertEquals(
            1,
            $openoffers[0]->status->get_code(),
            'K4: the fresh candidate must be genuinely offered (paid price), not autobooked.'
        );

        $this->assertCount(
            0,
            $DB->get_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $expireduser->id]),
            'G4: the old K4-expired candidate must NOT be reconsidered by the ordered mechanism.'
        );
        $this->assertCount(
            0,
            $DB->get_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $declineduser->id]),
            'G4: the old K7-declined candidate must NOT be reconsidered either.'
        );
        $this->assertFalse(
            $repository->is_open_mode_active($optionid),
            'G4: reconcile() must not have activated open mode - a genuine, unresolved candidate ' .
            '(the fresh one) still has an open offer, the list is not exhausted.'
        );
    }
}

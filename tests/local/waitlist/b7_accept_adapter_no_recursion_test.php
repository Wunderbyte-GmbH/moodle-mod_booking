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
 * B7 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie B - Regressionstests): a
 * dedicated regression test for the reentrant-recursion / memory-exhaustion bug found and fixed
 * 2026-08-21 in booking_accepted_waitlist_adapter (see its docblock). The adapter fires from
 * INSIDE booking_option::user_submit_response(), which is exactly what
 * progression::autobook() calls while iterating its own K1 batch loop inside an
 * already-in-progress reconcile() call. The original version of this adapter also called
 * progression_factory::get()->reconcile() - re-entering the very reconcile() call already on the
 * stack, autobooking further candidates that each re-trigger this same event, and so on. The fix
 * removed that reconcile() call entirely (accepting an offer never frees new capacity, so it was
 * both dangerous and pointless).
 *
 * Two tests: a precise, direct one pinning the actual invariant (accept() must never trigger new
 * offers/autobookings as a side effect), and a real end-to-end one that exercises the full event
 * chain through a multi-candidate K3 autobook batch - the exact shape of scenario that produced
 * the original crash.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\event\observer\booking_accepted_waitlist_adapter;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * B7: booking_accepted_waitlist_adapter::accept() must never re-enter reconcile().
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\event\observer\booking_accepted_waitlist_adapter::accept
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class b7_accept_adapter_no_recursion_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Direct, precise regression test: accept() must transition the accepting user's own offer,
     * but must NEVER create a new offer for anyone else - which is exactly what would happen if
     * accept() still called reconcile() internally (there is free capacity and a valid pending
     * candidate deliberately left in place to detect that side effect).
     */
    public function test_accept_never_triggers_a_new_offer_for_another_candidate(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        // 2 seats: A is about to occupy one (simulating a just-completed booking), leaving 1 free
        // seat that a wrongful reconcile() call would find and hand straight to B.
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);
        $ruleid = $this->create_interval_rule(0); // ALWAYS.

        $candidatea = $this->waitlist_user($course, $optionid, 'paidcat', 100);
        $candidateb = $this->waitlist_user($course, $optionid, 'paidcat', 200);

        // A already has an open K4 offer (as if a rule notified them earlier) and has just
        // finished paying/confirming - simulated directly, bypassing progression entirely, so
        // this test is a pure unit test of the adapter's own behaviour.
        $repository = new db_waitlist_offer_repository();
        $repository->create_offer($optionid, $candidatea->id, 1000, 1, new offered(), 2000000000, $ruleid);
        $DB->set_field(
            'booking_answers',
            'waitinglist',
            MOD_BOOKING_STATUSPARAM_BOOKED,
            ['optionid' => $optionid, 'userid' => $candidatea->id]
        );
        singleton_service::destroy_booking_answers($optionid);

        $this->setAdminUser();
        booking_accepted_waitlist_adapter::accept($optionid, (int) $candidatea->id);

        $aoffer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidatea->id]);
        $this->assertEquals(2, (int) $aoffer->status, 'A\'s own offer must transition to accepted (code 2).');

        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidateb->id]),
            'B7: accept() must never create a new offer for another candidate as a side effect - ' .
            'that would mean it re-entered reconcile(), the exact bug that caused the original ' .
            'memory-exhaustion crash.'
        );
        $banswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidateb->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $banswer->waitinglist,
            'B7: B must be left completely untouched by A\'s acceptance.'
        );
    }

    /**
     * Real end-to-end regression test: a multi-candidate K3 autobook batch is exactly the
     * scenario that produced the original crash - progression::autobook() calls
     * user_submit_response() for each free-price candidate in turn, which synchronously fires
     * the event this adapter listens to, from INSIDE the still-running reconcile() loop. With the
     * bug present, this would either recurse (stack/memory blow-up) or double-process candidates.
     * With the fix, it must complete cleanly with exactly one autobooked offer row per candidate.
     */
    public function test_multi_candidate_autobook_batch_completes_without_reentrant_reconcile(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $candidatecount = 5;
        $optionid = $this->create_priced_option($course, $teacher, $booking, $candidatecount, 5);
        $this->create_interval_rule(0); // ALWAYS.

        $candidates = [];
        for ($i = 0; $i < $candidatecount; $i++) {
            $candidates[] = $this->waitlist_user($course, $optionid, 'freecat', 100 + $i);
        }

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'test');
        $sink->close();

        $this->assertEquals(
            $candidatecount,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid]),
            'B7: exactly one offer row per candidate - a reentrant reconcile() would either ' .
            'double-process some candidates (more rows than candidates) or crash outright.'
        );
        foreach ($candidates as $candidate) {
            $this->assertEquals(
                1,
                $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]),
                "B7: candidate {$candidate->id} must have exactly one offer row, never zero or more than one."
            );
            $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
            $this->assertEquals(
                MOD_BOOKING_STATUSPARAM_BOOKED,
                (int) $answer->waitinglist,
                "B7: candidate {$candidate->id} must actually be booked (K3)."
            );
        }
    }
}

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
 * K7 "permanent until re-registration" (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §2.3): a lock
 * in booking_waitlist_declines - active decline (K7) as well as expired offer (K4) - is lifted as
 * soon as the person leaves the waiting list, so a later re-join is a normal fresh start. As long
 * as the person is still on the list, the lock must hold (see b1_k7_lock_persists_across_rounds_test).
 *
 * Leaving is detected in booking_option::user_delete_response(), i.e. every removal counts
 * (self-cancellation, removal by a manager, unloading a cart reservation) - but only once no
 * waiting-list or reserved answer of that person remains on the option.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\local\waitlist\offer_statuses\declined;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\local\waitlist\offer_statuses\offered;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * Locks are lifted when a person leaves the waiting list, never while they stay on it.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::user_delete_response
 */
final class k7_lock_lifted_on_leaving_waitlist_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Paid option with one free seat and an ALWAYS interval rule, so a candidate gets an OPEN
     * OFFER (not an autobooking) - that keeps "is this person offered again" directly observable.
     *
     * @return array [\stdClass $course, int $optionid]
     */
    private function create_option(): array {
        [$course, $teacher, $booking] = $this->prepare_course_and_booking('K7 lock lift');
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $this->create_interval_rule(0); // ALWAYS.
        return [$course, $optionid];
    }

    /**
     * Gives the user an offer and moves it to the given terminal status - transition() writes
     * the lock row with the matching reason, exactly as in production.
     *
     * @param int $optionid
     * @param stdClass $user
     * @param \mod_booking\local\waitlist\offer_status $status declined (K7) or expired (K4)
     * @return void
     */
    private function lock_via_offer(int $optionid, stdClass $user, offer_status $status): void {
        $repository = new db_waitlist_offer_repository();
        $offer = $repository->create_offer($optionid, (int) $user->id, 1, 1, new offered());
        $repository->transition($offer, $status);
        $this->assertTrue(
            $repository->is_permanently_declined($optionid, (int) $user->id),
            'Precondition: the ' . get_class($status) . ' transition must have written a lock.'
        );
    }

    /**
     * Removes all of the user's answers on the option the way the UI does it.
     *
     * @param int $optionid
     * @param int $userid
     * @param bool $cancelreservation true = only unload a cart reservation
     * @return void
     */
    private function leave(int $optionid, int $userid, bool $cancelreservation = false): void {
        $this->setAdminUser();
        // The fixture writes answers straight to the DB - purge the answers cache and singleton
        // built before that, as write_user_answer_to_db() does on the regular booking path.
        \mod_booking\booking_option::purge_cache_for_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $option = singleton_service::get_instance_of_booking_option((int) $settings->cmid, $optionid);
        $option->user_delete_response($userid, $cancelreservation);
        singleton_service::destroy_booking_answers($optionid);
    }

    /**
     * K7: an active decline locks the person out while they stay on the list - but once they
     * leave the waiting list, the lock must be gone.
     */
    public function test_declined_lock_is_lifted_when_the_person_leaves(): void {
        [$course, $optionid] = $this->create_option();
        $user = $this->waitlist_user($course, $optionid, 'paidcat', 1000);
        $this->lock_via_offer($optionid, $user, new declined());

        $this->leave($optionid, (int) $user->id);

        $this->assertFalse(
            (new db_waitlist_offer_repository())->is_permanently_declined($optionid, (int) $user->id),
            'K7: leaving the waiting list must lift the decline lock ("until re-registration").'
        );
    }

    /**
     * K4: the same applies to a lock written by an expired offer.
     */
    public function test_expired_lock_is_lifted_when_the_person_leaves(): void {
        [$course, $optionid] = $this->create_option();
        $user = $this->waitlist_user($course, $optionid, 'paidcat', 1000);
        $this->lock_via_offer($optionid, $user, new expired());

        $this->leave($optionid, (int) $user->id);

        $this->assertFalse(
            (new db_waitlist_offer_repository())->is_permanently_declined($optionid, (int) $user->id),
            'K4: leaving the waiting list must lift the expiry lock as well.'
        );
    }

    /**
     * The actual user-visible effect: decline, leave, re-join - the person is offered a free
     * seat again like any other candidate.
     */
    public function test_person_who_declined_left_and_rejoined_is_offered_again(): void {
        global $DB;

        [$course, $optionid] = $this->create_option();
        $user = $this->waitlist_user($course, $optionid, 'paidcat', 1000);
        $this->lock_via_offer($optionid, $user, new declined());

        $this->leave($optionid, (int) $user->id);

        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $user->id,
            'optionid' => $optionid,
            'timemodified' => 5000,
            'timecreated' => 5000,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            'status' => 0,
        ]);
        singleton_service::destroy_booking_answers($optionid);

        $this->build_progression()->reconcile($optionid, 'k7-rejoin');

        $offers = array_filter(
            (new db_waitlist_offer_repository())->get_open_offers($optionid),
            fn($offer) => $offer->userid === (int) $user->id
        );
        $this->assertCount(
            1,
            $offers,
            'K7: after leaving and re-joining, the person must be offered the free seat again.'
        );
    }

    /**
     * Guard: unloading only a cart reservation while a waiting-list row of the same person still
     * exists is NOT leaving the list - the lock must stay, otherwise the reconcile() running inside
     * that removal could re-offer a person who is still waiting behind their lock.
     */
    public function test_lock_stays_when_a_waitinglist_row_remains(): void {
        global $DB;

        [$course, $optionid] = $this->create_option();
        $user = $this->waitlist_user($course, $optionid, 'paidcat', 1000);
        $DB->insert_record('booking_answers', (object) [
            'bookingid' => 0,
            'userid' => $user->id,
            'optionid' => $optionid,
            'timemodified' => 1100,
            'timecreated' => 1100,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
            'status' => 0,
        ]);
        $this->lock_via_offer($optionid, $user, new expired());

        $this->leave($optionid, (int) $user->id, true);

        $this->assertTrue(
            $DB->record_exists('booking_answers', [
                'optionid' => $optionid,
                'userid' => $user->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]),
            'Precondition: only the reservation was unloaded, the waiting-list row is still there.'
        );
        $this->assertTrue(
            (new db_waitlist_offer_repository())->is_permanently_declined($optionid, (int) $user->id),
            'The lock must stay while the person is still on the waiting list.'
        );
    }
}

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
 * D3 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie D - Confirmation-Feinheiten):
 * unconfirm_waitlist_adapter::decline() (T4) is documented as "a no-op if the user has no open
 * offer under the new mechanism (e.g. they were only ever offered via the old chain)" - a real
 * "Altbestand" scenario: a genuinely pre-migration candidate, or simply someone who manually
 * unconfirms before ever receiving any offer at all under the new engine. This test proves that
 * no-op precisely: no exception, no K7 lock accidentally created out of thin air, the candidate's
 * waiting-list state left completely untouched, AND - the part that actually matters, not just
 * "did not crash" - the candidate remains fully eligible for a genuine offer in a later round,
 * since they were never actually declined.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\event\observer\unconfirm_waitlist_adapter;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * D3: unconfirm_waitlist_adapter::decline() must not crash and must not lock out a candidate who
 * never had an offer to begin with.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\event\observer\unconfirm_waitlist_adapter::decline
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class d3_unconfirm_without_existing_offer_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * A candidate on the waiting list who has NEVER received any offer under the new mechanism
     * (no reconcile() has run for them yet) gets a manual unconfirm. Must be a clean no-op: no
     * exception, no decline row, untouched answer - and still fully eligible for a genuine offer
     * afterwards.
     */
    public function test_unconfirm_without_offer_is_a_clean_noop_and_does_not_lock_the_candidate(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $ruleid = $this->create_interval_rule(0); // ALWAYS.

        $candidate = $this->waitlist_user($course, $optionid, 'freecat', 100);
        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]),
            'Sanity: the candidate must genuinely have no offer at all yet under the new mechanism.'
        );

        $this->setAdminUser();
        unconfirm_waitlist_adapter::decline($optionid, (int) $candidate->id); // Must not throw.

        $this->assertEquals(
            0,
            $DB->count_records('booking_waitlist_declines', ['optionid' => $optionid, 'userid' => $candidate->id]),
            'D3: no K7 lock may be created out of thin air - the candidate was never actually declined.'
        );
        $answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $answer->waitinglist,
            'D3: the candidate\'s answer must be left completely untouched.'
        );

        // The part that actually matters: the candidate must still be eligible for a genuine
        // offer afterwards - the no-op unconfirm must not have silently locked them out.
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'test');
        $sink->close();

        $offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertNotEmpty(
            $offer,
            'D3: the candidate must be fully eligible for a genuine offer in a later round - the ' .
            'earlier no-op unconfirm must never have locked them out.'
        );
        $this->assertEquals(6, (int) $offer->status, 'K3: the free-price candidate must be autobooked.');
        $this->assertEquals($ruleid, (int) $offer->ruleid);
    }
}

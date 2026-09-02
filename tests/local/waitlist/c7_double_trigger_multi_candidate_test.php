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
 * C7 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie C - Verschachtelte
 * Mischfälle, K5): existing K5/idempotency coverage (e.g. progression_test.php) exercises a
 * single candidate. This test scales it up to a whole batch: THREE simultaneously-affected
 * candidates (a mix of free/autobook and paid/offer decisions, like A1), processed by one
 * reconcile() call, then a second, duplicate reconcile() call immediately after - simulating two
 * near-simultaneous triggers for the same underlying capacity change (e.g. a double-fired event,
 * or the same free seat reported by two independent trigger paths). The second call must be a
 * complete no-op for EVERY candidate, not just avoid re-processing one of them while missing
 * another.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * C7: a duplicate trigger must be a complete no-op for a whole batch of candidates, not just one.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::get_unbehandelte_waitinglist
 */
final class c7_double_trigger_multi_candidate_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * 3 seats, 3 candidates (2 free -> autobook, 1 paid -> offer) resolved in round 1. An
     * immediate second reconcile() call (the duplicate trigger) must leave every single one of
     * them completely untouched - same offer rows, same statuses, no new rows, no new messages.
     */
    public function test_immediate_duplicate_reconcile_is_a_complete_noop_for_the_whole_batch(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('freecat', 0);
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 3, 5);
        $this->create_interval_rule(0); // ALWAYS.

        $free1 = $this->waitlist_user($course, $optionid, 'freecat', 100);
        $free2 = $this->waitlist_user($course, $optionid, 'freecat', 200);
        $paid1 = $this->waitlist_user($course, $optionid, 'paidcat', 300);

        $this->setAdminUser();

        $sink1 = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'trigger1');
        $messagesafterround1 = count($sink1->get_messages());
        $sink1->close();

        $offersafterround1 = $DB->get_records('booking_waitlist_offers', ['optionid' => $optionid]);
        $this->assertCount(3, $offersafterround1, 'Round 1 must resolve all 3 candidates.');

        // The duplicate trigger - simulates a second, near-simultaneous event for the same
        // underlying capacity change.
        $sink2 = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'trigger2 - duplicate');
        $newmessages = $sink2->get_messages();
        $sink2->close();

        $this->assertEmpty(
            $newmessages,
            'C7: the duplicate trigger must not send any new notification to anyone.'
        );

        $offersafterround2 = $DB->get_records('booking_waitlist_offers', ['optionid' => $optionid]);
        $this->assertCount(
            3,
            $offersafterround2,
            'C7: the duplicate trigger must not create any additional offer rows - still exactly 3.'
        );
        $this->assertEquals(
            $offersafterround1,
            $offersafterround2,
            'C7: every single offer row (id, status, roundid, everything) must be byte-identical ' .
            'after the duplicate trigger - not just the same COUNT of rows.'
        );

        // Each candidate individually, to make a partial-idempotency failure (some candidates
        // protected, others not) impossible to miss.
        foreach ([$free1, $free2, $paid1] as $candidate) {
            $this->assertEquals(
                1,
                $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]),
                "C7: candidate {$candidate->id} must have exactly one offer row, not two."
            );
        }

        // Free-price candidates ended up booked, the paid one offered - untouched by round 2.
        $free1answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $free1->id]);
        $free2answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $free2->id]);
        $paid1answer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $paid1->id]);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int) $free1answer->waitinglist);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, (int) $free2answer->waitinglist);
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, (int) $paid1answer->waitinglist);
    }
}

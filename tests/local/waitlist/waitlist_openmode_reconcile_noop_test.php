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
 * G3 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Teil 3 - Typ 2 "offen nach Durchlauf"):
 * while an option is in open mode, progression::reconcile() must create NO new offers at all,
 * even when a fresh, genuinely new trigger fires (e.g. a brand-new candidate joining the waiting
 * list while the seat is still open) - the seat stays a direct, first-come-first-served grab, not
 * something the reconciler distributes via the usual K1/K3/K4 mechanism. Open mode itself must
 * also stay untouched by the call.
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
 * Open mode: reconcile() must be a complete no-op, even for a brand-new candidate and a genuinely
 * fresh trigger.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 */
final class waitlist_openmode_reconcile_noop_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Option in open mode, one free seat, an active rule that WOULD create an offer if reconcile()
     * processed normally. A brand-new candidate joins the waiting list and reconcile() is called
     * (simulating a fresh trigger) - no offer may be created, open mode must stay active, and the
     * candidate must remain untouched on the waiting list.
     */
    public function test_reconcile_creates_no_offer_while_open_mode_is_active(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $DB->set_field('booking_options', 'waitlistrecycling', 2, ['id' => $optionid]);
        $DB->set_field('booking_options', 'waitlistopenmode', 1, ['id' => $optionid]);
        $this->create_interval_rule(0); // ALWAYS - would normally trigger an offer.

        $newcandidate = $this->waitlist_user($course, $optionid, 'paidcat', 100);

        $repository = new db_waitlist_offer_repository();
        $this->assertTrue(
            $repository->is_open_mode_active($optionid),
            'Precondition: open mode must be active before reconcile() is called.'
        );

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'latejoiner');
        $sink->close();

        $this->assertCount(
            0,
            $repository->get_open_offers($optionid),
            'G3: reconcile() must create NO offer at all while open mode is active, even for a ' .
            'brand-new candidate and a genuinely fresh trigger.'
        );
        $this->assertTrue(
            $repository->is_open_mode_active($optionid),
            'G3: reconcile() must never itself change the open-mode flag.'
        );
        $newcandidateanswer = $DB->get_record('booking_answers', ['optionid' => $optionid, 'userid' => $newcandidate->id]);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            (int) $newcandidateanswer->waitinglist,
            'G3: the new candidate must remain untouched on the waiting list, not offered or booked.'
        );
    }
}

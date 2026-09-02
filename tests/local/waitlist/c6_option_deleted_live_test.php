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
 * C6 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie C - Verschachtelte
 * Mischfälle, K10): option deletion was previously only characterized against the real migration
 * case (an orphaned task_adhoc row pointing at a deleted option, see the C3 migration test). This
 * test covers the LIVE-operation case instead: an option with an open offer and its own already
 * scheduled expire_waitlist_offer_adhoc task gets deleted while both are still pending - a real
 * scenario (admin deletes the option, or the whole course, between the offer being sent and the
 * candidate reacting to it). Both progression::reconcile() (via capacity_calculator's defensive
 * empty($settings) check) and expire_waitlist_offer_adhoc::execute() must handle this cleanly -
 * no crash/exception anywhere in the chain, including the reconcile() call
 * expire_waitlist_offer_adhoc triggers internally on expiry.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;
use mod_booking\task\expire_waitlist_offer_adhoc;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * C6: a deleted option must never crash reconcile() or the offer-expiry task.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\capacity_calculator::free_capacity
 * @covers \mod_booking\task\expire_waitlist_offer_adhoc::execute
 */
final class c6_option_deleted_live_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * An option with a genuine open offer is deleted outright. A direct reconcile() call for the
     * now-nonexistent option must be a clean no-op. The already-scheduled expire task for that
     * offer must also run cleanly - transitioning the (still-existing) offer row to expired and
     * internally re-reconciling the (now-gone) option without throwing.
     */
    public function test_deleted_option_never_crashes_reconcile_or_the_expire_task(): void {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);
        $this->create_interval_rule(0); // ALWAYS.

        $candidate = $this->waitlist_user($course, $optionid, 'paidcat', 100);

        $this->setAdminUser();
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'round1');
        $sink->close();

        $offer = $DB->get_record('booking_waitlist_offers', ['optionid' => $optionid, 'userid' => $candidate->id]);
        $this->assertNotEmpty($offer, 'Candidate must have a genuine open offer before the option is deleted.');
        $this->assertEquals(1, (int) $offer->status, 'K4: offered.');

        $expiretasks = $DB->get_records('task_adhoc', ['classname' => '\mod_booking\task\expire_waitlist_offer_adhoc']);
        $this->assertCount(1, $expiretasks, 'An expire task must already be scheduled for this offer.');

        // K10: the option is deleted outright, while the offer and its expire task both still
        // exist - a real live-operation scenario, not just the migration case.
        $DB->delete_records('booking_options', ['id' => $optionid]);
        singleton_service::destroy_booking_option_singleton($optionid);
        singleton_service::destroy_booking_answers($optionid);

        // A direct reconcile() call for the now-nonexistent option must be a clean no-op.
        $this->build_progression()->reconcile($optionid, 'round2'); // Must not throw.
        $this->assertEquals(
            1,
            $DB->count_records('booking_waitlist_offers', ['optionid' => $optionid]),
            'C6: reconcile() on a deleted option must not create/change anything - the offer ' .
            'row must be exactly as it was.'
        );

        // The already-scheduled expire task must also run cleanly, including the reconcile()
        // it triggers internally on expiry.
        $task = reset($expiretasks);
        $adhoctask = new expire_waitlist_offer_adhoc();
        $adhoctask->set_custom_data(json_decode($task->customdata));
        $adhoctask->execute(); // Must not throw.

        $offerafter = $DB->get_record('booking_waitlist_offers', ['id' => $offer->id]);
        $this->assertEquals(
            4,
            (int) $offerafter->status,
            'C6: the offer must still transition to expired even though the option is gone - ' .
            'that transition is a pure offer-row write, independent of the option\'s existence.'
        );
    }
}

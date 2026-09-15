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
 * E1 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie E - Wartelisten-Recycling,
 * E2E-Variante): db_waitlist_offer_repository_test.php already covers find_recyclable_options()/
 * reset_expired_locks() directly, and waitlist_heartbeat_task_test.php's own recycling tests
 * already run waitlist_heartbeat_task::execute() for real - but they force the K4 lock into
 * existence with a direct $repository->transition($offer, new expired()) call, skipping the real
 * expiry mechanism entirely. This test runs the FULL real chain instead, using only genuine
 * production entry points: reconcile() creates a real offer with a real hard-expiry deadline,
 * the clock is advanced past it, the REAL expire_waitlist_offer_adhoc task (as scheduled by
 * progression::offer() itself) expires it and re-reconciles, driving the option into a genuinely
 * "fully flagged" state - and only THEN does the real waitlist_heartbeat_task detect and recycle
 * it. No step is faked or shortcut.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\singleton_service;
use mod_booking\task\expire_waitlist_offer_adhoc;
use mod_booking\task\waitlist_heartbeat_task;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * E1: the full real chain (offer -> real expiry -> real heartbeat detection -> real recycling)
 * must end with the candidate offered again.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\task\waitlist_heartbeat_task::execute
 * @covers \mod_booking\task\expire_waitlist_offer_adhoc::execute
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::find_recyclable_options
 */
final class e1_heartbeat_recycling_e2e_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        set_config('cacheturnoffforbookinganswers', 1, 'booking');
    }

    /**
     * Genuine offer -> real hard-expiry task fires -> option is now fully flagged -> real
     * heartbeat task detects and recycles it -> candidate is offered again. Every step goes
     * through its real production entry point, none of it hand-forced.
     */
    public function test_full_real_chain_from_offer_to_expiry_to_heartbeat_recycling(): void {
        global $DB;

        $clock = $this->mock_clock_with_frozen(5000000000);

        [$course, $teacher, $booking] = $this->prepare_course_and_booking();
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 1, 5);
        $DB->set_field('booking_options', 'waitlistrecycling', 1, ['id' => $optionid]);
        singleton_service::destroy_booking_option_singleton($optionid);
        $ruleid = $this->create_interval_rule(0, 'e1subj', 'e1tmpl', 10); // ALWAYS, 10 minutes.

        $candidate = $this->waitlist_user($course, $optionid, 'paidcat', 100);

        $this->setAdminUser();
        $repository = new db_waitlist_offer_repository();

        // Step 1: a genuine offer, created via the real reconciler.
        $sink = $this->redirectMessages();
        $this->build_progression()->reconcile($optionid, 'setup');
        $sink->close();

        $firstoffer = $repository->get_open_offers($optionid)[0] ?? null;
        $this->assertNotEmpty($firstoffer, 'Precondition: the candidate must have a genuine open offer.');
        $this->assertEquals(1, $firstoffer->status->get_code(), 'K4: offered.');
        $firstroundid = $firstoffer->roundid;

        $expiretaskrow = $DB->get_record(
            'task_adhoc',
            ['classname' => '\mod_booking\task\expire_waitlist_offer_adhoc'],
            '*',
            MUST_EXIST
        );

        // Step 2: time passes the hard-expiry deadline.
        $clock->set_to((int) $firstoffer->expiresat + 1);

        // Step 3: the REAL expire task runs (not a hand-forced transition()) - expires the offer
        // and internally re-reconciles, which finds nobody eligible (the candidate is now K4
        // locked, and is the only waiting-list member) - a clean no-op that leaves the option
        // genuinely fully flagged.
        $expiretask = new expire_waitlist_offer_adhoc();
        $expiretask->set_custom_data(json_decode($expiretaskrow->customdata));
        $expiretask->execute();

        $this->assertTrue(
            $repository->is_permanently_declined($optionid, (int) $candidate->id),
            'Precondition: the real expiry must have locked the candidate out (K4).'
        );
        $this->assertCount(
            0,
            $repository->get_open_offers($optionid),
            'Precondition: no open offer must remain after the real expiry.'
        );
        $this->assertContains(
            $optionid,
            $repository->find_recyclable_options(),
            'Precondition: the option must now genuinely be detected as fully flagged.'
        );

        // Step 4: the REAL heartbeat task runs - no repository method called directly.
        $clock->bump(1000); // Distinct roundid from the setup round.
        (new waitlist_heartbeat_task())->execute();

        $this->assertFalse(
            $repository->is_permanently_declined($optionid, (int) $candidate->id),
            'E1: the real heartbeat run must have reset the K4 lock.'
        );
        $secondoffers = $repository->get_open_offers($optionid);
        $this->assertCount(1, $secondoffers, 'E1: the real heartbeat run must have re-offered to the candidate.');
        $this->assertEquals((int) $candidate->id, (int) $secondoffers[0]->userid);
        $this->assertEquals(1, $secondoffers[0]->status->get_code(), 'K4: offered again.');
        $this->assertEquals($ruleid, (int) $secondoffers[0]->ruleid);
        $this->assertNotEquals(
            $firstroundid,
            $secondoffers[0]->roundid,
            'E1: the recycled offer must belong to a genuinely new round, not the original one.'
        );

        unset($clock);
    }
}

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
 * C1 (M1, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): a genuinely running
 * send_mail_interval chain must survive the Phase 2/3 migration - the already-treated user
 * stays treated, the still-pending users are correctly picked up by the new mechanism.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Migration test for a running send_mail_interval chain (M1).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §6
 * (still "Entwurf, noch nicht final abgenommen" at the time this test was written). The class
 * names/namespaces (\mod_booking\local\waitlist\migration\upgrade_step,
 * \mod_booking\local\waitlist\progression_factory, \mod_booking\local\waitlist\
 * db_waitlist_offer_repository) do not exist yet - this test is guarded with class_exists()
 * and markTestSkipped() until Phase 2 lands them, per the Blueprint's requirement that
 * Category C tests be fully written and reviewed before Phase 2 begins, even though they stay
 * red/skipped until upgrade_step exists. Minor signature adjustments are expected once the
 * real classes land; the assertions' INTENT (documented in each one) is the stable part.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\migration\upgrade_step::run
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::get_unbehandelte_waitinglist
 * @runInSeparateProcess
 */
final class waitlist_migration_c1_running_chain_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The three planned Phase 2 classes this test needs. All three not existing yet is the
     * expected state throughout Phase 1 - the test stays skipped until Phase 2 lands them.
     *
     * @return bool
     */
    private function target_api_exists(): bool {
        return class_exists('\mod_booking\local\waitlist\migration\upgrade_step')
            && class_exists('\mod_booking\local\waitlist\progression_factory')
            && class_exists('\mod_booking\local\waitlist\db_waitlist_offer_repository');
    }

    /**
     * C1 (M1): running chain, upgrade must preserve "already treated" and correctly hand off
     * "still pending" to the new reconciler.
     */
    public function test_c1_running_mail_interval_chain_survives_migration(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'upgrade_step/progression_factory/db_waitlist_offer_repository do not exist yet ' .
                '(Phase 2). This test is fully written against the planned target API - see the ' .
                'class docblock - and will be activated once those classes land.'
            );
        }

        // Build a genuinely running M1 fixture through the CURRENT (pre-refactor) engine: one
        // user already treated (received their mail), two more only reachable via the still-
        // pending repeat task.
        $fixture = $this->build_running_mail_interval_chain(3);
        $treateduserid = (int) $fixture->treateduser->id;
        $pendinguserids = array_map(
            fn($u) => (int) $u->id,
            array_filter($fixture->waitlistusers, fn($u) => (int) $u->id !== $treateduserid)
        );

        // The fixture's own maxanswers=1 only ever frees ONE seat (the vacated occupant's) -
        // genuinely just enough for the already-treated user's migrated offer, structurally not
        // enough for the K1 catch-up this test is actually about. Free enough capacity for
        // EVERYONE still waiting (not just the one the old, one-at-a-time chain was aware of) -
        // this is the concrete K1/T8 scenario the migration must handle correctly: more capacity
        // may genuinely be available than the old chain ever knew about.
        global $DB;
        $DB->set_field('booking_options', 'maxanswers', count($pendinguserids) + 1, ['id' => $fixture->option->id]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete((int) $fixture->option->id);
        singleton_service::destroy_booking_option_singleton((int) $fixture->option->id);

        // Run the migration once (this is what db/upgrade.php calls in Phase 3, per the plan's
        // "echter Aufruf von upgrade_step::run() im Versions-Upgrade").
        $upgradestepclass = '\mod_booking\local\waitlist\migration\upgrade_step';
        $upgradestepclass::run();

        // Ask the new mechanism to reconcile this option, exactly as a real trigger
        // (e.g. the heartbeat, or the repeat task's replacement) would.
        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $progression = $factoryclass::get();
        $progression->reconcile((int) $fixture->option->id, 'migration_test_c1');

        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $repository = new $repositoryclass();

        // The already-treated user must NOT be re-offered/re-notified by the new mechanism -
        // "behandelte Person bleibt behandelt". They must not appear as an "unbehandelt"
        // candidate for a fresh offer.
        $unbehandelt = $repository->get_unbehandelte_waitinglist((int) $fixture->option->id, []);
        $unbehandeltuserids = array_map(fn($u) => (int) ($u->userid ?? $u->id), $unbehandelt);
        $this->assertNotContains(
            $treateduserid,
            $unbehandeltuserids,
            'C1/M1: the already-treated user must not be treated as an unhandled candidate after migration.'
        );

        // The still-pending users must now be correctly picked up by the NEW mechanism - each
        // must have an open offer, OR have been autobooked (this fixture's option has no price
        // category configured, so price resolves to 0 - K3 autobook, not K4 offer - a valid,
        // just differently-shaped, "picked up" outcome; get_open_offers() alone would not see it,
        // since autobooked is a terminal status, not an open one).
        $openoffers = $repository->get_open_offers((int) $fixture->option->id);
        $openofferuserids = array_map(fn($o) => (int) $o->userid, $openoffers);
        foreach ($pendinguserids as $pendinguserid) {
            $isopenoffer = in_array($pendinguserid, $openofferuserids, true);
            $isautobooked = !$DB->record_exists('booking_answers', [
                'optionid' => (int) $fixture->option->id,
                'userid' => $pendinguserid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]);
            $this->assertTrue(
                $isopenoffer || $isautobooked,
                "C1/M1: still-pending user {$pendinguserid} must have been picked up (open offer " .
                'or autobooked) by the new mechanism after migration - nothing may be silently lost.'
            );
        }

        // No user may end up with more than one open offer (K5/idempotency must hold across
        // the migration boundary too - the UNIQUE(optionid, roundid, userid) constraint from
        // §2.1 is the structural guarantee, this asserts its observable effect).
        $this->assertEquals(
            count(array_unique($openofferuserids)),
            count($openofferuserids),
            'C1/M1: no user may end up with more than one open offer after migration.'
        );
    }
}

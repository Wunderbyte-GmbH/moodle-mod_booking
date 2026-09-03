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
 * C2 (M2, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): an open confirm_bookinganswer offer
 * must survive the Phase 2/3 migration unchanged - it stays open for exactly the one offered
 * person, and the exclusive-mode invariant (at most one simultaneously open offer per option)
 * holds across the migration boundary.
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
 * Migration test for an open confirm_bookinganswer offer (M2), exclusive mode.
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §6
 * (still "Entwurf, noch nicht final abgenommen" at the time this test was written) - same
 * caveats as C1 (waitlist_migration_c1_running_chain_test.php): the target classes do not
 * exist yet, this test is guarded with class_exists()/markTestSkipped() and will need minor
 * signature reconciliation once Phase 2 lands them. The assertions' INTENT is the stable part.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\migration\upgrade_step::run
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::get_open_offers
 * @runInSeparateProcess
 */
final class waitlist_migration_c2_open_offer_test extends booking_advanced_testcase {
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
     * C2 (M2): an open, exclusive-mode confirm offer must survive migration unchanged - still
     * open, still for the same person, still the only open offer for the option.
     */
    public function test_c2_open_exclusive_confirm_offer_survives_migration(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'upgrade_step/progression_factory/db_waitlist_offer_repository do not exist yet ' .
                '(Phase 2). This test is fully written against the planned target API - see the ' .
                'class docblock - and will be activated once those classes land.'
            );
        }

        // Build a genuinely open M2 fixture through the CURRENT (pre-refactor) engine:
        // exclusive mode (confirmationonnotification=2), one user has an untouched, open
        // direct confirm task - nobody else may hold a simultaneously open offer.
        $fixture = $this->build_running_confirm_chain(2, 2);
        $offereduserid = (int) $fixture->offereduser->id;
        $otheruserids = array_map(
            fn($u) => (int) $u->id,
            array_filter($fixture->waitlistusers, fn($u) => (int) $u->id !== $offereduserid)
        );

        // Run the migration once, then reconcile - same sequence as C1.
        $upgradestepclass = '\mod_booking\local\waitlist\migration\upgrade_step';
        $upgradestepclass::run();

        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $progression = $factoryclass::get();
        $progression->reconcile((int) $fixture->option->id, 'migration_test_c2');

        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $repository = new $repositoryclass();

        $openoffers = $repository->get_open_offers((int) $fixture->option->id);
        $openofferuserids = array_map(fn($o) => (int) $o->userid, $openoffers);

        // C2/M2 core guarantee: the offer survives unchanged - still open, still for the
        // originally offered person. The migration must not silently drop it, nor "resolve"
        // it as a side effect of running.
        $this->assertContains(
            $offereduserid,
            $openofferuserids,
            'C2/M2: the originally offered user\'s open offer must survive the migration - not dropped, not silently resolved.'
        );

        // Exclusive mode invariant: at most one simultaneously open offer for this option,
        // across the migration boundary. None of the OTHER waiting-list users may have been
        // given a second, competing open offer.
        foreach ($otheruserids as $otheruserid) {
            $this->assertNotContains(
                $otheruserid,
                $openofferuserids,
                "C2/M2: exclusive mode must hold across migration - user {$otheruserid} must not " .
                'have an open offer while the originally offered user still does.'
            );
        }
        $this->assertCount(
            1,
            $openofferuserids,
            'C2/M2: exactly one open offer must exist for this option after migration (exclusive mode).'
        );

        // The offer must genuinely still be ACTIONABLE post-migration - not merely present as
        // an inert row. Confirming it now (via the new repository, if it exposes a direct
        // transition) or, at minimum, its status must still be an open/offered one, not
        // terminal (accepted/declined/expired/skipped).
        $survivingoffer = null;
        foreach ($openoffers as $offer) {
            if ((int) $offer->userid === $offereduserid) {
                $survivingoffer = $offer;
                break;
            }
        }
        $this->assertNotNull($survivingoffer, 'C2/M2: the surviving offer record must be retrievable.');
        $this->assertFalse(
            $survivingoffer->status->is_terminal(),
            'C2/M2: the migrated offer must still be in a non-terminal (open/offered) state, ready to be acted on.'
        );
    }
}

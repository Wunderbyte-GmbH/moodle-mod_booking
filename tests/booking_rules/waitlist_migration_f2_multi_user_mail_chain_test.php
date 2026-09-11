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
 * F2 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie F - Migration + laufender
 * Betrieb im Zusammenspiel): every existing M1 migration test (C1, C3, C4, C5, and
 * legacy_chain_reader_send_mail_interval_test.php) exercises usersalreadytreated with exactly
 * ONE entry - waitlist_old_chain_fixture_trait::build_running_mail_interval_chain() hardcodes it
 * that way. This test re-uses that same fixture but rewrites the repeat task's own customdata
 * afterwards to carry TWO already-treated users instead of one - a genuine, realistic case: the
 * old chain notifying more than one person before the site upgraded (in fact exactly the kind of
 * uncontrolled multi-notify the u:rise incident was about - no real capacity check existed in the
 * old engine). Verifies upgrade_step::run() migrates BOTH users correctly, in the right order,
 * while leaving the genuinely untreated waiting-list users alone for a later reconcile() round.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\waitlist\db_waitlist_offer_repository;
use mod_booking\local\waitlist\migration\upgrade_step;
use mod_booking\local\waitlist\progression_factory;
use mod_booking\singleton_service;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * F2: a legacy mail-interval chain with MULTIPLE already-treated users must migrate all of them
 * correctly, in order, leaving the genuinely untreated candidates untouched.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\migration\upgrade_step::run
 * @covers \mod_booking\local\waitlist\migration\legacy_chain_reader_send_mail_interval::extract
 * @runInSeparateProcess
 */
final class waitlist_migration_f2_multi_user_mail_chain_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The fixture's own repeat task carries exactly one already-treated user by default - this
     * test rewrites its customdata to carry TWO, then migrates.
     */
    public function test_migration_handles_more_than_one_already_treated_user(): void {
        global $DB;

        // 4 waiting-list users: 2 will be marked already-treated, 2 remain genuinely pending.
        $fixture = $this->build_running_mail_interval_chain(4);
        $waitlistusers = $fixture->waitlistusers;
        $treated1 = (int) $waitlistusers[0]->id; // Already treated per the fixture's own default.
        $treated2 = (int) $waitlistusers[1]->id; // Will be added as a second treated user.
        $pending1 = (int) $waitlistusers[2]->id;
        $pending2 = (int) $waitlistusers[3]->id;

        // Rewrite the repeat task's customdata to carry BOTH treated users, in order.
        $customdata = json_decode($fixture->repeattask->customdata);
        $rulejson = json_decode($customdata->rulejson);
        $rulejson->intervaldata->usersalreadytreated = [$treated1, $treated2];
        $customdata->rulejson = json_encode($rulejson);
        $DB->update_record('task_adhoc', (object) [
            'id' => $fixture->repeattask->id,
            'customdata' => json_encode($customdata),
        ]);

        // Migrate.
        upgrade_step::run();

        $repository = new db_waitlist_offer_repository();
        $offers = $repository->get_open_offers((int) $fixture->option->id);
        $offersbyuserid = [];
        foreach ($offers as $offer) {
            $offersbyuserid[(int) $offer->userid] = $offer;
        }

        // Both treated users must have been migrated - each as their own offer row.
        $this->assertArrayHasKey($treated1, $offersbyuserid, 'F2: the first treated user must be migrated.');
        $this->assertArrayHasKey($treated2, $offersbyuserid, 'F2: the second treated user must ALSO be migrated.');
        $this->assertFalse(
            $offersbyuserid[$treated1]->status->is_terminal(),
            'F2: the first migrated offer must be open/actionable.'
        );
        $this->assertFalse(
            $offersbyuserid[$treated2]->status->is_terminal(),
            'F2: the second migrated offer must ALSO be open/actionable.'
        );

        // Order preserved: sortorder must match their position in usersalreadytreated (1, 2).
        $this->assertEquals(
            1,
            (int) $offersbyuserid[$treated1]->sortorder,
            'F2: the first treated user\'s sortorder must be 1.'
        );
        $this->assertEquals(
            2,
            (int) $offersbyuserid[$treated2]->sortorder,
            'F2: the second treated user\'s sortorder must be 2, not scrambled or both 1.'
        );

        // Both migrated offers belong to the same migration batch (same roundid).
        $this->assertEquals(
            $offersbyuserid[$treated1]->roundid,
            $offersbyuserid[$treated2]->roundid,
            'F2: both migrated offers must belong to the same round.'
        );

        // The genuinely untreated users must NOT have been migrated - they were never in
        // usersalreadytreated, so the migration must leave them alone for a later reconcile().
        $this->assertArrayNotHasKey($pending1, $offersbyuserid, 'F2: a genuinely untreated user must not be migrated.');
        $this->assertArrayNotHasKey($pending2, $offersbyuserid, 'F2: neither must the other untreated user.');
        $this->assertCount(2, $offers, 'F2: exactly 2 offers total after migration - not more, not fewer.');

        // A subsequent reconcile() call must not crash even though this option is now
        // "oversubscribed" (2 open offers against maxanswers=1) - a genuine, realistic legacy
        // state the old engine's lack of capacity-checking could produce (the actual class of bug
        // this whole refactor exists to fix), and the migration must carry it over faithfully
        // rather than silently resolving or crashing on it.
        singleton_service::destroy_instance();
        $sink = $this->redirectMessages();
        progression_factory::get()->reconcile((int) $fixture->option->id, 'f2_post_migration'); // Must not throw.
        $sink->close();
    }
}

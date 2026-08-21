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
 * C3 (M3, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): orphaned old-chain tasks (referring
 * to an option that no longer exists, or otherwise unreadable by any registered
 * legacy_chain_reader) must be cleaned up by the migration without throwing and without
 * sending a mail with stale content - and must not block a genuinely valid chain elsewhere
 * from migrating correctly in the same run.
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
 * Migration test for orphaned old-chain tasks (M3).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §6/§7
 * (still "Entwurf, noch nicht final abgenommen" at the time this test was written) - same
 * caveats as C1/C2: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them. Per §7: "Nicht erkennbare Formate fallen an die T7-Heartbeat-Selbstheilung
 * durch" - i.e. the migration does not need to perfectly interpret an orphaned task, it only
 * needs to not crash and not act on stale data; the heartbeat/next reconcile() is the actual
 * safety net that picks up the option's real, current state regardless.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\migration\upgrade_step::run
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @runInSeparateProcess
 */
final class waitlist_migration_c3_orphaned_tasks_test extends booking_advanced_testcase {
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
     * C3 (M3): an orphaned task (option deleted out from under it) must not crash the
     * migration, must not send a stale mail, and must not block a genuinely valid chain
     * elsewhere from migrating correctly in the same run.
     */
    public function test_c3_orphaned_task_is_cleaned_up_without_exception_or_stale_mail(): void {
        global $DB;

        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'upgrade_step/progression_factory/db_waitlist_offer_repository do not exist yet ' .
                '(Phase 2). This test is fully written against the planned target API - see the ' .
                'class docblock - and will be activated once those classes land.'
            );
        }

        // Control group: a genuinely valid, migratable running chain (same as C1) - proves the
        // orphaned task built below does not poison the whole migration run.
        $validfixture = $this->build_running_mail_interval_chain(2);
        $validtreateduserid = (int) $validfixture->treateduser->id;
        $validpendinguserid = (int) $validfixture->pendinguser->id;

        // The fixture's own maxanswers=1 only ever frees ONE seat - genuinely just enough for
        // the already-treated user's migrated offer, not for the still-pending one too. Same fix
        // as C1's test.
        $DB->set_field('booking_options', 'maxanswers', 2, ['id' => $validfixture->option->id]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete((int) $validfixture->option->id);
        singleton_service::destroy_booking_option_singleton((int) $validfixture->option->id);

        // The orphaned task: build a second, otherwise-identical running chain, then delete
        // its option out from under the still-pending repeat task - simulating a task that
        // survives an option deletion between scheduling and the upgrade running (M3's actual
        // scenario, not a contrived malformed-JSON edge case).
        $orphanfixture = $this->build_running_mail_interval_chain(2);
        $orphanedoptionid = (int) $orphanfixture->option->id;
        $DB->delete_records('booking_options', ['id' => $orphanedoptionid]);
        \mod_booking\booking_option::purge_cache_for_option($orphanedoptionid);

        $sink = $this->redirectMessages();
        ob_start();
        $exceptionthrown = null;
        try {
            $upgradestepclass = '\mod_booking\local\waitlist\migration\upgrade_step';
            $upgradestepclass::run();
        } catch (\Throwable $e) {
            $exceptionthrown = $e;
        }
        ob_get_clean();

        $this->assertNull(
            $exceptionthrown,
            'C3/M3: an orphaned task (deleted option) must not make the whole migration throw. Got: '
                . ($exceptionthrown ? get_class($exceptionthrown) . ': ' . $exceptionthrown->getMessage() : '')
        );

        // No mail may have been sent referencing the deleted option's orphaned task.
        $messagesduringupgrade = $sink->get_messages();
        $sink->close();
        foreach ($messagesduringupgrade as $message) {
            $this->assertStringNotContainsString(
                (string) $orphanedoptionid,
                $message->fullmessage . $message->subject,
                'C3/M3: the orphaned task must not cause a mail with stale content to be sent during migration.'
            );
        }

        // The control-group chain must still migrate correctly in the SAME run - the orphaned
        // task must not have blocked or corrupted processing of a genuinely valid chain.
        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $progression = $factoryclass::get();
        $progression->reconcile((int) $validfixture->option->id, 'migration_test_c3_control');

        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $repository = new $repositoryclass();
        $unbehandelt = $repository->get_unbehandelte_waitinglist((int) $validfixture->option->id, []);
        $unbehandeltuserids = array_map(fn($u) => (int) ($u->userid ?? $u->id), $unbehandelt);
        $this->assertNotContains(
            $validtreateduserid,
            $unbehandeltuserids,
            'C3/M3: the control group\'s already-treated user must still be correctly excluded ' .
            '- the orphaned task elsewhere must not have corrupted this option\'s migration.'
        );
        // Picked up = an open offer, OR autobooked (this fixture has no price category, so price
        // resolves to 0 - K3 autobook, not K4 offer; see C1's test for the same reasoning).
        $openoffers = $repository->get_open_offers((int) $validfixture->option->id);
        $openofferuserids = array_map(fn($o) => (int) $o->userid, $openoffers);
        $isopenoffer = in_array($validpendinguserid, $openofferuserids, true);
        $isautobooked = !$DB->record_exists('booking_answers', [
            'optionid' => (int) $validfixture->option->id,
            'userid' => $validpendinguserid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $this->assertTrue(
            $isopenoffer || $isautobooked,
            'C3/M3: the control group\'s still-pending user must still be correctly picked up ' .
            '(open offer or autobooked) - the orphaned task elsewhere must not have blocked this ' .
            'option\'s migration.'
        );

        // Heartbeat/reconcile is the actual safety net for the orphaned option itself: calling
        // it must not throw either, even though the option no longer exists. It simply has
        // nothing valid left to reconcile.
        $orphanreconcileexception = null;
        try {
            $progression->reconcile($orphanedoptionid, 'migration_test_c3_orphaned');
        } catch (\Throwable $e) {
            $orphanreconcileexception = $e;
        }
        $this->assertNull(
            $orphanreconcileexception,
            'C3/M3: reconcile() on the now-nonexistent option must not throw either - the ' .
            'heartbeat safety net (§7) must degrade gracefully, not propagate an exception.'
        );
    }
}

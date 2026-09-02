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
 * F1 (WAITLIST_REFACTOR_OUTSTANDING_TESTS_2026-08-21.md, Kategorie F - Migration + laufender
 * Betrieb im Zusammenspiel): waitlist_migration_c2_open_offer_test.php already proves an open M2
 * confirm-offer survives migration + a first reconcile() call. This test goes one round further:
 * a SECOND, genuinely NEW, LATER trigger (a fresh seat becoming free, unrelated to the migration
 * itself) must be handled correctly too - the migrated offer must remain completely untouched by
 * that later round, and the newly freed capacity must be offered to the correct NEXT eligible
 * waiting-list candidate (O1/O2), proving migrated state and ongoing live operation coexist
 * cleanly across multiple, independent rounds - not just the one round immediately after
 * migration.
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
 * F1: a migrated open offer must stay untouched across a later, independent, genuinely new
 * trigger - and that new trigger must still work correctly for the next eligible candidate.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\migration\upgrade_step::run
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @runInSeparateProcess
 */
final class waitlist_migration_f1_open_offer_then_new_trigger_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * Creates one active send_mail_interval rule - the fixture's OWN rule uses the old
     * confirm_bookinganswer action, which rule_condition_checker::applicable_rules() never
     * matches (by design, it only looks for send_mail_interval rules). A genuinely new,
     * post-migration live trigger needs a rule the new engine actually recognises.
     *
     * @param int $optionid
     * @return int the new rule's id
     */
    private function create_new_engine_rule(int $optionid): int {
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $actstr = json_encode([
            'interval' => 30,
            'subject' => 'f1subj',
            'template' => 'f1tmpl',
            'templateformat' => '1',
        ]);
        $record = $plugingenerator->create_rule([
            'name' => 'f1-new-engine-rule',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);
        return (int) $record->id;
    }

    /**
     * F1: after migration + the first reconcile() (matching C2/M2 exactly), a SECOND, later,
     * genuinely new capacity-freeing trigger fires (a fresh seat, nothing to do with the
     * migration itself). The migrated offer must still be exactly as it was; the new seat must
     * correctly go to the next eligible waiting-list candidate.
     */
    public function test_migrated_offer_survives_a_later_independent_trigger(): void {
        global $DB;

        // 3 waiting-list users this time: one gets the migrated M2 offer, leaving two genuinely
        // untouched candidates for the later, new trigger to correctly pick between.
        $fixture = $this->build_running_confirm_chain(3, 2);
        $offereduserid = (int) $fixture->offereduser->id;
        $otherusers = array_values(array_filter(
            $fixture->waitlistusers,
            fn($u) => (int) $u->id !== $offereduserid
        ));
        $this->assertCount(2, $otherusers, 'Fixture sanity: exactly 2 other waiting-list users besides the offered one.');

        // The fixture's OWN rule uses the old confirm_bookinganswer action, invisible to the new
        // engine - a real new-engine rule is needed for the later, genuinely new trigger to have
        // anything to act under. Free capacity is still fully consumed by the migrated offer at
        // this point (maxanswers=1), so this cannot affect the first reconcile() round below.
        $this->create_new_engine_rule((int) $fixture->option->id);

        // Migration, then the first reconcile() - identical sequence to C2/M2.
        upgrade_step::run();
        $progression = progression_factory::get();
        $progression->reconcile((int) $fixture->option->id, 'migration_f1_setup');

        $repository = new db_waitlist_offer_repository();
        $offerbefore = null;
        foreach ($repository->get_open_offers((int) $fixture->option->id) as $offer) {
            if ((int) $offer->userid === $offereduserid) {
                $offerbefore = $offer;
            }
        }
        $this->assertNotNull($offerbefore, 'Precondition: the migrated offer must be open after the first round.');

        // A SECOND, later, genuinely new trigger: an unrelated seat becomes free (e.g. the
        // option owner raises capacity, or another booking cancels) - nothing to do with the
        // migration itself.
        $DB->set_field('booking_options', 'maxanswers', 2, ['id' => $fixture->option->id]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($fixture->option->id);
        singleton_service::destroy_booking_option_singleton($fixture->option->id);
        singleton_service::destroy_booking_answers($fixture->option->id);

        $sink = $this->redirectMessages();
        $progression->reconcile((int) $fixture->option->id, 'f1_new_live_trigger');
        $sink->close();

        // The migrated offer must be exactly as it was before this later, independent round.
        $openoffers = $repository->get_open_offers((int) $fixture->option->id);
        $offerafter = null;
        foreach ($openoffers as $offer) {
            if ((int) $offer->userid === $offereduserid) {
                $offerafter = $offer;
            }
        }
        $this->assertNotNull($offerafter, 'F1: the migrated offer must still exist after the later trigger.');
        $this->assertEquals(
            $offerbefore->id,
            $offerafter->id,
            'F1: it must be the SAME offer row, not replaced/re-created by the later trigger.'
        );
        $this->assertEquals(
            $offerbefore->status->get_code(),
            $offerafter->status->get_code(),
            'F1: the migrated offer\'s status must be completely unchanged by the later, unrelated round.'
        );

        // The newly freed seat must have gone to exactly one of the two other, genuinely
        // eligible waiting-list users - proving the new trigger was processed correctly and
        // independently of the migrated offer.
        $newlyofferduserids = array_values(array_diff(
            array_map(fn($o) => (int) $o->userid, $openoffers),
            [$offereduserid]
        ));
        $this->assertCount(
            1,
            $newlyofferduserids,
            'F1: exactly one of the two other waiting-list users must have received the newly freed seat.'
        );
        $this->assertContains(
            $newlyofferduserids[0],
            array_map(fn($u) => (int) $u->id, $otherusers),
            'F1: the newly offered user must be one of the genuinely eligible other waiting-list users.'
        );
    }
}

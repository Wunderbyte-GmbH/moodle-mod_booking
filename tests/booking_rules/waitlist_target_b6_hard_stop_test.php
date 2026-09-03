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
 * B6 (K12, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): the capacity ceiling must be a
 * STRUCTURAL guarantee, not something that depends on correct admin configuration elsewhere.
 * WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.3/§8: the `free <= 0` guard in reconcile()
 * fires BEFORE the K11 "should this rule even react" condition check, and is unconditional - no
 * special-case logic needed. This test proves the practical, black-box consequence of that
 * ordering: (1) with zero free capacity, reconcile() is an absolute no-op regardless of how
 * permissive/broken the rule condition happens to be, and (2) no amount of redundant/repeated
 * reconcile() calls (a storm of misfiring or duplicate triggers - exactly the kind of
 * misconfiguration K12 defends against) can ever hand out more offers than seats actually exist.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\tests\booking_rules\waitlist_old_chain_fixture_trait;
use mod_booking\bo_availability\bo_info;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
require_once(__DIR__ . '/waitlist_old_chain_fixture_trait.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Target-behaviour test for K12's structural capacity hard-stop (B6).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.3
 * and §8 (still "Entwurf, noch nicht final abgenommen" at the time this test was written) - same
 * caveats as B1-B5/C1-C5: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\local\waitlist\capacity_calculator::free_capacity
 * @runInSeparateProcess
 */
final class waitlist_target_b6_hard_stop_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The two planned Phase 2 classes this test needs. Both not existing yet is the expected
     * state throughout Phase 1 - the test stays skipped until Phase 2 lands them.
     *
     * @return bool
     */
    private function target_api_exists(): bool {
        return class_exists('\mod_booking\local\waitlist\progression_factory')
            && class_exists('\mod_booking\local\waitlist\db_waitlist_offer_repository');
    }

    /**
     * Builds one paid option with $occupantcount occupied seats and $waitlistcount waiting-list
     * users, optionally freeing the occupied seats - shared scaffolding for B6's two scenarios.
     *
     * @param \stdClass $course
     * @param \stdClass $teacher
     * @param \stdClass $booking
     * @param \mod_booking_generator $plugingenerator
     * @param string $optiontext
     * @param int $occupantcount
     * @param int $waitlistcount
     * @param bool $freeseats whether to immediately cancel all occupants after setup
     * @return \stdClass {option, settings, boinfo, optionobj, occupants, waitlistusers}
     */
    private function build_option(
        \stdClass $course,
        \stdClass $teacher,
        \stdClass $booking,
        \mod_booking_generator $plugingenerator,
        string $optiontext,
        int $occupantcount,
        int $waitlistcount,
        bool $freeseats
    ): \stdClass {
        $occupants = [];
        for ($i = 0; $i < $occupantcount; $i++) {
            $occupants[] = $this->getDataGenerator()->create_user();
        }
        $waitlistusers = [];
        for ($i = 0; $i < $waitlistcount; $i++) {
            $waitlistusers[] = $this->getDataGenerator()->create_user();
        }
        $this->setAdminUser();
        foreach (array_merge($occupants, $waitlistusers) as $u) {
            $this->getDataGenerator()->enrol_user($u->id, $course->id, 'student');
        }

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = $optiontext;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = max(1, $occupantcount);
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->useprice = 1;
        $record->importing = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        // K11: progression::reconcile() only acts when an active send_mail_interval rule applies
        // - this test predates rule_condition_checker, so a plain ALWAYS rule is added here. Also
        // strengthens B6a: proves the K12 free<=0 guard short-circuits even when K11 WOULD pass.
        $plugingenerator->create_rule([
            'name' => 'b6-interval-rule-' . $optiontext,
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => json_encode(['interval' => 60, 'subject' => 's', 'template' => 't', 'templateformat' => '1']),
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => '0', // ALWAYS.
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        foreach ($occupants as $occupant) {
            $this->setUser($occupant);
            singleton_service::destroy_user($occupant->id);
            booking_bookit::bookit('option', $settings->id, $occupant->id);
            [$id] = $boinfo->is_available($settings->id, $occupant->id, false);
            if ($id === MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION) {
                booking_bookit::bookit('option', $settings->id, $occupant->id);
                [$id] = $boinfo->is_available($settings->id, $occupant->id, true);
            }
            if ($id !== MOD_BOOKING_BO_COND_ALREADYBOOKED) {
                $this->setAdminUser();
                $optionobj->user_submit_response($occupant, 0, 0, 0, MOD_BOOKING_VERIFIED);
            }
        }
        $this->setAdminUser();

        foreach ($waitlistusers as $u) {
            $this->setUser($u);
            singleton_service::destroy_user($u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            booking_bookit::bookit('option', $settings->id, $u->id);
            [$id] = $boinfo->is_available($settings->id, $u->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                'Precondition: every waiting-list user must actually reach ONWAITINGLIST.'
            );
        }
        $this->setAdminUser();

        if ($freeseats) {
            foreach ($occupants as $occupant) {
                $this->setUser($occupant);
                $optionobj->user_delete_response($occupant->id);
            }
            singleton_service::destroy_booking_option_singleton($option->id);
            singleton_service::destroy_booking_answers($option->id);
            $this->setAdminUser();
        }

        return (object) [
            'option' => $option,
            'settings' => $settings,
            'boinfo' => $boinfo,
            'optionobj' => $optionobj,
            'occupants' => $occupants,
            'waitlistusers' => $waitlistusers,
        ];
    }

    /**
     * B6a (K12): zero free capacity must make reconcile() an absolute no-op, regardless of the
     * rule condition - the free <= 0 guard fires FIRST, before K11 is even evaluated.
     */
    public function test_b6a_zero_free_capacity_is_an_absolute_no_op(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'progression_factory/db_waitlist_offer_repository do not exist yet (Phase 2). ' .
                'This test is fully written against the planned target API - see the class ' .
                'docblock - and will be activated once those classes land.'
            );
        }

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        // Seat stays occupied (freeseats = false) - zero free capacity, three people waiting.
        $fixture = $this->build_option(
            $course,
            $teacher,
            $booking,
            $plugingenerator,
            'b6a-zero-capacity',
            1,
            3,
            false
        );

        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $progression = $factoryclass::get();
        $repository = new $repositoryclass();

        $progression->reconcile((int) $fixture->option->id, 'b6a_test_zero_capacity');

        $openoffers = $repository->get_open_offers((int) $fixture->option->id);
        $this->assertCount(
            0,
            $openoffers,
            'B6a/K12: with zero free capacity, reconcile() must not create a single offer, no ' .
            'matter what the rule condition or candidate queue looks like.'
        );

        $unbehandelt = $repository->get_unbehandelte_waitinglist((int) $fixture->option->id, []);
        $this->assertCount(
            3,
            $unbehandelt,
            'B6a/K12: all three candidates must still be fully unbehandelt - the free <= 0 ' .
            'guard must short-circuit before any candidate is even looked at.'
        );

        // Nobody may have been silently autobooked either.
        foreach ($fixture->waitlistusers as $u) {
            [$id] = $fixture->boinfo->is_available($fixture->settings->id, $u->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                'B6a/K12: every waiting-list user must remain exactly ONWAITINGLIST - zero free ' .
                'capacity must block autobooking too, not just offers.'
            );
        }
    }

    /**
     * B6b (K12): a storm of redundant/repeated reconcile() calls (misfiring or duplicate
     * triggers) must never hand out more offers than seats actually exist - the capacity guard
     * is a hard, repeatable ceiling, not a one-shot check that can be worn down by retriggering.
     */
    public function test_b6b_repeated_reconcile_calls_never_exceed_capacity(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'progression_factory/db_waitlist_offer_repository do not exist yet (Phase 2). ' .
                'This test is fully written against the planned target API - see the class ' .
                'docblock - and will be activated once those classes land.'
            );
        }

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 80,
            'pricecatsortorder' => 1,
        ]);

        // Two free seats, heavily oversubscribed with ten waiting candidates.
        $fixture = $this->build_option(
            $course,
            $teacher,
            $booking,
            $plugingenerator,
            'b6b-oversubscribed',
            2,
            10,
            true
        );

        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $progression = $factoryclass::get();
        $repository = new $repositoryclass();

        // A storm of ten redundant reconcile() calls in a row - simulates exactly the kind of
        // misfiring/duplicate-trigger scenario K12 must be safe against.
        for ($i = 0; $i < 10; $i++) {
            $progression->reconcile((int) $fixture->option->id, "b6b_test_storm_call_{$i}");
        }

        $openoffers = $repository->get_open_offers((int) $fixture->option->id);
        $this->assertCount(
            2,
            $openoffers,
            'B6b/K12: exactly two seats exist - ten redundant reconcile() calls must still ' .
            'produce exactly two open offers, never more.'
        );
        $offereduserids = array_map(fn($o) => (int) $o->userid, $openoffers);
        $this->assertEquals(
            2,
            count(array_unique($offereduserids)),
            'B6b/K5: no candidate may have accumulated more than one offer across the storm of ' .
            'repeated calls.'
        );

        $unbehandelt = $repository->get_unbehandelte_waitinglist((int) $fixture->option->id, []);
        $this->assertCount(
            8,
            $unbehandelt,
            'B6b/K12: the eight remaining candidates must still be genuinely unbehandelt, not ' .
            'silently offered beyond the physical capacity.'
        );
    }
}

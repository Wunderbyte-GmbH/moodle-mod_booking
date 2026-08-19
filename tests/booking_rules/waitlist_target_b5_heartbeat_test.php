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
 * B5 (T7, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): a lost/missed trigger (a seat freed up
 * but no reconcile() call ever happened for it - a crashed cron, a bug in a future trigger
 * adapter, whatever the cause) must be self-healed by the periodic `waitlist_heartbeat_task`.
 * The task's underlying query must stay NARROWLY scoped (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md
 * §4.2: "nur Optionen mit aktiven WL-Antworten UND ohne offene Offers UND free > 0" - a lesson
 * from the USI load-test experience referenced in the architecture doc) - it must not
 * indiscriminately re-reconcile every option with a waiting list on every tick.
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
 * Target-behaviour test for T7's heartbeat self-healing and its narrow query scope (B5).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §4.2
 * (still "Entwurf, noch nicht final abgenommen" at the time this test was written) - same
 * caveats as B1-B4/C1-C5: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them. In particular, `find_stalled_options()` is only sketched as
 * `$this->repository->find_stalled_options()` inside §4.2's task pseudocode, not listed in the
 * formal `waitlist_offer_repository` interface in §3.2 - same kind of doc-internal
 * inconsistency already flagged in B1's docblock for `is_permanently_declined()`. This test
 * calls it directly on `db_waitlist_offer_repository` and assumes it returns an array of
 * option ids (int) - the most natural reading of "findet Optionen".
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\task\waitlist_heartbeat_task::execute
 * @covers \mod_booking\local\waitlist\db_waitlist_offer_repository::find_stalled_options
 * @runInSeparateProcess
 */
final class waitlist_target_b5_heartbeat_test extends booking_advanced_testcase {
    use waitlist_old_chain_fixture_trait;

    /**
     * The three planned Phase 2 classes this test needs. None existing yet is the expected
     * state throughout Phase 1 - the test stays skipped until Phase 2 lands them.
     *
     * @return bool
     */
    private function target_api_exists(): bool {
        return class_exists('\mod_booking\local\waitlist\progression_factory')
            && class_exists('\mod_booking\local\waitlist\db_waitlist_offer_repository')
            && class_exists('\mod_booking\task\waitlist_heartbeat_task');
    }

    /**
     * Builds one option with a fixed price, $occupantcount occupied seats and $waitlistcount
     * waiting-list users - a small shared helper so B5's four differently-shaped option
     * scenarios do not each repeat the full booking_bookit() choreography.
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
        // - this test predates rule_condition_checker, so a plain ALWAYS rule is added here.
        $plugingenerator->create_rule([
            'name' => 'b5-interval-rule-' . $optiontext,
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
     * B5 (T7): a stalled option (free seat, waiting candidate, reconcile() never called) is
     * self-healed by waitlist_heartbeat_task, while its underlying query stays narrowly scoped
     * to genuinely stalled options only.
     */
    public function test_b5_heartbeat_self_heals_stalled_option_with_narrow_scope(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'progression_factory/db_waitlist_offer_repository/waitlist_heartbeat_task do ' .
                'not exist yet (Phase 2). This test is fully written against the planned target ' .
                'API - see the class docblock - and will be activated once those classes land.'
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

        // Option A - the genuinely stalled option: seat freed, one candidate waiting, but
        // reconcile() is deliberately NEVER called for it - the exact "lost trigger" scenario.
        $optiona = $this->build_option($course, $teacher, $booking, $plugingenerator, 'b5-stalled', 1, 1, true);

        // Option B - free capacity, but no waiting-list candidates at all - nothing to do,
        // must not appear (no "aktive WL-Antworten").
        $optionb = $this->build_option($course, $teacher, $booking, $plugingenerator, 'b5-no-candidates', 1, 0, true);

        // Option C - a waiting candidate, but the seat was never freed (no free capacity) -
        // must not appear (free <= 0).
        $optionc = $this->build_option($course, $teacher, $booking, $plugingenerator, 'b5-full', 1, 1, false);

        // Option D - free capacity and a waiting candidate, but reconcile() was ALREADY run
        // (an open offer already exists) - must not appear ("ohne offene Offers").
        $optiond = $this->build_option($course, $teacher, $booking, $plugingenerator, 'b5-already-offered', 1, 1, true);
        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $repositoryclass = '\mod_booking\local\waitlist\db_waitlist_offer_repository';
        $progression = $factoryclass::get();
        $repository = new $repositoryclass();
        $progression->reconcile((int) $optiond->option->id, 'b5_test_prime_option_d');
        $optiondopenoffersbefore = $repository->get_open_offers((int) $optiond->option->id);
        $this->assertCount(
            1,
            $optiondopenoffersbefore,
            'Precondition: option D must already have exactly one open offer before the heartbeat runs.'
        );

        // Part 1 (§4.2 query contract): the stalled-options query must find EXACTLY option A.
        $stalledoptionids = array_map('intval', $repository->find_stalled_options());
        $this->assertContains(
            (int) $optiona->option->id,
            $stalledoptionids,
            'B5/T7: the genuinely stalled option (free seat, waiting candidate, never ' .
            'reconciled) must be found by the heartbeat query.'
        );
        $this->assertNotContains(
            (int) $optionb->option->id,
            $stalledoptionids,
            'B5/§4.2: an option with free capacity but no waiting-list candidates must not be ' .
            'considered stalled - there is nothing to reconcile.'
        );
        $this->assertNotContains(
            (int) $optionc->option->id,
            $stalledoptionids,
            'B5/§4.2: an option with a waiting candidate but no free capacity must not be ' .
            'considered stalled - the narrow scope must exclude free <= 0.'
        );
        $this->assertNotContains(
            (int) $optiond->option->id,
            $stalledoptionids,
            'B5/§4.2: an option that already has an open offer must not be considered stalled ' .
            'again - the narrow scope must exclude options with open offers, exactly the USI ' .
            'load-test lesson behind keeping this query tight.'
        );

        // Part 2 (T7 self-healing): running the actual scheduled task must reconcile option A's
        // lost trigger, and must not disturb B, C or D.
        $heartbeattaskclass = '\mod_booking\task\waitlist_heartbeat_task';
        $heartbeattask = new $heartbeattaskclass();
        ob_start();
        $heartbeattask->execute();
        ob_get_clean();
        $this->setAdminUser();

        $optionaopenoffers = $repository->get_open_offers((int) $optiona->option->id);
        $optionaofferuserids = array_map(fn($o) => (int) $o->userid, $optionaopenoffers);
        $this->assertContains(
            (int) $optiona->waitlistusers[0]->id,
            $optionaofferuserids,
            'B5/T7: the heartbeat must self-heal the stalled option - the waiting candidate ' .
            'must have an open offer after execute(), even though reconcile() was never called ' .
            'for this option directly.'
        );

        $optionbopenoffers = $repository->get_open_offers((int) $optionb->option->id);
        $this->assertCount(
            0,
            $optionbopenoffers,
            'B5: option B (no candidates) must remain untouched - no offers, no exception.'
        );

        [$optioncid] = $optionc->boinfo->is_available(
            $optionc->settings->id,
            $optionc->waitlistusers[0]->id,
            true
        );
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $optioncid,
            'B5: option C (still full, no free capacity) must remain untouched by the heartbeat.'
        );

        $optiondopenoffersafter = $repository->get_open_offers((int) $optiond->option->id);
        $this->assertCount(
            1,
            $optiondopenoffersafter,
            'B5: option D (already had an open offer) must still have exactly one open offer - ' .
            'the heartbeat must not create a duplicate.'
        );
    }
}

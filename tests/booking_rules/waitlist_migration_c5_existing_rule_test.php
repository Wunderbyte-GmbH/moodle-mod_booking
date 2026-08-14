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
 * C5 (M5, WAITLIST_REFACTOR_TEST_COVERAGE_2026-08-04.md): a send_mail_interval rule that was
 * already configured BEFORE the upgrade must keep working end-to-end afterwards - a full
 * cycle (cancellation -> new mechanism's reconcile -> notification) driven by the NEW
 * architecture, using the OLD rule configuration unchanged.
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
 * Migration test for a pre-existing rule working end-to-end after the upgrade (M5).
 *
 * Written against the planned target API in WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §6
 * (still "Entwurf, noch nicht final abgenommen" at the time this test was written) - same
 * caveats as C1-C4: the target classes do not exist yet, this test is guarded with
 * class_exists()/markTestSkipped() and will need minor signature reconciliation once Phase 2
 * lands them. In particular, Phase 3 is what wires the trigger adapters (e.g.
 * freetobookagain_waitlist_adapter) to replace the direct calls in booking_option.php - this
 * test, written during Phase 1, calls progression::reconcile() directly to simulate what an
 * adapter will eventually do, since the adapters themselves are a Phase 3 concern.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\migration\upgrade_step::run
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @runInSeparateProcess
 */
final class waitlist_migration_c5_existing_rule_test extends booking_advanced_testcase {
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
     * C5 (M5): a rule configured before the upgrade must drive a correct, complete
     * notification cycle through the NEW mechanism afterwards, unmodified.
     */
    public function test_c5_pre_existing_rule_works_end_to_end_after_upgrade(): void {
        if (!$this->target_api_exists()) {
            $this->markTestSkipped(
                'upgrade_step/progression_factory/db_waitlist_offer_repository do not exist yet ' .
                '(Phase 2). This test is fully written against the planned target API - see the ' .
                'class docblock - and will be activated once those classes land.'
            );
        }

        $bdata = $this->fixture_bdata();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $occupant = $this->getDataGenerator()->create_user();
        $waitlisted = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($occupant->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($waitlisted->id, $course->id, 'student');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // The rule is configured BEFORE the upgrade runs - this is the "existing rule" M5 is
        // about. Its exact subject/template is what we check survives the upgrade unchanged.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $actstr = '{"sendical":0,"sendicalcreateorcancel":"","interval":60,';
        $actstr .= '"subject":"m5survivedsubj","template":"m5survivedmsg","templateformat":"1"}';
        $ruledata = [
            'name' => 'preexistingrule',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actstr,
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[],"condition":"2"}',
        ];
        $plugingenerator->create_rule($ruledata);

        $record = new \stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'c5-existing-rule';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 1;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 1;
        $record->description = 'Will start in 2050';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $optionobj = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

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
        $this->setAdminUser();

        $this->setUser($waitlisted);
        singleton_service::destroy_user($waitlisted->id);
        booking_bookit::bookit('option', $settings->id, $waitlisted->id);
        booking_bookit::bookit('option', $settings->id, $waitlisted->id);
        [$id] = $boinfo->is_available($settings->id, $waitlisted->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
        $this->setAdminUser();

        // Upgrade runs BEFORE anything has ever triggered for this option - there is no
        // running chain here at all, only the pre-existing rule configuration and a plain
        // waiting-list entry. This isolates "does an old rule survive the upgrade" from M1/M2's
        // "does a running chain survive the upgrade".
        $upgradestepclass = '\mod_booking\local\waitlist\migration\upgrade_step';
        $upgradestepclass::run();

        // Free the seat, then drive the NEW mechanism directly (Phase 3's trigger adapters are
        // out of scope for Phase 1 - this simulates what freetobookagain_waitlist_adapter will
        // eventually do automatically).
        $this->setUser($occupant);
        $optionobj->user_delete_response($occupant->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->setAdminUser();

        $factoryclass = '\mod_booking\local\waitlist\progression_factory';
        $progression = $factoryclass::get();

        $sink = $this->redirectMessages();
        ob_start();
        $progression->reconcile((int) $option->id, 'migration_test_c5');
        // Note: reconcile() may deliver synchronously or via an adhoc task, depending on the
        // final Phase 2 implementation - run any pending tasks too, robust to either choice.
        $this->runAdhocTasks();
        ob_get_clean();
        $messages = $sink->get_messages();
        $sink->close();

        $matchingmessages = array_filter(
            $messages,
            fn($m) => $m->useridto == $waitlisted->id && $m->subject === 'm5survivedsubj'
        );
        $this->assertNotEmpty(
            $matchingmessages,
            'C5/M5: the pre-existing rule\'s exact subject/template must drive a real ' .
            'notification to the waiting-list user through the NEW mechanism, unmodified.'
        );
    }
}

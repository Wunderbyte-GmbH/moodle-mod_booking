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
 * Isolated test for Bug-A fix: free-user auto-booking re-triggers adhoc task for
 * late-joining waitinglist users.
 *
 * This test is kept in its own class so it is never affected by static-state
 * side-effects from other tests in the booking-rules test suite.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use stdClass;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\bo_info;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\local\cartstore;
use tool_mocktesttime\time_mock;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Isolated test for Bug-A fix: free-user auto-booking re-triggers adhoc task for
 * late-joining waitinglist users.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booking_waitinglist_confirmation_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        // Reset static arrays not covered by destroy_singletons().
        rules_info::$rulestocancel = [];
        booking_rules::$rules = [];
        // Purge MUC price caches so stale price-category data from a previous test
        // (e.g. student price=0) does not bleed into the next test's price lookups.
        \cache_helper::purge_by_event('setbackprices');
        \cache::make('mod_booking', 'cachedprices')->purge();
        \cache::make('mod_booking', 'cachedpricecategories')->purge();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Helper: common booking-module settings used across tests.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }

    /**
     * Regression test for the one-at-a-time WL confirmation chain:
     * when a paid WL user's confirm-task runs (paid path), the chain architecture
     * (confirm_bookinganswer creates tasks one by one, like send_mail_interval)
     * ensures that late joiners (s8, s9) are naturally picked up on each fresh WL
     * query without any explicit re-trigger.
     *
     * Scenario
     * --------
     * maxanswers=2 → s1+s2 fully book via shopping_cart.
     * s3–s7 (all price=100/default) join WL.
     * s1 cancels → freetobookagain fires → chain creates:
     *   - 1 direct confirm task for s3 (first WL user)
     *   - 1 repeat-trigger task for s4 (second WL user, repeat=1)
     *   All other WL users (s5–s7) are skipped in the initial event.
     * s8+s9 join WL AFTER the cancel (late joiners).
     * Batches are run until no tasks remain:
     *   - Each batch processes one direct confirm + one repeat-trigger.
     *   - The repeat-trigger re-executes the rule with a fresh WL query (usersalreadytreated
     *     is stored in rulejson) so late joiners are picked up when their turn comes.
     * After all batches:
     *   - s3–s9: WL=1, confirmationcount=1 in booking_answer JSON (all 7 WL users confirmed).
     *   - Total booked (WL=0) = 1 (s2 only; s1 cancelled, all WL users stay on WL in paid path).
     *
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\booking_option::check_if_free_to_book_again
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     */
    public function test_all_paid_waitinglist_users_get_tasks_and_late_joiners_retriggered(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart not installed - cannot fully book a priced option.');
        }

        $this->resetAfterTest();

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create a custom profile field to set price category for each user.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // All 9 users use the default price category (price=100). No student-pricecat users.
        for ($i = 1; $i <= 9; $i++) {
            $student[$i] = $this->getDataGenerator()->create_user();
        }
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        for ($i = 1; $i <= 9; $i++) {
            $this->getDataGenerator()->enrol_user($student[$i]->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories: default=100 (all users in this test), student=0 (unused).
        $pricecategories = [
            'default' => (object)[
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ],
            'student' => (object)[
                'ordernum' => 2,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 0,
                'pricecatsortorder' => 2,
            ],
        ];
        foreach ($pricecategories as $pc) {
            $plugingenerator->create_pricecategory($pc);
        }

        // Create booking rule: react on freetobookagain, select all WL users (borole=1),
        // action = confirm_bookinganswer.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[]}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Create booking option: maxanswers=2 (fully booked by s1+s2), useprice=1,
        // waitforconfirmation=2, confirmationonnotification=1 (non-exclusive, all WL users
        // can be confirmed in parallel; value 2 would cause an infinite loop with the re-trigger).
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'bugabfixtest';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 1;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // Phase 1: Book s1 and s2 via shopping_cart to fill the option (maxanswers=2 → fully booked).
        for ($i = 1; $i <= 2; $i++) {
            $this->setAdminUser();
            shopping_cart::delete_all_items_from_cart($student[$i]->id);
            shopping_cart::buy_for_user($student[$i]->id);
            $cartstore = cartstore::instance($student[$i]->id);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            shopping_cart::confirm_payment($student[$i]->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
            singleton_service::destroy_user($student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ALREADYBOOKED,
                $id,
                "student{$i} should be booked after shopping_cart payment"
            );
        }

        // Phase 2: s3–s7 join WL (all have price=100 → paid path when their tasks run).
        for ($i = 3; $i <= 7; $i++) {
            time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
            $this->setUser($student[$i]);
            singleton_service::destroy_user($student[$i]->id);
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, false);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                $id,
                "student{$i}: first bookit should show CONFIRMASKFORCONFIRMATION"
            );
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                "student{$i}: second bookit should result in ONWAITINGLIST"
            );
        }

        // Phase 3: s1 cancels → freetobookagain fires → tasks for ALL 5 WL users (s3–s7).
        // select_student_in_bo has no seat-count limit: it selects every WL user.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $tasksaftercancel = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $this->assertCount(
            2,
            $tasksaftercancel,
            'Chain architecture: expected exactly 2 tasks after s1 cancels: '
            . '1 direct confirm for s3 (first WL user) + 1 repeat-trigger for s4 (second WL user). '
            . 'select_student_in_bo returns all 5 WL users but the action only creates 2 tasks per event.'
        );

        // Phase 4: s8 and s9 join WL AFTER the tasks for s3–s7 are already queued (late joiners).
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        for ($i = 8; $i <= 9; $i++) {
            time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
            $this->setUser($student[$i]);
            singleton_service::destroy_user($student[$i]->id);
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, false);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                $id,
                "student{$i}: first bookit should show CONFIRMASKFORCONFIRMATION"
            );
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                "student{$i}: second bookit should result in ONWAITINGLIST"
            );
        }

        // Confirm s8 and s9 still have no confirm tasks (joined after the initial freetobookagain).
        $alltasksbefore = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $taskfors8before = array_filter($alltasksbefore, fn($t) => $t->get_custom_data()->userid == $student[8]->id);
        $taskfors9before = array_filter($alltasksbefore, fn($t) => $t->get_custom_data()->userid == $student[9]->id);
        $this->assertEmpty($taskfors8before, 's8 must have no confirm task before batch 1 runs (late joiner)');
        $this->assertEmpty($taskfors9before, 's9 must have no confirm task before batch 1 runs (late joiner)');

        // Phase 5: Run all batches until no tasks remain.
        // Each batch processes one direct confirm (paid path → confirmationcount=1) and one
        // repeat-trigger (re-executes rule with fresh WL query → picks up late joiners and
        // creates the next pair of tasks). The chain terminates when all WL users have been
        // processed (everyone is in usersalreadytreated, rule returns empty records).
        $this->setAdminUser();
        $mocktime = time_mock::get_mock_time();
        $maxbatches = 20; // Safety cap: 7 WL users × 1 batch each + buffer.
        $batchcount = 0;
        do {
            ob_start();
            $plugingenerator->runtaskswithintime($mocktime);
            ob_end_clean();
            $batchcount++;
            $remaining = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        } while (!empty($remaining) && $batchcount < $maxbatches);

        $this->assertLessThan(
            $maxbatches,
            $batchcount,
            'Chain must terminate within ' . $maxbatches . ' batches (possible infinite loop)'
        );

        // Refresh singletons after all task runs.
        for ($i = 1; $i <= 9; $i++) {
            singleton_service::destroy_user($student[$i]->id);
        }
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Final assertions: all 7 WL users (s3–s9) must have confirmationcount=1.
        for ($i = 3; $i <= 9; $i++) {
            $answer = $DB->get_record('booking_answers', [
                'optionid' => $option->id,
                'userid' => $student[$i]->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]);
            $this->assertNotEmpty($answer, "student{$i} must have a booking_answers record on WL");
            $answerjson = empty($answer->json) ? (object)[] : json_decode($answer->json);
            $this->assertEquals(
                1,
                $answerjson->confirmationcount ?? 0,
                "Chain: student{$i} must have confirmationcount=1 after all chain batches ran"
            );
        }

        // All 7 WL users must be at PRICEISSET (confirmed by admin, awaiting payment).
        for ($i = 3; $i <= 9; $i++) {
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_PRICEISSET,
                $id,
                "student{$i}: after paid-path confirm task, user should be at PRICEISSET"
            );
        }

        // Sanity: only s2 remains booked (s1 cancelled; all WL users stay on WL in paid path).
        $bookedcount = $DB->count_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertEquals(
            1,
            $bookedcount,
            'Only s2 should remain booked after s1 cancelled and all WL users stayed on WL (paid path)'
        );
    }

    /**
     * Regression test for Bug A: after a free-user's confirm-task auto-books them, the fix
     * must purge the option cache and call check_if_free_to_book_again so that any remaining
     * free seat triggers a new freetobookagain event, which in turn re-queues confirm tasks
     * for waitinglist users who joined AFTER the original event (e.g. student5).
     *
     * Scenario
     * --------
     * maxanswers=2 → s1+s2 fully book via shopping_cart.
     * s3 (price=100) + s4 (price=0/student) join WL (s3 first, s4 second).
     * Admin raises maxanswers to 4 → freetobookagain fires → confirm tasks created for s3+s4.
     * s5 joins WL AFTER the tasks are already queued (so s5 has no task yet).
     * runtaskswithintime:
     *   - s3 task → paid path → stays WL=1 (2 seats remain free after s3 stays on WL).
     *   - s4 task → free path → auto-booked → Bug-A fix fires freetobookagain → new tasks for s3+s5.
     * Expected state after two runtaskswithintime rounds:
     *   - After batch 1: s3 confirmed (paid, stays WL), s4's repeat-trigger re-executes the rule
     *     → new confirm task for s4 + repeat-trigger for s5 created; s4 NOT yet auto-booked.
     *   - s5 has a pending task after batch 1 (chain: repeat-trigger).
     *   - After batch 2: s4 auto-booked (free path), s5's repeat-trigger creates a direct confirm task.
     *   - Booked (WL=0): s1, s2, s4  → exactly 3 records after batch 2.
     *
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\booking_option::check_if_free_to_book_again
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     */
    public function test_free_user_autobooking_retriggers_task_for_late_joining_waitinglist_user(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart not installed - cannot fully book a priced option.');
        }

        $this->resetAfterTest();

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create a custom profile field to set price category for each user.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Student4 has the student price category (price = 0); all others use the default (price = 100).
        $student[1] = $this->getDataGenerator()->create_user();
        $student[2] = $this->getDataGenerator()->create_user();
        $student[3] = $this->getDataGenerator()->create_user();
        $student[4] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $student[5] = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        for ($i = 1; $i <= 5; $i++) {
            $this->getDataGenerator()->enrol_user($student[$i]->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories: default = 100, student = 0.
        $pricecategories = [
            'default' => (object)[
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ],
            'student' => (object)[
                'ordernum' => 2,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 0,
                'pricecatsortorder' => 2,
            ],
        ];
        foreach ($pricecategories as $pc) {
            $plugingenerator->create_pricecategory($pc);
        }

        // Create booking rule: react on freetobookagain, select waitinglist users,
        // action = confirm_bookinganswer (no mail, no counter limit, no repeat mechanism).
        // This ensures the ONLY path for student5 to receive a task is the re-trigger fix.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[]}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Create booking option: maxanswers=2 (will be fully booked by student1+2),
        // useprice=1, waitforconfirmation=2, confirmationonnotification=1.
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'bugafixtest';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 1;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // Phase 1: Book student1 and student2 via shopping_cart to fill the option.
        for ($i = 1; $i <= 2; $i++) {
            $this->setAdminUser();
            shopping_cart::delete_all_items_from_cart($student[$i]->id);
            shopping_cart::buy_for_user($student[$i]->id);
            $cartstore = cartstore::instance($student[$i]->id);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            $res = shopping_cart::confirm_payment($student[$i]->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
            singleton_service::destroy_user($student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ALREADYBOOKED,
                $id,
                "student{$i} should be fully booked after shopping_cart payment"
            );
        }

        // Phase 2: student3 (paid) and student4 (free) join the waitinglist.
        // student3 joins first (earlier timemodified -> first in WL queue).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[3]);
        singleton_service::destroy_user($student[3]->id);
        $result = booking_bookit::bookit('option', $settings->id, $student[3]->id);
        [$id] = $boinfo->is_available($settings->id, $student[3]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student[3]->id);
        [$id] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Student4 joins second (later timemodified -> second in WL queue).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[4]);
        singleton_service::destroy_user($student[4]->id);
        $result = booking_bookit::bookit('option', $settings->id, $student[4]->id);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student[4]->id);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Phase 3: Admin increases maxanswers to 4 -> freetobookagain fires.
        // With maxanswers=4 and 2 booked users, 2 seats are free.
        // After student4 (free) is auto-booked, 1 seat will still remain -> re-trigger fires.
        $this->setAdminUser();
        $updaterecord = new stdClass();
        $updaterecord->id = $option->id;
        $updaterecord->bookingid = $booking1->id;
        $updaterecord->text = $record->text;
        $updaterecord->maxanswers = 4;
        $updaterecord->chooseorcreatecourse = 1;
        $updaterecord->courseid = $course->id;
        $updaterecord->maxoverbooking = 10;
        $updaterecord->waitforconfirmation = 2;
        $updaterecord->confirmationonnotification = 1;
        $updaterecord->useprice = 1;
        $updaterecord->importing = 1;
        $updaterecord->optiondateid_0 = "0";
        $updaterecord->daystonotify_0 = "0";
        $updaterecord->coursestarttime_0 = $record->coursestarttime_0;
        $updaterecord->courseendtime_0 = $record->courseendtime_0;
        $updaterecord->teachersforoption = $teacher->username;
        booking_option::update($updaterecord);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // The rule must have created confirm tasks for student3 and student4 (the two WL users).
        $tasksafter = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $this->assertCount(
            2,
            $tasksafter,
            'Expected exactly 2 confirm tasks (for student3 and student4) after maxanswers increase'
        );

        // Phase 4: student5 joins AFTER the tasks are already queued.
        // student5 will NOT have a confirm task at this point.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[5]);
        singleton_service::destroy_user($student[5]->id);
        $result = booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        // Confirm student5 has no confirm task yet (joined after the initial event fired).
        $alltasks = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $taskfors5before = array_filter($alltasks, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertEmpty(
            $taskfors5before,
            'student5 must have no confirm task before tasks run (joined after the initial freetobookagain event)'
        );

        /* Phase 5: Run batch 1.
        student3 task (direct confirm, repeat=0): paid (price=100) -> confirmwaitinglist JSON set, stays on WL.
        student4 task (repeat-trigger, repeat=1): re-executes the rule with fresh WL query.
        -> rule creates a new direct confirm task for s4 + a repeat-trigger for s5.
        -> s4 is NOT auto-booked yet in this batch.
        */
        $this->setAdminUser();
        $mocktime = time_mock::get_mock_time();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        // Assertions after batch 1.

        // KEY ASSERTION: student5 must now have a pending task created by the chain.
        // s4's repeat-trigger re-executed the rule (fresh WL query included s5), which created
        // a repeat-trigger task for s5. Without the chain architecture this would never happen.
        $tasksafterb1 = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $taskfors5 = array_filter($tasksafterb1, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertNotEmpty(
            $taskfors5,
            'Chain: a confirm_bookinganswer_by_rule_adhoc task for student5 must exist after batch 1 '
            . '(created when s4\'s repeat-trigger re-executed the rule and the fresh WL query included s5)'
        );

        // Phase 6: Run batch 2.
        // student4 task (direct confirm, repeat=0): free (price=0) -> user_submit_response auto-books s4.
        // student5 task (repeat-trigger, repeat=1): re-executes rule -> creates direct confirm task for s5.
        $this->setAdminUser();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        // Assertions after batch 2.

        // Student4 must now be fully booked (auto-booked by free path in batch 2).
        singleton_service::destroy_user($student[4]->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'student4 (free user) should have been auto-booked in batch 2'
        );

        // The option must still have a free seat (maxanswers=4, booked: s1+s2+s4=3).
        // Use a direct DB count to avoid any Moodle-cache or singleton contamination.
        $bookedrecords = $DB->get_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $bookedcount = count($bookedrecords);
        // Diagnostic: list the booked user IDs vs expected.
        $expecteduserids = [$student[1]->id, $student[2]->id, $student[4]->id];
        $actualuserids = array_column(array_values($bookedrecords), 'userid');
        sort($expecteduserids);
        sort($actualuserids);
        $this->assertEquals(
            3,
            $bookedcount,
            sprintf(
                'Expected exactly 3 booked (s1=%d,s2=%d,s4=%d) after batch 2 but got %d: actual booked userids=[%s] '
                    . '(s3=%d s5=%d)',
                $student[1]->id,
                $student[2]->id,
                $student[4]->id,
                $bookedcount,
                implode(',', $actualuserids),
                $student[3]->id,
                $student[5]->id
            )
        );

        // Student5 must have a direct confirm task (created when s5's repeat-trigger re-ran the rule in batch 2).
        $tasksafterb2 = \core\task\manager::get_adhoc_tasks(\mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class);
        $taskfors5b2 = array_filter($tasksafterb2, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertNotEmpty(
            $taskfors5b2,
            'Chain: student5 must have a pending confirm task after batch 2 '
            . '(created when s5\'s repeat-trigger re-executed the rule in batch 2)'
        );
    }

    /**
     * Regression for confirmationonnotification=2 (exclusive mode):
     *
     * Requirement (a): if two free seats are available and one WL user gets booked (free path),
     * other WL users must still get notified one-at-a-time via the chain.
     *
     * Requirement (b): users added to WL after freetobookagain was created must still get notified
     * if a place is still free, but must not be notified if all places have already been taken.
     *
     * Scenario
     * --------
     * maxanswers=2 -> s1+s2 are fully booked.
     * s3 (paid) + s4 (free) join WL.
     * maxanswers increased to 4 -> two free seats available, initial chain tasks created.
     * s5 (free) joins WL AFTER initial tasks are queued -> no task yet.
     * batch1: s3 direct-confirm (paid, stays WL), s4 repeat-trigger reruns rule -> s5 gets task.
     * batch2: s4 direct-confirm (free, booked), s5 repeat-trigger reruns rule -> s5 direct task exists.
     *   -> proves (a): one seat consumed, other WL user still notified.
     * batch3: s5 direct-confirm (free, booked) -> all seats consumed.
     * s6 joins WL AFTER seats consumed -> must never receive task.
     *
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     */
    public function test_confirmationmode2_late_joiner_notified_while_seat_free_but_not_after_full(): void {
        global $DB;

        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart not installed - cannot fully book a priced option.');
        }

        $this->resetAfterTest();

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;
        set_config('cancelationfee', 0, 'local_shopping_cart');

        // Create a custom profile field to set price category for each user.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create course and users.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // S4 and s5 are free users; others default paid.
        $student[1] = $this->getDataGenerator()->create_user();
        $student[2] = $this->getDataGenerator()->create_user();
        $student[3] = $this->getDataGenerator()->create_user();
        $student[4] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $student[5] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'student']);
        $student[6] = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        for ($i = 1; $i <= 6; $i++) {
            $this->getDataGenerator()->enrol_user($student[$i]->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price categories: default=100, student=0.
        $pricecategories = [
            'default' => (object)[
                'ordernum' => 1,
                'name' => 'default',
                'identifier' => 'default',
                'defaultvalue' => 100,
                'pricecatsortorder' => 1,
            ],
            'student' => (object)[
                'ordernum' => 2,
                'name' => 'student',
                'identifier' => 'student',
                'defaultvalue' => 0,
                'pricecatsortorder' => 2,
            ],
        ];
        foreach ($pricecategories as $pc) {
            $plugingenerator->create_pricecategory($pc);
        }

        // Rule reacts on freetobookagain and confirms WL users.
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[]}',
        ];
        $plugingenerator->create_rule($ruledata);

        // Confirmationonnotification=2 (exclusive mode).
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'confirmationmode2-latejoiners';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 2;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boinfo = new bo_info($settings);

        // Fully book s1+s2.
        for ($i = 1; $i <= 2; $i++) {
            $this->setAdminUser();
            shopping_cart::delete_all_items_from_cart($student[$i]->id);
            shopping_cart::buy_for_user($student[$i]->id);
            $cartstore = cartstore::instance($student[$i]->id);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
            shopping_cart::confirm_payment($student[$i]->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
            singleton_service::destroy_user($student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);
        }

        // S3 (paid) and s4 (free) join waiting list.
        for ($i = 3; $i <= 4; $i++) {
            time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
            $this->setUser($student[$i]);
            singleton_service::destroy_user($student[$i]->id);
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, false);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
                $id,
                "student{$i}: first bookit should show CONFIRMASKFORCONFIRMATION"
            );
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                "student{$i}: second bookit should result in ONWAITINGLIST"
            );
        }

        // Open exactly two free seats (maxanswers 2 -> 4) and trigger freetobookagain.
        $this->setAdminUser();
        $updaterecord = new stdClass();
        $updaterecord->id = $option->id;
        $updaterecord->bookingid = $booking1->id;
        $updaterecord->text = $record->text;
        $updaterecord->maxanswers = 4;
        $updaterecord->chooseorcreatecourse = 1;
        $updaterecord->courseid = $course->id;
        $updaterecord->maxoverbooking = 10;
        $updaterecord->waitforconfirmation = 2;
        $updaterecord->confirmationonnotification = 2;
        $updaterecord->useprice = 1;
        $updaterecord->importing = 1;
        $updaterecord->optiondateid_0 = "0";
        $updaterecord->daystonotify_0 = "0";
        $updaterecord->coursestarttime_0 = $record->coursestarttime_0;
        $updaterecord->courseendtime_0 = $record->courseendtime_0;
        $updaterecord->teachersforoption = $teacher->username;
        booking_option::update($updaterecord);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        $taskclass = \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class;
        $tasksafterupdate = \core\task\manager::get_adhoc_tasks($taskclass);
        $this->assertCount(
            2,
            $tasksafterupdate,
            'Mode 2 chain setup: expected exactly 2 initial tasks (direct + repeat-trigger).'
        );

        // Late joiner s5 joins after event/tasks were already created.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[5]);
        singleton_service::destroy_user($student[5]->id);
        booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $alltasksbefore = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors5before = array_filter($alltasksbefore, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertEmpty(
            $taskfors5before,
            'Requirement (b, positive precondition): s5 must have no task immediately after late join (event already queued).'
        );

        // Run batch 1: s3 direct confirm + s4 repeat trigger reruns rule and includes s5.
        $this->setAdminUser();
        $mocktime = time_mock::get_mock_time();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $tasksafterb1 = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors5b1 = array_filter($tasksafterb1, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertNotEmpty(
            $taskfors5b1,
            'Requirement (b, positive): late joiner s5 must be notified while a seat is still free.'
        );

        // Run batch 2: s4 direct free-booking consumes one seat, s5 repeat creates direct s5 task.
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $bookedcountafterb2 = $DB->count_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertEquals(3, $bookedcountafterb2, 'After batch 2 exactly one of two free seats must be consumed (booked=3).');

        $tasksafterb2 = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors5b2 = array_filter($tasksafterb2, fn($t) => $t->get_custom_data()->userid == $student[5]->id);
        $this->assertNotEmpty(
            $taskfors5b2,
            'Requirement (a): after one user is booked with two seats available, other WL users (s5) must still be notified.'
        );

        // Run batch 3: s5 direct free-booking consumes the last free seat.
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $finalbookedcount = $DB->count_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertEquals(4, $finalbookedcount, 'All seats must be consumed after s4 and s5 free-booking runs (booked=4).');

        // Late joiner s6 joins only after option is full.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[6]);
        singleton_service::destroy_user($student[6]->id);
        booking_bookit::bookit('option', $settings->id, $student[6]->id);
        [$id] = $boinfo->is_available($settings->id, $student[6]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        booking_bookit::bookit('option', $settings->id, $student[6]->id);
        [$id] = $boinfo->is_available($settings->id, $student[6]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $tasksbefores6run = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors6 = array_filter($tasksbefores6run, fn($t) => $t->get_custom_data()->userid == $student[6]->id);
        $this->assertEmpty(
            $taskfors6,
            'Requirement (b, negative): late joiner s6 must not be notified after all seats are already taken.'
        );

        // Even running tasks again must not create a task for s6.
        $this->setAdminUser();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $tasksafters6run = \core\task\manager::get_adhoc_tasks($taskclass);
        $taskfors6after = array_filter($tasksafters6run, fn($t) => $t->get_custom_data()->userid == $student[6]->id);
        $this->assertEmpty(
            $taskfors6after,
            'Requirement (b, negative): s6 should remain without notification task after subsequent task runs.'
        );
    }

    /**
     * Regression test for the drained-chain late joiner with action confirm_bookinganswer:
     * once the confirm-chain has fully drained (all WL users confirmed, no tasks left),
     * a NEW user joining the WL must immediately receive a confirm task as long as the
     * option still has a free seat. This is handled by the companion rule mechanism in
     * rules_info::collect_rules_for_execution(), which re-triggers freetobookagain rules
     * on bookingoptionwaitinglist_booked - it must cover the confirm_bookinganswer action,
     * not only send_mail_interval.
     *
     * Scenario
     * --------
     * maxanswers=2 → s1+s2 force-booked by admin (fully booked).
     * s3 (paid) joins WL.
     * s1 cancels → freetobookagain fires → exactly 1 direct confirm task for s3
     *   (single WL user → counter never reaches 1, no repeat-trigger).
     * Batch runs → s3 confirmed (paid, stays WL), task queue EMPTY (chain drained).
     * s4 joins WL AFTER the drain → companion mechanism must create a direct confirm
     *   task for exactly s4 (seat still free: s3 stays on WL in paid path).
     * Batch runs → s4 confirmed (confirmationcount=1, PRICEISSET).
     * Admin shrinks maxanswers to 1 → option fully booked with s2 alone.
     * s5 joins WL → must NOT receive a task (option_is_fully_booked gate).
     *
     * @covers \mod_booking\booking_rules\rules_info::collect_rules_for_execution
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     * @covers \mod_booking\event\bookingoption_freetobookagain
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     * @covers \mod_booking\booking_rules\rules\rule_react_on_event
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer
     * @covers \mod_booking\booking_rules\conditions\select_student_in_bo
     */
    public function test_confirm_chain_drained_late_joiner_gets_confirmation_task(): void {
        global $DB;

        $this->resetAfterTest();

        $bdata = self::booking_common_settings_provider();
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        $bdata['cancancelbook'] = 1;

        // Create a custom profile field to set price category for each user.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        // Create course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // All 5 users use the default price category (price=100, paid path keeps them on WL).
        // Set the pricecat field explicitly: user ids are recycled between tests in the same
        // process and stale user_info_data rows (e.g. pricecat=student from a previous test)
        // can survive the DB reset, which would silently flip users onto the free path.
        $student = [];
        for ($i = 1; $i <= 5; $i++) {
            $student[$i] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'default']);
        }
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        for ($i = 1; $i <= 5; $i++) {
            $this->getDataGenerator()->enrol_user($student[$i]->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create price category: default=100 for all users in this test.
        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 100,
            'pricecatsortorder' => 1,
        ]);

        // Create booking rule: react on freetobookagain, select all WL users (borole=1),
        // action = confirm_bookinganswer (same rule as in the chain tests above).
        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[]}',
        ];
        $rule = $plugingenerator->create_rule($ruledata);

        // Create booking option: maxanswers=2, useprice=1, waitforconfirmation=2,
        // confirmationonnotification=1 (non-exclusive).
        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'drained-chain-late-joiner';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = 1;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boinfo = new bo_info($settings);

        $taskclass = \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class;

        // Phase 1: Admin force-books s1 and s2 to fill the option (maxanswers=2 → fully booked).
        for ($i = 1; $i <= 2; $i++) {
            $boption->user_submit_response(
                $student[$i],
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE,
                MOD_BOOKING_VERIFIED
            );
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ALREADYBOOKED,
                $id,
                "student{$i} should be booked after admin force-booking"
            );
        }

        // Phase 2: s3 joins WL.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[3]);
        singleton_service::destroy_user($student[3]->id);
        booking_bookit::bookit('option', $settings->id, $student[3]->id);
        [$id] = $boinfo->is_available($settings->id, $student[3]->id, false);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
            $id,
            'student3: first bookit should show CONFIRMASKFORCONFIRMATION'
        );
        booking_bookit::bookit('option', $settings->id, $student[3]->id);
        [$id] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $id,
            'student3: second bookit should result in ONWAITINGLIST'
        );

        // Phase 3: s1 cancels → freetobookagain fires → exactly 1 direct confirm task for s3
        // (single WL user → the action never reaches counter=1, so no repeat-trigger is created).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $tasksaftercancel = \core\task\manager::get_adhoc_tasks($taskclass);
        $this->assertCount(
            1,
            $tasksaftercancel,
            'Expected exactly 1 direct confirm task for s3 after s1 cancels (single WL user, no repeat-trigger).'
        );

        // Phase 4: Run the batch → s3 confirmed (paid path, stays WL), chain fully drained.
        $mocktime = time_mock::get_mock_time();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $answer3 = $DB->get_record('booking_answers', [
            'optionid' => $option->id,
            'userid' => $student[3]->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $this->assertNotEmpty($answer3, 'student3 must have a booking_answers record on WL');
        $answer3json = empty($answer3->json) ? (object)[] : json_decode($answer3->json);
        $this->assertEquals(
            1,
            $answer3json->confirmationcount ?? 0,
            'student3 must have confirmationcount=1 after the chain batch ran'
        );
        $this->assertCount(
            0,
            \core\task\manager::get_adhoc_tasks($taskclass),
            'Drained-chain precondition: no confirm tasks may remain after the batch ran.'
        );

        // Phase 5 (KEY): s4 joins WL AFTER the chain has drained. The companion mechanism must
        // re-trigger the freetobookagain rule for exactly s4 (one free seat left: s3 stays on WL).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        $this->setUser($student[4]);
        singleton_service::destroy_user($student[4]->id);
        booking_bookit::bookit('option', $settings->id, $student[4]->id);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, false);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION,
            $id,
            'student4: first bookit should show CONFIRMASKFORCONFIRMATION'
        );
        booking_bookit::bookit('option', $settings->id, $student[4]->id);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $id,
            'student4: second bookit should result in ONWAITINGLIST'
        );

        $tasksafterlatejoin = array_filter(
            \core\task\manager::get_adhoc_tasks($taskclass),
            fn($task) => (int)($task->get_custom_data()->ruleid ?? 0) === (int)$rule->id
        );
        $latejoinuserids = array_map(fn($task) => (int)($task->get_custom_data()->userid ?? 0), $tasksafterlatejoin);
        $this->assertEquals(
            [(int)$student[4]->id],
            array_values($latejoinuserids),
            'Late joiner s4=' . $student[4]->id . ' must get exactly one confirm task right after joining '
                . 'the drained WL; actual queued confirm userids: [' . implode(',', $latejoinuserids) . ']'
        );

        // Run the batch → s4 confirmed (paid path, stays WL).
        $this->setAdminUser();
        $mocktime = time_mock::get_mock_time();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $answer4 = $DB->get_record('booking_answers', [
            'optionid' => $option->id,
            'userid' => $student[4]->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $this->assertNotEmpty($answer4, 'student4 must have a booking_answers record on WL');
        $answer4json = empty($answer4->json) ? (object)[] : json_decode($answer4->json);
        $this->assertEquals(
            1,
            $answer4json->confirmationcount ?? 0,
            'Late joiner student4 must have confirmationcount=1 after their confirm task ran'
        );

        singleton_service::destroy_user($student[4]->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_PRICEISSET,
            $id,
            'student4: after paid-path confirm task, user should be at PRICEISSET'
        );

        // Phase 6 (negative): shrink maxanswers to 1 → option fully booked with s2 alone.
        // (A force-book of s1 would land him on the WL with waitforconfirmation=2 and itself
        // trigger the companion mechanism, so we make the option full via the setting instead.)
        // A late joiner must NOT receive a confirm task when no seat is free.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        $updaterecord = new stdClass();
        $updaterecord->id = $option->id;
        $updaterecord->bookingid = $booking1->id;
        $updaterecord->text = $record->text;
        $updaterecord->maxanswers = 1;
        $updaterecord->chooseorcreatecourse = 1;
        $updaterecord->courseid = $course->id;
        $updaterecord->maxoverbooking = 10;
        $updaterecord->waitforconfirmation = 2;
        $updaterecord->confirmationonnotification = 1;
        $updaterecord->useprice = 1;
        $updaterecord->importing = 1;
        $updaterecord->optiondateid_0 = "0";
        $updaterecord->daystonotify_0 = "0";
        $updaterecord->coursestarttime_0 = $record->coursestarttime_0;
        $updaterecord->courseendtime_0 = $record->courseendtime_0;
        $updaterecord->teachersforoption = $teacher->username;
        booking_option::update($updaterecord);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        // Premise check: the option must really be fully booked now (s2 on 1/1 seats).
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertTrue(
            $bookinganswers->is_fully_booked(),
            'Phase 6 premise: option must be fully booked after maxanswers was reduced to 1.'
        );
        $this->assertCount(
            0,
            \core\task\manager::get_adhoc_tasks($taskclass),
            'Phase 6 premise: no confirm tasks may be queued before s5 joins.'
        );

        $this->setUser($student[5]);
        singleton_service::destroy_user($student[5]->id);
        booking_bookit::bookit('option', $settings->id, $student[5]->id);
        booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ONWAITINGLIST,
            $id,
            'student5: second bookit should result in ONWAITINGLIST'
        );

        $this->assertCount(
            0,
            \core\task\manager::get_adhoc_tasks($taskclass),
            'Late joiner s5 must NOT trigger any confirm task when the option is fully booked.'
        );

        // Even running tasks again must not create a task for s5.
        $this->setAdminUser();
        $mocktime = time_mock::get_mock_time();
        ob_start();
        $plugingenerator->runtaskswithintime($mocktime);
        ob_end_clean();

        $tasksfors5after = array_filter(
            \core\task\manager::get_adhoc_tasks($taskclass),
            fn($task) => (int)($task->get_custom_data()->userid ?? 0) === (int)$student[5]->id
        );
        $this->assertEmpty(
            $tasksfors5after,
            's5 should remain without confirm task after subsequent task runs.'
        );
    }

    /**
     * Shared scaffold for the chain-behavior regression tests below:
     * rule (react on freetobookagain, select_student_in_bo borole=1, action confirm_bookinganswer),
     * priced option (waitforconfirmation=2, maxanswers=2), s1+s2 force-booked by admin,
     * s3..s(2+$wlusercount) join the waitinglist with one-hour gaps (distinct timemodified).
     *
     * @param int $confirmationmode value for confirmationonnotification (1 or 2)
     * @param int $wlusercount how many users join the waitinglist initially (1-3)
     * @return array with keys settings, student, plugingenerator, option, booking, course, teacher, record
     */
    private function setup_waitinglist_chain_scenario(int $confirmationmode, int $wlusercount): array {
        $bdata = self::booking_common_settings_provider()['bdata'][0] ?? null;
        if ($bdata === null) {
            $bdata = self::booking_common_settings_provider();
        }
        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');
        $bdata['cancancelbook'] = 1;

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'pricecat',
            'name' => 'pricecat',
        ]);
        set_config('pricecategoryfield', 'pricecat', 'booking');
        set_config('displayemptyprice', 1, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Explicit pricecat guards against stale user_info_data of recycled user ids.
        $student = [];
        for ($i = 1; $i <= 5; $i++) {
            $student[$i] = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'default']);
        }
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        for ($i = 1; $i <= 5; $i++) {
            $this->getDataGenerator()->enrol_user($student[$i]->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 100,
            'pricecatsortorder' => 1,
        ]);

        $boevent = '"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_freetobookagain"';
        $plugingenerator->create_rule([
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'contextid' => 1,
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'confirm_bookinganswer',
            'actiondata' => '{}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{' . $boevent . ',"aftercompletion":0,"cancelrules":[]}',
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'chain-regression';
        $record->maxanswers = 2;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxoverbooking = 10;
        $record->waitforconfirmation = 2;
        $record->confirmationonnotification = $confirmationmode;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00', time());
        $record->courseendtime_0 = strtotime('20 July 2050 14:00', time());
        $record->teachersforoption = $teacher->username;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boinfo = new bo_info($settings);

        foreach ([1, 2] as $i) {
            $boption->user_submit_response(
                $student[$i],
                0,
                0,
                MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE,
                MOD_BOOKING_VERIFIED
            );
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id, "student{$i} should be force-booked");
        }

        for ($i = 3; $i < 3 + $wlusercount; $i++) {
            time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
            $this->setUser($student[$i]);
            singleton_service::destroy_user($student[$i]->id);
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            booking_bookit::bookit('option', $settings->id, $student[$i]->id);
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id, "student{$i} should be on the waitinglist");
        }

        return [
            'settings' => $settings,
            'student' => $student,
            'plugingenerator' => $plugingenerator,
            'option' => $option,
            'booking' => $booking,
            'course' => $course,
            'teacher' => $teacher,
            'record' => $record,
        ];
    }

    /**
     * Helper: map userid => timemodified for all current waitinglist answers of the option.
     *
     * @param int $optionid
     * @return array
     */
    private function get_waitinglist_timemodified_map(int $optionid): array {
        global $DB;
        $rows = $DB->get_records('booking_answers', [
            'optionid' => $optionid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ], '', 'id, userid, timemodified');
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row->userid] = (int)$row->timemodified;
        }
        return $map;
    }

    /**
     * Helper: run confirm-task batches until the queue is drained.
     *
     * @param mod_booking_generator $plugingenerator
     * @return void
     */
    private function drain_confirm_tasks(mod_booking_generator $plugingenerator): void {
        $this->setAdminUser();
        $taskclass = \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class;
        $batchcount = 0;
        do {
            ob_start();
            $plugingenerator->runtaskswithintime(time_mock::get_mock_time());
            ob_end_clean();
            $batchcount++;
        } while (!empty(\core\task\manager::get_adhoc_tasks($taskclass)) && $batchcount < 10);
        $this->assertLessThan(10, $batchcount, 'Confirm-task chain must drain within 10 batches.');
    }

    /**
     * Confirming and un-confirming waitinglist users must not change their timemodified:
     * timemodified is the waitinglist ORDER. In exclusive mode (confirmationonnotification=2)
     * each confirmation also un-confirms all other WL users, which used to rewrite every WL
     * row and flatten/destroy the original join order.
     *
     * @covers \mod_booking\booking_option::write_user_answer_to_db
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     */
    public function test_confirmation_writes_do_not_change_waitinglist_order(): void {
        global $DB;

        $this->resetAfterTest();
        $scenario = $this->setup_waitinglist_chain_scenario(2, 2);
        $settings = $scenario['settings'];
        $student = $scenario['student'];
        $plugingenerator = $scenario['plugingenerator'];
        $option = $scenario['option'];

        $tmbefore = $this->get_waitinglist_timemodified_map($option->id);
        $this->assertCount(2, $tmbefore, 'Premise: s3 and s4 must be on the waitinglist.');

        // S1 cancels -> freetobookagain -> confirm chain runs through (mode 2 march).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $this->drain_confirm_tasks($plugingenerator);

        $tmafter = $this->get_waitinglist_timemodified_map($option->id);
        $this->assertEquals(
            $tmbefore,
            $tmafter,
            'Confirm/un-confirm writes must not change timemodified of waitinglist answers (= the WL order).'
        );

        // The queue order (timemodified ASC, id ASC) must still be s3 before s4.
        $ordereduserids = array_keys($DB->get_records_sql(
            "SELECT ba.userid
               FROM {booking_answers} ba
              WHERE ba.optionid = :optionid AND ba.waitinglist = :wl
           ORDER BY ba.timemodified ASC, ba.id ASC",
            ['optionid' => $option->id, 'wl' => MOD_BOOKING_STATUSPARAM_WAITINGLIST]
        ));
        $this->assertEquals(
            [(int)$student[3]->id, (int)$student[4]->id],
            array_map('intval', $ordereduserids),
            'Waitinglist order must remain the join order after the confirm chain ran.'
        );
    }

    /**
     * A new freetobookagain event must not re-treat users who already hold a confirmation:
     * previously the chain restarted from the first WL user, inflating confirmationcount
     * (1 -> 2) and re-notifying everyone instead of advancing to unconfirmed users.
     *
     * @covers \mod_booking\booking_rules\actions\confirm_bookinganswer::execute
     * @covers \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::execute
     */
    public function test_chain_restart_does_not_retreat_confirmed_users(): void {
        global $DB;

        $this->resetAfterTest();
        $scenario = $this->setup_waitinglist_chain_scenario(1, 2);
        $settings = $scenario['settings'];
        $student = $scenario['student'];
        $plugingenerator = $scenario['plugingenerator'];
        $option = $scenario['option'];
        $record = $scenario['record'];
        $taskclass = \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class;

        // Event 1: s1 cancels -> chain confirms s3 and s4 (mode 1, both keep confirmation).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $this->drain_confirm_tasks($plugingenerator);

        foreach ([3, 4] as $i) {
            $answer = $DB->get_record('booking_answers', [
                'optionid' => $option->id,
                'userid' => $student[$i]->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]);
            $json = empty($answer->json) ? (object)[] : json_decode($answer->json);
            $this->assertEquals(1, $json->confirmationcount ?? 0, "student{$i} must be confirmed once after event 1");
        }
        $countsbefore = [];
        $tmbefore = $this->get_waitinglist_timemodified_map($option->id);

        // Event 2: shrink maxanswers to 1 (fully booked with s2 alone), then raise it back to 2
        // -> freetobookagain fires again. All WL users are already confirmed -> nothing to do.
        foreach ([1, 2] as $newmax) {
            time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
            $updaterecord = new stdClass();
            $updaterecord->id = $option->id;
            $updaterecord->bookingid = $scenario['booking']->id;
            $updaterecord->text = $record->text;
            $updaterecord->maxanswers = $newmax;
            $updaterecord->chooseorcreatecourse = 1;
            $updaterecord->courseid = $scenario['course']->id;
            $updaterecord->maxoverbooking = 10;
            $updaterecord->waitforconfirmation = 2;
            $updaterecord->confirmationonnotification = 1;
            $updaterecord->useprice = 1;
            $updaterecord->importing = 1;
            $updaterecord->optiondateid_0 = "0";
            $updaterecord->daystonotify_0 = "0";
            $updaterecord->coursestarttime_0 = $record->coursestarttime_0;
            $updaterecord->courseendtime_0 = $record->courseendtime_0;
            $updaterecord->teachersforoption = $scenario['teacher']->username;
            booking_option::update($updaterecord);
            singleton_service::destroy_booking_option_singleton($option->id);
        }

        $this->assertCount(
            0,
            \core\task\manager::get_adhoc_tasks($taskclass),
            'A repeated freetobookagain must not queue confirm tasks for already-confirmed WL users.'
        );

        // Belt and braces: run batches anyway; counts and timestamps must stay untouched.
        $this->drain_confirm_tasks($plugingenerator);
        foreach ([3, 4] as $i) {
            $answer = $DB->get_record('booking_answers', [
                'optionid' => $option->id,
                'userid' => $student[$i]->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]);
            $json = empty($answer->json) ? (object)[] : json_decode($answer->json);
            $this->assertEquals(
                1,
                $json->confirmationcount ?? 0,
                "student{$i}: confirmationcount must not be inflated by a repeated event"
            );
        }
        $this->assertEquals(
            $tmbefore,
            $this->get_waitinglist_timemodified_map($option->id),
            'Waitinglist order must survive a repeated freetobookagain event unchanged.'
        );
    }

    /**
     * A user who joins the waitinglist while the LAST direct confirm task of a chain is still
     * pending (no repeat-trigger in the queue anymore) must still receive a confirm task:
     * previously the companion mechanism was blocked by ANY pending task of the rule/option,
     * although only a repeat-trigger re-queries the waitinglist.
     *
     * @covers \mod_booking\booking_rules\rules_info::collect_rules_for_execution
     * @covers \mod_booking\event\bookingoptionwaitinglist_booked
     */
    public function test_late_joiner_during_last_pending_direct_task_gets_task(): void {
        global $DB;

        $this->resetAfterTest();
        $scenario = $this->setup_waitinglist_chain_scenario(1, 1);
        $settings = $scenario['settings'];
        $student = $scenario['student'];
        $plugingenerator = $scenario['plugingenerator'];
        $option = $scenario['option'];
        $taskclass = \mod_booking\task\confirm_bookinganswer_by_rule_adhoc::class;

        // S1 cancels -> single WL user s3 -> exactly ONE direct task, no repeat-trigger.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $tasks = \core\task\manager::get_adhoc_tasks($taskclass);
        $this->assertCount(1, $tasks, 'Premise: single WL user -> exactly one direct confirm task.');
        $task = reset($tasks);
        $this->assertEmpty(
            $task->get_custom_data()->repeat ?? 0,
            'Premise: the single pending task must be a direct task, not a repeat-trigger.'
        );

        // S4 joins the WL while s3's direct task is still pending.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);
        $this->setUser($student[4]);
        singleton_service::destroy_user($student[4]->id);
        booking_bookit::bookit('option', $settings->id, $student[4]->id);
        booking_bookit::bookit('option', $settings->id, $student[4]->id);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id, 'student4 should be on the waitinglist');

        $tasksfors4 = array_filter(
            \core\task\manager::get_adhoc_tasks($taskclass),
            fn($t) => (int)($t->get_custom_data()->userid ?? 0) === (int)$student[4]->id
        );
        $this->assertCount(
            1,
            $tasksfors4,
            'Late joiner s4 must get exactly one confirm task although s3\'s direct task is still pending '
                . '(only a repeat-trigger may block the companion mechanism).'
        );

        // Drain: both users end up confirmed once.
        $this->drain_confirm_tasks($plugingenerator);
        foreach ([3, 4] as $i) {
            $answer = $DB->get_record('booking_answers', [
                'optionid' => $option->id,
                'userid' => $student[$i]->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]);
            $json = empty($answer->json) ? (object)[] : json_decode($answer->json);
            $this->assertEquals(1, $json->confirmationcount ?? 0, "student{$i} must be confirmed exactly once");
        }
    }
}

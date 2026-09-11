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
     * Rewritten 2026-08-26 for the waitlist-progression refactor (Phase 3): the old
     * confirm_bookinganswer task chain (which notified/"confirmed" ALL waiting-list users
     * regardless of real free capacity) is gone - confirm_bookinganswer and
     * confirm_bookinganswer_by_rule_adhoc are now deliberate no-ops
     * (WAITLIST_REFACTOR_BLUEPRINT_2026-08-04.md §2.5). progression::reconcile() is the new,
     * single write path and enforces K1/K2 (free capacity = maxanswers - booked - open offers):
     * exactly ONE offer is created per real free seat, never more - a correctness improvement
     * over the old behaviour, not a like-for-like replacement (confirmed with Georg, 2026-08-26).
     *
     * Scenario
     * --------
     * maxanswers=2 → s1+s2 fully book via shopping_cart.
     * s3–s7 (all price=100/default) join WL, in that order.
     * s1 cancels → check_if_free_to_book_again() → freetobookagain_waitlist_adapter →
     * progression::reconcile() runs SYNCHRONOUSLY (no task queue for the offer step) → exactly
     * ONE offer for s3 (oldest WL user, K1), confirmationonnotification=1 grants confirmation
     * immediately (PRICEISSET) - s4-s7 stay untouched (ONWAITINGLIST).
     * s8+s9 join WL AFTER the cancel (late joiners) - capacity is fully consumed by s3's open
     * offer, so they get nothing either, exactly like s4-s7.
     * s3 completes real payment via shopping_cart → truly booked (WL=0) - the seat s3's offer
     * reserved is now actually taken, so no new capacity opens for anyone else.
     * s2 also cancels (second real seat frees) → reconciler offers the next-oldest UNTOUCHED WL
     * user, s4 - NOT s8/s9, proving late joiners stay behind pre-existing WL members (O1/O2).
     *
     * @covers \mod_booking\local\waitlist\progression::reconcile
     * @covers \mod_booking\local\waitlist\progression::grant_confirmation_if_required
     * @covers \mod_booking\event\observer\freetobookagain_waitlist_adapter::reconcile
     * @covers \mod_booking\booking_option::check_if_free_to_book_again
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
        // action = send_mail_interval - the new architecture's rule_condition_checker only
        // recognizes this actionname as a waitlist-progression rule (K11); confirm_bookinganswer
        // rows are silently ignored, and reconcile() no-ops entirely without an applicable rule.
        $actiondata = json_encode([
            'interval' => 60,
            'subject' => 'confirmwaitinglistsubj',
            'template' => 'confirmwaitinglistmsg',
            'templateformat' => '1',
        ]);
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actiondata,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => (string) \mod_booking\booking_rules\rules\rule_react_on_event::ALWAYS,
        ];
        $plugingenerator->create_rule($ruledata);

        // Create booking option: maxanswers=2 (fully booked by s1+s2), useprice=1,
        // waitforconfirmation=2, confirmationonnotification=1 (grant confirmation as soon as the
        // offer is created, no separate manual-confirm step).
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

        // Phase 2: s3–s7 join WL (all have price=100 → paid/offer path once capacity allows).
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

        // Phase 3: s1 cancels → progression::reconcile() runs synchronously → exactly ONE offer
        // for the oldest WL user (s3, K1) - s4-s7 are left completely untouched.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $offersaftercancel = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(
            1,
            $offersaftercancel,
            'K1: exactly one offer must exist after s1 cancels - only one real seat freed up, ' .
            'regardless of how many users are waiting.'
        );
        $offer = reset($offersaftercancel);
        $this->assertEquals(
            (int) $student[3]->id,
            (int) $offer->userid,
            'The offer must go to s3, the oldest untouched WL user.'
        );

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        [$id] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_PRICEISSET,
            $id,
            'student3: confirmationonnotification=1 must grant confirmation as soon as the offer is created.'
        );
        for ($i = 4; $i <= 7; $i++) {
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                "student{$i}: must stay untouched on WL - no free capacity left (K1)."
            );
        }

        // Phase 4: s8 and s9 join WL AFTER s3's offer already consumed the only free seat -
        // capacity is 0, so they must be left untouched exactly like s4-s7 (late joiners never
        // jump the queue, O1/O2).
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

        $offersafterlatejoin = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(
            1,
            $offersafterlatejoin,
            'Late joiners s8/s9 must not trigger any new offer - no seat freed up for them.'
        );

        // Phase 5: s3 actually completes payment - the seat their offer reserved is now truly
        // taken. No new capacity opens up for anyone else as a result.
        $this->setAdminUser();
        shopping_cart::delete_all_items_from_cart($student[3]->id);
        shopping_cart::buy_for_user($student[3]->id);
        $cartstore = cartstore::instance($student[3]->id);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
        shopping_cart::confirm_payment($student[3]->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        singleton_service::destroy_user($student[3]->id);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        [$id] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'student3 must be truly booked (WL=0) after completing payment.'
        );
        foreach ([4, 5, 6, 7, 8, 9] as $i) {
            [$id] = $boinfo->is_available($settings->id, $student[$i]->id, true);
            $this->assertEquals(
                MOD_BOOKING_BO_COND_ONWAITINGLIST,
                $id,
                "student{$i}: must still be untouched - s3 paying did not free a NEW seat."
            );
        }

        // Phase 6: s2 also cancels → a second real seat frees up → the reconciler must offer the
        // next-oldest UNTOUCHED WL user, s4 - NOT s8/s9, proving late joiners stay in order.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[2]->id);

        $offersfinal = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id], 'id ASC');
        $this->assertCount(
            2,
            $offersfinal,
            'Two offers must exist in total now: s3 (already resolved via payment) + s4 (new).'
        );
        $newoffer = end($offersfinal);
        $this->assertEquals(
            (int) $student[4]->id,
            (int) $newoffer->userid,
            'The second real seat must go to s4 (oldest remaining untouched WL user), not s8/s9 (late joiners).'
        );

        // Sanity: exactly s2 (booked, then cancelled) is gone, s3 truly booked, everyone else
        // still on WL except s4 (offered, not yet paid - stays WL=1 until payment).
        $bookedcount = $DB->count_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertEquals(
            1,
            $bookedcount,
            'Only s3 should be truly booked (s1 and s2 both cancelled, s4-s9 remain on WL).'
        );
    }

    /**
     * Rewritten 2026-08-26 for the waitlist-progression refactor (Phase 3). The old "Bug A" fix
     * this test protected (auto-booking a free user must re-fire freetobookagain so a remaining
     * free seat re-queues a confirm task for late joiners) is now structurally built into
     * progression::reconcile(): its inner loop (K1) already processes as many candidates - mixed
     * offer/autobook decisions - as fit into the free capacity of a SINGLE reconcile() call, no
     * task queue or re-trigger needed at all. This test now verifies that guarantee directly.
     *
     * Scenario
     * --------
     * maxanswers=2 → s1+s2 fully book via shopping_cart.
     * s3 (price=100, paid) + s4 (price=0/student, free) join WL, s3 first.
     * Admin raises maxanswers to 4 → 2 seats free → ONE progression::reconcile() call must
     * process BOTH: s3 gets an offer (K4), s4 is autobooked immediately (K3) - no batches.
     * s5 joins WL AFTER that reconcile() already ran: capacity is now 4 - 3 booked (s1,s2,s4) -
     * 1 open offer (s3) = 0, so s5 must be left completely untouched (K1/late-joiner ordering,
     * already covered end-to-end for the cascading-cancellation case by
     * test_all_paid_waitinglist_users_get_tasks_and_late_joiners_retriggered - this test's own
     * value-add is the single-call, mixed-decision multi-candidate batch).
     *
     * @covers \mod_booking\local\waitlist\progression::reconcile
     * @covers \mod_booking\event\observer\freetobookagain_waitlist_adapter::reconcile
     * @covers \mod_booking\booking_option::check_if_free_to_book_again
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
        // action = send_mail_interval - the only actionname rule_condition_checker recognizes
        // as a waitlist-progression rule (K11); without it, reconcile() finds no applicable rule
        // and no-ops entirely, not even the K3 autobook.
        $actiondata = json_encode([
            'interval' => 60,
            'subject' => 'confirmwaitinglistsubj',
            'template' => 'confirmwaitinglistmsg',
            'templateformat' => '1',
        ]);
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actiondata,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => (string) \mod_booking\booking_rules\rules\rule_react_on_event::ALWAYS,
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
        $boinfo = new bo_info($settings);

        // KEY ASSERTION: a SINGLE reconcile() call (triggered synchronously by
        // check_if_free_to_book_again() from booking_option::update()) must process BOTH WL
        // users in one pass - no task queue, no batches, no re-trigger needed.
        // progression::autobook() also writes a booking_waitlist_offers row (status=autobooked,
        // an audit-trail entry, not an open offer) - so both candidates always get a row here;
        // what matters is each one's STATUS, and only "offered" counts as an open offer against
        // capacity (db_waitlist_offer_repository::get_open_offers()).
        $offers = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id], 'sortorder ASC');
        $this->assertCount(
            2,
            $offers,
            'Both s3 (offered) and s4 (autobooked) must have a booking_waitlist_offers row after '
            . 'the maxanswers increase - one reconcile() call, no separate batches.'
        );
        $offersbyuserid = [];
        foreach ($offers as $o) {
            $offersbyuserid[(int) $o->userid] = (int) $o->status;
        }
        $this->assertEquals(
            (new \mod_booking\local\waitlist\offer_statuses\offered())->get_code(),
            $offersbyuserid[(int) $student[3]->id] ?? null,
            's3 (paid candidate) must be offered, not autobooked.'
        );
        $this->assertEquals(
            (new \mod_booking\local\waitlist\offer_statuses\autobooked())->get_code(),
            $offersbyuserid[(int) $student[4]->id] ?? null,
            's4 (free candidate) must be autobooked, not merely offered.'
        );

        // S4 (free, price=0): autobooked immediately (K3) - in the SAME reconcile() call as s3's
        // offer, not a separate batch.
        singleton_service::destroy_user($student[4]->id);
        [$id] = $boinfo->is_available($settings->id, $student[4]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_ALREADYBOOKED,
            $id,
            'student4 (free user) must be autobooked in the same reconcile() call as s3\'s offer - '
            . 'no separate batch/re-trigger needed under the new architecture.'
        );

        // Exactly 3 booked (s1, s2, s4) - s3 stays on WL (offered, not booked yet).
        $bookedrecords = $DB->get_records('booking_answers', [
            'optionid' => $option->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ]);
        $this->assertCount(
            3,
            $bookedrecords,
            'Expected exactly 3 booked (s1, s2, s4) immediately after the maxanswers increase.'
        );

        // Phase 4: student5 joins AFTER reconcile() already ran. Free capacity is now
        // 4 (maxanswers) - 3 (booked: s1,s2,s4) - 1 (open offer: s3) = 0, so student5 must be
        // left completely untouched - no offer, no autobook, just ONWAITINGLIST (K1/O1-O2:
        // late joiners never jump the queue). The end-to-end "does a late joiner eventually get
        // processed once real capacity frees up again" guarantee is already covered by
        // test_all_paid_waitinglist_users_get_tasks_and_late_joiners_retriggered.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[5]);
        singleton_service::destroy_user($student[5]->id);
        $result = booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        $result = booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $offersafters5 = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(
            2,
            $offersafters5,
            'student5 joining must not create any new offer/autobook row - no free capacity left (K1).'
        );
    }

    /**
     * Rewritten 2026-08-26 for the waitlist-progression refactor (Phase 3). The old
     * "confirmationonnotification 1 vs 2 (exclusive)" distinction does not exist in the new
     * grant_confirmation_if_required() (local/waitlist/progression.php:288): it treats ANY
     * non-zero confirmationonnotification value identically - grant confirmation immediately once
     * an offer/autobook is created. This test now verifies exactly that: mode 2 behaves the same
     * as mode 1 (already covered for mode 1 by
     * test_free_user_autobooking_retriggers_task_for_late_joining_waitinglist_user), and that a
     * late joiner still gets nothing once K1 capacity is exhausted - the old "one seat consumed,
     * other WL user still notified one-at-a-time" requirement no longer holds, because an OFFER
     * now correctly reserves its seat (open offers count against capacity) instead of being a
     * capacity-free notification - confirmed with Georg, 2026-08-26 (see
     * test_all_paid_waitinglist_users_get_tasks_and_late_joiners_retriggered's docblock for the
     * same finding).
     *
     * Scenario
     * --------
     * maxanswers=2 -> s1+s2 are fully booked.
     * s3 (paid) + s4 (free) join WL.
     * maxanswers increased to 4 -> ONE reconcile() call processes both: s3 offered (K4,
     * confirmation granted immediately despite mode=2), s4 autobooked (K3) - both free seats
     * claimed in that single call.
     * s5 (free) joins WL AFTER that reconcile() already ran -> capacity is 0, s5 gets nothing.
     *
     * @covers \mod_booking\local\waitlist\progression::reconcile
     * @covers \mod_booking\local\waitlist\progression::grant_confirmation_if_required
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

        // Rule reacts on freetobookagain - action = send_mail_interval, the only actionname
        // rule_condition_checker recognizes as a waitlist-progression rule (K11).
        $actiondata = json_encode([
            'interval' => 60,
            'subject' => 'confirmwaitinglistsubj',
            'template' => 'confirmwaitinglistmsg',
            'templateformat' => '1',
        ]);
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actiondata,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => (string) \mod_booking\booking_rules\rules\rule_react_on_event::ALWAYS,
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

        // ONE reconcile() call must process both WL users: s3 offered, s4 autobooked - see
        // test_free_user_autobooking_retriggers_task_for_late_joining_waitinglist_user for the
        // same K1 mechanism verified in detail (mode=1 there).
        $offers = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $offersbyuserid = [];
        foreach ($offers as $o) {
            $offersbyuserid[(int) $o->userid] = (int) $o->status;
        }
        $this->assertCount(2, $offers, 'Both s3 (offered) and s4 (autobooked) must be processed in one reconcile() call.');
        $this->assertEquals(
            (new \mod_booking\local\waitlist\offer_statuses\offered())->get_code(),
            $offersbyuserid[(int) $student[3]->id] ?? null,
            's3 (paid candidate) must be offered.'
        );
        $this->assertEquals(
            (new \mod_booking\local\waitlist\offer_statuses\autobooked())->get_code(),
            $offersbyuserid[(int) $student[4]->id] ?? null,
            's4 (free candidate) must be autobooked.'
        );

        // Mode-2-specific assertion: confirmation must be granted immediately for s3, exactly
        // like mode 1 - grant_confirmation_if_required() does not distinguish 1 from 2.
        [$id] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(
            MOD_BOOKING_BO_COND_PRICEISSET,
            $id,
            'confirmationonnotification=2 must grant confirmation immediately, same as mode 1.'
        );

        // Late joiner s5 joins after reconcile() already claimed both free seats - capacity is
        // 0 (4 maxanswers - 3 booked[s1,s2,s4] - 1 open offer[s3]), so s5 must get nothing.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setUser($student[5]);
        singleton_service::destroy_user($student[5]->id);
        booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, false);
        $this->assertEquals(MOD_BOOKING_BO_COND_CONFIRMASKFORCONFIRMATION, $id);
        booking_bookit::bookit('option', $settings->id, $student[5]->id);
        [$id] = $boinfo->is_available($settings->id, $student[5]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $offersafters5 = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(
            2,
            $offersafters5,
            'Late joiner s5 must not trigger any new offer/autobook - no free capacity left (K1).'
        );
    }

    /**
     * Rewritten 2026-08-26 for the waitlist-progression refactor (Phase 3). The old "companion
     * mechanism" this test protected (a late joiner immediately gets notified once the chain has
     * drained, as long as a seat is nominally free) does not carry over cleanly: under the new
     * architecture, an outstanding, unresolved OFFER correctly counts against free capacity
     * (capacity_calculator: maxanswers - booked - open_offers). So once s3 has an open offer, the
     * option's free capacity is genuinely 0, and a new joiner (s4) correctly gets nothing - there
     * is no seat to give them, unlike the old chain where "confirmed but still on WL" never
     * consumed capacity.
     *
     * Separately, this test surfaced a real, confirmed gap while investigating (T5,
     * WAITLIST_REFACTOR_IMPLEMENTATION_PROGRESS_2026-08-12.md: "latejoiner_waitlist_adapter ...
     * noch offen/nicht recherchiert"): progression::reconcile() is only ever triggered by a
     * cancellation/maxanswers-change (freetobookagain_waitlist_adapter), an offer expiring
     * (expire_waitlist_offer_adhoc), or the periodic waitlist_heartbeat_task (T7, up to ~15min) -
     * never by a user simply JOINING the waiting list. So if real free capacity DID exist at
     * join time, the joiner would not be offered immediately, only picked up by the next
     * heartbeat run. Confirmed with Georg (2026-08-26): not implementing T5 now, tracked
     * separately, this test intentionally does not exercise the heartbeat's catch-up (that
     * belongs in a dedicated T5/T7 test once T5 is built).
     *
     * Scenario
     * --------
     * maxanswers=2 → s1+s2 force-booked by admin (fully booked).
     * s3 (paid) joins WL.
     * s1 cancels → ONE reconcile() call → s3 gets an offer (K4), confirmation granted
     *   immediately (confirmationonnotification=1) → PRICEISSET.
     * s4 joins WL while s3's offer is still open → free capacity is 0 (maxanswers=2 - booked=1[s2]
     *   - open_offers=1[s3]) → s4 must get nothing (T5 gap aside, there is genuinely no seat).
     * Admin shrinks maxanswers to 1 → option fully booked with s2 alone.
     * s5 joins WL → must NOT receive an offer either (same reason, now trivially true).
     *
     * @covers \mod_booking\local\waitlist\progression::reconcile
     * @covers \mod_booking\local\waitlist\capacity_calculator::free_capacity
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

        // Create booking rule: react on freetobookagain - action = send_mail_interval, the only
        // actionname rule_condition_checker recognizes as a waitlist-progression rule (K11).
        $actiondata = json_encode([
            'interval' => 60,
            'subject' => 'confirmwaitinglistsubj',
            'template' => 'confirmwaitinglistmsg',
            'templateformat' => '1',
        ]);
        $ruledata = [
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actiondata,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => (string) \mod_booking\booking_rules\rules\rule_react_on_event::ALWAYS,
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

        // Phase 3: s1 cancels → ONE reconcile() call offers the seat to s3 (K4), confirmation
        // granted immediately (confirmationonnotification=1) → PRICEISSET.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $offersaftercancel = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(
            1,
            $offersaftercancel,
            'Expected exactly one offer for s3 after s1 cancels (single WL user, one free seat).'
        );
        $offer = reset($offersaftercancel);
        $this->assertEquals((int) $student[3]->id, (int) $offer->userid);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

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
            'student3 must have confirmationcount=1 immediately after reconcile() ran'
        );
        [$id] = $boinfo->is_available($settings->id, $student[3]->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_PRICEISSET, $id);

        // Phase 4 (rewritten intent, see docblock): s4 joins WL while s3's offer is still open.
        // Free capacity is 0 (maxanswers=2 - booked=1[s2] - open_offers=1[s3]) - there is
        // genuinely no seat to give s4, so they must get nothing (not a "companion mechanism"
        // failing, capacity is correctly exhausted).
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
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

        $offersafters4 = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(
            1,
            $offersafters4,
            's4 joining while s3\'s offer is still open must not create any new offer - no free capacity.'
        );

        // Phase 5 (negative, now trivially true too): shrink maxanswers to 1 → option fully
        // booked with s2 alone. A late joiner (s5) must not receive an offer either.
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
            'Phase 5 premise: option must be fully booked after maxanswers was reduced to 1.'
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

        $offersafters5 = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(
            1,
            $offersafters5,
            'Late joiner s5 must NOT trigger any new offer when the option is fully booked.'
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

        // Action = send_mail_interval - the only actionname rule_condition_checker recognizes as
        // a waitlist-progression rule (K11); confirm_bookinganswer is a Phase-3 no-op.
        $actiondata = json_encode([
            'interval' => 60,
            'subject' => 'confirmwaitinglistsubj',
            'template' => 'confirmwaitinglistmsg',
            'templateformat' => '1',
        ]);
        $plugingenerator->create_rule([
            'name' => 'confirmwaitinglistusers',
            'conditionname' => 'select_student_in_bo',
            'conditiondata' => '{"borole":"1"}',
            'actionname' => 'send_mail_interval',
            'actiondata' => $actiondata,
            'rulename' => 'rule_react_on_event',
            'boevent' => '\\mod_booking\\event\\bookingoption_freetobookagain',
            'condition' => (string) \mod_booking\booking_rules\rules\rule_react_on_event::ALWAYS,
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
     * Rewritten 2026-08-26 for the waitlist-progression refactor (Phase 3) - same finding as
     * test_confirm_chain_drained_late_joiner_gets_confirmation_task's docblock (T5 gap,
     * capacity now correctly reserved by an open offer): s3's offer is still open/unresolved
     * when s4 joins, so free capacity is genuinely 0 (maxanswers=2 - booked=1[s2] -
     * open_offers=1[s3]) - s4 must get nothing, not "exactly one task despite s3 still pending".
     *
     * @covers \mod_booking\local\waitlist\progression::reconcile
     * @covers \mod_booking\local\waitlist\capacity_calculator::free_capacity
     */
    public function test_late_joiner_during_last_pending_direct_task_gets_task(): void {
        global $DB;

        $this->resetAfterTest();
        $scenario = $this->setup_waitinglist_chain_scenario(1, 1);
        $settings = $scenario['settings'];
        $student = $scenario['student'];
        $plugingenerator = $scenario['plugingenerator'];
        $option = $scenario['option'];

        // S1 cancels -> single WL user s3 -> reconcile() offers the one free seat to s3.
        time_mock::set_mock_time(strtotime('+1 hour', time_mock::get_mock_time()));
        $this->setAdminUser();
        singleton_service::destroy_booking_option_singleton($option->id);
        $boption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $boption->user_delete_response($student[1]->id);

        $offers = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(1, $offers, 'Premise: single WL user -> exactly one offer.');
        $offer = reset($offers);
        $this->assertEquals((int) $student[3]->id, (int) $offer->userid);

        // S4 joins the WL while s3's offer is still open (unresolved) - capacity is 0, s4 must
        // get nothing (T5 gap aside, there is genuinely no seat - see docblock).
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

        $offersafters4 = $DB->get_records('booking_waitlist_offers', ['optionid' => $option->id]);
        $this->assertCount(
            1,
            $offersafters4,
            's4 joining while s3\'s offer is still open must not create any new offer - no free capacity.'
        );

        // Sanity: s3 alone ends up confirmed once (s4 never got anything to be confirmed by).
        // No task-draining here: reconcile() already ran synchronously at cancel time, and
        // draining would risk running s3's own K4 hard-expiry task if enough mock time has
        // passed, which would cascade a fresh offer to s4 and defeat the point of this check.
        $answer3 = $DB->get_record('booking_answers', [
            'optionid' => $option->id,
            'userid' => $student[3]->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $json3 = empty($answer3->json) ? (object)[] : json_decode($answer3->json);
        $this->assertEquals(1, $json3->confirmationcount ?? 0, 'student3 must be confirmed exactly once');

        $answer4 = $DB->get_record('booking_answers', [
            'optionid' => $option->id,
            'userid' => $student[4]->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);
        $json4 = empty($answer4->json) ? (object)[] : json_decode($answer4->json);
        $this->assertEquals(0, $json4->confirmationcount ?? 0, 'student4 must not be confirmed - never got an offer');
    }
}

<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_booking;

use mod_booking\event\bookinganswer_waitingforconfirmation;
use mod_booking\event\bookingoptionwaitinglist_booked;

/**
 * Guards the intended event semantics for waitforconfirmation options: when a
 * user lands on the REAL waiting list of a FULL option that requires
 * confirmation, bookinganswer_waitingforconfirmation fires ADDITIONALLY to
 * bookingoptionwaitinglist_booked - same as in the parked case (free seat).
 * Firing both is intended: rule-level cancelrules (skip) take care of
 * suppressing redundant mails.
 *
 * Production-style rule setup mirrored here (contextid 1):
 * - a receipt rule reacts on bookingoptionwaitinglist_booked (receipt mail to
 *   the user) and cancels the plain waiting list place rule.
 * - a waiting list place rule reacts on bookingoptionwaitinglist_booked
 *   (plain waiting list mail to the user).
 * - a supervisor request rule reacts on bookinganswer_waitingforconfirmation
 *   (confirmation request mail) and also cancels the waiting list place rule.
 * Expected outcome of a waiting list landing: receipt + confirmation request
 * go out, the plain waiting list mail is skipped.
 *
 * Note on PHPUnit vs production: in production, rules collected from ALL events
 * of the request are filtered (cancelrules) once in the shutdown hook. Under
 * PHPUnit, observer::execute_rule() filters + executes after EVERY event. In
 * this scenario the waitingforconfirmation event fires before the
 * waitinglist_booked event, so the cancel semantics end up the same as in
 * production; with the reverse event order they would not.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::user_submit_response
 * @covers \mod_booking\event\bookinganswer_waitingforconfirmation
 * @covers \mod_booking\event\bookingoptionwaitinglist_booked
 * @covers \mod_booking\booking_rules\rules_info::filter_rules_and_execute
 */
final class waitingforconfirmation_waitinglist_events_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Create a mail rule reacting on the given booking event.
     *
     * @param string $name rule name
     * @param string $event short booking event class name
     * @param string $subject mail subject prefix (needle)
     * @param array $cancel rule ids to cancel
     * @return \stdClass the created rule record
     */
    private function create_mail_rule(string $name, string $event, string $subject, array $cancel): \stdClass {
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        return $gen->create_rule([
            'name' => $name,
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",'
                . '"subject":"' . $subject . ' {title}","template":"x","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\' . $event . '",'
                . '"condition":"0","aftercompletion":1,"cancelrules":' . json_encode($cancel) . '}',
        ]);
    }

    /**
     * Collect the subject needles of all queued rule mail tasks.
     *
     * @param string[] $needles subject prefixes to look for
     * @return string[] found needles
     */
    private function queued_rule_mail_subjects(array $needles): array {
        $queued = [];
        foreach (\core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc') as $task) {
            $customdata = json_encode($task->get_custom_data());
            foreach ($needles as $needle) {
                if (str_contains($customdata, $needle)) {
                    $queued[] = $needle;
                }
            }
        }
        return $queued;
    }

    /**
     * A user landing on the real waiting list of a full option (waitforconfirmation
     * enabled) fires BOTH events; the rules then deliver the receipt mail and the
     * confirmation request while the plain waiting list mail is skipped.
     */
    public function test_waitinglist_landing_on_full_wfc_option_fires_both_events(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Confirmation booking',
        ]);

        $bookeduser = $this->getDataGenerator()->create_user();
        $eventprobe = $this->getDataGenerator()->create_user();
        $rulesprobe = $this->getDataGenerator()->create_user();
        foreach ([$bookeduser, $eventprobe, $rulesprobe] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Full option with confirmation waiting list',
            'description' => 'Full option with confirmation waiting list',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => 2,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        // Fill the single seat while confirmation is still off, then enable
        // waitforconfirmation - the persisted state of the affected options.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($bookeduser, 0, 0, 0, MOD_BOOKING_VERIFIED);

        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'waitforconfirmation' => 1,
        ]);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertEquals(1, (int) $settings->waitforconfirmation, 'precondition: confirmation is enabled');
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(1, $answers->get_usersonlist(), 'precondition: the single seat is taken');

        // The three mail rules. A production supervisor rule would address the
        // supervisor via a user profile field; the addressee is irrelevant for
        // WHICH rule reacts, so all use select_user_from_event here.
        $ruleplace = $this->create_mail_rule(
            'waitinglist place mail',
            'bookingoptionwaitinglist_booked',
            'Waiting list place for',
            []
        );
        $this->create_mail_rule(
            'waitinglist receipt mail',
            'bookingoptionwaitinglist_booked',
            'Registration received for',
            [(string) $ruleplace->id]
        );
        $this->create_mail_rule(
            'confirmation request mail',
            'bookinganswer_waitingforconfirmation',
            'Confirmation request for',
            [(string) $ruleplace->id]
        );

        // Phase A - event sink: which events fire when a user lands on the real
        // waiting list? (Redirected events do NOT reach observers, so the rules
        // stay quiet during this phase.)
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $sink = $this->redirectEvents();
        $bookingoption->user_submit_response($eventprobe, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $firedclasses = array_map(static fn($e): string => get_class($e), $sink->get_events());
        $sink->close();
        $bookingevents = array_values(array_filter(
            $firedclasses,
            static fn(string $c): bool => str_starts_with($c, 'mod_booking\\')
        ));

        // Phase B - live observers: book the second probe user, let the rules
        // react (under PHPUnit they are filtered + executed right after each
        // event, which stands in for the production shutdown hook) and record
        // which rule mails end up in the adhoc queue.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($rulesprobe, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $queuedsubjects = $this->queued_rule_mail_subjects([
            'Waiting list place for',
            'Registration received for',
            'Confirmation request for',
        ]);

        // Sanity: both probe users really landed on the waiting list, the seat is untouched.
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(1, $answers->get_usersonlist(), 'the single seat still holds one user');
        $this->assertCount(2, $answers->get_usersonwaitinglist(), 'both probe users are on the waiting list');

        // Intended semantics: the waiting list landing on a confirmation option
        // fires BOTH events, mirroring the parked case.
        $this->assertContains(
            bookingoptionwaitinglist_booked::class,
            $bookingevents,
            'landing on the waiting list must fire bookingoptionwaitinglist_booked'
        );
        $this->assertContains(
            bookinganswer_waitingforconfirmation::class,
            $bookingevents,
            'landing on the real waiting list of a confirmation option must also fire '
                . 'bookinganswer_waitingforconfirmation'
        );

        // Rule outcome: receipt + confirmation request go out, the plain waiting
        // list mail is skipped via cancelrules.
        $this->assertContains(
            'Registration received for',
            $queuedsubjects,
            'the receipt rule mail must be queued'
        );
        $this->assertContains(
            'Confirmation request for',
            $queuedsubjects,
            'the confirmation-request rule mail must be queued'
        );
        $this->assertNotContains(
            'Waiting list place for',
            $queuedsubjects,
            'the plain waiting list mail must be skipped via cancelrules'
        );
    }

    /**
     * The incident state observed on booking 9.3.2: maxoverbooking was reduced
     * from unlimited (-1) to 2 while four users stayed on the waiting list, and
     * the option requires confirmation. A further booking attempt then ran
     * through check_if_limit's overbooking fallthrough (BOOKED), which the
     * waitforconfirmation parking branch converted back to WAITINGLIST - firing
     * bookinganswer_waitingforconfirmation for a plain waiting list landing.
     * Fixed: such an attempt is rejected without any event or mail.
     */
    public function test_overfull_waitinglist_with_confirmation_rejects_attempts(): void {
        set_config('keepusersbookedonreducingmaxanswers', 1, 'booking');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Overfull confirmation booking',
        ]);

        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $eventprobe = $this->getDataGenerator()->create_user();
        $rulesprobe = $this->getDataGenerator()->create_user();
        foreach (array_merge([$bookeduser, $eventprobe, $rulesprobe], $waiters) as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Single seat, unlimited waiting list',
            'description' => 'Single seat, unlimited waiting list',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => -1,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        // Fill the seat and the unlimited waiting list.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($bookeduser, 0, 0, 0, MOD_BOOKING_VERIFIED);
        foreach ($waiters as $waiter) {
            $bookingoption->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }

        // The admin reduces the waiting list limit below the current waiting count
        // and the option requires confirmation (pre-registration flow).
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxoverbooking' => 2,
            'waitforconfirmation' => 1,
        ]);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertEquals(1, (int) $settings->waitforconfirmation, 'precondition: confirmation is enabled');
        $this->assertEquals(2, (int) $settings->maxoverbooking, 'precondition: waiting list limit reduced to 2');
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(1, $answers->get_usersonlist(), 'precondition: the single seat is taken');
        $this->assertCount(4, $answers->get_usersonwaitinglist(), 'precondition: four users stay on the over-full list');

        // The two mail rules.
        $ruleplace = $this->create_mail_rule(
            'waitinglist place mail',
            'bookingoptionwaitinglist_booked',
            'Waiting list place for',
            []
        );
        $this->create_mail_rule(
            'confirmation request mail',
            'bookinganswer_waitingforconfirmation',
            'Confirmation request for',
            [(string) $ruleplace->id]
        );

        // Phase A - event sink: which events fire for a booking attempt in this state?
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $sink = $this->redirectEvents();
        $bookingoption->user_submit_response($eventprobe, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $firedclasses = array_map(static fn($e): string => get_class($e), $sink->get_events());
        $sink->close();
        $bookingevents = array_values(array_filter(
            $firedclasses,
            static fn(string $c): bool => str_starts_with($c, 'mod_booking\\')
        ));

        // Phase B - live observers + rules.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($rulesprobe, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $queuedsubjects = $this->queued_rule_mail_subjects([
            'Waiting list place for',
            'Confirmation request for',
        ]);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);

        // The seat must never be overbooked, in no variant of this bug.
        $this->assertCount(1, $answers->get_usersonlist(), 'the option must not be overbooked beyond maxanswers');

        // Desired behaviour: the over-full waiting list rejects further users
        // without pretending they are waiting for confirmation.
        $this->assertNotContains(
            bookinganswer_waitingforconfirmation::class,
            $bookingevents,
            'a booking attempt on a full option with an over-full waiting list must not fire '
                . 'bookinganswer_waitingforconfirmation'
        );
        $this->assertNotContains(
            'Confirmation request for',
            $queuedsubjects,
            'the confirmation-request rule mail must not be queued in this state'
        );
        $this->assertCount(
            4,
            $answers->get_usersonwaitinglist(),
            'the over-full waiting list must not accept further users'
        );
    }

    /**
     * Confirmation mode 2 (confirmation only once a waiting list exists) on an
     * over-full waiting list: the first booking onto the empty option is booked
     * directly, further users must be rejected once the list is over-full, and a
     * freed seat is given to the longest-waiting CONFIRMED user only - the
     * over-full state must not break the confirmed-users-only promotion rule.
     */
    public function test_overfull_waitinglist_with_confirmationmode2(): void {
        global $DB;

        set_config('keepusersbookedonreducingmaxanswers', 1, 'booking');
        // Enable a confirmation subplugin so promotions require one confirmation.
        set_config('confirmationtrainerenabled', 1, 'bookingextension_confirmation_trainer');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Confirmation mode 2 booking',
        ]);

        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $latecomer = $this->getDataGenerator()->create_user();
        foreach (array_merge([$bookeduser, $latecomer], $waiters) as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Single seat, confirmation mode 2',
            'description' => 'Single seat, confirmation mode 2',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => -1,
            'waitforconfirmation' => 2,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        // Mode 2 books the first user directly: no waiting list exists yet.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($bookeduser, 0, 0, 0, MOD_BOOKING_VERIFIED);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(
            1,
            $answers->get_usersonlist(),
            'mode 2 books the first user directly while no waiting list exists'
        );

        // Four users land on the unlimited waiting list, longest-waiting first.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        foreach ($waiters as $waiter) {
            $bookingoption->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        foreach ($waiters as $i => $waiter) {
            $DB->set_field(
                'booking_answers',
                'timemodified',
                time() - 500 + ($i * 100),
                [
                    'optionid' => $optionid,
                    'userid' => $waiter->id,
                    'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                ]
            );
        }
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();

        // The admin reduces the waiting list limit below the current waiting count.
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxoverbooking' => 2,
        ]);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertEquals(2, (int) $settings->maxoverbooking, 'precondition: waiting list limit reduced to 2');
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(4, $answers->get_usersonwaitinglist(), 'precondition: four users stay on the over-full list');

        // The over-full list rejects further users, nobody is booked or moved.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertFalse(
            $bookingoption->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'a booking attempt on the full option with an over-full waiting list must be rejected'
        );
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(1, $answers->get_usersonlist(), 'the option must not be overbooked');
        $this->assertCount(4, $answers->get_usersonwaitinglist(), 'the over-full waiting list must not grow');

        // Only the second waiter is confirmed, the head of the queue is not.
        $DB->set_field(
            'booking_answers',
            'json',
            '{"confirmationcount":1}',
            [
                'optionid' => $optionid,
                'userid' => $waiters[1]->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]
        );
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();

        // The booked user cancels. The queue is strictly ordered: the unconfirmed
        // user at its head is not overtaken by a confirmed user further down, so
        // the freed seat stays empty until the head user confirms.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_delete_response($bookeduser->id);

        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertCount(
            0,
            $answers->get_usersonlist(),
            'nobody is promoted while the head of the waiting list is unconfirmed (no overtaking)'
        );
        $this->assertCount(4, $answers->get_usersonwaitinglist(), 'all four users keep waiting');

        // Once the head user confirms, the next sync promotes exactly them - the
        // over-full state must not block the promotion of a confirmed user.
        $DB->set_field(
            'booking_answers',
            'json',
            '{"confirmationcount":1}',
            [
                'optionid' => $optionid,
                'userid' => $waiters[0]->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]
        );
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();

        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->sync_waiting_list();

        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $bookedids = array_values(array_map(static fn($o): int => (int) $o->userid, $answers->get_usersonlist()));
        $waitingids = array_values(array_map(static fn($o): int => (int) $o->userid, $answers->get_usersonwaitinglist()));
        $this->assertSame(
            [(int) $waiters[0]->id],
            $bookedids,
            'the confirmed head of the queue takes the freed seat despite the over-full list'
        );
        $this->assertEqualsCanonicalizing(
            [(int) $waiters[1]->id, (int) $waiters[2]->id, (int) $waiters[3]->id],
            $waitingids,
            'the other waiters stay on the waiting list'
        );
    }
}

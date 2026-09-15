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
 * End-to-end scenario for waiting list type 4 (waitlistrecycling=3, "remove from waiting list when
 * offer expires") with a 24 hour payment deadline:
 *
 * The option is full, three people wait: a staff member (price 0) and two paying people. Two seats
 * become free, so the first candidates are handled: the staff member is booked automatically, the
 * first paying person gets an offer and 24 hours to pay. Whoever lets the deadline pass is taken off
 * the waiting list and the next person gets the offer. A removed person may sign up again and then
 * queues at the end. This repeats until someone pays or the waiting list is empty - and once it is
 * empty, the free seat can be booked normally again.
 *
 * Deadlines are driven by the frozen clock plus the expire_waitlist_offer_adhoc task that was really
 * queued for each offer, executed once its scheduled time has come.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option;
use mod_booking\local\waitlist\offer_statuses\accepted;
use mod_booking\local\waitlist\offer_statuses\expired;
use mod_booking\singleton_service;
use mod_booking\tests\local\waitlist\waitlist_progression_fixture_trait;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/waitlist_progression_fixture_trait.php');

/**
 * Type 4 full cycle: offer, 24 hour deadline, removal, re-join, empty list, normal booking.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\waitlist\progression::reconcile
 * @covers \mod_booking\task\expire_waitlist_offer_adhoc::execute
 * @covers \mod_booking\booking_option::remove_from_waitinglist_after_offer_expiry
 */
final class waitlist_type4_full_cycle_test extends \advanced_testcase {
    use waitlist_progression_fixture_trait;

    /** @var int payment deadline of an offer: 24 hours */
    private const DEADLINE_SECONDS = 24 * HOURSECS;

    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Builds the starting situation: a full option (2 seats, both booked) with a 24 hour interval
     * rule, type 4, and three people on the waiting list in this order: staff (price 0), anna, ben.
     *
     * @return array [int $optionid, stdClass $course, stdClass[] $booked, stdClass $staff, stdClass $anna, stdClass $ben]
     */
    private function create_full_option_with_three_waiting(): array {
        global $DB;

        [$course, $teacher, $booking] = $this->prepare_course_and_booking('Type 4 full cycle');
        // Price categories must exist before the option, prices are resolved at creation time.
        $this->create_pricecategory('staffcat', 0);
        $this->create_pricecategory('paidcat', 80);
        $optionid = $this->create_priced_option($course, $teacher, $booking, 2, 5);

        $DB->set_field('booking_options', 'waitlistrecycling', 3, ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);

        $this->create_interval_rule(0, 'Ein Platz ist frei', 'Bitte innerhalb von 24 Stunden bezahlen', 24 * 60);

        $booked = [];
        foreach ([1, 2] as $i) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $DB->insert_record('booking_answers', (object) [
                'bookingid' => 0,
                'userid' => $user->id,
                'optionid' => $optionid,
                'timemodified' => 50 + $i,
                'timecreated' => 50 + $i,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
                'status' => 0,
            ]);
            $booked[] = $user;
        }

        $staff = $this->waitlist_user($course, $optionid, 'staffcat', 100);
        $anna = $this->waitlist_user($course, $optionid, 'paidcat', 101);
        $ben = $this->waitlist_user($course, $optionid, 'paidcat', 102);

        booking_option::purge_cache_for_answers($optionid);
        $this->setAdminUser();

        return [$optionid, $course, $booked, $staff, $anna, $ben];
    }

    /**
     * The user's current (not deleted) booking answer status on the option, or null if none.
     *
     * @param int $optionid
     * @param int $userid
     * @return int|null MOD_BOOKING_STATUSPARAM_*
     */
    private function status_of(int $optionid, int $userid): ?int {
        global $DB;
        $records = $DB->get_records_select(
            'booking_answers',
            'optionid = :optionid AND userid = :userid AND waitinglist <> :deleted',
            ['optionid' => $optionid, 'userid' => $userid, 'deleted' => MOD_BOOKING_STATUSPARAM_DELETED],
            'id DESC',
            'id, waitinglist',
            0,
            1
        );
        $record = reset($records);
        return $record ? (int) $record->waitinglist : null;
    }

    /**
     * The one open offer of the option, asserting there is exactly one and it belongs to $user.
     *
     * @param int $optionid
     * @param stdClass $user
     * @param string $message
     * @return waitlist_offer
     */
    private function assert_open_offer_for(int $optionid, stdClass $user, string $message): waitlist_offer {
        $offers = (new db_waitlist_offer_repository())->get_open_offers($optionid);
        $this->assertCount(1, $offers, $message . ' - exactly one seat is on offer.');
        $offer = reset($offers);
        $this->assertEquals((int) $user->id, $offer->userid, $message);
        $this->assertEquals(
            $offer->offeredat + self::DEADLINE_SECONDS,
            $offer->expiresat,
            $message . ' - the person has exactly 24 hours to pay.'
        );
        return $offer;
    }

    /**
     * Lets the 24 hours of an offer pass without payment: moves the clock past the deadline and runs
     * the expire task that was queued for exactly this offer, scheduled for exactly that deadline.
     *
     * @param \frozen_clock $clock
     * @param waitlist_offer $offer
     * @return void
     */
    private function let_deadline_pass(\frozen_clock $clock, waitlist_offer $offer): void {
        $tasks = array_filter(
            \core\task\manager::get_adhoc_tasks('\mod_booking\task\expire_waitlist_offer_adhoc'),
            fn($task) => (int) $task->get_custom_data()->offerid === $offer->id
        );
        $this->assertCount(1, $tasks, 'An expire task must have been queued for the offer.');
        $task = reset($tasks);
        $this->assertEquals($offer->expiresat, $task->get_next_run_time(), 'The task runs exactly at the deadline.');

        $clock->set_to($offer->expiresat + 1);
        ob_start();
        $task->execute();
        ob_get_clean();
        $this->setAdminUser();
        singleton_service::destroy_booking_answers($offer->optionid);
    }

    /**
     * Whether a notification (the offer mail from the interval rule) reached the user.
     *
     * @param array $messages captured by redirectMessages()
     * @param stdClass $user
     * @return bool
     */
    private function was_notified(array $messages, stdClass $user): bool {
        foreach ($messages as $message) {
            if ((int) $message->useridto === (int) $user->id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Nobody pays: offer, deadline, removal and re-join repeat until the waiting list is empty, after
     * which the free seat can be booked normally again.
     */
    public function test_nobody_pays_until_the_waiting_list_is_empty_then_normal_booking(): void {
        $clock = $this->mock_clock_with_frozen(time());
        [$optionid, $course, $booked, $staff, $anna, $ben] = $this->create_full_option_with_three_waiting();
        $repository = new db_waitlist_offer_repository();

        // Start: the option is full, three people wait.
        $this->assertTrue(
            singleton_service::get_instance_of_booking_answers(
                singleton_service::get_instance_of_booking_option_settings($optionid)
            )->is_fully_booked(),
            'Precondition: the option is full.'
        );
        foreach ([$staff, $anna, $ben] as $user) {
            $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, $this->status_of($optionid, (int) $user->id));
        }

        // Two seats become free (two bookings are cancelled) - the first candidates are handled.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $option = singleton_service::get_instance_of_booking_option((int) $settings->cmid, $optionid);
        $sink = $this->redirectMessages();
        foreach ($booked as $user) {
            booking_option::purge_cache_for_answers($optionid);
            $option->user_delete_response((int) $user->id);
        }
        $messages = $sink->get_messages();
        $sink->close();
        booking_option::purge_cache_for_answers($optionid);

        // The staff member (price 0) is booked automatically, without paying. Note: this happens in
        // booking_option::sync_waiting_list() during the cancellation, which books free-price waiters
        // directly, before progression::reconcile() runs - so there is no waitlist offer row for staff.
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            $this->status_of($optionid, (int) $staff->id),
            'Staff (price 0) must be booked automatically.'
        );
        $this->assertFalse(
            \core\di::get(\moodle_database::class)->record_exists('booking_waitlist_offers', [
                'optionid' => $optionid,
                'userid' => $staff->id,
            ]),
            'Staff never gets an offer to pay - they are simply booked.'
        );

        // Anna, the first paying person, is notified and gets 24 hours to pay; Ben keeps waiting.
        $annaoffer = $this->assert_open_offer_for($optionid, $anna, 'Anna must get the offer for the second seat');
        $this->assertTrue($this->was_notified($messages, $anna), 'Anna must be notified about the free seat.');
        $this->assertFalse($this->was_notified($messages, $ben), 'Ben must not be notified yet, no seat is left for him.');
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_WAITINGLIST, $this->status_of($optionid, (int) $ben->id));

        // 24 hours pass, Anna does not pay: she is taken off the list, Ben is notified.
        $sink = $this->redirectMessages();
        $this->let_deadline_pass($clock, $annaoffer);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertNull($this->status_of($optionid, (int) $anna->id), 'Anna must be removed from the waiting list.');
        $this->assertEquals(
            (new expired())->get_code(),
            (int) $repository->get_offer_by_id($annaoffer->id)->status->get_code(),
            'Anna\'s offer must be expired.'
        );
        $benoffer = $this->assert_open_offer_for($optionid, $ben, 'Ben must now get the offer');
        $this->assertTrue($this->was_notified($messages, $ben), 'Ben must be notified about the free seat.');

        // Anna signs up for the waiting list again - she queues behind Ben, whose seat is on offer.
        $option->user_submit_response(\core_user::get_user($anna->id), 0, 0, 0, MOD_BOOKING_VERIFIED);
        booking_option::purge_cache_for_answers($optionid);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            $this->status_of($optionid, (int) $anna->id),
            'Anna may sign up again and must land on the waiting list, not take Ben\'s offered seat.'
        );
        $this->assert_open_offer_for($optionid, $ben, 'Anna\'s re-join must not touch Ben\'s offer');

        // 24 hours pass, Ben does not pay either: Ben is removed, the re-joined Anna is notified again.
        $sink = $this->redirectMessages();
        $this->let_deadline_pass($clock, $benoffer);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertNull($this->status_of($optionid, (int) $ben->id), 'Ben must be removed from the waiting list.');
        $annasecondoffer = $this->assert_open_offer_for(
            $optionid,
            $anna,
            'The re-joined Anna must get a fresh offer - the process repeats'
        );
        $this->assertTrue($this->was_notified($messages, $anna), 'Anna must be notified again.');

        // 24 hours pass, Anna again does not pay: she is removed - nobody is left.
        $this->let_deadline_pass($clock, $annasecondoffer);

        $this->assertNull($this->status_of($optionid, (int) $anna->id), 'Anna must be removed again.');
        foreach ([$anna, $ben] as $user) {
            $this->assertNotEquals(
                MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                $this->status_of($optionid, (int) $user->id),
                'Nobody paid, so nobody may be left on the waiting list.'
            );
        }
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertEmpty($answers->get_usersonwaitinglist(), 'Nobody paid - the waiting list must be empty again.');
        $this->assertEmpty($repository->get_open_offers($optionid), 'No offer may be left open.');
        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, $this->status_of($optionid, (int) $staff->id));

        // With the waiting list empty and one seat free, a new person can book normally again.
        $clara = $this->getDataGenerator()->create_user(['profile_field_pricecat' => 'paidcat']);
        $this->getDataGenerator()->enrol_user($clara->id, $course->id, 'student');
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_user((int) $clara->id);
        [$conditionid] = (new bo_info($settings))->is_available($optionid, (int) $clara->id, true);
        $this->assertNotContains(
            $conditionid,
            [MOD_BOOKING_BO_COND_FULLYBOOKED, MOD_BOOKING_BO_COND_ONWAITINGLIST],
            'With an empty waiting list and a free seat, the option must not be full or waiting-list only.'
        );

        $option->user_submit_response(\core_user::get_user($clara->id), 0, 0, 0, MOD_BOOKING_VERIFIED);
        booking_option::purge_cache_for_answers($optionid);
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            $this->status_of($optionid, (int) $clara->id),
            'A new person must be booked directly - normal booking works again.'
        );
    }

    /**
     * Someone pays within the 24 hours: the seat is theirs and the process stops - nobody else is
     * notified, and the offer's expire task does nothing once its time comes.
     */
    public function test_process_stops_as_soon_as_someone_pays(): void {
        $clock = $this->mock_clock_with_frozen(time());
        [$optionid, , $booked, $staff, $anna, $ben] = $this->create_full_option_with_three_waiting();
        $repository = new db_waitlist_offer_repository();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $option = singleton_service::get_instance_of_booking_option((int) $settings->cmid, $optionid);
        $sink = $this->redirectMessages();
        foreach ($booked as $user) {
            booking_option::purge_cache_for_answers($optionid);
            $option->user_delete_response((int) $user->id);
        }
        $sink->close();
        booking_option::purge_cache_for_answers($optionid);

        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, $this->status_of($optionid, (int) $staff->id));
        $annaoffer = $this->assert_open_offer_for($optionid, $anna, 'Anna must get the offer');

        // Anna pays within the 24 hours.
        $clock->set_to($annaoffer->offeredat + 3 * HOURSECS);
        $option->user_submit_response(\core_user::get_user($anna->id), 0, 0, 0, MOD_BOOKING_VERIFIED);
        booking_option::purge_cache_for_answers($optionid);

        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            $this->status_of($optionid, (int) $anna->id),
            'Anna paid and is booked.'
        );
        $this->assertEquals(
            (new accepted())->get_code(),
            (int) $repository->get_offer_by_id($annaoffer->id)->status->get_code(),
            'Anna\'s offer must be accepted.'
        );

        // Her original deadline still comes - the expire task must leave everything as it is.
        $sink = $this->redirectMessages();
        $this->let_deadline_pass($clock, $annaoffer);
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertEquals(MOD_BOOKING_STATUSPARAM_BOOKED, $this->status_of($optionid, (int) $anna->id), 'Anna stays booked.');
        $this->assertEmpty($repository->get_open_offers($optionid), 'The option is full again, nothing is on offer.');
        $this->assertEquals(
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            $this->status_of($optionid, (int) $ben->id),
            'Ben keeps his place on the waiting list for a later free seat.'
        );
        $this->assertFalse($this->was_notified($messages, $ben), 'Ben must not be notified - the process stopped.');
    }
}

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

namespace mod_booking;

use cache;
use local_shopping_cart\shopping_cart;
use mod_booking\external\release_slots;
use mod_booking\external\save_slot_selection;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_update_service;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Personal calendar events and iCal attachment of booked slots (GH-1686).
 *
 * Slot options keep the booked times in the booking answers, not in sessions, so the generic
 * calendar code created no events for them (and for the slot type "session" an event for every
 * session). The observers of the slot events now keep one event per booked slot.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_calendar
 * @covers     \mod_booking_observer::bookinganswer_slots_changed
 * @covers     \mod_booking_observer::bookinganswer_cancelled
 * @covers     \mod_booking\ical
 */
final class slot_calendar_test extends booking_advanced_testcase {
    /** @var float Price of a single slot. */
    private const SLOT_PRICE = 30.0;

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Booked slots get one calendar event each, and the events follow a move, a release of one slot
     * and the cancellation of one of two purchases.
     *
     * @return void
     */
    public function test_slot_events_follow_book_move_release_and_cancel(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $userid] = $this->create_priced_slot_option();
        $slots = $this->picker_slots($optionid, $userid);
        $first = $slots[0];
        $second = $slots[1];
        $secondlast = $slots[count($slots) - 2];
        $last = $slots[count($slots) - 1];

        // Purchase A: two slots. Purchase B: one slot.
        $historya = $this->buy_slots_in_one_purchase($optionid, $userid, [$first['key'], $last['key']]);
        $historyb = $this->buy_slots_in_one_purchase($optionid, $userid, [$secondlast['key']]);
        $this->assertSame(
            [$first['start'], $secondlast['start'], $last['start']],
            $this->user_event_starts($optionid, $userid),
            'Every booked slot must get one calendar event.'
        );

        $this->setUser($userid);
        $answera = $this->answer_of_purchase($optionid, $userid, $historya);

        // Move the last slot of purchase A to the second slot.
        slot_update_service::apply($optionid, (int)$answera->id, $userid, [$first['key'], $second['key']]);
        $this->assertSame(
            [$first['start'], $second['start'], $secondlast['start']],
            $this->user_event_starts($optionid, $userid),
            'The event of a moved slot must move with it.'
        );

        // Release the moved slot again.
        release_slots::execute($optionid, (int)$answera->id, json_encode([$second['key']]), '');
        $this->assertSame(
            [$first['start'], $secondlast['start']],
            $this->user_event_starts($optionid, $userid),
            'The event of a released slot must be removed.'
        );

        // Cancel purchase B: the event of purchase A must stay.
        $result = shopping_cart::cancel_purchase($optionid, 'option', $userid, 'mod_booking', (int)$historyb->id);
        $this->assertEquals(1, $result['success'], 'Purchase B could not be cancelled.');
        $this->assertSame(
            [$first['start']],
            $this->user_event_starts($optionid, $userid),
            'Cancelling one purchase must keep the events of the other purchase.'
        );
    }

    /**
     * With the slot type "session", only the booked sessions get an event, not every session of the option.
     *
     * @return void
     */
    public function test_session_slot_type_gets_only_the_booked_sessions(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        $sessions = [
            [strtotime('2050-01-07 09:00:00 UTC'), strtotime('2050-01-07 10:00:00 UTC')],
            [strtotime('2050-01-08 09:00:00 UTC'), strtotime('2050-01-08 10:00:00 UTC')],
            [strtotime('2050-01-09 09:00:00 UTC'), strtotime('2050-01-09 10:00:00 UTC')],
        ];
        [$optionid, $userid] = $this->create_priced_slot_option('session', $sessions);
        $slots = $this->picker_slots($optionid, $userid);
        $this->assertCount(3, $slots, 'Each session must be one slot.');

        $this->buy_slots_in_one_purchase($optionid, $userid, [$slots[1]['key']]);

        $this->assertSame(
            [$sessions[1][0]],
            $this->user_event_starts($optionid, $userid),
            'Only the booked session may be in the calendar.'
        );
    }

    /**
     * The iCal attachment of a slot option holds the slots the user has booked.
     *
     * @return void
     */
    public function test_ical_contains_the_booked_slots(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $userid] = $this->create_priced_slot_option();
        $slots = $this->picker_slots($optionid, $userid);
        $first = $slots[0];
        $last = $slots[count($slots) - 1];
        $this->buy_slots_in_one_purchase($optionid, $userid, [$first['key'], $last['key']]);

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);
        $user = \core_user::get_user($userid);

        $ical = new ical($bookingsettings, $optionsettings, $user, $bookingsettings->bookingmanageruser, false);
        $this->assertSame(
            [$first['start'], $last['start']],
            array_map(fn($time) => (int)$time->coursestarttime, $ical->get_times()),
            'The iCal must use the booked slots.'
        );

        $attachments = $ical->get_attachments();
        $this->assertArrayHasKey('booking.ics', $attachments, 'A slot booking must get an iCal attachment.');
        $file = file_get_contents($attachments['booking.ics']);
        $this->assertSame(2, substr_count($file, 'BEGIN:VEVENT'), 'One event per booked slot.');
        $this->assertStringContainsString('DTSTART:' . gmdate('Ymd\THis\Z', $first['start']), $file);
        $this->assertStringContainsString('DTSTART:' . gmdate('Ymd\THis\Z', $last['start']), $file);
    }

    /**
     * Start times of the user's calendar events of the option, sorted.
     *
     * @param int $optionid
     * @param int $userid
     * @return int[]
     */
    private function user_event_starts(int $optionid, int $userid): array {
        global $DB;

        $starts = $DB->get_fieldset_sql(
            "SELECT e.timestart
               FROM {event} e
               JOIN {booking_userevents} ue ON ue.eventid = e.id
              WHERE ue.optionid = :optionid AND ue.userid = :userid AND e.eventtype = 'user' AND e.userid = :euserid
           ORDER BY e.timestart ASC",
            ['optionid' => $optionid, 'userid' => $userid, 'euserid' => $userid]
        );

        return array_map('intval', $starts);
    }

    /**
     * The bookable slots of the option, sorted by start.
     *
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    private function picker_slots(int $optionid, int $userid): array {
        $slots = slot_dto::build_picker_slots($optionid, $userid);
        usort($slots, fn($a, $b) => (int)$a['start'] <=> (int)$b['start']);
        return array_map(function ($slot) {
            $slot['start'] = (int)$slot['start'];
            return $slot;
        }, $slots);
    }

    /**
     * Buy the given slots in one purchase, paid by the cashier.
     *
     * @param int $optionid
     * @param int $userid
     * @param array $slotkeys keys of the slots to buy
     * @return stdClass the history record of the purchase
     */
    private function buy_slots_in_one_purchase(int $optionid, int $userid, array $slotkeys): stdClass {
        global $DB;

        $this->setAdminUser();
        save_slot_selection::execute($optionid, $userid, json_encode(array_values($slotkeys)));
        shopping_cart::delete_all_items_from_cart($userid);
        shopping_cart::buy_for_user($userid);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        $records = $DB->get_records('local_shopping_cart_history', [
            'componentname' => 'mod_booking',
            'area' => 'option',
            'itemid' => $optionid,
            'userid' => $userid,
            'paymentstatus' => LOCAL_SHOPPING_CART_PAYMENT_SUCCESS,
        ], 'id DESC', '*', 0, 1);
        $this->assertNotEmpty($records, 'The purchase must be in the history.');

        return reset($records);
    }

    /**
     * The booking answer that belongs to a purchase.
     *
     * @param int $optionid
     * @param int $userid
     * @param stdClass $history the history record of the purchase
     * @return stdClass
     */
    private function answer_of_purchase(int $optionid, int $userid, stdClass $history): stdClass {
        global $DB;

        return $DB->get_record('booking_answers', [
            'optionid' => $optionid,
            'userid' => $userid,
            'purchaseidentifier' => $history->identifier,
        ], '*', MUST_EXIST);
    }

    /**
     * A priced slot option with "Allow rebooking", self-cancellation and several purchases per user.
     *
     * @param string $slottype 'fixed' or 'session'
     * @param array $sessions for 'session': list of [start, end]
     * @return array{0:int, 1:int} optionid, userid
     */
    private function create_priced_slot_option(string $slottype = 'fixed', array $sessions = []): array {
        global $DB;

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slot calendar ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'useprice' => 1,
            'slot_enabled' => 1,
            'slot_type' => $slottype,
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '13:00',
            'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 4,
            'slot_booking_view_mode' => 'list',
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = 1;
        }
        foreach ($sessions as $i => [$start, $end]) {
            $record['coursestarttime_' . $i] = $start;
            $record['courseendtime_' . $i] = $end;
            $record['daystonotify_' . $i] = 0;
        }

        $option = $plugingenerator->create_option((object)$record);
        $optionid = (int)$option->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Allow a second purchase of the same option right away.
        $json = json_decode($DB->get_field('booking_options', 'json', ['id' => $optionid]) ?: '{}');
        $json->multiplebookings = 1;
        $json->allowtobookagainafter = 0;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);

        // Self-cancellation on the instance, and the cart allows users to cancel (no fee).
        $DB->set_field('booking', 'cancancelbook', 1, ['id' => $booking->id]);
        cache::make('mod_booking', 'cachedbookinginstances')->delete((int)$settings->cmid);
        set_config('cancelationfee', 0, 'local_shopping_cart');

        $plugingenerator->create_pricecategory([
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Default',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
            'disabled' => 0,
        ]);
        $DB->insert_record('booking_prices', (object)[
            'itemid' => $optionid,
            'area' => 'option',
            'pricecategoryidentifier' => 'default',
            'price' => 0,
            'currency' => 'EUR',
        ]);

        // Every slot costs SLOT_PRICE.
        $ruleid = (int)$DB->insert_record('booking_slot_rule', (object)[
            'optionid' => $optionid,
            'ruletype' => 'price',
            'priority' => 1,
            'activefrom' => 0,
            'activeuntil' => 0,
            'weekdays' => '',
            'timerangestart' => '00:00',
            'timerangeend' => '23:59',
            'timecreated' => time(),
        ]);
        $DB->insert_record('booking_slot_rule_price', (object)[
            'ruleid' => $ruleid,
            'pricecategoryidentifier' => 'default',
            'mode' => 'delta',
            'value' => self::SLOT_PRICE,
            'currency' => 'EUR',
        ]);

        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        return [$optionid, (int)$student->id];
    }
}

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
use mod_booking\external\save_slot_selection;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\local\slotbooking\slot_mover;
use mod_booking\local\slotbooking\slot_update_service;
use mod_booking\option\dates_handler;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The built-in mail when a slot is moved (GH-1686).
 *
 * It used to come from the no-reply user, showed a literal "\n", named the span of the whole booking
 * instead of the moved slot, could not be switched off and reached only the examiners of the slot.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\slotbooking\slot_mover
 */
final class slot_move_notification_test extends booking_advanced_testcase {
    /** @var float Price of a single slot. */
    private const SLOT_PRICE = 30.0;

    /** @var stdClass participant */
    private stdClass $student;

    /** @var stdClass teacher of the option */
    private stdClass $teacher;

    /** @var stdClass booking manager of the instance */
    private stdClass $manager;

    /** @var string title of the option */
    private string $title;

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The participant moves a slot: the participant and the teacher of the option get a mail from the
     * booking manager, with real line breaks, naming only the slot that moved.
     *
     * @return void
     */
    public function test_self_move_mail_names_the_moved_slot(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $baid, $slots] = $this->book_two_slots();
        [$first, $second, $last] = [$slots[0], $slots[1], $slots[count($slots) - 1]];

        $sink = $this->redirectEmails();
        $this->setUser($this->student);
        slot_update_service::apply($optionid, $baid, (int)$this->student->id, [$first['key'], $second['key']]);
        $mails = $sink->get_messages();
        $sink->close();

        $usermails = $this->mails_with_subject($mails, get_string('slot_rebook_notification_user_subject', 'mod_booking'));
        $teachermails = $this->mails_with_subject($mails, get_string('slot_rebook_notification_teacher_subject', 'mod_booking'));
        $this->assertCount(1, $usermails, 'The participant gets one mail.');
        $this->assertCount(1, $teachermails, 'The teacher of the option gets one mail.');
        $this->assertSame($this->student->email, $usermails[0]->to);
        $this->assertSame($this->teacher->email, $teachermails[0]->to);

        foreach ([$usermails[0], $teachermails[0]] as $mail) {
            $this->assertStringContainsString(fullname($this->manager), $mail->header, 'The booking manager is the sender.');
            $body = $this->plain($mail->body);
            $this->assertStringNotContainsString('\n', $mail->body, 'No literal \n in the mail.');
            $this->assertStringContainsString(fullname($this->student), $body);
            $this->assertStringContainsString($this->title, $body);
            $this->assertStringContainsString($this->slot_text($last), $body, 'The old time of the moved slot.');
            $this->assertStringContainsString($this->slot_text($second), $body, 'The new time of the moved slot.');
            $this->assertStringNotContainsString($this->slot_text($first), $body, 'The slot that did not move is not named.');
        }
    }

    /**
     * Staff move a slot with a reason: the teacher gets a teacher text, not the participant's text.
     *
     * @return void
     */
    public function test_staff_move_mail_has_reason_and_teacher_text(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        [$optionid, $baid, $slots] = $this->book_two_slots();
        [$first, $second] = [$slots[0], $slots[1]];

        $sink = $this->redirectEmails();
        $this->setAdminUser();
        slot_mover::move($optionid, $baid, [$second['key'], $slots[count($slots) - 1]['key']], 'Room not available');
        $mails = $sink->get_messages();
        $sink->close();

        $usermails = $this->mails_with_subject($mails, get_string('slot_move_notification_subject', 'mod_booking'));
        $teachermails = $this->mails_with_subject($mails, get_string('slot_move_notification_teacher_subject', 'mod_booking'));
        $this->assertCount(1, $usermails, 'The participant gets one mail.');
        $this->assertCount(1, $teachermails, 'The teacher gets the teacher mail, not the participant mail.');
        $this->assertSame($this->teacher->email, $teachermails[0]->to);

        $body = $this->plain($usermails[0]->body);
        $this->assertStringContainsString('Room not available', $body, 'The reason is in the mail.');
        $this->assertStringContainsString($this->slot_text($first), $body, 'The old time of the moved slot.');
        $this->assertStringContainsString($this->slot_text($second), $body, 'The new time of the moved slot.');
    }

    /**
     * With the setting off, no built-in mail is sent, so a booking rule can replace it.
     *
     * @return void
     */
    public function test_no_mail_when_switched_off(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }

        set_config('slotmovenotification', 0, 'booking');
        [$optionid, $baid, $slots] = $this->book_two_slots();

        $sink = $this->redirectEmails();
        $this->setUser($this->student);
        slot_update_service::apply($optionid, $baid, (int)$this->student->id, [$slots[0]['key'], $slots[1]['key']]);
        $mails = $sink->get_messages();
        $sink->close();

        $usersubject = get_string('slot_rebook_notification_user_subject', 'mod_booking');
        $teachersubject = get_string('slot_rebook_notification_teacher_subject', 'mod_booking');
        $this->assertCount(0, $this->mails_with_subject($mails, $usersubject));
        $this->assertCount(0, $this->mails_with_subject($mails, $teachersubject));
    }

    /**
     * Create the option and let the student buy its first and its last slot in one purchase.
     *
     * @return array{0:int, 1:int, 2:array} optionid, booking answer id, the slots sorted by start
     */
    private function book_two_slots(): array {
        global $DB;

        $optionid = $this->create_priced_slot_option();
        $userid = (int)$this->student->id;

        $slots = slot_dto::build_picker_slots($optionid, $userid);
        usort($slots, fn($a, $b) => (int)$a['start'] <=> (int)$b['start']);

        $this->setAdminUser();
        save_slot_selection::execute($optionid, $userid, json_encode([$slots[0]['key'], $slots[count($slots) - 1]['key']]));
        shopping_cart::delete_all_items_from_cart($userid);
        shopping_cart::buy_for_user($userid);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        booking_option::purge_cache_for_answers($optionid);
        singleton_service::destroy_instance();

        $baid = (int)$DB->get_field('booking_answers', 'id', [
            'optionid' => $optionid,
            'userid' => $userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
        ], MUST_EXIST);

        return [$optionid, $baid, $slots];
    }

    /**
     * The mails with the given subject.
     *
     * @param array $mails mails from the sink
     * @param string $subject
     * @return array
     */
    private function mails_with_subject(array $mails, string $subject): array {
        return array_values(array_filter($mails, fn($mail) => $mail->subject === $subject));
    }

    /**
     * A slot as the mail writes it, with whitespace normalised.
     *
     * @param array $slot ['start' => int, 'end' => int]
     * @return string
     */
    private function slot_text(array $slot): string {
        return $this->plain(dates_handler::prettify_optiondates_start_end((int)$slot['start'], (int)$slot['end'], 'en'));
    }

    /**
     * Mail text with the line wrapping of the mailer undone.
     *
     * @param string $text
     * @return string
     */
    private function plain(string $text): string {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES)));
    }

    /**
     * A priced slot option with "Allow rebooking", a teacher and a booking manager.
     *
     * @return int option id
     */
    private function create_priced_slot_option(): int {
        global $DB;

        $generator = self::getDataGenerator();
        $this->student = $generator->create_user(['firstname' => 'Pat', 'lastname' => 'Participant']);
        $this->teacher = $generator->create_user(['firstname' => 'Tess', 'lastname' => 'Teacher']);
        $this->manager = $generator->create_user(['firstname' => 'Mona', 'lastname' => 'Manager']);

        $course = $generator->create_course();
        $generator->enrol_user($this->student->id, $course->id, 'student');
        $generator->enrol_user($this->teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance([
            'course' => $course->id,
            'bookingmanager' => $this->manager->username,
        ]);

        $this->title = 'Counselling ' . uniqid();
        $record = [
            'bookingid' => $booking->id,
            'text' => $this->title,
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'useprice' => 1,
            'teachersforoption' => $this->teacher->username,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
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
        $option = $plugingenerator->create_option((object)$record);
        $optionid = (int)$option->id;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Self-cancellation (and with it self-rebooking) on the instance; the cart allows users to cancel.
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

        return $optionid;
    }
}

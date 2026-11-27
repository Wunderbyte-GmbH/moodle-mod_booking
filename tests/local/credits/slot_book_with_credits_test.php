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
 * Tests for booking slots with credits (custom user profile field).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\credits;

use local_shopping_cart\local\cartstore;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_bookit;
use mod_booking\form\condition\slotbooking_form;
use mod_booking\local\mobile\slotbookingstore;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_update_service;
use mod_booking\singleton_service;
use mod_booking\tests\booking_advanced_testcase;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Booking slots with credits: per slot, only when the booking is saved, back on cancellation.
 *
 * The bookings go through the same calls as the booking dialog: the slot selection is stored,
 * then the page after the slot page is loaded (bo_info::load_pre_booking_page()), which books.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\local\credits\user_credits
 * @covers \mod_booking\bo_availability\conditions\bookwithcredits
 * @covers \mod_booking\bo_availability\bo_info::load_pre_booking_page
 * @covers \mod_booking\booking_bookit::bookit
 * @covers \mod_booking\local\slotbooking\slot_update_service
 */
final class slot_book_with_credits_test extends booking_advanced_testcase {
    /** @var int Start of the slot window (a Monday in 2050). */
    private const VALID_FROM = 2409195600;

    /** @var int End of the slot window. */
    private const VALID_UNTIL = 2409627000;

    /** @var stdClass|null Course of the last created option. */
    private ?stdClass $course = null;

    /**
     * Setup: book with credits on, credits in the profile field "credit".
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'credit',
            'name' => 'Credit',
            'visible' => PROFILE_VISIBLE_NONE,
        ]);
        set_config('bookwithcreditsactive', 1, 'booking');
        set_config('bookwithcreditsprofilefield', 'credit', 'booking');
    }

    /**
     * With and without a price.
     *
     * @return array
     */
    public static function price_provider(): array {
        return [
            'with price' => [true],
            'without price' => [false],
        ];
    }

    /**
     * One slot costs the credits of the option. Before the fix, an option with a price went to the
     * shopping cart, the cart refused it and the user saw "There was an error when booking".
     *
     * @dataProvider price_provider
     * @param bool $useprice
     */
    public function test_one_slot_is_booked_with_credits(bool $useprice): void {
        [$optionid, $slots] = $this->create_slot_option($useprice, 1);
        $student = $this->create_student(3);

        $this->book_slots($optionid, $student, [$slots[0]]);

        $answer = $this->get_active_answer($optionid, $student->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
        $json = json_decode($answer->json);
        $this->assertEquals(1, $json->paidwithcredits);
        $this->assertEquals(1, $json->creditsperslot);
        $this->assertEquals(1, $json->creditspaid);
        $this->assertEquals(2, $this->get_credits($student->id));
        $this->assertSame([], $this->get_cart_items($student->id));
    }

    /**
     * Several slots in one booking cost the credits per slot (before the fix: only once).
     *
     * @dataProvider price_provider
     * @param bool $useprice
     */
    public function test_several_slots_cost_credits_per_slot(bool $useprice): void {
        [$optionid, $slots] = $this->create_slot_option($useprice, 2);
        $student = $this->create_student(5);

        $this->book_slots($optionid, $student, [$slots[0], $slots[1]]);

        $answer = $this->get_active_answer($optionid, $student->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
        $this->assertEquals(4, json_decode($answer->json)->creditspaid);
        $this->assertEquals(1, $this->get_credits($student->id));
    }

    /**
     * With a price and too few credits, the slots are paid with money, like a normal option.
     */
    public function test_too_few_credits_with_price_goes_to_the_cart(): void {
        [$optionid, $slots] = $this->create_slot_option(true, 2);
        $student = $this->create_student(3);

        // 2 slots x 2 credits = 4 credits, the student has 3.
        $this->book_slots($optionid, $student, [$slots[0], $slots[1]]);

        $answer = $this->get_active_answer($optionid, $student->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_RESERVED, (int)$answer->waitinglist);
        $this->assertEmpty(json_decode($answer->json)->paidwithcredits ?? null);
        $this->assertSame(['mod_booking-option-' . $optionid], $this->get_cart_items($student->id));
        $this->assertEquals(3, $this->get_credits($student->id));
    }

    /**
     * Without a price and with too few credits, the slot page says so and nothing is booked.
     */
    public function test_too_few_credits_without_price_shows_a_clear_message(): void {
        [$optionid, $slots] = $this->create_slot_option(false, 1);
        $student = $this->create_student(1);
        $this->setUser($student);

        $expected = get_string('notenoughcreditsforslots', 'mod_booking', (object)[
            'slots' => 2,
            'required' => '2',
            'available' => '1',
        ]);

        // The slot page (form validation) shows the message.
        $form = new slotbooking_form(null, [], 'post', '', [], true, [
            'id' => $optionid,
            'userid' => $student->id,
        ], true);
        $errors = $form->validation([
            'id' => $optionid,
            'userid' => $student->id,
            'slot_validation_error_target' => 'slot_selection',
            'slot_max_selection' => 3,
            'slot_teachers_required_count' => 0,
            'slot_teacher_selection' => json_encode([]),
            'slot_selection' => $this->key($slots[0]) . ',' . $this->key($slots[1]),
        ], []);
        $this->assertSame($expected, $errors['slot_selection'] ?? null);

        // One slot is fine.
        $errors = $form->validation([
            'id' => $optionid,
            'userid' => $student->id,
            'slot_validation_error_target' => 'slot_selection',
            'slot_max_selection' => 3,
            'slot_teachers_required_count' => 0,
            'slot_teacher_selection' => json_encode([]),
            'slot_selection' => $this->key($slots[0]),
        ], []);
        $this->assertArrayNotHasKey('slot_selection', $errors);

        // And booking anyway (e.g. a direct webservice call) books nothing and takes nothing.
        try {
            $this->book_slots($optionid, $student, [$slots[0], $slots[1]]);
            $this->fail('The booking must be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame($expected, $e->getMessage());
        }
        $this->assertNull($this->get_active_answer($optionid, $student->id));
        $this->assertEquals(1, $this->get_credits($student->id));
    }

    /**
     * The credits are taken only when the booking is saved: a booking that fails after the credit
     * check costs nothing (before the fix, the credits were taken first and then lost).
     */
    public function test_credits_are_not_taken_when_saving_the_booking_fails(): void {
        // Rolling slots: 60 minutes every 30 minutes, so neighbouring slots overlap.
        [$optionid, $slots] = $this->create_slot_option(false, 1, [
            'slot_type' => 'rolling',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 30,
            'slot_custom_start_interval_minutes' => 30,
            'slot_closing_time' => '12:00',
        ]);
        $this->assertGreaterThan($slots[1][0], $slots[0][1], 'Fixture: the first two slots overlap.');
        $student = $this->create_student(3);

        // Each slot alone is bookable, so the checks before booking pass. Saving the booking
        // refuses two overlapping slots.
        try {
            $this->book_slots($optionid, $student, [$slots[0], $slots[1]]);
            $this->fail('The booking must fail.');
        } catch (moodle_exception $e) {
            $this->assertSame('slot_error_selection_overlap', $e->errorcode);
        }

        $this->assertNull($this->get_active_answer($optionid, $student->id));
        $this->assertEquals(3, $this->get_credits($student->id));
    }

    /**
     * Cancelling slots gives their credits back: one slot, then the last one (full cancellation).
     */
    public function test_cancelled_slots_give_their_credits_back(): void {
        [$optionid, $slots] = $this->create_slot_option(true, 1);
        $student = $this->create_student(3);
        $this->book_slots($optionid, $student, [$slots[0], $slots[1]]);
        $this->assertEquals(1, $this->get_credits($student->id));
        $baid = (int)$this->get_active_answer($optionid, $student->id)->id;

        // The user gives up one of the two slots.
        slot_update_service::apply($optionid, $baid, (int)$student->id, [$this->key($slots[1])]);
        $this->assertEquals(2, $this->get_credits($student->id));
        $this->assertEquals(1, json_decode($this->get_answer($baid)->json)->creditspaid);
        $this->assertSame([], $this->get_cart_items($student->id));

        // The user gives up the last slot: the booking is cancelled.
        slot_update_service::apply($optionid, $baid, (int)$student->id, []);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_DELETED, (int)$this->get_answer($baid)->waitinglist);
        $this->assertEquals(3, $this->get_credits($student->id));
    }

    /**
     * Cancelling the whole booking gives all its credits back.
     */
    public function test_cancelling_the_booking_gives_all_credits_back(): void {
        [$optionid, $slots] = $this->create_slot_option(false, 1);
        $student = $this->create_student(3);
        $this->book_slots($optionid, $student, [$slots[0], $slots[1]]);
        $this->assertEquals(1, $this->get_credits($student->id));

        booking_bookit::answer_booking_option('option', $optionid, MOD_BOOKING_STATUSPARAM_DELETED, (int)$student->id);

        $this->assertNull($this->get_active_answer($optionid, $student->id));
        $this->assertEquals(3, $this->get_credits($student->id));
    }

    /**
     * Staff cancelling a slot gives its credits back too.
     */
    public function test_staff_cancelling_a_slot_gives_its_credits_back(): void {
        [$optionid, $slots] = $this->create_slot_option(true, 1);
        $student = $this->create_student(3);
        $this->book_slots($optionid, $student, [$slots[0], $slots[1]]);
        $baid = (int)$this->get_active_answer($optionid, $student->id)->id;

        $this->setAdminUser();
        slot_update_service::apply($optionid, $baid, (int)$student->id, [$this->key($slots[0])], '', 'manager');

        $this->assertEquals(2, $this->get_credits($student->id));
    }

    /**
     * Moving a slot changes nothing: same number of slots, no credits, no money.
     */
    public function test_moving_a_slot_does_not_change_the_credits(): void {
        [$optionid, $slots] = $this->create_slot_option(true, 1);
        $student = $this->create_student(3);
        $this->book_slots($optionid, $student, [$slots[0]]);
        $baid = (int)$this->get_active_answer($optionid, $student->id)->id;

        $result = slot_update_service::apply($optionid, $baid, (int)$student->id, [$this->key($slots[3])]);

        $this->assertSame('direct', $result['mode']);
        $answer = $this->get_answer($baid);
        $this->assertSame([$this->key($slots[3])], $this->get_answer_slot_keys($answer));
        $this->assertEquals(1, json_decode($answer->json)->creditspaid);
        $this->assertEquals(2, $this->get_credits($student->id));
        $this->assertSame([], $this->get_cart_items($student->id));
    }

    /**
     * A normal option with a price is booked with credits as before; cancelling it gives nothing back.
     */
    public function test_normal_option_behaves_as_before(): void {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'cancancelbook' => 1]);
        $this->create_price_category();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Normal option',
            'chooseorcreatecourse' => 1,
            'courseid' => $course->id,
            'useprice' => 1,
            'credits' => 2,
        ]);
        singleton_service::destroy_instance();
        $student = $this->create_student(3, $course->id);
        $this->setUser($student);
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$option->id);

        [$id] = (new bo_info($settings))->is_available((int)$option->id, (int)$student->id, true);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKWITHCREDITS, $id);

        booking_bookit::bookit('option', (int)$option->id, (int)$student->id);
        booking_bookit::bookit('option', (int)$option->id, (int)$student->id);

        $answer = $this->get_active_answer((int)$option->id, $student->id);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_BOOKED, (int)$answer->waitinglist);
        $json = json_decode($answer->json);
        $this->assertEquals(1, $json->paidwithcredits);
        $this->assertEquals(2, $json->creditspaid);
        $this->assertObjectNotHasProperty('creditsperslot', $json);
        $this->assertEquals(1, $this->get_credits($student->id));

        booking_bookit::answer_booking_option('option', (int)$option->id, MOD_BOOKING_STATUSPARAM_DELETED, (int)$student->id);
        $this->assertEquals(1, $this->get_credits($student->id));
    }

    /**
     * Book the given slots like the booking dialog does: store the selection, load the next page.
     *
     * @param int $optionid
     * @param stdClass $student
     * @param array $slots list of [start, end]
     * @return void
     */
    private function book_slots(int $optionid, stdClass $student, array $slots): void {
        $this->setUser($student);
        $selection = implode(',', array_map(fn(array $slot): string => $this->key($slot), $slots));
        (new slotbookingstore((int)$student->id, $optionid))->set_slotbooking_data((object)['slot_selection' => $selection]);
        singleton_service::destroy_instance();

        // Page 0 is the slot page, page 1 the confirmation: loading it books.
        bo_info::load_pre_booking_page($optionid, 1, (int)$student->id);
        singleton_service::destroy_instance();
    }

    /**
     * Create a fixed slot option (20 minute slots on Mondays, 09:00 - 11:00).
     *
     * @param bool $useprice
     * @param int $credits credits per slot
     * @param array $overrides slot settings to change
     * @return array{0:int, 1:array} option id and the slots as [start, end]
     */
    private function create_slot_option(bool $useprice, int $credits, array $overrides = []): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'cancancelbook' => 1]);
        if ($useprice) {
            $this->create_price_category();
        }

        $record = $overrides + [
            'bookingid' => $booking->id,
            'text' => 'Slot option',
            'chooseorcreatecourse' => 1,
            'courseid' => $course->id,
            'maxanswers' => 10,
            'useprice' => $useprice ? 1 : 0,
            'credits' => $credits,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'slot_enabled' => 1,
            'slot_type' => 'fixed',
            'slot_booking_view_mode' => 'calendar',
            'slot_duration_minutes' => 20,
            'slot_interval_minutes' => 20,
            'slot_opening_time' => '09:00',
            'slot_closing_time' => '11:00',
            'slot_valid_from' => self::VALID_FROM,
            'slot_valid_until' => self::VALID_UNTIL,
            'slot_day_1' => 1,
            'slot_max_participants_per_slot' => 3,
            'slot_max_slots_per_user' => 3,
            'slot_allow_self_rebooking' => 1,
            'slot_change_deadline_minutes' => '',
        ];
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)$record);
        singleton_service::destroy_instance();

        $slots = array_values(slot_availability::get_slots_for_range((int)$option->id, self::VALID_FROM, self::VALID_UNTIL));
        $this->assertGreaterThanOrEqual(4, count($slots), 'Fixture: not enough slots.');
        $slots = array_map(fn($slot): array => array_map('intval', array_values((array)$slot)), $slots);

        $this->course = $course;
        return [(int)$option->id, $slots];
    }

    /**
     * Create a student with credits, enrolled in the course of the last option.
     *
     * @param int $credits
     * @param int|null $courseid
     * @return stdClass
     */
    private function create_student(int $credits, ?int $courseid = null): stdClass {
        $student = $this->getDataGenerator()->create_user(['profile_field_credit' => (string)$credits]);
        $this->getDataGenerator()->enrol_user($student->id, $courseid ?? $this->course->id, 'student');
        return $student;
    }

    /**
     * Create the default price category (10 per slot / option).
     *
     * @return void
     */
    private function create_price_category(): void {
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 10.0,
            'pricecatsortorder' => 1,
        ]);
    }

    /**
     * The credits of the user in the database.
     *
     * @param int $userid
     * @return float
     */
    private function get_credits(int $userid): float {
        global $DB;
        return (float)$DB->get_field_sql(
            "SELECT d.data FROM {user_info_data} d JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE f.shortname = 'credit' AND d.userid = ?",
            [$userid]
        );
    }

    /**
     * The booked or reserved answer of the user, or null.
     *
     * @param int $optionid
     * @param int $userid
     * @return stdClass|null
     */
    private function get_active_answer(int $optionid, int $userid): ?stdClass {
        global $DB;
        $answers = $DB->get_records_select(
            'booking_answers',
            'optionid = ? AND userid = ? AND waitinglist IN (?, ?)',
            [$optionid, $userid, MOD_BOOKING_STATUSPARAM_BOOKED, MOD_BOOKING_STATUSPARAM_RESERVED]
        );
        return $answers ? reset($answers) : null;
    }

    /**
     * An answer by id.
     *
     * @param int $baid
     * @return stdClass
     */
    private function get_answer(int $baid): stdClass {
        global $DB;
        return $DB->get_record('booking_answers', ['id' => $baid], '*', MUST_EXIST);
    }

    /**
     * The slot keys an answer holds.
     *
     * @param stdClass $answer
     * @return array
     */
    private function get_answer_slot_keys(stdClass $answer): array {
        $slots = json_decode($answer->json, true)['slot']['slots'] ?? [];
        return array_map(fn(array $slot): string => $slot['start'] . ':' . $slot['end'], $slots);
    }

    /**
     * The items in the shopping cart of the user.
     *
     * @param int $userid
     * @return array
     */
    private function get_cart_items(int $userid): array {
        return array_keys(cartstore::instance($userid)->get_items() ?? []);
    }

    /**
     * Slot key "start:end".
     *
     * @param array $slot [start, end]
     * @return string
     */
    private function key(array $slot): string {
        return $slot[0] . ':' . $slot[1];
    }
}

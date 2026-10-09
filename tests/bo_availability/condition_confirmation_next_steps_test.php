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
 * Tests for the next steps and the entry ticket QR code in the booking confirmation step.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\conditions\confirmation;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\local\ticket\ticket_template_installer;
use mod_booking\price;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the next steps and the entry ticket QR code in the booking confirmation step.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\conditions\confirmation
 * @covers \mod_booking\price::get_display_label
 */
final class condition_confirmation_next_steps_test extends booking_advanced_testcase {
    /** @var stdClass */
    private $course;

    /** @var stdClass */
    private $booking;

    /**
     * Creates a course and a booking instance with tickets switched on site-wide.
     *
     * @return void
     */
    private function prepare_instance(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        set_config('bookingticketon', 1, 'booking');

        $this->course = $this->getDataGenerator()->create_course();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id,
            'name' => 'Confirmation fixture',
            'eventtype' => 'Test',
        ]);
    }

    /**
     * Creates an option with two future dates.
     *
     * @param array $extra additional option fields
     * @return int option id
     */
    private function create_option(array $extra = []): int {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = (object) array_merge([
            'bookingid' => $this->booking->id,
            'text' => 'Yoga',
            'chooseorcreatecourse' => 1,
            'courseid' => $this->course->id,
            'description' => 'x',
            'maxanswers' => 5,
            'optiondateid_0' => '0',
            'daystonotify_0' => '0',
            'coursestarttime_0' => strtotime('+10 days 18:00'),
            'courseendtime_0' => strtotime('+10 days 19:30'),
            'optiondateid_1' => '0',
            'daystonotify_1' => '0',
            'coursestarttime_1' => strtotime('+17 days 18:00'),
            'courseendtime_1' => strtotime('+17 days 19:30'),
        ], $extra);
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);
        return (int) $option->id;
    }

    /**
     * Enrols a new student and books them on the option through the real booking flow.
     *
     * @param int $optionid
     * @return stdClass the student
     */
    private function book_new_student(int $optionid): stdClass {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setAdminUser();
        booking_bookit::bookit('option', $optionid, $student->id);
        booking_bookit::bookit('option', $optionid, $student->id);
        singleton_service::destroy_booking_answers($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
        return $student;
    }

    /**
     * The template data of the confirmation step for a user.
     *
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    private function render_data(int $optionid, int $userid): array {
        $returnarray = (new confirmation())->render_page($optionid, $userid);
        return $returnarray['data'][0]['data'];
    }

    /**
     * A booked user of an option with tickets sees the QR code of their own valid ticket.
     */
    public function test_booked_with_ticket_shows_qr_code(): void {
        $this->prepare_instance();
        $optionid = $this->create_option(['ticket' => ticket_template_installer::ensure_installed()]);
        $student = $this->book_new_student($optionid);

        $data = $this->render_data($optionid, (int) $student->id);

        $this->assertSame('booked', $data['confirmationstate']);
        $this->assertTrue($data['hasticket']);
        $ticket = ticket_manager::find_valid_ticket($optionid, (int) $student->id);
        $this->assertNotEmpty($ticket);
        $this->assertSame($ticket->code, $data['ticket']['code']);
        $prefix = 'data:image/svg+xml;base64,';
        $this->assertStringStartsWith($prefix, $data['ticket']['qrcodesrc']);
        $svg = base64_decode(substr($data['ticket']['qrcodesrc'], strlen($prefix)));
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertNotEmpty($data['ticket']['qrcodealt']);
        $this->assertNotEmpty($data['mybookingsurl']);
    }

    /**
     * Without tickets on the option there is no QR code; the steps are booked plus the next date.
     */
    public function test_booked_without_ticket_lists_next_date(): void {
        $this->prepare_instance();
        $optionid = $this->create_option();
        $student = $this->book_new_student($optionid);

        $data = $this->render_data($optionid, (int) $student->id);

        $this->assertSame('booked', $data['confirmationstate']);
        $this->assertFalse($data['hasticket']);
        $this->assertCount(2, $data['steps']);
        $this->assertTrue($data['steps'][0]['done']);
        $this->assertSame(get_string('confirmationstepnextdate', 'mod_booking'), $data['steps'][1]['title']);
        $this->assertStringContainsString(
            get_string('confirmationmoredate', 'mod_booking'),
            $data['steps'][1]['text']
        );
    }

    /**
     * Tickets switched off site-wide hide the QR code even if the option has a ticket design.
     */
    public function test_ticket_feature_off_shows_no_qr_code(): void {
        $this->prepare_instance();
        $optionid = $this->create_option(['ticket' => ticket_template_installer::ensure_installed()]);
        set_config('bookingticketon', 0, 'booking');
        $student = $this->book_new_student($optionid);

        $data = $this->render_data($optionid, (int) $student->id);

        $this->assertSame('booked', $data['confirmationstate']);
        $this->assertFalse($data['hasticket']);
        $this->assertEmpty($data['ticket']);
    }

    /**
     * A user on the waiting list gets no ticket and the step to move up automatically.
     */
    public function test_waitinglist_has_no_ticket_and_moveup_step(): void {
        $this->prepare_instance();
        $optionid = $this->create_option([
            'ticket' => ticket_template_installer::ensure_installed(),
            'maxanswers' => 1,
            'maxoverbooking' => 5,
        ]);
        $this->book_new_student($optionid);
        $waiting = $this->book_new_student($optionid);

        $data = $this->render_data($optionid, (int) $waiting->id);

        $this->assertSame('waitinglist', $data['confirmationstate']);
        $this->assertFalse($data['hasticket']);
        $this->assertCount(2, $data['steps']);
        $this->assertTrue($data['steps'][0]['done']);
        $this->assertTrue($data['steps'][1]['current']);
        $this->assertSame(get_string('confirmationstepmoveup', 'mod_booking'), $data['steps'][1]['title']);
    }

    /**
     * Options that wait for confirmation show the confirmation step instead of moving up.
     */
    public function test_waitforconfirmation_shows_confirmation_step(): void {
        $this->prepare_instance();
        $optionid = $this->create_option(['waitforconfirmation' => 1]);
        $student = $this->book_new_student($optionid);

        $data = $this->render_data($optionid, (int) $student->id);

        $this->assertSame('waitinglist', $data['confirmationstate']);
        $this->assertSame(get_string('confirmationstepconfirmation', 'mod_booking'), $data['steps'][1]['title']);
    }

    /**
     * Price display settings for the payment step.
     *
     * @return array
     */
    public static function cart_price_provider(): array {
        return [
            'price shown' => [79, 1, 0, true],
            'zero price shown with displayemptyprice' => [0, 1, 0, true],
        ];
    }

    /**
     * A reserved cart item shows the payment step with the same price label as the book button.
     *
     * A zero price without "displayemptyprice" never reaches the cart, not even with
     * "priceisalwayson" (the option is booked directly, see priceisset), so those cases are
     * covered by test_price_display_label().
     *
     * @dataProvider cart_price_provider
     * @param float $price
     * @param int $displayemptyprice
     * @param int $priceisalwayson
     * @param bool $expectlabel
     */
    public function test_reserved_in_cart_shows_payment_step_with_price(
        float $price,
        int $displayemptyprice,
        int $priceisalwayson,
        bool $expectlabel
    ): void {
        $this->prepare_instance();
        set_config('displayemptyprice', $displayemptyprice, 'booking');
        set_config('priceisalwayson', $priceisalwayson, 'booking');
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => $price,
            'pricecatsortorder' => 1,
        ]);
        $optionid = $this->create_option(['useprice' => 1, 'importing' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);
        \local_shopping_cart\shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        singleton_service::destroy_instance();

        $data = $this->render_data($optionid, (int) $student->id);

        $this->assertSame('reserved', $data['confirmationstate']);
        $this->assertFalse($data['hasticket']);
        $this->assertCount(3, $data['steps']);
        $this->assertTrue($data['steps'][0]['done']);
        $this->assertTrue($data['steps'][1]['current']);
        $this->assertSame($expectlabel, $data['steps'][1]['hastext']);
        $this->assertSame(
            price::get_display_label(price::get_price('option', $optionid, $student)),
            $data['steps'][1]['text']
        );
        $this->assertStringContainsString(format_float($price, 2), $data['steps'][1]['text']);
    }

    /**
     * The price label follows "displayemptyprice" and "priceisalwayson" like the book button.
     */
    public function test_price_display_label(): void {
        $this->resetAfterTest(true);
        $zero = ['price' => 0, 'currency' => 'EUR'];
        $paid = ['price' => 49, 'currency' => 'EUR'];

        set_config('displayemptyprice', 0, 'booking');
        set_config('priceisalwayson', 0, 'booking');
        $this->assertSame('', price::get_display_label($zero));
        $this->assertSame(format_float(49, 2) . ' EUR', price::get_display_label($paid));

        set_config('displayemptyprice', 1, 'booking');
        $this->assertSame(format_float(0, 2) . ' EUR', price::get_display_label($zero));

        set_config('displayemptyprice', 0, 'booking');
        set_config('priceisalwayson', 1, 'booking');
        $this->assertSame(format_float(0, 2) . ' EUR', price::get_display_label($zero));
    }

    /**
     * A user who is not booked sees the error state without steps, ticket or links.
     */
    public function test_not_booked_shows_error_without_steps(): void {
        $this->prepare_instance();
        $optionid = $this->create_option(['ticket' => ticket_template_installer::ensure_installed()]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $data = $this->render_data($optionid, (int) $student->id);

        $this->assertSame('error', $data['confirmationstate']);
        $this->assertFalse($data['hassteps']);
        $this->assertFalse($data['hasticket']);
        $this->assertSame('', $data['mybookingsurl']);
    }

    /**
     * The template renders the QR code as an image and keeps the icon out of the language string.
     */
    public function test_template_renders_qr_code_image(): void {
        global $OUTPUT;
        $this->prepare_instance();
        $optionid = $this->create_option(['ticket' => ticket_template_installer::ensure_installed()]);
        $student = $this->book_new_student($optionid);

        $html = $OUTPUT->render_from_template(
            'mod_booking/condition/confirmation',
            $this->render_data($optionid, (int) $student->id)
        );

        $this->assertStringContainsString('class="mod-booking-confirmation-qrcode"', $html);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $html);
        $this->assertStringContainsString('mod-booking-confirmation-booked', $html);
        $this->assertStringContainsString('class="mod-booking-confirmation-icon"', $html);
        // The language string is rendered unchanged, so site customisations of it keep working.
        $this->assertStringContainsString(get_string('thankyoubooked', 'mod_booking', 'Yoga'), $html);
    }
}

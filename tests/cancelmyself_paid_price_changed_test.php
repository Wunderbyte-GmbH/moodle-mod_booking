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
 * Tests that the cancel button follows the price which was paid, not the current price.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_history;
use mod_booking\bo_availability\conditions\cancelmyself;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that the cancel button follows the price which was paid, not the current price.
 *
 * The price of an option depends on the price category of the user (e.g. an affiliation stored in
 * a profile field). When the category changes after a paid purchase and the current price is empty,
 * the user used to get the normal cancel button, which cancels the booking without touching the
 * purchase in shopping cart: no refund. The button of shopping cart has to be shown instead.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cancelmyself_paid_price_changed_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        singleton_service::destroy_instance();
    }

    /**
     * The user paid a price, afterwards the current price for this user is empty:
     * the cancel button of shopping cart is shown and the paid price is known.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::render_button
     * @covers \mod_booking\price::get_purchased_price
     */
    public function test_shopping_cart_cancel_button_when_current_price_is_empty(): void {
        ['settings' => $settings, 'student' => $student] = $this->create_priced_option();

        $historyitem = $this->buy_option($settings->id, (int)$student->id);
        $this->assertEquals(100, (float)$historyitem->price);

        $this->setUser($student);

        // Control: as long as the current price is the paid one, the shopping cart button is shown.
        $this->assertTrue($this->is_shopping_cart_cancel_button($settings->id, (int)$student->id));

        $this->set_current_price_to_zero($settings->id);
        $user = singleton_service::get_instance_of_user((int)$student->id);
        $this->assertEmpty((float)(price::get_price('option', $settings->id, $user)['price'] ?? 0));

        $this->assertEquals(100, price::get_purchased_price($settings->id, (int)$student->id));
        $this->assertTrue(
            $this->is_shopping_cart_cancel_button($settings->id, (int)$student->id),
            'A price was paid: the purchase has to be cancelled via shopping cart.'
        );
    }

    /**
     * The user is booked, but there is no purchase in shopping cart and the current price is empty:
     * the normal cancel button is shown.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::render_button
     * @covers \mod_booking\price::get_purchased_price
     */
    public function test_normal_cancel_button_without_purchase(): void {
        global $DB;

        ['settings' => $settings, 'student' => $student] = $this->create_priced_option();

        $this->buy_option($settings->id, (int)$student->id);
        // The booking stays, the purchase is gone: like a booking which never went through shopping cart.
        $DB->delete_records('local_shopping_cart_history', ['userid' => $student->id]);
        cache::make('mod_booking', 'userpurchases')->purge();

        $this->setUser($student);
        $this->set_current_price_to_zero($settings->id);

        $this->assertNull(price::get_purchased_price($settings->id, (int)$student->id));
        $this->assertFalse($this->is_shopping_cart_cancel_button($settings->id, (int)$student->id));
    }

    /**
     * The purchase in shopping cart was for free and the current price is empty:
     * nothing was paid, so the normal cancel button is shown.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::render_button
     * @covers \mod_booking\price::get_purchased_price
     */
    public function test_normal_cancel_button_when_nothing_was_paid(): void {
        global $DB;

        ['settings' => $settings, 'student' => $student] = $this->create_priced_option();

        $historyitem = $this->buy_option($settings->id, (int)$student->id);
        $DB->set_field('local_shopping_cart_history', 'price', 0, ['id' => $historyitem->id]);
        cache::make('mod_booking', 'userpurchases')->purge();

        $this->setUser($student);
        $this->set_current_price_to_zero($settings->id);

        $this->assertEquals(0, price::get_purchased_price($settings->id, (int)$student->id));
        $this->assertFalse($this->is_shopping_cart_cancel_button($settings->id, (int)$student->id));
    }

    /**
     * Renders the cancel button and returns if it is the one of shopping cart.
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    private function is_shopping_cart_cancel_button(int $optionid, int $userid): bool {
        singleton_service::destroy_booking_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $button = (new cancelmyself())->render_button($settings, $userid);
        $rendered = json_encode($button);

        $this->assertStringContainsString('bo-cancel-button', $rendered, 'A cancel button is rendered.');

        return strpos($rendered, 'shopping-cart-cancel-button') !== false;
    }

    /**
     * Sets the current price of the option to zero. This stands for a change of the price category
     * of the user after the purchase: the price for this user is empty now.
     *
     * @param int $optionid
     */
    private function set_current_price_to_zero(int $optionid): void {
        global $DB;

        $DB->set_field('booking_prices', 'price', 0, ['itemid' => $optionid, 'area' => 'option']);
        cache::make('mod_booking', 'cachedprices')->purge();
    }

    /**
     * Creates a booking with one priced option.
     *
     * @return array settings, student
     */
    private function create_priced_option(): array {
        set_config('cancelationfee', 0, 'local_shopping_cart');
        // With empty prices displayed, every purchase gets the shopping cart button anyway.
        // The paid price only decides when they are not displayed.
        set_config('displayemptyprice', 0, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = self::provide_bdata();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $bdata['cancancelbook'] = 1;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 100,
            'pricecatsortorder' => 1,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option with price';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
        $record->useprice = 1;
        $record->importing = 1;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('+ 1 year', time());
        $record->courseendtime_0 = strtotime('+ 1 year + 2 hours', time());
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        return ['settings' => $settings, 'student' => $student];
    }

    /**
     * The cashier buys the option on behalf of the user via shopping cart.
     *
     * @param int $optionid
     * @param int $userid
     * @return stdClass the shopping cart history record
     */
    private function buy_option(int $optionid, int $userid): stdClass {
        $this->setAdminUser();
        shopping_cart::delete_all_items_from_cart($userid);
        shopping_cart::buy_for_user($userid);
        cartstore::instance($userid);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);
        shopping_cart::confirm_payment($userid, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);

        singleton_service::destroy_booking_answers($optionid);

        $historyitem = shopping_cart_history::get_most_recent_historyitem('mod_booking', 'option', $optionid, $userid);
        $this->assertNotEmpty($historyitem->id, 'The purchase is stored in shopping cart history.');
        return $historyitem;
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking paid price',
            'eventtype' => 'Test event',
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];
    }
}

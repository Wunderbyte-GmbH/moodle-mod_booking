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
 * Tests for the rendered notify-me bell in the bookit button chain.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use stdClass;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/classes/booking_advanced_testcase.php');
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests the rendered HTML of the bookit button for every notify-me bell state.
 *
 * The bell must follow the booking answer state only (waitinglist column):
 * - user holds a notify-me entry (3) and the option is bookable:
 *   bell (filled) next to the add-to-cart button, price rendered exactly once;
 * - user holds a notify-me entry and the option is fully booked:
 *   standalone bell (filled) with the price above it;
 * - user holds no entry and the option is fully booked: bell (empty) to subscribe;
 * - user holds no entry and the option is bookable: no bell at all;
 * - user is booked (0): no bell.
 *
 * The asserts pin the markup structure as well: the price container must appear
 * exactly once (the bell partial must not inherit and re-render the price of
 * the surrounding template) and the bookit_price wrapper must not be nested a
 * second time by the embedded bell.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class notifyme_bell_render_test extends booking_advanced_testcase {
    /**
     * The outer wrapper of the price button template. Must occur exactly once
     * per render - before the template split, the embedded standalone bell
     * template rendered a second, nested copy of it.
     */
    private const PRICEWRAPPER = 'w-100 d-flex justify-content-center booking-button-area';

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        singleton_service::destroy_instance();
    }

    /**
     * Renders the bookit button chain for every bell state and checks the returned HTML.
     *
     * @covers \mod_booking\booking_bookit::render_bookit_button
     * @covers \mod_booking\output\button_notifyme
     * @covers \mod_booking\bo_availability\conditions\notifymelist
     *
     * @return void
     */
    public function test_notifyme_bell_rendering(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('The rendered price button requires local_shopping_cart.');
        }

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('usenotificationlist', 1, 'booking');
        set_config('cancelationfee', 0, 'local_shopping_cart');

        $bdata = [
            'name' => 'Notifyme Bell Test',
            'eventtype' => 'Test bell',
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

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $filler = $this->getDataGenerator()->create_user();
        $watcher = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        foreach ([$filler, $watcher, $bystander] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 79,
            'pricecatsortorder' => 1,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'aerial yoga';
        $record->maxanswers = 1;
        $record->maxoverbooking = 0;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->description = 'bell rendering test';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->useprice = 1;
        $record->importing = 1;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // State 1: bookable, no notify-me entry -> price button without any bell.
        $this->setUser($bystander);
        $html = booking_bookit::render_bookit_button($settings, $bystander->id);
        $this->assertSame(
            1,
            substr_count($html, 'pricecontainer'),
            'Bookable state without bell must render the price exactly once.'
        );
        $this->assertStringNotContainsString(
            'booking-button-notify-me',
            $html,
            'A user without a notify-me entry must not get a bell while the option is bookable.'
        );

        // State 2: bookable, user holds a notify-me entry -> bell next to the price button.
        $this->setUser($watcher);
        booking_option::toggle_notify_user($watcher->id, $settings->id);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->setUser($watcher);
        $html = booking_bookit::render_bookit_button($settings, $watcher->id);
        $this->assertSame(
            1,
            substr_count($html, 'booking-button-notify-me'),
            'A user on the notify-me list must see exactly one bell next to the bookable price button.'
        );
        $this->assertStringContainsString(
            'fa fa-bell"',
            $html,
            'The bell of a subscribed user must render as filled (unsubscribe affordance).'
        );
        $this->assertSame(
            1,
            substr_count($html, 'pricecontainer'),
            'The price must not be duplicated by the embedded bell (partial context inheritance).'
        );
        $this->assertSame(
            1,
            substr_count($html, self::PRICEWRAPPER),
            'The embedded bell must not render a second, nested price button wrapper.'
        );
        // The bell has to live INSIDE the replaced wrapper, not in front of it:
        // everything before the wrapper div must be free of the bell markup.
        $wrapperpos = strpos($html, self::PRICEWRAPPER);
        $bellpos = strpos($html, 'booking-button-notify-me');
        $this->assertGreaterThan(
            $wrapperpos,
            $bellpos,
            'The bell must be rendered inside the bookit wrapper that the JS replaces on re-renders.'
        );

        // Fully book the option with the filler user (cashier flow).
        $this->setAdminUser();
        \local_shopping_cart\shopping_cart::delete_all_items_from_cart($filler->id);
        \local_shopping_cart\shopping_cart::buy_for_user($filler->id);
        \local_shopping_cart\local\cartstore::instance($filler->id);
        \local_shopping_cart\shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);
        \local_shopping_cart\shopping_cart::confirm_payment($filler->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // State 3: fully booked, user holds a notify-me entry -> standalone filled bell with price.
        $this->setUser($watcher);
        $html = booking_bookit::render_bookit_button($settings, $watcher->id);
        $this->assertSame(
            1,
            substr_count($html, 'booking-button-notify-me'),
            'Fully booked: the subscribed user must see exactly one bell.'
        );
        $this->assertStringContainsString(
            'fa fa-bell"',
            $html,
            'Fully booked: the bell of the subscribed user must render as filled.'
        );
        $this->assertStringNotContainsString(
            'bookit-addtocartbtn-area',
            $html,
            'Fully booked: there must be no add-to-cart button next to the bell.'
        );

        // State 4: fully booked, user without an entry -> empty bell to subscribe.
        $this->setUser($bystander);
        $html = booking_bookit::render_bookit_button($settings, $bystander->id);
        $this->assertSame(
            1,
            substr_count($html, 'booking-button-notify-me'),
            'Fully booked: a user without an entry must see exactly one bell to subscribe.'
        );
        $this->assertStringContainsString(
            'fa fa-bell-o',
            $html,
            'Fully booked: the bell of an unsubscribed user must render as empty.'
        );

        // State 5: the booked user (waitinglist 0) gets no bell.
        $this->setUser($filler);
        $html = booking_bookit::render_bookit_button($settings, $filler->id);
        $this->assertStringNotContainsString(
            'booking-button-notify-me',
            $html,
            'A booked user is not on the notify-me list and must not get a bell.'
        );

        // State 6: unsubscribing removes the bell from the bookable price button again.
        $this->setAdminUser();
        booking_option::toggle_notify_user($watcher->id, $settings->id);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->setUser($watcher);
        $html = booking_bookit::render_bookit_button($settings, $watcher->id);
        $this->assertStringContainsString(
            'fa fa-bell-o',
            $html,
            'Fully booked: after unsubscribing the user must see the empty bell again.'
        );
    }
}

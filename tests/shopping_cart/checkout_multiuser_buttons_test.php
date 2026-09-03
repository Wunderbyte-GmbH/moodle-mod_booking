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
 * Tests for the checkout page buttons (+/-/delete) for multi-user bookings.
 *
 * A user books a booking option for multiple users via the customform
 * prepage (enrolusersaction). On the checkout page the +/- icons call the
 * increase/decrease_number_of_item webservices and the trash icon calls
 * delete_item. All three must work for the buying user.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use local_shopping_cart\external\decrease_number_of_item;
use local_shopping_cart\external\delete_item_from_cart;
use local_shopping_cart\external\increase_number_of_item;
use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use mod_booking\form\condition\customform_form;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the checkout page buttons (+/-/delete) for multi-user bookings.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checkout_multiuser_buttons_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * The buying user books a multi-user option (enrolusersaction) and can then
     * use the +/- and delete buttons on the checkout page.
     *
     * @param array $bdata
     * @runInSeparateProcess
     * @covers \mod_booking\shopping_cart\service_provider::adjust_number_of_items
     * @covers \mod_booking\shopping_cart\service_provider::unload_cartitem
     * @dataProvider booking_common_settings_provider
     */
    public function test_buyer_can_adjust_and_delete_multiuser_item(array $bdata): void {
        global $DB;

        [$buyer, $settings] = $this->setup_multiuser_scenario($bdata);

        $this->setUser($buyer);

        // The buyer submits the customform prepage for themself with 3 users.
        $_POST = [
            'id' => (string)$settings->id,
            'userid' => (string)$buyer->id,
            'customform_enrolusersaction_1' => '3',
            'sesskey' => sesskey(),
            '_qf__mod_booking_form_condition_customform_form' => '1',
        ];
        $form = new customform_form(null, null, 'post', '', [], true, $_POST, true);
        $this->assertTrue($form->is_validated(), 'Customform submission should validate for the buyer.');
        $form->process_dynamic_submission();
        $_POST = [];

        // The buyer adds the option to their own cart.
        shopping_cart::delete_all_items_from_cart($buyer->id);
        $cartstore = cartstore::instance($buyer->id);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, 0);

        $optionitem = $this->get_option_item($cartstore);
        $this->assertNotNull($optionitem, 'Booking option item must be in the cart.');
        $this->assertEquals(3, (int)($optionitem['nritems'] ?? 0), 'Cart item must carry the entered number of users.');
        $this->assertEquals(1, (int)($optionitem['multipliable'] ?? 0));

        // Click "+" on the checkout page: the JS sends the item's userid (the buyer).
        $result = increase_number_of_item::execute('mod_booking', 'option', $settings->id, (int)$buyer->id);
        $this->assertEquals(1, (int)$result['success'], 'Increasing the number of items must succeed.');
        $optionitem = $this->get_option_item($cartstore);
        $this->assertEquals(4, (int)($optionitem['nritems'] ?? 0), 'Increase must be reflected in the cart.');

        // The booking answer must be updated as well.
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $settings->id,
            'userid' => $buyer->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ]);
        $this->assertEquals(4, (int)$answer->places, 'Increase must be reflected in the booking answer.');

        // Click "-" on the checkout page.
        $result = decrease_number_of_item::execute('mod_booking', 'option', $settings->id, (int)$buyer->id);
        $this->assertEquals(1, (int)$result['success'], 'Decreasing the number of items must succeed.');
        $optionitem = $this->get_option_item($cartstore);
        $this->assertEquals(3, (int)($optionitem['nritems'] ?? 0), 'Decrease must be reflected in the cart.');

        // Click the trash icon on the checkout page.
        $result = delete_item_from_cart::execute('mod_booking', 'option', $settings->id, (int)$buyer->id);
        $this->assertEquals(1, (int)$result['success'], 'Deleting the item must succeed.');
        $this->assertNull($this->get_option_item($cartstore), 'Item must be gone from the cart.');

        // The reservation must be gone, too.
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $settings->id,
            'userid' => $buyer->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ]);
        $this->assertEmpty($answer, 'Reserved booking answer must be removed.');
    }

    /**
     * A cashier (local/shopping_cart:cashier, but WITHOUT mod/booking:bookforothers -
     * the constellation of the former "Anzahl: 1" bug) books the multi-user option
     * on behalf of another user and can then use the +/- and delete buttons.
     *
     * @param array $bdata
     * @runInSeparateProcess
     * @covers \mod_booking\shopping_cart\service_provider::adjust_number_of_items
     * @covers \mod_booking\shopping_cart\service_provider::unload_cartitem
     * @covers \mod_booking\form\condition\customform_form::require_userid_access
     * @dataProvider booking_common_settings_provider
     */
    public function test_cashier_can_adjust_and_delete_multiuser_item_for_other_user(array $bdata): void {
        global $DB;

        [$targetuser, $settings, $cashieruser] = $this->setup_multiuser_scenario($bdata, true);

        $this->setUser($cashieruser);

        // The cashier submits the customform prepage for the TARGET user with 3 users.
        $_POST = [
            'id' => (string)$settings->id,
            'userid' => (string)$targetuser->id,
            'customform_enrolusersaction_1' => '3',
            'sesskey' => sesskey(),
            '_qf__mod_booking_form_condition_customform_form' => '1',
        ];
        $form = new customform_form(null, null, 'post', '', [], true, $_POST, true);
        $this->assertTrue($form->is_validated(), 'Customform submission should validate for the cashier.');
        $form->process_dynamic_submission();
        $_POST = [];

        // The cashier selects the target user on cashier.php (buy_for_user mechanics)
        // and adds the option to the target user's cart.
        shopping_cart::delete_all_items_from_cart($targetuser->id);
        shopping_cart::buy_for_user($targetuser->id);
        $cartstore = cartstore::instance($targetuser->id);
        shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);

        // Regression against the "Anzahl: 1" bug: the item carries 3, not 1.
        $optionitem = $this->get_option_item($cartstore);
        $this->assertNotNull($optionitem, 'Booking option item must be in the target user\'s cart.');
        $this->assertEquals(3, (int)($optionitem['nritems'] ?? 0), 'Cart item must carry the entered number of users.');
        $this->assertEquals(1, (int)($optionitem['multipliable'] ?? 0));
        $this->assertEquals((int)$targetuser->id, (int)($optionitem['userid'] ?? 0), 'Item must belong to the target user.');

        // The reservation belongs to the TARGET user (not the cashier) and carries 3 places.
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $settings->id,
            'userid' => $targetuser->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ]);
        $this->assertNotEmpty($answer, 'Reservation must exist for the target user.');
        $this->assertEquals(3, (int)$answer->places);
        $this->assertEmpty(
            $DB->get_records('booking_answers', ['optionid' => $settings->id, 'userid' => $cashieruser->id]),
            'The cashier must not get a booking answer themself.'
        );

        // Click "+" as the cashier: the JS sends the item's userid (the target user).
        $result = increase_number_of_item::execute('mod_booking', 'option', $settings->id, (int)$targetuser->id);
        $this->assertEquals(1, (int)$result['success'], 'Increasing the number of items must succeed for the cashier.');
        $optionitem = $this->get_option_item($cartstore);
        $this->assertEquals(4, (int)($optionitem['nritems'] ?? 0), 'Increase must be reflected in the cart.');
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $settings->id,
            'userid' => $targetuser->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ]);
        $this->assertEquals(4, (int)$answer->places, 'Increase must be reflected in the booking answer.');

        // Click "-" as the cashier.
        $result = decrease_number_of_item::execute('mod_booking', 'option', $settings->id, (int)$targetuser->id);
        $this->assertEquals(1, (int)$result['success'], 'Decreasing the number of items must succeed for the cashier.');
        $optionitem = $this->get_option_item($cartstore);
        $this->assertEquals(3, (int)($optionitem['nritems'] ?? 0), 'Decrease must be reflected in the cart.');
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $settings->id,
            'userid' => $targetuser->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ]);
        $this->assertEquals(3, (int)$answer->places, 'Decrease must be reflected in the booking answer.');

        // Click the trash icon as the cashier.
        $result = delete_item_from_cart::execute('mod_booking', 'option', $settings->id, (int)$targetuser->id);
        $this->assertEquals(1, (int)$result['success'], 'Deleting the item must succeed for the cashier.');
        $this->assertNull($this->get_option_item($cartstore), 'Item must be gone from the target user\'s cart.');

        // The reservation of the target user must be gone, too.
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $settings->id,
            'userid' => $targetuser->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ]);
        $this->assertEmpty($answer, 'Reserved booking answer of the target user must be removed.');
    }

    /**
     * Returns the mod_booking option item from the cart or null.
     *
     * @param cartstore $cartstore
     * @return array|null
     */
    private function get_option_item(cartstore $cartstore): ?array {
        foreach ($cartstore->get_items() as $item) {
            if (($item['componentname'] ?? '') === 'mod_booking' && ($item['area'] ?? '') === 'option') {
                return $item;
            }
        }
        return null;
    }

    /**
     * Creates course, booking instance, price category, an option with an
     * enrolusersaction customform and an enrolled buyer (student).
     *
     * If $withcashier is true, additionally creates a cashier user whose role has
     * local/shopping_cart:cashier (plus mod/booking:conditionforms and mod/booking:choose,
     * like a typical dedicated cashier role) but NOT mod/booking:bookforothers.
     *
     * @param array $bdata
     * @param bool $withcashier
     * @return array [$buyer, $settings, $cashieruser|null]
     */
    private function setup_multiuser_scenario(array $bdata, bool $withcashier = false): array {

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $buyer = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $bdata['autoenrol'] = 1;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($buyer->id, $course->id, 'student');

        $cashieruser = null;
        if ($withcashier) {
            // Cashier role: like a typical dedicated cashier role - shopping cart cashier
            // rights, choose and conditionforms, but NO mod/booking:bookforothers.
            // Note: mod/booking:choose is required because the hardcoded capbookingchoose
            // condition (via allowedtobookininstance) checks it for the ACTING user, and
            // service_provider::allow_add_item_to_cart() has no cashier bypass for it.
            $cashieruser = $this->getDataGenerator()->create_user();
            $systemcontext = \context_system::instance();
            $roleid = create_role('Cashier', 'cashierrole', 'Cashier role');
            assign_capability('local/shopping_cart:cashier', CAP_ALLOW, $roleid, $systemcontext->id);
            assign_capability('mod/booking:conditionforms', CAP_ALLOW, $roleid, $systemcontext->id);
            assign_capability('mod/booking:choose', CAP_ALLOW, $roleid, $systemcontext->id);
            role_assign($roleid, $cashieruser->id, $systemcontext->id);
        }

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $pricecategorydata = (object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 25,
            'pricecatsortorder' => 1,
        ];
        $plugingenerator->create_pricecategory($pricecategorydata);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Multi-user option';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->importing = 1;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course2->id;
        $record->useprice = 1;
        $record->enrolmentstatus = 2;
        $record->maxanswers = 20;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Book multiple users';
        $record->bo_cond_customform_value_1_1 = 1;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        return [$buyer, $settings, $cashieruser];
    }

    /**
     * Data provider for common booking settings.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Checkout multiuser buttons test',
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
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}

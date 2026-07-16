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
 * Tests for cashier access to the customform prepage (enrolusersaction).
 *
 * A cashier books for another user on the shopping cart cashier page. The
 * customform prepage ("book multiple users") is loaded and submitted with the
 * target user's id. Cashiers without mod/booking:bookforothers must still be
 * able to do this - otherwise the form silently breaks and the booking falls
 * back to quantity 1 (GH regression, cashier "Anzahl: 1" bug).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use mod_booking\form\condition\customform_form;
use mod_booking\local\mobile\customformstore;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for cashier access to the customform prepage (enrolusersaction).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cashier_customform_access_test extends booking_advanced_testcase {
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
     * A cashier without mod/booking:bookforothers can load and submit the
     * customform for the target user, and the resulting cart item carries the
     * entered number of users (nritems), not 1.
     *
     * @param array $bdata
     * @covers \mod_booking\form\condition\customform_form::require_userid_access
     * @covers \mod_booking\shopping_cart\service_provider::load_cartitem
     * @dataProvider booking_common_settings_provider
     */
    public function test_cashier_without_bookforothers_books_multiple_users(array $bdata): void {
        global $DB;

        [$targetuser, $cashieruser, $settings] = $this->setup_cashier_scenario($bdata);

        $this->setUser($cashieruser);

        $ajaxformdata = [
            'id' => (string)$settings->id,
            'userid' => (string)$targetuser->id,
        ];

        // Loading the form for the target user (as the customform modal does) must not throw.
        new customform_form(null, null, 'post', '', [], true, $ajaxformdata, true);

        // Submitting the form must store the data under the TARGET user's cache key.
        $_POST = [
            'id' => (string)$settings->id,
            'userid' => (string)$targetuser->id,
            'customform_enrolusersaction_1' => '10',
            'sesskey' => sesskey(),
            '_qf__mod_booking_form_condition_customform_form' => '1',
        ];
        $form = new customform_form(null, null, 'post', '', [], true, $_POST, true);
        $this->assertTrue($form->is_validated(), 'Customform submission should validate for the cashier.');
        $form->process_dynamic_submission();
        $_POST = [];

        $customformstore = new customformstore($targetuser->id, $settings->id);
        $storeddata = (array)$customformstore->get_customform_data();
        $this->assertEquals(10, (int)($storeddata['customform_enrolusersaction_1'] ?? 0));

        // Now the cashier adds the option to the target user's cart.
        shopping_cart::delete_all_items_from_cart($targetuser->id);
        shopping_cart::buy_for_user($targetuser->id);
        $cartstore = cartstore::instance($targetuser->id);

        shopping_cart::add_item_to_cart('mod_booking', 'option', $settings->id, -1);

        $optionitem = null;
        foreach ($cartstore->get_items() as $item) {
            if (($item['componentname'] ?? '') === 'mod_booking' && ($item['area'] ?? '') === 'option') {
                $optionitem = $item;
            }
        }
        $this->assertNotNull($optionitem, 'Booking option item must be in the cart.');
        $this->assertEquals(10, (int)($optionitem['nritems'] ?? 0), 'Cart item must carry the entered number of users.');
        $this->assertEquals(1, (int)($optionitem['multipliable'] ?? 0));
        $this->assertEquals(250.0, (float)($optionitem['price'] ?? 0), 'Price must be multiplied by the number of users.');

        // The reserved booking answer carries the customform json and the number of places.
        $answer = $DB->get_record('booking_answers', [
            'optionid' => $settings->id,
            'userid' => $targetuser->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_RESERVED,
        ]);
        $this->assertNotEmpty($answer->json ?? '', 'Reserved answer must carry the customform json.');
        $this->assertEquals(10, (int)$answer->places);
    }

    /**
     * A user without cashier rights and without mod/booking:bookforothers must
     * still be denied access to another user's customform data.
     *
     * @param array $bdata
     * @covers \mod_booking\form\condition\customform_form::require_userid_access
     * @dataProvider booking_common_settings_provider
     */
    public function test_user_without_permission_cannot_access_customform_of_other_user(array $bdata): void {

        [$targetuser, , $settings] = $this->setup_cashier_scenario($bdata);

        $plainuser = $this->getDataGenerator()->create_user();
        $this->setUser($plainuser);

        $this->expectException(\core\exception\required_capability_exception::class);
        customform_form::require_userid_access($targetuser->id, $settings->id);
    }

    /**
     * Accessing your own customform data never requires additional capabilities.
     *
     * @param array $bdata
     * @covers \mod_booking\form\condition\customform_form::require_userid_access
     * @dataProvider booking_common_settings_provider
     */
    public function test_user_can_access_own_customform(array $bdata): void {
        global $USER;

        [, , $settings] = $this->setup_cashier_scenario($bdata);

        $plainuser = $this->getDataGenerator()->create_user();
        $this->setUser($plainuser);

        customform_form::require_userid_access((int)$USER->id, $settings->id);
        customform_form::require_userid_access(0, $settings->id);

        // No exception means access is granted.
        $this->assertTrue(true);
    }

    /**
     * Creates course, booking instance, price category, an option with an
     * enrolusersaction customform and a cashier user whose role has
     * local/shopping_cart:cashier but NOT mod/booking:bookforothers.
     *
     * @param array $bdata
     * @return array [$targetuser, $cashieruser, $settings]
     */
    private function setup_cashier_scenario(array $bdata): array {

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $course2 = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $targetuser = $this->getDataGenerator()->create_user();
        $cashieruser = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $bdata['autoenrol'] = 1;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($targetuser->id, $course->id, 'student');

        // Cashier role: like a typical dedicated cashier role - shopping cart cashier
        // rights, choose and conditionforms, but NO mod/booking:bookforothers.
        // Note: mod/booking:choose is required because the hardcoded capbookingchoose
        // condition (via allowedtobookininstance) checks it for the ACTING user, and
        // service_provider::allow_add_item_to_cart() has no cashier bypass for it.
        $systemcontext = \context_system::instance();
        $roleid = create_role('Cashier', 'cashierrole', 'Cashier role');
        assign_capability('local/shopping_cart:cashier', CAP_ALLOW, $roleid, $systemcontext->id);
        assign_capability('mod/booking:conditionforms', CAP_ALLOW, $roleid, $systemcontext->id);
        assign_capability('mod/booking:choose', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $cashieruser->id, $systemcontext->id);

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
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_1 = 'Book multiple users';
        $record->bo_cond_customform_value_1_1 = 1;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        return [$targetuser, $cashieruser, $settings];
    }

    /**
     * Data provider for common booking settings.
     *
     * @return array
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Cashier customform access test',
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

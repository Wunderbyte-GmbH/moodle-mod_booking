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
 * Tests for the booking action "Set user profile field" with the operators add and subtract.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use local_shopping_cart\local\cartstore;
use local_shopping_cart\shopping_cart;
use local_shopping_cart\shopping_cart_credits;
use mod_booking\bo_actions\actions_info;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/local/shopping_cart/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Tests for the booking action "Set user profile field" with the operators add and subtract.
 *
 * The operators must work on the value that is stored in the database,
 * also when the cached user object has no profile or an old copy of it (issue #1689).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\bo_actions\action_types\userprofilefield
 */
final class userprofilefield_action_test extends booking_advanced_testcase {
    /** @var string Shortname of the custom user profile field used in the tests. */
    private const FIELD = 'credits';

    /** @var stdClass The custom user profile field. */
    private stdClass $field;

    /** @var stdClass The user who books. */
    private stdClass $student;

    /** @var int Id of the booking instance. */
    private int $bookingid;

    /**
     * Creates the profile field, a course with a booking instance and an enrolled student.
     *
     * @param string $startvalue value of the profile field of the student
     */
    private function setup_site(string $startvalue): void {
        $this->setAdminUser();

        $this->field = $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => self::FIELD,
            'name' => 'Credits',
            'datatype' => 'text',
        ]);

        $this->student = $this->getDataGenerator()->create_user(['profile_field_' . self::FIELD => $startvalue]);

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id);

        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Booking with profile field actions',
            'bookingmanager' => $bookingmanager->username,
        ]);
        $this->bookingid = (int)$booking->id;
    }

    /**
     * Creates a booking option with the action "Set user profile field".
     *
     * @param string $operator add or subtract
     * @param string $value the value of the action
     * @param int $price 0 for a free option, otherwise the price of the option
     * @return int the id of the booking option
     */
    private function create_option(string $operator, string $value, int $price = 0): int {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = [
            'bookingid' => $this->bookingid,
            'text' => "Package: {$operator} {$value}",
            'courseid' => 0,
            'maxanswers' => 10,
            'importing' => 1,
            'json' => json_encode(['boactions' => [1 => [
                'id' => 1,
                'action_type' => 'userprofilefield',
                'boactionname' => "{$operator} {$value}",
                'boactionselectuserprofilefield' => self::FIELD,
                'boactionuserprofileoperator' => $operator,
                'boactionuserprofilefieldvalue' => $value,
            ]]]),
        ];
        if ($price > 0) {
            $record['useprice'] = 1;
            $record['default'] = $price;
        }
        $option = $plugingenerator->create_option((object)$record);

        // The settings have to be read freshly from the database, like in a new request.
        singleton_service::destroy_instance();

        return (int)$option->id;
    }

    /**
     * Creates the price category "default", needed for paid booking options.
     */
    private function create_default_pricecategory(): void {
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 20,
            'pricecatsortorder' => 1,
        ]);
    }

    /**
     * Returns the value of the profile field of the student, as stored in the database.
     *
     * @return string|false false if the student has no stored value
     */
    private function stored_value() {
        global $DB;
        return $DB->get_field('user_info_data', 'data', [
            'userid' => $this->student->id,
            'fieldid' => $this->field->id,
        ]);
    }

    /**
     * Runs the actions after booking of an option for the student, like in a new request.
     *
     * @param int $optionid
     */
    private function apply_actions_in_new_request(int $optionid): void {
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        actions_info::apply_actions($settings, (int)$this->student->id);
    }

    /**
     * The reported case: the user buys a paid package two times and pays with credits of the shopping cart.
     */
    public function test_add_when_user_buys_paid_option_with_cart(): void {
        $this->setup_site('1');
        $this->create_default_pricecategory();
        $optionids = [$this->create_option('add', '10', 20), $this->create_option('add', '10', 20)];
        shopping_cart_credits::add_credit((int)$this->student->id, 100, 'EUR');

        $values = [];
        foreach ($optionids as $optionid) {
            $this->setUser($this->student);
            singleton_service::destroy_instance();
            shopping_cart::delete_all_items_from_cart((int)$this->student->id);
            cartstore::instance((int)$this->student->id);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, (int)$this->student->id);

            // The checkout is a new request.
            singleton_service::destroy_instance();
            $result = shopping_cart::confirm_payment((int)$this->student->id, LOCAL_SHOPPING_CART_PAYMENT_METHOD_CREDITS);
            $this->assertEquals(1, $result['status'], 'The payment with credits worked.');

            $values[] = $this->stored_value();
        }

        $this->assertSame(['11', '21'], $values);
    }

    /**
     * A cashier buys a paid package two times for another user.
     */
    public function test_add_when_cashier_buys_paid_option_for_other_user(): void {
        $this->setup_site('1');
        $this->create_default_pricecategory();
        $optionids = [$this->create_option('add', '10', 20), $this->create_option('add', '10', 20)];

        $values = [];
        foreach ($optionids as $optionid) {
            $this->setAdminUser();
            singleton_service::destroy_instance();
            shopping_cart::delete_all_items_from_cart((int)$this->student->id);
            shopping_cart::buy_for_user((int)$this->student->id);
            cartstore::instance((int)$this->student->id);
            shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, -1);

            // The payment is a new request.
            singleton_service::destroy_instance();
            $result = shopping_cart::confirm_payment(
                (int)$this->student->id,
                LOCAL_SHOPPING_CART_PAYMENT_METHOD_CASHIER_CASH
            );
            $this->assertEquals(1, $result['status'], 'The cash payment worked.');

            $values[] = $this->stored_value();
        }

        $this->assertSame(['11', '21'], $values);
    }

    /**
     * Other code loaded the profile into the cached user before, in the same request.
     * The action must not use this old copy, and the copy must show the new value afterwards.
     */
    public function test_add_when_profile_was_loaded_before_in_same_request(): void {
        $this->setup_site('1');
        $optionid = $this->create_option('add', '10');

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cacheduser = singleton_service::get_instance_of_user((int)$this->student->id, true);
        $this->assertEquals('1', $cacheduser->profile[self::FIELD]);

        actions_info::apply_actions($settings, (int)$this->student->id);
        $this->assertSame('11', $this->stored_value());

        actions_info::apply_actions($settings, (int)$this->student->id);
        $this->assertSame('21', $this->stored_value());

        $this->assertEquals('21', $cacheduser->profile[self::FIELD], 'The cached profile is up to date.');
    }

    /**
     * The user books for themselves and $USER has a loaded profile.
     * After the action, the profile in $USER must show the new value.
     */
    public function test_add_updates_profile_of_current_user(): void {
        global $USER;

        $this->setup_site('1');
        $optionid = $this->create_option('add', '10');

        $this->setUser($this->student);
        profile_load_custom_fields($USER);
        $this->assertEquals('1', $USER->profile[self::FIELD]);

        $this->apply_actions_in_new_request($optionid);

        $this->assertSame('11', $this->stored_value());
        $this->assertEquals('11', $USER->profile[self::FIELD], 'The profile in $USER is up to date.');
    }

    /**
     * The operator subtract works on the stored value, also when it is repeated.
     */
    public function test_subtract(): void {
        $this->setup_site('1');
        $optionid = $this->create_option('subtract', '3');

        $this->apply_actions_in_new_request($optionid);
        $this->assertSame('-2', $this->stored_value());

        $this->apply_actions_in_new_request($optionid);
        $this->assertSame('-5', $this->stored_value());
    }

    /**
     * The user has no stored value in the profile field yet. The operator add starts with 0.
     */
    public function test_add_to_empty_field(): void {
        global $DB;

        $this->setup_site('');
        $optionid = $this->create_option('add', '10');

        // Like a user who was created before the profile field existed: no stored row at all.
        $DB->delete_records('user_info_data', ['userid' => $this->student->id, 'fieldid' => $this->field->id]);
        $this->assertFalse($this->stored_value(), 'The user has no stored value yet.');

        $this->apply_actions_in_new_request($optionid);
        $this->assertSame('10', $this->stored_value());

        $this->apply_actions_in_new_request($optionid);
        $this->assertSame('20', $this->stored_value());
    }
}

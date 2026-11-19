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

use mod_booking\bo_availability\conditions\noshoppingcart;
use mod_booking\output\bookit_button;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;

/**
 * The button of a priced option on a site without local_shopping_cart comes from the condition
 * noshoppingcart, whose render data had no userid. The constructor of bookit_button read the
 * userid for the "for user" label before it applied its fallback to the current user, so the
 * option list crashed with a TypeError in bo_info::get_for_user_button_string().
 *
 * The condition is called directly: local_shopping_cart is installed wherever these tests run,
 * so the condition never becomes the button condition of booking_bookit here.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\output\bookit_button
 * @covers     \mod_booking\bo_availability\conditions\noshoppingcart::render_button
 */
final class bookit_button_userid_test extends booking_advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Creates a priced booking option with the price category "default".
     *
     * @return booking_option_settings
     */
    private function create_priced_option(): booking_option_settings {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 50,
            'pricecatsortorder' => 1,
        ]);

        $booking = $plugingenerator->create_instance([
            'course' => $course->id,
            'name' => 'Priced option without shopping cart',
            'eventtype' => 'Test',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'priced-option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
        $record->useprice = 1;
        $record->importing = 1;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return singleton_service::get_instance_of_booking_option_settings($option->id);
    }

    /**
     * Render data without a userid (or with an empty one) is attributed to the current user
     * instead of crashing, and gets no "for user" label.
     *
     * @dataProvider missing_userid_provider
     * @param array $data
     */
    public function test_missing_userid_falls_back_to_current_user(array $data): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $button = new bookit_button($data);

        $this->assertEquals($user->id, $button->data['userid']);
        $this->assertSame('', $button->data['main']['foruser']);
    }

    /**
     * Shapes of render data without a usable userid.
     *
     * @return array
     */
    public static function missing_userid_provider(): array {
        return [
            'no userid key (price data of noshoppingcart)' => [['priceitems' => [], 'fullwidth' => true]],
            'userid null' => [['userid' => null]],
            'userid 0' => [['userid' => 0]],
            'userid empty string' => [['userid' => '']],
        ];
    }

    /**
     * A user who is not logged in has the id 0, which must not crash either.
     */
    public function test_missing_userid_when_not_logged_in(): void {
        $this->setUser(null);

        $button = new bookit_button(['priceitems' => []]);

        $this->assertEquals(0, $button->data['userid']);
        $this->assertSame('', $button->data['main']['foruser']);
    }

    /**
     * An explicit target user is kept and named on the button when somebody else books for them.
     */
    public function test_explicit_other_user_is_kept(): void {
        $target = $this->getDataGenerator()->create_user(['firstname' => 'Tara', 'lastname' => 'Target']);
        $this->setAdminUser();

        $button = new bookit_button(['userid' => $target->id]);

        $this->assertEquals($target->id, $button->data['userid']);
        $this->assertStringContainsString(fullname($target), $button->data['main']['foruser']);
        $this->assertStringContainsString("ID:{$target->id}", $button->data['main']['foruser']);
    }

    /**
     * The condition noshoppingcart returns the user it resolved the price for, and bookit_button
     * keeps it: for the current user (userid 0) as well as for an explicit other user.
     */
    public function test_noshoppingcart_render_button_carries_the_user(): void {
        $settings = $this->create_priced_option();
        $target = $this->getDataGenerator()->create_user();
        $cashier = $this->getDataGenerator()->create_user();
        $this->setUser($cashier);

        $condition = new noshoppingcart();

        // No explicit user: the current user.
        [$template, $data] = $condition->render_button($settings);
        $this->assertSame('mod_booking/col_price', $template);
        $this->assertEquals($cashier->id, $data['userid']);
        $this->assertEquals(50, $data['priceitems']['price']);
        $this->assertEquals($cashier->id, (new bookit_button($data))->data['userid']);

        // Rendered for another user: the button must not silently become the cashier's own.
        [, $data] = $condition->render_button($settings, (int) $target->id);
        $this->assertEquals($target->id, $data['userid']);
        $button = new bookit_button($data);
        $this->assertEquals($target->id, $button->data['userid']);
        $this->assertStringContainsString("ID:{$target->id}", $button->data['main']['foruser']);
    }

    /**
     * An id without a user record (e.g. a deleted user) falls back to the current user. Before,
     * the missing record (false) was passed on to price::get_price(), which threw a TypeError.
     */
    public function test_noshoppingcart_render_button_with_unknown_user(): void {
        global $DB;

        $settings = $this->create_priced_option();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $unknownid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1000;

        [, $data] = (new noshoppingcart())->render_button($settings, $unknownid);

        $this->assertEquals($user->id, $data['userid']);
        $this->assertEquals(50, $data['priceitems']['price']);
        $this->assertEquals($user->id, (new bookit_button($data))->data['userid']);
    }
}

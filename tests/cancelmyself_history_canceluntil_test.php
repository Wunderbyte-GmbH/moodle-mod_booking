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
 * Tests that the cancel button and the cancellation check use the same canceluntil date.
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
use mod_booking\output\bookingoption_description;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that the cancel button and the cancellation check use the same canceluntil date.
 *
 * Shopping cart stores canceluntil in local_shopping_cart_history at checkout and checks that stored
 * value when a purchase is cancelled. The cancel button of mod_booking (cancelmyself) used to
 * look at the CURRENT date of the booking option instead. When the option's date is moved after the
 * purchase, the user sees a cancel button but the cancellation fails with "nopermission".
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cancelmyself_history_canceluntil_test extends booking_advanced_testcase {
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
     * The option's canceluntil was already in the past when the user bought it and is moved into
     * the future afterwards. The cancel button and the cancellation itself have to agree.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     * @covers \local_shopping_cart\shopping_cart::allowed_to_cancel
     * @covers \local_shopping_cart\shopping_cart::cancel_purchase
     */
    public function test_cancel_button_matches_cancellation_after_canceluntil_moved_to_future(): void {
        ['settings' => $settings, 'student' => $student] = $this->create_priced_option(time() - DAYSECS);

        $historyitem = $this->buy_option($settings->id, (int)$student->id);

        // The date stored at checkout is the one which was already expired when buying.
        $this->assertEquals(LOCAL_SHOPPING_CART_PAYMENT_SUCCESS, (int)$historyitem->paymentstatus);
        $this->assertNotEmpty($historyitem->canceluntil);
        $this->assertLessThan(time(), (int)$historyitem->canceluntil, 'Date stored in history is in the past');

        // Afterwards the date of the option is moved into the future.
        $newcanceluntil = time() + 30 * DAYSECS;
        $this->set_option_canceluntil($settings->id, $newcanceluntil);
        $settings = singleton_service::get_instance_of_booking_option_settings($settings->id);
        $this->assertEquals($newcanceluntil, (int)$settings->canceluntil);

        // The history keeps the expired date, we do not touch it.
        $historyitem = shopping_cart_history::get_most_recent_historyitem(
            'mod_booking',
            'option',
            $settings->id,
            $student->id
        );
        $this->assertLessThan(time(), (int)$historyitem->canceluntil);

        $this->setUser($student);

        // What the user sees: is_available() == false means, the cancel button is shown.
        $buttonshown = !(new cancelmyself())->is_available($settings, (int)$student->id);

        // What shopping cart enforces.
        $allowed = shopping_cart::allowed_to_cancel($historyitem->id, $settings->id, 'option', (int)$student->id);

        $this->assertSame(
            $allowed,
            $buttonshown,
            'The cancel button is ' . ($buttonshown ? 'shown' : 'hidden') . ' but shopping cart '
                . ($allowed ? 'allows' : 'denies') . ' the cancellation.'
        );

        // The date valid at the time of booking applies to this user: it has passed, so no cancelling.
        // The later change of the option's date only counts for users who book afterwards.
        $this->assertFalse($buttonshown, 'The date of the booking time has passed: no cancel button.');
        $this->assertFalse($allowed, 'The date of the booking time has passed: cancelling is denied.');

        // The real cancellation has to behave like the button promised.
        ob_start();
        $result = shopping_cart::cancel_purchase(
            $settings->id,
            'option',
            (int)$student->id,
            'mod_booking',
            (int)$historyitem->id,
            0
        );
        ob_end_clean();
        $this->assertSame(
            $buttonshown,
            !empty($result['success']),
            'A visible cancel button must lead to a successful cancellation, a hidden one must not exist.'
        );
    }

    /**
     * Control: canceluntil is in the future when buying and stays there. Button and cancellation
     * agree and the user can cancel. Proves the setup of the test above is sound.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     * @covers \local_shopping_cart\shopping_cart::allowed_to_cancel
     * @covers \local_shopping_cart\shopping_cart::cancel_purchase
     */
    public function test_cancel_button_matches_cancellation_with_future_canceluntil(): void {
        ['settings' => $settings, 'student' => $student] = $this->create_priced_option(time() + 10 * DAYSECS);

        $historyitem = $this->buy_option($settings->id, (int)$student->id);
        $this->assertGreaterThan(time(), (int)$historyitem->canceluntil);

        $this->setUser($student);
        $buttonshown = !(new cancelmyself())->is_available($settings, (int)$student->id);
        $allowed = shopping_cart::allowed_to_cancel($historyitem->id, $settings->id, 'option', (int)$student->id);

        $this->assertTrue($buttonshown, 'The cancel button is shown.');
        $this->assertTrue($allowed, 'Shopping cart allows the cancellation.');

        ob_start();
        $result = shopping_cart::cancel_purchase(
            $settings->id,
            'option',
            (int)$student->id,
            'mod_booking',
            (int)$historyitem->id,
            0
        );
        ob_end_clean();
        $this->assertEquals(1, $result['success']);
    }

    /**
     * The date valid at the time of booking applies to the purchase, even if the date of the option has
     * been moved to the past afterwards (or was never updated): the cancel button follows the date
     * stored in the history of the purchase. This is also what a correction by an admin in the history
     * table relies on.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     * @covers \local_shopping_cart\shopping_cart::allowed_to_cancel
     * @covers \local_shopping_cart\shopping_cart::cancel_purchase
     */
    public function test_history_canceluntil_applies_even_if_option_date_is_in_the_past(): void {
        global $DB;

        $optioncanceluntil = time() - DAYSECS;
        ['settings' => $settings, 'student' => $student] = $this->create_priced_option($optioncanceluntil);

        $historyitem = $this->buy_option($settings->id, (int)$student->id);
        $this->assertLessThan(time(), (int)$historyitem->canceluntil, 'Date stored in history is in the past');

        // The date of the purchase is corrected directly in the history table, the option keeps its date.
        $historycanceluntil = time() + 30 * DAYSECS;
        $DB->set_field('local_shopping_cart_history', 'canceluntil', $historycanceluntil, ['id' => $historyitem->id]);

        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($settings->id);
        $this->assertEquals($optioncanceluntil, (int)$settings->canceluntil);

        $this->setUser($student);

        $allowed = shopping_cart::allowed_to_cancel($historyitem->id, $settings->id, 'option', (int)$student->id);
        $this->assertTrue($allowed, 'Shopping cart allows the cancellation, the history date is in the future.');

        // The date of the purchase is in the future: the cancel button is shown (is_available() == false).
        $this->assertFalse(
            (new cancelmyself())->is_available($settings, (int)$student->id),
            'The cancel button is shown, the date of the purchase has not passed.'
        );

        ob_start();
        $result = shopping_cart::cancel_purchase(
            $settings->id,
            'option',
            (int)$student->id,
            'mod_booking',
            (int)$historyitem->id,
            0
        );
        ob_end_clean();
        $this->assertEquals(1, $result['success']);
    }

    /**
     * The whole story of the ticket: A buys, does not cancel, the date of the option passes and is then
     * moved into the future. A keeps the date from the time of booking: no cancel button and no
     * cancellation. B, who books after the change, gets the new date and can cancel.
     *
     * @covers \mod_booking\bo_availability\conditions\cancelmyself::is_available
     * @covers \local_shopping_cart\shopping_cart::allowed_to_cancel
     * @covers \local_shopping_cart\shopping_cart::cancel_purchase
     */
    public function test_date_of_booking_time_applies_per_user_when_option_date_is_extended(): void {
        $start = time();
        ['settings' => $settings, 'student' => $usera, 'student2' => $userb]
            = $this->create_priced_option($start + DAYSECS);

        // A buys while the date is still in the future and could cancel.
        $historya = $this->buy_option($settings->id, (int)$usera->id);
        $this->assertEquals($start + DAYSECS, (int)$historya->canceluntil);
        [$buttonshown, $allowed] = $this->cancel_state($settings->id, $usera, (int)$historya->id);
        $this->assertTrue($buttonshown, 'A sees the cancel button before the date has passed.');
        $this->assertTrue($allowed, 'A is allowed to cancel before the date has passed.');

        // The date passes, A did not cancel.
        time_mock::set_mock_time($start + 2 * DAYSECS);
        [$buttonshown, $allowed] = $this->cancel_state($settings->id, $usera, (int)$historya->id);
        $this->assertFalse($buttonshown, 'No cancel button after the date has passed.');
        $this->assertFalse($allowed, 'No cancellation after the date has passed.');

        // The date of the option is set into the future.
        $newcanceluntil = $start + 30 * DAYSECS;
        $this->set_option_canceluntil($settings->id, $newcanceluntil);

        // A keeps the date of the booking time: still no button, still no cancellation.
        [$buttonshown, $allowed] = $this->cancel_state($settings->id, $usera, (int)$historya->id);
        $this->assertFalse($buttonshown, 'A must not see a cancel button: the date of A\'s booking has passed.');
        $this->assertFalse($allowed, 'A must not be allowed to cancel: the date of A\'s booking has passed.');

        // B books after the change and gets the new date.
        $historyb = $this->buy_option($settings->id, (int)$userb->id);
        $this->assertEquals($newcanceluntil, (int)$historyb->canceluntil);
        [$buttonshown, $allowed] = $this->cancel_state($settings->id, $userb, (int)$historyb->id);
        $this->assertTrue($buttonshown, 'B sees the cancel button, the new date applies to B.');
        $this->assertTrue($allowed, 'B is allowed to cancel.');

        // B booking does not change anything for A.
        [$buttonshown, $allowed] = $this->cancel_state($settings->id, $usera, (int)$historya->id);
        $this->assertFalse($buttonshown);
        $this->assertFalse($allowed);

        // A real cancellation: denied for A, successful for B.
        $this->setUser($usera);
        ob_start();
        $result = shopping_cart::cancel_purchase(
            $settings->id,
            'option',
            (int)$usera->id,
            'mod_booking',
            (int)$historya->id,
            0
        );
        ob_end_clean();
        $this->assertEmpty($result['success'], 'The cancellation is denied for A.');

        $this->setUser($userb);
        ob_start();
        $result = shopping_cart::cancel_purchase(
            $settings->id,
            'option',
            (int)$userb->id,
            'mod_booking',
            (int)$historyb->id,
            0
        );
        ob_end_clean();
        $this->assertEquals(1, $result['success'], 'The cancellation works for B.');
    }

    /**
     * Same story as above, but for the info "cancelling possible until" in the option description:
     * every user is shown the date which applies to them.
     *
     * @covers \mod_booking\output\bookingoption_description::get_returnarray
     */
    public function test_description_shows_date_of_booking_time_per_user(): void {
        $start = time();
        ['settings' => $settings, 'student' => $usera, 'student2' => $userb]
            = $this->create_priced_option($start + DAYSECS);

        $historya = $this->buy_option($settings->id, (int)$usera->id);
        $this->assertEquals($start + DAYSECS, (int)$historya->canceluntil);

        time_mock::set_mock_time($start + 2 * DAYSECS);
        $newcanceluntil = $start + 30 * DAYSECS;
        $this->set_option_canceluntil($settings->id, $newcanceluntil);

        $historyb = $this->buy_option($settings->id, (int)$userb->id);
        $this->assertEquals($newcanceluntil, (int)$historyb->canceluntil);

        $format = get_string('strftimedatetime', 'langconfig');

        // A is shown the date of the booking time and that cancelling is not possible anymore.
        $data = $this->description_data($settings->id, $usera);
        $this->assertSame(userdate($start + DAYSECS, $format), $data['canceluntil']);
        $this->assertEmpty($data['canstillbecancelled'], 'A cannot cancel anymore.');

        // B is shown the new date and that cancelling is possible.
        $data = $this->description_data($settings->id, $userb);
        $this->assertSame(userdate($newcanceluntil, $format), $data['canceluntil']);
        $this->assertNotEmpty($data['canstillbecancelled'], 'B can still cancel.');
    }

    /**
     * Returns what the cancel button shows and what shopping cart allows for the given user.
     *
     * @param int $optionid
     * @param stdClass $user
     * @param int $historyid
     * @return array [bool button is shown, bool shopping cart allows cancelling]
     */
    private function cancel_state(int $optionid, stdClass $user, int $historyid): array {
        $this->setUser($user);
        singleton_service::destroy_booking_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // The boolean is_available() == false means, the cancel button is shown.
        $buttonshown = !(new cancelmyself())->is_available($settings, (int)$user->id);
        $allowed = shopping_cart::allowed_to_cancel($historyid, $optionid, 'option', (int)$user->id);

        return [$buttonshown, $allowed];
    }

    /**
     * Returns the data of the option description as it is rendered for the given user.
     *
     * @param int $optionid
     * @param stdClass $user
     * @return array
     */
    private function description_data(int $optionid, stdClass $user): array {
        $this->setUser($user);
        singleton_service::destroy_booking_answers($optionid);

        return (new bookingoption_description(
            $optionid,
            null,
            MOD_BOOKING_DESCRIPTION_WEBSITE,
            true,
            null,
            $user
        ))->get_returnarray();
    }

    /**
     * Creates a booking with one priced option which has the given canceluntil date.
     *
     * @param int $canceluntil
     * @return array settings, student, student2 (a second user who books later)
     */
    private function create_priced_option(int $canceluntil): array {
        set_config('cancelationfee', 0, 'local_shopping_cart');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = self::provide_bdata();
        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;
        $bdata['cancancelbook'] = 1;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
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
        $record->text = 'Option with canceluntil';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 5;
        $record->useprice = 1;
        $record->importing = 1;
        // The start of the course is far away, so only the fixed canceluntil date matters.
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('+ 1 year', time());
        $record->courseendtime_0 = strtotime('+ 1 year + 2 hours', time());
        $record->canceluntilcheckbox = 1;
        $record->canceluntil = $canceluntil;
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $this->assertEquals($canceluntil, (int)$settings->canceluntil, 'The option has the canceluntil date we set.');

        return ['settings' => $settings, 'student' => $student, 'student2' => $student2];
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
        $this->assertNotEmpty($historyitem, 'The purchase is stored in shopping cart history.');
        return $historyitem;
    }

    /**
     * Changes the canceluntil date of the option directly in its json, like an editor saving the form.
     *
     * @param int $optionid
     * @param int $canceluntil
     */
    private function set_option_canceluntil(int $optionid, int $canceluntil): void {
        global $DB;

        $json = json_decode((string)$DB->get_field('booking_options', 'json', ['id' => $optionid]) ?: '{}');
        $json->canceluntil = $canceluntil;
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
    }

    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test Booking canceluntil',
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

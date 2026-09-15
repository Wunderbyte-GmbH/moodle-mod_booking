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
 * Tests that the booked / waitinglist button labels carry the user's price when the shopping cart is installed.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests that the booked / waitinglist button labels carry the user's price when the shopping cart is installed.
 *
 * With a price and the shopping cart, a not yet booked user sees the add-to-cart price button. Once booked
 * or on the waitinglist the button only said "Booked" / "You are on the waiting list"; now the label carries
 * the price for the user ("Booked (Price: 100.00 EUR)"), but only for regular users, only when the price
 * for that user is greater than 0. The separate price line under the button is left untouched.
 */
final class bookit_button_price_in_label_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }
    }

    /**
     * Creates a booking instance with one priced option and returns [$option, $course, $defaultprice].
     *
     * @param int $maxanswers
     * @param int $maxoverbooking
     * @param float $defaultprice
     * @return array
     */
    private function create_priced_option(int $maxanswers, int $maxoverbooking, float $defaultprice): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Price in label',
            'eventtype' => 'Test event',
        ]);

        $this->setAdminUser();
        // The "Booked" label is replaced by a course link when this setting is on; the label test wants the label.
        set_config('linktomoodlecourseonbookedbutton', 0, 'booking');
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object) [
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => $defaultprice,
            'pricecatsortorder' => 1,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Priced option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = $maxanswers;
        $record->maxoverbooking = $maxoverbooking;
        $record->useprice = 1;
        $record->importing = 1;
        $option = $plugingenerator->create_option($record);

        return [$option, $course];
    }

    /**
     * Renders the button for the given user and returns the main label plus whether a sub line exists.
     *
     * @param int $optionid
     * @param int $userid
     * @return array [string $label, bool $hassub]
     */
    private function render_label(int $optionid, int $userid): array {
        singleton_service::destroy_booking_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        [, $datas] = booking_bookit::render_bookit_template_data($settings, $userid, true, '');
        $labels = [];
        $hassub = false;
        foreach ($datas as $data) {
            if (!empty($data->data['main']['label'])) {
                $labels[] = (string)$data->data['main']['label'];
            }
            if (!empty($data->data['sub'])) {
                $hassub = true;
            }
        }
        return [implode(' | ', $labels), $hassub];
    }

    /**
     * A booked user sees the price in the "Booked" label, admins and zero prices do not.
     *
     * @covers \mod_booking\bo_availability\bo_info::render_button
     */
    public function test_booked_label_carries_price_for_regular_user_only(): void {
        [$option, $course] = $this->create_priced_option(10, 0, 100);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $bookingoption->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $bookedlabel = get_string('bocondalreadybookednotavailable', 'mod_booking');
        $expected = get_string('buttonlabelwithprice', 'mod_booking', [
            'label' => $bookedlabel,
            'price' => '100.00 EUR',
        ]);

        // The student sees the price inside the label.
        $this->setUser($student);
        [$label] = $this->render_label($option->id, $student->id);
        $this->assertStringContainsString($expected, $label);

        // Someone who may book for others keeps the plain label.
        $this->setAdminUser();
        [$label] = $this->render_label($option->id, $student->id);
        $this->assertStringContainsString($bookedlabel, $label);
        $this->assertStringNotContainsString('Price', $label);
    }

    /**
     * A price of 0 leaves the label untouched.
     *
     * @covers \mod_booking\bo_availability\bo_info::render_button
     */
    public function test_booked_label_stays_plain_when_price_is_zero(): void {
        [$option, $course] = $this->create_priced_option(10, 0, 0);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $bookingoption->user_submit_response($student, 0, 0, 0, MOD_BOOKING_VERIFIED);

        $this->setUser($student);
        [$label] = $this->render_label($option->id, $student->id);
        $this->assertStringContainsString(get_string('bocondalreadybookednotavailable', 'mod_booking'), $label);
        $this->assertStringNotContainsString('Price', $label);
    }

    /**
     * A user on the waitinglist sees the price in the waitinglist label and still gets the price line below.
     *
     * @covers \mod_booking\bo_availability\bo_info::render_button
     */
    public function test_waitinglist_label_carries_price(): void {
        [$option, $course] = $this->create_priced_option(1, 1, 100);
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $bookingoption->user_submit_response($student1, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $bookingoption->user_submit_response($student2, 0, 0, 0, MOD_BOOKING_VERIFIED);

        singleton_service::destroy_booking_answers($option->id);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertSame(MOD_BOOKING_STATUSPARAM_WAITINGLIST, $answers->user_status($student2->id));

        $expected = get_string('buttonlabelwithprice', 'mod_booking', [
            'label' => get_string('bocondonwaitinglistnotavailable', 'mod_booking'),
            'price' => '100.00 EUR',
        ]);

        $this->setUser($student2);
        [$label, $hassub] = $this->render_label($option->id, $student2->id);
        $this->assertStringContainsString($expected, $label);
        $this->assertTrue($hassub, 'The price line under the waitinglist button must stay.');
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }
}

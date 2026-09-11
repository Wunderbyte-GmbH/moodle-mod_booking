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
 * Tests the response contract of the two-step booking confirmation.
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
 * Tests the response contract of the two-step booking confirmation.
 *
 * The first click does not book: it arms the confirmation cache, and the button has to be
 * re-rendered so it can say "click again". The client tells that apart from a genuine no-op by the
 * message, so the message is a contract and belongs in a test - the regression it guards against
 * (Wunderbyte-GmbH/Wunderbyte-GmbH#2306) turned ~75% of the behat suite red while every PHPUnit
 * test stayed green, because nothing pinned this return value.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookit_two_step_confirmation_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * The first click arms the confirmation and says so, the second one books.
     *
     * @covers \mod_booking\booking_bookit::bookit
     */
    public function test_first_click_arms_the_confirmation_and_reports_it(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Two step booking',
            'eventtype' => 'Test event',
        ]);

        $this->setAdminUser();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Two step option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->maxanswers = 10;
        $record->importing = 1;
        $option = $plugingenerator->create_option($record);

        $this->setUser($user);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // First click: nothing is booked yet, but the confirmation is armed - and the answer must
        // say so, otherwise the client cannot know that the button changed.
        $first = booking_bookit::bookit('option', $settings->id, $user->id);
        $this->assertSame(0, (int)$first['status']);
        $this->assertSame(
            'confirmationarmed',
            $first['message'],
            'The first click must be distinguishable from a no-op, or the button is never re-rendered.'
        );

        singleton_service::destroy_booking_answers($settings->id);
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_NOTBOOKED,
            $bookinganswers->user_status($user->id),
            'The first click must not book yet.'
        );

        // What the re-render produces is the confirmation label.
        [, $datas] = booking_bookit::render_bookit_template_data($settings, $user->id, true, '');
        $labels = implode(' | ', array_map(fn($data) => (string)($data->data['main']['label'] ?? ''), $datas));
        $this->assertStringContainsString(get_string('areyousure:book', 'mod_booking'), $labels);

        // Second click: now it books.
        $second = booking_bookit::bookit('option', $settings->id, $user->id);
        $this->assertSame(1, (int)$second['status']);
        $this->assertSame('booked', $second['message']);

        singleton_service::destroy_booking_answers($settings->id);
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $this->assertSame(
            MOD_BOOKING_STATUSPARAM_BOOKED,
            $bookinganswers->user_status($user->id),
            'The second click must book.'
        );
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

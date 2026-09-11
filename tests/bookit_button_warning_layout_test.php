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
 * Warning of a blocking availability condition next to the book button.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\tests\booking_advanced_testcase;
use stdClass;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/booking_advanced_testcase.php');

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Renders the real bookit button chain for a user who has reached the maximum number of bookings
 * while being allowed to book anyway (bookforothers): the condition warning is merged into the
 * "top" area of the book button and has to be rendered ABOVE the button, never next to it.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookit_button_warning_layout_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        singleton_service::destroy_instance();
    }

    /**
     * Max number of bookings reached: the warning is stacked above the book button.
     *
     * @covers \mod_booking\booking_bookit::render_bookit_button
     * @covers \mod_booking\bo_availability\conditions\max_number_of_bookings
     *
     * @return void
     */
    public function test_maxperuser_warning_is_rendered_above_book_button(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $bdata = [
            'name' => 'Warning layout test',
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
            'maxperuser' => 1,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($USER->id, $course->id, 'manager');

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $teacher->username;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $options = [];
        foreach (['first option', 'second option'] as $i => $text) {
            $record = new stdClass();
            $record->bookingid = $booking->id;
            $record->text = $text;
            $record->maxanswers = 5;
            $record->chooseorcreatecourse = 1;
            $record->courseid = $course->id;
            $record->description = 'warning layout test';
            $record->optiondateid_0 = "0";
            $record->daystonotify_0 = "0";
            $record->coursestarttime_0 = strtotime('20 June 2050 15:00') + $i * DAYSECS;
            $record->courseendtime_0 = strtotime('20 June 2050 17:00') + $i * DAYSECS;
            $record->useprice = 0;
            $record->importing = 1;
            $options[] = $plugingenerator->create_option($record);
        }
        singleton_service::destroy_instance();

        // The admin books the first option, so the limit of one booking per user is reached.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($options[0]->id);
        $option1 = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $option1->user_submit_response($USER, 0, 0, 0, MOD_BOOKING_VERIFIED);
        singleton_service::destroy_instance();

        $settings2 = singleton_service::get_instance_of_booking_option_settings($options[1]->id);
        $results = bo_info::get_condition_results($settings2->id, $USER->id);
        $this->assertContains(
            MOD_BOOKING_BO_COND_MAX_NUMBER_OF_BOOKINGS,
            array_keys($results),
            'The max number of bookings condition has to block the second option for this user.'
        );

        $html = booking_bookit::render_bookit_button($settings2, $USER->id);

        $toppos = strpos($html, 'booking-button-toparea');
        $mainpos = strpos($html, 'booking-button-mainarea');
        $this->assertNotFalse($toppos, 'The warning of the condition is missing: ' . $html);
        $this->assertNotFalse($mainpos, 'The book button for a user with bookforothers is missing: ' . $html);
        $this->assertLessThan($mainpos, $toppos, 'The warning has to be rendered before the book button.');

        // Both live in the same button area, which is an explicit vertical flex column: plugin css
        // (e.g. local_urise) sets display:flex on it and laid the warning out left of the button.
        $areapos = strrpos(substr($html, 0, $toppos), '<div class="booking-button-area');
        $this->assertNotFalse($areapos, 'The warning must sit inside a booking-button-area wrapper.');
        $areatag = substr($html, $areapos, strpos($html, '>', $areapos) - $areapos);
        $this->assertMatchesRegularExpression(
            '/class="[^"]*\bd-flex\b[^"]*\bflex-column\b[^"]*"/',
            $areatag,
            'The booking-button-area holding warning and button must be a vertical flex column: ' . $areatag
        );
        $this->assertSame(
            false,
            strpos(substr($html, $areapos + 1, $mainpos - $areapos - 1), '<div class="booking-button-area'),
            'Warning and book button must share one booking-button-area.'
        );
    }
}

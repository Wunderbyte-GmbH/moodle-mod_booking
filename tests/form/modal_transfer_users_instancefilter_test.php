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
 * Tests for the booking instance filter of the transfer users modal.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\form\modal_transfer_users;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The filter lists all booking instances plus "All booking instances", is preset to the instance of
 * the source option, is wired to the target option search and keeps a changed value on re-rendering.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\form\modal_transfer_users::definition
 */
final class modal_transfer_users_instancefilter_test extends \advanced_testcase {
    /**
     * Common setup: fresh DB state and no cached option settings between tests.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * The instance of the source option is preselected; the other instance and "all" are offered.
     */
    public function test_current_instance_is_preselected(): void {
        $this->setAdminUser();
        [$booking, $option, $course] = $this->create_option('Filter instance A');
        [$otherbooking] = $this->create_option('Filter instance B');

        $html = $this->render_form($booking, $option);

        $choices = $this->filter_choices($html);
        $this->assertSame(get_string('transferallbookinginstances', 'mod_booking'), $choices[0]['label']);
        $this->assertSame('Filter instance A (' . $course->shortname . ')', $choices[(int)$booking->id]['label']);
        $this->assertArrayHasKey((int)$otherbooking->id, $choices);

        $this->assertTrue($choices[(int)$booking->id]['selected']);
        $this->assertFalse($choices[0]['selected']);
        $this->assertFalse($choices[(int)$otherbooking->id]['selected']);

        // The target option search reads the live value of the filter.
        $this->assertStringContainsString('data-bookingidselect="bookinginstancefilter"', $html);
    }

    /**
     * "All booking instances" or another instance chosen by the user survives the re-rendering of the form.
     */
    public function test_changed_filter_is_kept(): void {
        $this->setAdminUser();
        [$booking, $option] = $this->create_option('Filter instance A');
        [$otherbooking] = $this->create_option('Filter instance B');

        $choices = $this->filter_choices($this->render_form($booking, $option, ['bookinginstancefilter' => 0]));
        $this->assertTrue($choices[0]['selected']);
        $this->assertFalse($choices[(int)$booking->id]['selected']);

        $choices = $this->filter_choices(
            $this->render_form($booking, $option, ['bookinginstancefilter' => (int)$otherbooking->id])
        );
        $this->assertTrue($choices[(int)$otherbooking->id]['selected']);
        $this->assertFalse($choices[(int)$booking->id]['selected']);
    }

    /**
     * The value the filter sends restricts the search to that instance; 0 searches all instances as before.
     */
    public function test_search_follows_filter_value(): void {
        $this->setAdminUser();
        [$booking, $option] = $this->create_option('Filter instance A');
        [$otherbooking, $otheroption] = $this->create_option('Filter instance B');

        $result = booking_option::load_booking_options_filtered('Transfer', (int)$booking->id);
        $this->assertSame([(int)$option->id], array_map('intval', array_keys($result['list'])));

        $result = booking_option::load_booking_options_filtered('Transfer', (int)$otherbooking->id);
        $this->assertSame([(int)$otheroption->id], array_map('intval', array_keys($result['list'])));

        $result = booking_option::load_booking_options_filtered('Transfer', 0);
        $this->assertEqualsCanonicalizing(
            [(int)$option->id, (int)$otheroption->id],
            array_map('intval', array_keys($result['list']))
        );
    }

    /**
     * Helper: the options of the filter select as [booking instance id => ['label' => ..., 'selected' => bool]].
     *
     * @param string $html
     * @return array<int, array{label: string, selected: bool}>
     */
    private function filter_choices(string $html): array {
        $found = preg_match('/<select[^>]*name="bookinginstancefilter"[^>]*>(.*?)<\/select>/s', $html, $select);
        $this->assertSame(1, $found, 'The booking instance filter must be rendered.');

        preg_match_all('/<option\s+value="(\d+)"([^>]*)>(.*?)<\/option>/s', $select[1], $options, PREG_SET_ORDER);
        $choices = [];
        foreach ($options as $option) {
            $choices[(int)$option[1]] = [
                'label' => trim(html_entity_decode($option[3])),
                'selected' => str_contains($option[2], 'selected'),
            ];
        }
        return $choices;
    }

    /**
     * Helper: render the modal for the given option with optional extra submit data.
     *
     * @param stdClass $booking
     * @param stdClass $option
     * @param array $extra
     * @return string
     */
    private function render_form(stdClass $booking, stdClass $option, array $extra = []): string {
        $submitdata = modal_transfer_users::mock_ajax_submit(array_merge([
            'cmid' => (int)$booking->cmid,
            'optionid' => (int)$option->id,
            'checkedids' => '',
        ], $extra));
        $form = new modal_transfer_users(null, null, 'post', '', [], true, $submitdata, true);
        $form->set_data_for_dynamic_submission();
        return $form->render();
    }

    /**
     * Helper: one booking instance (in a course of its own) with one option.
     *
     * @param string $name Name of the booking instance.
     * @return array{0: stdClass, 1: stdClass, 2: stdClass}
     */
    private function create_option(string $name): array {
        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => $name,
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Transfer option of ' . $name;
        $record->useprice = 0;
        $record->maxanswers = 5;
        $option = $plugingenerator->create_option($record);

        return [$booking, $option, $course];
    }
}

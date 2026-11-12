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
 * Tests for the "Only future booking options" checkbox of the transfer users modal.
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
 * The checkbox is always free to change and wired to the autocomplete; the site setting
 * booking/transferonlyfuture only decides whether it is ticked when the dialog opens.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\form\modal_transfer_users::definition
 */
final class modal_transfer_users_onlyfuture_test extends \advanced_testcase {
    /**
     * Common setup: fresh DB state and no cached option settings between tests.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Setting off: the checkbox is unticked, enabled and wired to the autocomplete.
     */
    public function test_checkbox_is_free_by_default(): void {
        $this->setAdminUser();
        [$booking, $option] = $this->create_option();

        $html = $this->render_form($booking, $option);

        $checkbox = $this->checkbox_tag($html);
        $this->assertStringNotContainsString('checked', $checkbox);
        $this->assertStringNotContainsString('disabled', $checkbox);
        $this->assertStringContainsString('data-onlyfuturecheckbox="onlyfuture"', $html);
        $this->assertStringNotContainsString('data-onlyfuture="1"', $html);

        // A ticked checkbox survives the re-rendering of the dynamic form (e.g. with warnings).
        $html = $this->render_form($booking, $option, ['onlyfuture' => 1]);
        $this->assertStringContainsString('checked', $this->checkbox_tag($html));
    }

    /**
     * Setting on: the checkbox is ticked when the dialog opens, but stays enabled and wired to the autocomplete.
     */
    public function test_setting_ticks_checkbox_by_default(): void {
        set_config('transferonlyfuture', 1, 'booking');
        $this->setAdminUser();
        [$booking, $option] = $this->create_option();

        $html = $this->render_form($booking, $option);

        $checkbox = $this->checkbox_tag($html);
        $this->assertStringContainsString('checked', $checkbox);
        $this->assertStringNotContainsString('disabled', $checkbox);
        // The search follows the live state of the checkbox, there is no static restriction any more.
        $this->assertStringContainsString('data-onlyfuturecheckbox="onlyfuture"', $html);
        $this->assertStringNotContainsString('data-onlyfuture="1"', $html);
        // Not frozen: no hidden input that would submit a constant 1.
        $this->assertDoesNotMatchRegularExpression('/<input type="hidden" name="onlyfuture" value="1">/', $html);
    }

    /**
     * Setting on: a user who unticks the box keeps it unticked when the form is re-rendered (e.g. with warnings).
     */
    public function test_setting_does_not_override_unticked_checkbox(): void {
        set_config('transferonlyfuture', 1, 'booking');
        $this->setAdminUser();
        [$booking, $option] = $this->create_option();

        $html = $this->render_form($booking, $option, ['onlyfuture' => 0]);

        $checkbox = $this->checkbox_tag($html);
        $this->assertStringNotContainsString('checked', $checkbox);
        $this->assertStringNotContainsString('disabled', $checkbox);
    }

    /**
     * Helper: the rendered checkbox tag of the onlyfuture element (spans several lines in the template).
     *
     * @param string $html
     * @return string
     */
    private function checkbox_tag(string $html): string {
        $found = preg_match('/<input\s+type="checkbox"[^>]*name="onlyfuture"[^>]*>/', $html, $matches);
        $this->assertSame(1, $found, 'The onlyfuture checkbox must be rendered.');
        return $matches[0];
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
     * Helper: one booking instance with one option.
     *
     * @return array{0: stdClass, 1: stdClass, 2: stdClass}
     */
    private function create_option(): array {
        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Transfer onlyfuture test booking',
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
        $record->text = 'Transfer source option';
        $record->useprice = 0;
        $record->maxanswers = 5;
        $option = $plugingenerator->create_option($record);

        return [$booking, $option, $course];
    }
}

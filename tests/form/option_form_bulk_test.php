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
 * Unit tests for option_form_bulk: bulk saving of fields on booking options and templates.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\form\option_form_bulk;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for option_form_bulk – saving fields in bulk on booking options and templates.
 *
 * Covers:
 *  - Bulk-updating description, bookingopeningtime, and beforecompletedtext on regular options.
 *  - Bulk-updating those same fields on booking option templates (bookingid = 0), verifying
 *    that templates keep their template status (bookingid stays 0) after the bulk save.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class option_form_bulk_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /* -----------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------------- */

    /**
     * Create a course and a booking instance, return the booking record.
     *
     * @return array [booking]
     */
    private function create_booking_setup(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setAdminUser();

        $bdata = [
            'name' => 'Test Bulk Booking',
            'course' => $course->id,
            'bookingmanager' => $user->username,
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ];

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        return [$booking];
    }

    /**
     * Create three booking options in the given booking, return their IDs.
     *
     * @param object $booking
     * @return int[]
     */
    private function create_three_options(object $booking): array {
        /** @var mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $record = new stdClass();
            $record->bookingid = $booking->id;
            $record->text = "Bulk Test Option $i";
            $record->description = 'Initial description';
            $record->maxanswers = 5;
            $option = $gen->create_option($record);
            $ids[] = (int)$option->id;
        }
        return $ids;
    }

    /**
     * Create three booking option templates (bookingid = 0) in the given booking context, return their IDs.
     *
     * @param object $booking used only for context (cmid); created templates have bookingid = 0.
     * @return int[]
     */
    private function create_three_templates(object $booking): array {
        /** @var mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $template = $gen->create_template([
                'bookingid' => $booking->id,
                'templatename' => "Bulk Template $i",
                'text' => "Bulk Template $i",
                'description' => 'Initial template description',
            ]);
            $ids[] = (int)$template->id;
        }
        return $ids;
    }

    /* -----------------------------------------------------------------------
     * Tests – regular booking options
     * ----------------------------------------------------------------------- */

    /**
     * Bulk-updating the description field applies to all three selected booking options.
     *
     * @covers \mod_booking\form\option_form_bulk::save_options
     * @covers \mod_booking\option\fields\description::prepare_save_field
     */
    public function test_bulk_update_description_on_booking_options(): void {
        global $DB;

        [$booking] = $this->create_booking_setup();
        $optionids = $this->create_three_options($booking);

        $fielddata = new stdClass();
        $fielddata->description = ['text' => 'Bulk updated description', 'format' => FORMAT_HTML];

        option_form_bulk::save_options($fielddata, $optionids);

        foreach ($optionids as $optionid) {
            $record = $DB->get_record('booking_options', ['id' => $optionid]);
            $this->assertEquals(
                'Bulk updated description',
                $record->description,
                "Option $optionid: description was not updated by bulk operation."
            );
            // Sanity: still a regular option (bookingid > 0).
            $this->assertGreaterThan(0, (int)$record->bookingid, "Option $optionid: bookingid must be > 0.");
        }
    }

    /**
     * Bulk-updating the booking opening time applies to all three selected booking options.
     *
     * @covers \mod_booking\form\option_form_bulk::save_options
     * @covers \mod_booking\option\fields\easy_bookingopeningtime::prepare_save_field
     */
    public function test_bulk_update_bookingopeningtime_on_booking_options(): void {
        global $DB;

        [$booking] = $this->create_booking_setup();
        $optionids = $this->create_three_options($booking);

        $opentime = strtotime('+7 days');
        $fielddata = new stdClass();
        $fielddata->restrictanswerperiodopening = 1;
        $fielddata->bookingopeningtime = $opentime;

        option_form_bulk::save_options($fielddata, $optionids);

        foreach ($optionids as $optionid) {
            $record = $DB->get_record('booking_options', ['id' => $optionid]);
            $this->assertEquals(
                $opentime,
                (int)$record->bookingopeningtime,
                "Option $optionid: bookingopeningtime was not updated by bulk operation."
            );
            $this->assertGreaterThan(0, (int)$record->bookingid, "Option $optionid: bookingid must be > 0.");
        }
    }

    /**
     * Bulk-updating the beforecompletedtext field applies to all three selected booking options.
     *
     * @covers \mod_booking\form\option_form_bulk::save_options
     * @covers \mod_booking\option\fields\beforecompletedtext::prepare_save_field
     */
    public function test_bulk_update_beforecompletedtext_on_booking_options(): void {
        global $DB;

        [$booking] = $this->create_booking_setup();
        $optionids = $this->create_three_options($booking);

        $fielddata = new stdClass();
        $fielddata->beforecompletedtext = ['text' => 'Bulk completed text', 'format' => FORMAT_HTML];

        option_form_bulk::save_options($fielddata, $optionids);

        foreach ($optionids as $optionid) {
            $record = $DB->get_record('booking_options', ['id' => $optionid]);
            $this->assertEquals(
                'Bulk completed text',
                $record->beforecompletedtext,
                "Option $optionid: beforecompletedtext was not updated by bulk operation."
            );
            $this->assertGreaterThan(0, (int)$record->bookingid, "Option $optionid: bookingid must be > 0.");
        }
    }

    /* -----------------------------------------------------------------------
     * Tests – booking option templates (bookingid = 0)
     * ----------------------------------------------------------------------- */

    /**
     * Bulk-updating description on templates updates the field AND keeps bookingid = 0.
     *
     * @covers \mod_booking\form\option_form_bulk::save_options
     * @covers \mod_booking\option\fields\description::prepare_save_field
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     */
    public function test_bulk_update_description_on_templates(): void {
        global $DB;

        [$booking] = $this->create_booking_setup();
        $templateids = $this->create_three_templates($booking);

        // Verify templates were created correctly.
        foreach ($templateids as $id) {
            $this->assertEquals(0, (int)$DB->get_field('booking_options', 'bookingid', ['id' => $id]));
        }

        $fielddata = new stdClass();
        $fielddata->description = ['text' => 'Template bulk description', 'format' => FORMAT_HTML];

        option_form_bulk::save_options($fielddata, $templateids);

        foreach ($templateids as $id) {
            $record = $DB->get_record('booking_options', ['id' => $id]);
            $this->assertEquals(
                'Template bulk description',
                $record->description,
                "Template $id: description was not updated by bulk operation."
            );
            // The critical assertion: template must remain a template.
            $this->assertEquals(
                0,
                (int)$record->bookingid,
                "Template $id: bookingid must stay 0 after bulk update (template status must be preserved)."
            );
        }
    }

    /**
     * Bulk-updating bookingopeningtime on templates updates the field AND keeps bookingid = 0.
     *
     * @covers \mod_booking\form\option_form_bulk::save_options
     * @covers \mod_booking\option\fields\easy_bookingopeningtime::prepare_save_field
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     */
    public function test_bulk_update_bookingopeningtime_on_templates(): void {
        global $DB;

        [$booking] = $this->create_booking_setup();
        $templateids = $this->create_three_templates($booking);

        $opentime = strtotime('+14 days');
        $fielddata = new stdClass();
        $fielddata->restrictanswerperiodopening = 1;
        $fielddata->bookingopeningtime = $opentime;

        option_form_bulk::save_options($fielddata, $templateids);

        foreach ($templateids as $id) {
            $record = $DB->get_record('booking_options', ['id' => $id]);
            $this->assertEquals(
                $opentime,
                (int)$record->bookingopeningtime,
                "Template $id: bookingopeningtime was not updated by bulk operation."
            );
            $this->assertEquals(
                0,
                (int)$record->bookingid,
                "Template $id: bookingid must stay 0 after bulk update (template status must be preserved)."
            );
        }
    }

    /**
     * Bulk-updating beforecompletedtext on templates updates the field AND keeps bookingid = 0.
     *
     * @covers \mod_booking\form\option_form_bulk::save_options
     * @covers \mod_booking\option\fields\beforecompletedtext::prepare_save_field
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     */
    public function test_bulk_update_beforecompletedtext_on_templates(): void {
        global $DB;

        [$booking] = $this->create_booking_setup();
        $templateids = $this->create_three_templates($booking);

        $fielddata = new stdClass();
        $fielddata->beforecompletedtext = ['text' => 'Template completed text', 'format' => FORMAT_HTML];

        option_form_bulk::save_options($fielddata, $templateids);

        foreach ($templateids as $id) {
            $record = $DB->get_record('booking_options', ['id' => $id]);
            $this->assertEquals(
                'Template completed text',
                $record->beforecompletedtext,
                "Template $id: beforecompletedtext was not updated by bulk operation."
            );
            $this->assertEquals(
                0,
                (int)$record->bookingid,
                "Template $id: bookingid must stay 0 after bulk update (template status must be preserved)."
            );
        }
    }

    /**
     * Applying multiple sequential bulk updates to templates must never convert a template
     * into a regular booking option (bookingid must remain 0 after every update).
     *
     * @covers \mod_booking\form\option_form_bulk::save_options
     * @covers \mod_booking\option\fields\addastemplate::prepare_save_field
     */
    public function test_templates_stay_templates_after_multiple_bulk_updates(): void {
        global $DB;

        [$booking] = $this->create_booking_setup();
        $templateids = $this->create_three_templates($booking);

        // First bulk update: description.
        option_form_bulk::save_options((object)[
            'description' => ['text' => 'First bulk update', 'format' => FORMAT_HTML],
        ], $templateids);

        // Second bulk update: opening time.
        $opentime = strtotime('+3 days');
        option_form_bulk::save_options((object)[
            'restrictanswerperiodopening' => 1,
            'bookingopeningtime' => $opentime,
        ], $templateids);

        // Third bulk update: beforecompletedtext.
        option_form_bulk::save_options((object)[
            'beforecompletedtext' => ['text' => 'Final text', 'format' => FORMAT_HTML],
        ], $templateids);

        foreach ($templateids as $id) {
            $record = $DB->get_record('booking_options', ['id' => $id]);

            $this->assertEquals(
                'First bulk update',
                $record->description,
                "Template $id: description not set correctly."
            );
            $this->assertEquals(
                $opentime,
                (int)$record->bookingopeningtime,
                "Template $id: bookingopeningtime not set correctly."
            );
            $this->assertEquals(
                'Final text',
                $record->beforecompletedtext,
                "Template $id: beforecompletedtext not set correctly."
            );

            // Core invariant: still a template after all updates.
            $this->assertEquals(
                0,
                (int)$record->bookingid,
                "Template $id: bookingid must remain 0 after all bulk updates."
            );
        }
    }
}

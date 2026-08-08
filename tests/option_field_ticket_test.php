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
 * Tests for the ticketing section of the booking option form.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\ticket\ticket_manager;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Test that the ticketing configuration is stored in and read from the option json.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\option\fields\ticket
 */
final class option_field_ticket_test extends booking_advanced_testcase {
    /** @var stdClass Course. */
    protected $course;

    /** @var stdClass Booking module instance. */
    protected $booking;

    /** @var int Ticket template id. */
    protected $templateid;

    /**
     * Set up a course with a booking instance and a certificate template.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();

        $template = $this->getDataGenerator()->get_plugin_generator('tool_certificate')
            ->create_template((object) ['name' => 'Ticket design']);
        $this->templateid = (int) $template->get_id();

        set_config('bookingticketon', 1, 'booking');

        $bdata = [
            'name' => 'Test Booking', 'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'], 'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'], 'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'], 'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'], 'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'], 'tags' => '',
            'course' => $this->course->id, 'bookingmanager' => $teacher->username,
        ];
        $this->booking = $this->getDataGenerator()->create_module('booking', $bdata);
    }

    /**
     * Create a booking option with the given extra record fields.
     *
     * @param array $extra
     *
     * @return int The option id.
     */
    protected function create_option(array $extra = []): int {
        $record = (object) array_merge([
            'bookingid' => $this->booking->id,
            'text' => 'Test option',
            'chooseorcreatecourse' => 1,
            'courseid' => $this->course->id,
            'description' => 'Test description',
        ], $extra);

        $option = self::getDataGenerator()->get_plugin_generator('mod_booking')->create_option($record);
        return (int) $option->id;
    }

    /**
     * The ticket settings are written into the json column of the booking option.
     */
    public function test_ticket_settings_are_saved_to_json(): void {
        $optionid = $this->create_option([
            'ticket' => $this->templateid,
            'ticketpersonalized' => 1,
            'ticketconfirmidentity' => 1,
            'ticketextrainfo' => 'Please bring a photo ID.',
        ]);

        $this->assertEquals(
            $this->templateid,
            (int) booking_option::get_value_of_json_by_key($optionid, 'ticket')
        );
        $this->assertEquals(1, (int) booking_option::get_value_of_json_by_key($optionid, 'ticketconfirmidentity'));
        $this->assertEquals(
            'Please bring a photo ID.',
            booking_option::get_value_of_json_by_key($optionid, 'ticketextrainfo')
        );

        $this->assertTrue(ticket_manager::is_enabled_for_option($optionid));
        $this->assertEquals($this->templateid, ticket_manager::get_template_id_for_option($optionid));
        $this->assertTrue(ticket_manager::requires_identity_confirmation($optionid));
        $this->assertTrue(ticket_manager::is_personalized($optionid));
    }

    /**
     * Without a template, the dependent settings are not stored either.
     */
    public function test_no_template_clears_dependent_settings(): void {
        $optionid = $this->create_option([
            'ticket' => 0,
            'ticketconfirmidentity' => 1,
            'ticketextrainfo' => 'Ignored without a design.',
        ]);

        $this->assertNull(booking_option::get_value_of_json_by_key($optionid, 'ticket'));
        $this->assertNull(booking_option::get_value_of_json_by_key($optionid, 'ticketconfirmidentity'));
        $this->assertNull(booking_option::get_value_of_json_by_key($optionid, 'ticketextrainfo'));

        $this->assertFalse(ticket_manager::is_enabled_for_option($optionid));
        $this->assertEquals(0, ticket_manager::get_template_id_for_option($optionid));
        $this->assertFalse(ticket_manager::requires_identity_confirmation($optionid));
    }

    /**
     * A template id that no longer exists is treated as "no ticket design".
     */
    public function test_missing_template_disables_ticketing(): void {
        $optionid = $this->create_option(['ticket' => $this->templateid]);

        \tool_certificate\template::instance($this->templateid)->delete();

        $this->assertEquals(0, ticket_manager::get_template_id_for_option($optionid));
        $this->assertFalse(ticket_manager::is_enabled_for_option($optionid));
    }

    /**
     * Tickets are personalized unless explicitly marked transferable.
     */
    public function test_personalized_defaults_to_true(): void {
        $optionid = $this->create_option(['ticket' => $this->templateid]);

        $this->assertTrue(ticket_manager::is_personalized($optionid));
    }
}

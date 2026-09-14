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
 * Tests for booking option events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2017 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use context_course;
use context_system;
use mod_booking\settings\optionformconfig\optionformconfig_info;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit test case for the class.
 */
final class optionformconfig_info_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info
     */
    public function test_save_and_return_configured_fields(): void {
        global $DB;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $context = context_system::instance();
        $capability = optionformconfig_info::CAPABILITIES[0];
        $json = json_encode(['foo' => 'bar']);

        $status = optionformconfig_info::save_configured_fields($context->id, $capability, $json);
        $this->assertEquals('success', $status);

        // Check DB record.
        $record = $DB->get_record('booking_form_config', [
            'area' => 'option',
            'capability' => $capability,
            'contextid' => $context->id,
        ]);
        $this->assertNotEmpty($record);
        $this->assertEquals($json, $record->json);

        // Test updating config.
        $json2 = json_encode(['foo' => 'baz']);
        $status = optionformconfig_info::save_configured_fields($context->id, $capability, $json2);
        $this->assertEquals('success', $status);
        $record = $DB->get_record('booking_form_config', ['id' => $record->id]);
        $this->assertEquals($json2, $record->json);

        // Test resetting (delete).
        $status = optionformconfig_info::save_configured_fields($context->id, $capability, json_encode(['reset' => true]));
        $this->assertEquals('success', $status);
        $record = $DB->get_record('booking_form_config', ['id' => $record->id]);
        $this->assertFalse($record);
        return;
    }

    /**
     * Mandatory clean-up after each test.
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info
     * @return void
     */
    public function test_return_configured_fields_returns_array(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $context = context_system::instance();

        $result = optionformconfig_info::return_configured_fields($context->id);
        $this->assertIsArray($result);
        $this->assertCount(count(optionformconfig_info::CAPABILITIES), $result);
        $this->assertArrayHasKey('json', $result[0]);
        return;
    }

    /**
     * Mandatory clean-up after each test.
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info
     * @return void
     */
    public function test_return_capability_for_user_with_admin(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $context = context_system::instance();
        $capability = optionformconfig_info::return_capability_for_user($context->id);
        $this->assertContains($capability, optionformconfig_info::CAPABILITIES);
        return;
    }

    /**
     * Mandatory clean-up after each test.
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info
     * @return void
     */
    public function test_get_classname_returns_localized_string(): void {
        $name = optionformconfig_info::get_classname('mod_booking\\option\\fields\\prepare_import');
        $this->assertIsString($name);
        return;
    }

    /**
     * Mandatory clean-up after each test.
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info
     * @return void
     */
    public function test_return_message_stored_optionformconfig_without_record(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $context = $this->getDataGenerator()->create_course();
        $context = context_course::instance($context->id);

        $message = optionformconfig_info::return_message_stored_optionformconfig($context->id);
        $this->assertIsString($message);
        $this->assertStringContainsString('No special', $message);
        return;
    }

    /**
     * A user who may edit booking options but holds no option form capability gets the fallback form, if configured.
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info::return_capability_for_user
     * @return void
     */
    public function test_return_capability_for_user_fallback(): void {
        $this->resetAfterTest(true);

        $context = context_system::instance();

        // A site role (no course enrolment) which may edit own options, but has no option form capability.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $context->id);
        $editor = $this->getDataGenerator()->create_user();
        role_assign($roleid, $editor->id, $context->id);

        $otheruser = $this->getDataGenerator()->create_user();

        // Without a configured fallback, nothing is returned.
        $this->setUser($editor);
        $this->assertSame('', optionformconfig_info::return_capability_for_user($context->id));

        // With a fallback, the editor gets it, also when passing the userid.
        set_config('optionformfallbackcapability', 'mod/booking:reducedoptionform1', 'booking');
        $this->assertSame('mod/booking:reducedoptionform1', optionformconfig_info::return_capability_for_user($context->id));
        $this->setUser($otheruser);
        $this->assertSame(
            'mod/booking:reducedoptionform1',
            optionformconfig_info::return_capability_for_user($context->id, $editor->id)
        );

        // Users who may not edit or add booking options never get the fallback.
        $this->assertSame('', optionformconfig_info::return_capability_for_user($context->id));

        // A real option form capability wins over the fallback.
        assign_capability('mod/booking:reducedoptionform3', CAP_ALLOW, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($editor);
        $this->assertSame('mod/booking:reducedoptionform3', optionformconfig_info::return_capability_for_user($context->id));

        // Invalid config values are ignored.
        unassign_capability('mod/booking:reducedoptionform3', $roleid, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        set_config('optionformfallbackcapability', 'moodle/site:config', 'booking');
        $this->assertSame('', optionformconfig_info::return_capability_for_user($context->id));
    }
}

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

use advanced_testcase;
use context_module;
use mod_booking\local\wizard\options\skills\configure_booking_instance_skill;
use mod_booking\local\wizard\options\skills\list_instance_settings_skill;

/**
 * Tests for the configure split: read-only mod_booking.list_instance_settings (R0)
 * plus the write-only configure skill's graceful list_fields redirect.
 *
 * @package    mod_booking
 * @covers     \mod_booking\local\wizard\options\skills\list_instance_settings_skill
 * @covers     \mod_booking\local\wizard\options\skills\configure_booking_instance_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_instance_settings_skill_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Create a course + booking instance and return [contextid, bookingid].
     *
     * @return array
     */
    private function create_booking_instance(): array {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Settings course']);
        $booking = $gen->create_module('booking', ['course' => $course->id, 'name' => 'Settings bookings']);
        return [(int)context_module::instance($booking->cmid)->id, (int)$booking->id];
    }

    /**
     * The new skill is a discoverable read-only R0 skill (no confirmation queue).
     */
    public function test_r0_readonly_contract_and_discovery(): void {
        $skill = new list_instance_settings_skill();

        $this->assertSame('mod_booking.list_instance_settings', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(
            \mod_booking\local\wizard\engine\skill_risk_class::R0,
            $skill->get_risk_class()
        );
        $this->assertTrue((bool)($skill->get_schema()['readonly'] ?? false));

        // Registered via the provider's automatic discovery, same as its siblings.
        $names = array_map(
            static fn($s): string => $s->get_name(),
            (new \mod_booking\local\wizard\skill_provider())->get_skills()
        );
        $this->assertContains('mod_booking.list_instance_settings', $names);
    }

    /**
     * execute() returns the full field catalog with current values — no pending confirmation.
     */
    public function test_execute_returns_field_catalog(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $bookingid] = $this->create_booking_instance();

        $result = (new list_instance_settings_skill())->execute([], $contextid, (int)$USER->id);

        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($result['fields']);
        $fieldnames = array_column($result['fields'], 'field');
        $this->assertContains('maxperuser', $fieldnames);
        $this->assertContains('name', $fieldnames);
        // Catalog is identical to the write skill's catalog.
        $this->assertSame(
            array_keys(configure_booking_instance_skill::get_configurable_fields()),
            $fieldnames
        );
        $this->assertStringContainsString('configurable field(s)', (string)$result['usermessage']);
        $this->assertStringContainsString('id=' . $bookingid, (string)$result['usermessage']);
    }

    /**
     * Users without mod/booking:updatebooking get a graceful error, not a crash.
     */
    public function test_execute_requires_updatebooking_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$contextid] = $this->create_booking_instance();
        $student = $this->getDataGenerator()->create_user();

        $result = (new list_instance_settings_skill())->execute([], $contextid, (int)$student->id);

        $this->assertSame('error', $result['status']);
        $this->assertContains('NO_NATIVE_CAPABILITY', (array)($result['issue_codes'] ?? []));
    }

    /**
     * configure_booking_instance action=list_fields answers with a graceful redirect
     * to mod_booking.list_instance_settings on the execute path.
     */
    public function test_configure_list_fields_execute_redirects(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid] = $this->create_booking_instance();

        $result = (new configure_booking_instance_skill())->execute(
            ['action' => 'list_fields'],
            $contextid,
            (int)$USER->id
        );

        $this->assertSame('executed', $result['status']);
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
        $this->assertStringContainsString('mod_booking.list_instance_settings', (string)$result['observation_full']);
        // No field catalog anymore on this path.
        $this->assertArrayNotHasKey('fields', $result);
    }

    /**
     * configure_booking_instance action=list_fields never reaches the confirmation queue:
     * preflight blocks with the redirect clarification instead of queueing an empty preview.
     */
    public function test_configure_list_fields_preflight_redirects(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid] = $this->create_booking_instance();

        $preflight = (new configure_booking_instance_skill())->preflight(
            ['action' => 'list_fields'],
            $contextid,
            (int)$USER->id
        );

        $this->assertNotSame('pass', $preflight->status);
        $messages = implode(' ', array_map(
            static fn(array $issue): string => (string)($issue['message'] ?? ''),
            (array)$preflight->issues
        ));
        $this->assertStringContainsString('mod_booking.list_instance_settings', $messages);
    }

    /**
     * The write skill's update preflight stashes the target so the preview names the activity.
     */
    public function test_configure_update_preview_shows_target_and_changes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid] = $this->create_booking_instance();
        $skill = new configure_booking_instance_skill();

        $preflight = $skill->preflight(
            ['action' => 'update', 'changes' => [['field' => 'maxperuser', 'value' => '3']]],
            $contextid,
            (int)$USER->id
        );
        $this->assertSame('pass', $preflight->status);

        $descriptor = $skill->describe_proposed_action($preflight->preparedinput);
        $rows = [];
        foreach ($descriptor['rows'] as $row) {
            $rows[$row['label']] = $row['value'];
        }
        $this->assertStringContainsString('Settings bookings', $rows['Booking activity']);
        $this->assertStringContainsString('Settings course', $rows['Booking activity']);
        $this->assertSame('3', $rows['Max bookings per user']);
    }
}

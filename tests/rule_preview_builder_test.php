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
use mod_booking\local\wizard\options\skills\rule_preview_builder;
use mod_booking\local\wizard\options\skills\create_rule_from_template_skill;
use mod_booking\local\wizard\options\skills\update_rule_from_template_skill;

/**
 * Tests for the booking-rule pre-confirmation preview (Phase 4).
 *
 * @package    mod_booking
 * @covers     \mod_booking\local\wizard\options\skills\rule_preview_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_preview_builder_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Map a descriptor's rows into a label => value array.
     *
     * @param array $descriptor
     * @return array<string,string>
     */
    private function rows_map(array $descriptor): array {
        $map = [];
        foreach ($descriptor['rows'] as $row) {
            $map[$row['label']] = $row['value'];
        }
        return $map;
    }

    /**
     * create_descriptor shows template, rule name, active state and question.
     */
    public function test_create_descriptor(): void {
        $descriptor = rule_preview_builder::create_descriptor([
            'templatequery' => 'Reminder template',
            'rulename' => 'Course reminder',
            'isactive' => true,
            'question' => 'days before',
        ]);
        $this->assertSame('Create rule from template', $descriptor['title']);
        $rows = $this->rows_map($descriptor);
        $this->assertSame('Reminder template', $rows['Template']);
        $this->assertSame('Course reminder', $rows['Rule name']);
        $this->assertSame('Active', $rows['Active']);
        $this->assertSame('days before', $rows['Question']);
    }

    /**
     * update_descriptor resolves the target and shows the inactive state.
     */
    public function test_update_descriptor(): void {
        $descriptor = rule_preview_builder::update_descriptor([
            'ruleid' => 7,
            'templatequery' => 'New template',
            'isactive' => false,
        ]);
        $this->assertStringContainsString('7', $descriptor['title']);
        $rows = $this->rows_map($descriptor);
        $this->assertSame('New template', $rows['Template']);
        $this->assertSame('Inactive', $rows['Active']);
    }

    /**
     * update_descriptor prefers the preflight-resolved rule and template NAMES over raw ids/queries.
     */
    public function test_update_descriptor_resolved_names(): void {
        $descriptor = rule_preview_builder::update_descriptor([
            'ruleid' => 37,
            'rule_name_resolved' => 'My reminder rule',
            'templatequery' => 'raw user query text',
            'template_name_resolved' => 'Template - Confirm booking',
            'isactive' => 1,
        ]);

        $this->assertSame('Update rule "My reminder rule" (#37)', $descriptor['title']);
        $rows = $this->rows_map($descriptor);
        $this->assertSame('Template - Confirm booking', $rows['Template']);
        $this->assertSame('Active', $rows['Active']);
    }

    /**
     * create_descriptor shows the resolved template name and the requested target activity.
     */
    public function test_create_descriptor_resolved_template_and_target(): void {
        $descriptor = rule_preview_builder::create_descriptor([
            'templatequery' => 'please add a booking confirmation',
            'template_name_resolved' => 'Template - Confirm booking',
            'rulename' => 'Confirmation 8',
            'activityquery' => 'Semester bookings',
        ]);

        $rows = $this->rows_map($descriptor);
        $this->assertSame('Template - Confirm booking', $rows['Template']);
        $this->assertSame('Confirmation 8', $rows['Rule name']);
        $this->assertSame('Semester bookings', $rows['Requested target']);
    }

    /**
     * Rule previews resolve the target booking activity row from a stashed cmid.
     */
    public function test_rule_previews_show_target_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Rules course']);
        $booking = $gen->create_module('booking', ['course' => $course->id, 'name' => 'Rules bookings']);

        $create = rule_preview_builder::create_descriptor([
            'template_name_resolved' => 'Template - Confirm booking',
            'targetcmid' => (int)$booking->cmid,
        ]);
        $createrows = $this->rows_map($create);
        $this->assertStringContainsString('Rules bookings', $createrows['Booking activity']);
        $this->assertStringContainsString('Rules course', $createrows['Booking activity']);

        $update = rule_preview_builder::update_descriptor([
            'ruleid' => 5,
            'rule_name_resolved' => 'Reminder',
            'targetcmid' => (int)$booking->cmid,
            'isactive' => 0,
        ]);
        $updaterows = $this->rows_map($update);
        $this->assertStringContainsString('Rules bookings', $updaterows['Booking activity']);
        $this->assertSame('Inactive', $updaterows['Active']);
    }

    /**
     * The rule skill overrides delegate to the shared builder.
     */
    public function test_skill_overrides_delegate(): void {
        $create = (new create_rule_from_template_skill())->describe_proposed_action(['templatequery' => 'T', 'rulename' => 'R']);
        $this->assertSame('T', $this->rows_map($create)['Template']);

        $update = (new update_rule_from_template_skill())->describe_proposed_action(['rulequery' => 'My rule', 'isactive' => true]);
        $this->assertStringContainsString('My rule', $update['title']);
        $this->assertSame('Active', $this->rows_map($update)['Active']);
    }
}

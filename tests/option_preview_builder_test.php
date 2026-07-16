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
use mod_booking\local\wizard\options\skills\option_preview_builder;
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;
use mod_booking\local\wizard\options\skills\create_slotbooking_option_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking\local\wizard\options\skills\update_option_trainer_skill;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\add_price_category_skill;
use mod_booking\local\wizard\options\skills\configure_booking_instance_skill;

/**
 * Tests for the option write-task pre-confirmation preview (Phase 1).
 *
 * @package    mod_booking
 * @covers     \mod_booking\local\wizard\options\skills\option_preview_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class option_preview_builder_test extends advanced_testcase {
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
     * create_descriptor surfaces curated, relabelled fields and drops noise.
     */
    public function test_create_descriptor(): void {
        $descriptor = option_preview_builder::create_descriptor([
            'text' => 'Yoga class',
            'maxanswers' => 12,
            'maxoverbooking' => 3,
            'coursestarttime' => strtotime('2026-07-01 10:00'),
            'location' => 'Studio 1',
            'teacherquery' => 'Jane Doe',
            'prices' => ['default' => 10, 'student' => 5],
            'invisible' => 1,
            'description' => 'A very long description that should not appear in the preview.',
            'outputlang' => 'en',
        ]);

        $this->assertSame('Create booking option "Yoga class"', $descriptor['title']);
        $this->assertSame('', $descriptor['summary']);

        $rows = $this->rows_map($descriptor);
        $this->assertSame('12', $rows['Seats']);
        $this->assertSame('3', $rows['Waiting list']);
        $this->assertSame('Studio 1', $rows['Location']);
        $this->assertSame('Jane Doe', $rows['Teacher']);
        $this->assertSame('default: 10, student: 5', $rows['Prices']);
        $this->assertSame('Hidden', $rows['Visibility']);
        $this->assertArrayHasKey('Start', $rows);
        $this->assertStringContainsString('2026', $rows['Start']);

        // Long description and framework-internal keys are not shown.
        $this->assertArrayNotHasKey('Description', $rows);
        $this->assertArrayNotHasKey('Outputlang', $rows);
    }

    /**
     * slotbooking_descriptor collapses the weekday flags + window and builds a summary.
     */
    public function test_slotbooking_descriptor(): void {
        $descriptor = option_preview_builder::slotbooking_descriptor([
            'text' => 'Sprechstunde',
            'slot_opening_time' => '08:00',
            'slot_closing_time' => '10:00',
            'slot_duration_minutes' => 30,
            'slot_max_participants_per_slot' => 1,
            'slot_valid_from' => '2026-07-01',
            'slot_valid_until' => '2026-07-31',
            'slot_day_1' => false,
            'slot_day_3' => true,
            'optiontype' => 'slotbooking',
            'slot_enabled' => true,
            'outputlang' => 'en',
        ]);

        $this->assertSame('Create slot booking option "Sprechstunde"', $descriptor['title']);

        $rows = $this->rows_map($descriptor);
        $this->assertStringContainsString('08:00', $rows['Availability window']);
        $this->assertStringContainsString('10:00', $rows['Availability window']);
        $this->assertSame('Wednesday', $rows['Weekdays']);
        $this->assertSame('30 minutes', $rows['Slot length']);
        $this->assertSame('1', $rows['Seats per slot']);
        $this->assertStringContainsString('2026', $rows['Valid']);

        // Derived/internal fields never appear.
        $this->assertArrayNotHasKey('Optiontype', $rows);
        $this->assertArrayNotHasKey('Slot enabled', $rows);

        $this->assertStringContainsString('Bookable 08:00', $descriptor['summary']);
        $this->assertStringContainsString('Wednesday', $descriptor['summary']);
        $this->assertStringContainsString('30-min slots', $descriptor['summary']);
        $this->assertStringContainsString('capacity 1', $descriptor['summary']);
    }

    /**
     * update_descriptor shows ONLY changed fields and excludes targeting keys.
     */
    public function test_update_descriptor_changed_fields_only(): void {
        $descriptor = option_preview_builder::update_descriptor([
            'optionid' => 0,
            'optionquery' => 'some query',
            'resolvedoptionid' => 0,
            'maxanswers' => 20,
            'location' => 'New room',
        ]);

        $this->assertSame('Update booking option', $descriptor['title']);

        $rows = $this->rows_map($descriptor);
        $this->assertSame('20', $rows['Seats']);
        $this->assertSame('New room', $rows['Location']);

        // Targeting keys are never presented as a change.
        $this->assertArrayNotHasKey('Optionid', $rows);
        $this->assertArrayNotHasKey('Optionquery', $rows);
        $this->assertArrayNotHasKey('Resolvedoptionid', $rows);
    }

    /**
     * update_descriptor resolves the target option's title and id.
     */
    public function test_update_descriptor_resolves_option_title(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        /** @var \mod_booking_generator $bgen */
        $bgen = $gen->get_plugin_generator('mod_booking');
        $course = $gen->create_course();
        $booking = $gen->create_module('booking', ['course' => $course->id]);
        $option = $bgen->create_option([
            'bookingid' => (int)$booking->id,
            'text' => 'Existing option',
            'description' => 'Existing option',
            'chooseorcreatecourse' => 1,
            'courseid' => (int)$course->id,
            'optiondateid_0' => 0,
            'daystonotify_0' => 0,
            'coursestarttime_0' => strtotime('+2 days 10:00'),
            'courseendtime_0' => strtotime('+2 days 12:00'),
        ]);

        $descriptor = option_preview_builder::update_descriptor([
            'optionid' => (int)$option->id,
            'maxanswers' => 25,
        ]);

        $this->assertStringContainsString('Existing option', $descriptor['title']);
        $this->assertStringContainsString('#' . (int)$option->id, $descriptor['title']);
        $rows = $this->rows_map($descriptor);
        $this->assertSame('25', $rows['Seats']);
    }

    /**
     * The skill overrides delegate to the shared builder.
     */
    public function test_skill_overrides_delegate(): void {
        $createrows = $this->rows_map((new create_option_skill())->describe_proposed_action(['text' => 'X', 'maxanswers' => 5]));
        $this->assertSame('5', $createrows['Seats']);

        $slot = (new create_slotbooking_option_skill())->describe_proposed_action([
            'text' => 'Y',
            'slot_day_3' => true,
            'slot_duration_minutes' => 15,
        ]);
        $this->assertSame('Wednesday', $this->rows_map($slot)['Weekdays']);

        $update = (new update_option_skill())->describe_proposed_action(['optionid' => 0, 'maxanswers' => 9]);
        $this->assertSame('9', $this->rows_map($update)['Seats']);
    }

    /**
     * trainer_descriptor shows the trainer query and a target-aware title.
     */
    public function test_trainer_descriptor(): void {
        $descriptor = option_preview_builder::trainer_descriptor([
            'optionid' => 0,
            'teacherquery' => 'Jane Doe',
        ]);
        $this->assertStringContainsString('Update trainers', $descriptor['title']);
        $this->assertSame('Jane Doe', $this->rows_map($descriptor)['Teacher']);
    }

    /**
     * bulk_descriptor shows the selection target and the changed fields.
     */
    public function test_bulk_descriptor(): void {
        $all = option_preview_builder::bulk_descriptor(['apply_to_all' => true, 'maxanswers' => 30]);
        $allrows = $this->rows_map($all);
        $this->assertSame('All options', $allrows['Applies to']);
        $this->assertSame('30', $allrows['Seats']);

        $some = option_preview_builder::bulk_descriptor(['optionids' => [1, 2, 3], 'location' => 'Hall']);
        $somerows = $this->rows_map($some);
        $this->assertStringContainsString('3', $somerows['Applies to']);
        $this->assertSame('Hall', $somerows['Location']);
    }

    /**
     * add_price_category_descriptor shows identifier, name, default price and sort order.
     */
    public function test_add_price_category_descriptor(): void {
        $rows = $this->rows_map(option_preview_builder::add_price_category_descriptor([
            'identifier' => 'student',
            'name' => 'Student price',
            'defaultvalue' => 7.5,
            'pricecatsortorder' => 2,
        ]));
        $this->assertSame('student', $rows['Identifier']);
        $this->assertSame('Student price', $rows['Name']);
        $this->assertSame('7.5', $rows['Default price']);
        $this->assertSame('2', $rows['Sort order']);
    }

    /**
     * configure_instance_descriptor maps each change to its localized field label.
     */
    public function test_configure_instance_descriptor(): void {
        $spec = [
            'maxperuser' => ['type' => 'integer'],
            'cancancelbook' => ['type' => 'boolean'],
        ];
        $rows = $this->rows_map(option_preview_builder::configure_instance_descriptor([
            'action' => 'update',
            'changes' => [
                ['field' => 'maxperuser', 'value' => 3],
                ['field' => 'cancancelbook', 'value' => true],
                ['field' => 'unknownfield', 'value' => 'x'],
            ],
        ], $spec));
        $this->assertSame('3', $rows['Max bookings per user']);
        $this->assertSame(get_string('yes'), $rows['Allow cancellation']);
        // Unknown fields are skipped.
        $this->assertArrayNotHasKey('Unknownfield', $rows);
    }

    /**
     * Every preview names the resolved target booking activity and its course.
     */
    public function test_create_descriptor_includes_target_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Target course 101']);
        $booking = $gen->create_module('booking', ['course' => $course->id, 'name' => 'Semester bookings']);

        $descriptor = option_preview_builder::create_descriptor([
            'text' => 'Yoga class',
            'maxanswers' => 12,
            'targetcmid' => (int)$booking->cmid,
            'outputlang' => 'en',
        ]);

        $rows = $this->rows_map($descriptor);
        $this->assertArrayHasKey('Booking activity', $rows);
        $this->assertStringContainsString('Semester bookings', $rows['Booking activity']);
        $this->assertStringContainsString('Target course 101', $rows['Booking activity']);
    }

    /**
     * When only the user's activity query is known, it is shown verbatim as the requested target.
     */
    public function test_create_descriptor_requested_target_fallback(): void {
        $descriptor = option_preview_builder::create_descriptor([
            'text' => 'Yoga class',
            'activityquery' => 'selflearning bookings',
        ]);

        $rows = $this->rows_map($descriptor);
        $this->assertSame('selflearning bookings', $rows['Requested target']);
        $this->assertArrayNotHasKey('Booking activity', $rows);
    }

    /**
     * A self-learning create preview shows the option type and the humanized duration.
     */
    public function test_create_descriptor_selflearning_duration_and_type(): void {
        $descriptor = option_preview_builder::create_descriptor([
            'text' => 'Self-paced course',
            'maxanswers' => 10,
            'duration' => 14400,
            'optiontype' => 'selflearning',
            'selflearningcourse' => true,
            'outputlang' => 'en',
        ]);

        $rows = $this->rows_map($descriptor);
        $this->assertSame('4 ' . get_string('hours'), $rows[get_string('duration', 'booking')]);
        $this->assertSame(get_string('selflearningcourse', 'booking'), $rows[get_string('optiontype', 'booking')]);
        $this->assertSame('10', $rows['Seats']);

        // The selflearning skill delegates to the same builder.
        $delegated = (new create_selflearning_option_skill())->describe_proposed_action([
            'text' => 'Self-paced course',
            'duration' => 5400,
        ]);
        $this->assertSame(
            '1 ' . get_string('hour') . ' 30 ' . get_string('mins'),
            $this->rows_map($delegated)[get_string('duration', 'booking')]
        );
    }

    /**
     * bulk_descriptor names the selected options, resolves the target activity and never
     * drops a changed field (description was silently hidden before).
     */
    public function test_bulk_descriptor_names_options_and_shows_description(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        /** @var \mod_booking_generator $bgen */
        $bgen = $gen->get_plugin_generator('mod_booking');
        $course = $gen->create_course(['fullname' => 'Bulk course']);
        $booking = $gen->create_module('booking', ['course' => $course->id, 'name' => 'Bulk bookings']);

        $optionids = [];
        foreach (['First option', 'Second option'] as $title) {
            $option = $bgen->create_option([
                'bookingid' => (int)$booking->id,
                'text' => $title,
                'description' => $title,
                'chooseorcreatecourse' => 1,
                'courseid' => (int)$course->id,
                'optiondateid_0' => 0,
                'daystonotify_0' => 0,
                'coursestarttime_0' => strtotime('+2 days 10:00'),
                'courseendtime_0' => strtotime('+2 days 12:00'),
            ]);
            $optionids[] = (int)$option->id;
        }

        $longdescription = str_repeat('New description text. ', 10);
        $descriptor = option_preview_builder::bulk_descriptor([
            'optionids' => $optionids,
            'description' => $longdescription,
            'outputlang' => 'en',
        ]);

        $rows = $this->rows_map($descriptor);
        // Count row stays, the named list is new.
        $this->assertStringContainsString('2', $rows['Applies to']);
        $this->assertStringContainsString('#' . $optionids[0] . ' First option', $rows['Options']);
        $this->assertStringContainsString('#' . $optionids[1] . ' Second option', $rows['Options']);
        // Target activity resolved from the option ids.
        $this->assertStringContainsString('Bulk bookings', $rows['Booking activity']);
        // The description change produces a row, truncated to the preview cap.
        $this->assertArrayHasKey('Description', $rows);
        $this->assertStringContainsString('New description text.', $rows['Description']);
        $this->assertLessThanOrEqual(80, \core_text::strlen($rows['Description']));
        $this->assertStringEndsWith('…', $rows['Description']);
    }

    /**
     * More than five selected options collapse into "+N more" after the named ones.
     */
    public function test_bulk_descriptor_option_list_caps_at_five(): void {
        $descriptor = option_preview_builder::bulk_descriptor([
            'optionids' => [11, 12, 13, 14, 15, 16, 17],
            'maxanswers' => 9,
        ]);

        $rows = $this->rows_map($descriptor);
        $this->assertStringContainsString('#15', $rows['Options']);
        $this->assertStringNotContainsString('#16', $rows['Options']);
        $this->assertStringContainsString(
            get_string('previewvalue_andnmore', 'booking', 2),
            $rows['Options']
        );
    }

    /**
     * An update preview shows a description-only change (no silent empty diff).
     */
    public function test_update_descriptor_shows_description_change(): void {
        $descriptor = option_preview_builder::update_descriptor([
            'optionid' => 0,
            'description' => 'Now with catering.',
        ]);

        $rows = $this->rows_map($descriptor);
        $this->assertSame('Now with catering.', $rows['Description']);
    }

    /**
     * The configure-instance preview names the target activity next to the change rows.
     */
    public function test_configure_instance_descriptor_includes_target_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Config course']);
        $booking = $gen->create_module('booking', ['course' => $course->id, 'name' => 'Config bookings']);

        $spec = ['maxperuser' => ['type' => 'integer']];
        $rows = $this->rows_map(option_preview_builder::configure_instance_descriptor([
            'action' => 'update',
            'targetcmid' => (int)$booking->cmid,
            'changes' => [['field' => 'maxperuser', 'value' => 3]],
        ], $spec));

        $this->assertStringContainsString('Config bookings', $rows['Booking activity']);
        $this->assertStringContainsString('Config course', $rows['Booking activity']);
        $this->assertSame('3', $rows['Max bookings per user']);
    }

    /**
     * book_users_descriptor resolves participant names with a cap and "+N more".
     */
    public function test_book_users_descriptor(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();

        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $ids[] = (int)$gen->create_user(['firstname' => 'Test', 'lastname' => 'User' . $i])->id;
        }

        $descriptor = option_preview_builder::book_users_descriptor([
            'optionid' => 0,
            'resolvedbookuserids' => $ids,
            'bookuserscompleted' => true,
        ]);

        $rows = $this->rows_map($descriptor);
        $this->assertStringContainsString('Test User1', $rows['Participants']);
        // Capped at 10 names, remaining surfaced as "+2 more".
        $this->assertStringContainsString('+2 more', $rows['Participants']);
        $this->assertSame(get_string('yes'), $rows['Mark as completed']);
        $this->assertStringContainsString('Book users', $descriptor['title']);
    }

    /**
     * The Phase 2 skill overrides delegate to the shared builder.
     */
    public function test_phase2_skill_overrides_delegate(): void {
        $trainer = (new update_option_trainer_skill())->describe_proposed_action(['optionid' => 0, 'teacherquery' => 'Ann']);
        $this->assertSame('Ann', $this->rows_map($trainer)['Teacher']);

        $bulk = (new bulk_update_options_skill())->describe_proposed_action(['apply_to_all' => true, 'maxanswers' => 4]);
        $this->assertSame('All options', $this->rows_map($bulk)['Applies to']);

        $price = (new add_price_category_skill())->describe_proposed_action(['identifier' => 'staff', 'name' => 'Staff']);
        $this->assertSame('staff', $this->rows_map($price)['Identifier']);

        $configure = (new configure_booking_instance_skill())->describe_proposed_action([
            'action' => 'update',
            'changes' => [['field' => 'maxperuser', 'value' => 2]],
        ]);
        $this->assertSame('2', $this->rows_map($configure)['Max bookings per user']);
    }
}

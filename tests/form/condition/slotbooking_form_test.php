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
 * Tests for slotbooking dynamic form.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\form\condition\slotbooking_form;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * PHPUnit tests for slotbooking_form.
 *
 * @covers \mod_booking\form\condition\slotbooking_form
 */
final class slotbooking_form_test extends advanced_testcase {
    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        $this->setAdminUser();
    }

    /**
     * Cleanup.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Data provider for slot mode coverage.
     *
     * @return array[]
     */
    public static function slot_mode_provider(): array {
        $fridaystart = strtotime('2050-01-07 09:00:00 UTC');
        $fridayend = strtotime('2050-01-07 17:00:00 UTC');
        $mondaystart = strtotime('2050-01-10 09:00:00 UTC');
        $mondayend = strtotime('2050-01-10 17:00:00 UTC');

        return [
            'fixed_slots_with_gap_days' => [[
                'slot_type' => 'fixed',
                'booking_interface' => 'list',
                'slot_duration_minutes' => 60,
                'slot_interval_minutes' => 60,
                'slot_start_interval_minutes' => 30,
                'slot_max_days_per_slot' => 1,
                'opening_time' => '09:00',
                'closing_time' => '12:00',
                'valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
                'valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
                'days_of_week' => '1,5',
                'max_participants_per_slot' => 3,
                'max_slots_per_user' => 3,
                'expectedrendercontains' => ['slot_selection'],
                'expectweekendgap' => true,
                'expectslotsatleast' => 2,
            ]],
            'rolling_slots_calendar_mode' => [[
                'slot_type' => 'rolling',
                'booking_interface' => 'calendar',
                'slot_duration_minutes' => 60,
                'slot_interval_minutes' => 30,
                'slot_start_interval_minutes' => 30,
                'slot_max_days_per_slot' => 1,
                'opening_time' => '09:00',
                'closing_time' => '12:00',
                'valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
                'valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
                'days_of_week' => '1,5',
                'max_participants_per_slot' => 3,
                'max_slots_per_user' => 1,
                'expectedrendercontains' => ['booking-slot-calendar-picker', 'booking-slot-fixed-editor'],
                'expectweekendgap' => true,
                'expectslotsatleast' => 8,
            ]],
            'session_slots_from_optiondates' => [[
                'slot_type' => 'session',
                'booking_interface' => 'list',
                'slot_duration_minutes' => 60,
                'slot_interval_minutes' => 60,
                'slot_start_interval_minutes' => 30,
                'slot_max_days_per_slot' => 1,
                'opening_time' => '00:00',
                'closing_time' => '23:59',
                'valid_from' => strtotime('2050-01-01 00:00:00 UTC'),
                'valid_until' => strtotime('2050-01-31 23:59:59 UTC'),
                'days_of_week' => '1,2,3,4,5,6,7',
                'max_participants_per_slot' => 3,
                'max_slots_per_user' => 2,
                'optiondates' => [
                    [$fridaystart, $fridayend],
                    [$mondaystart, $mondayend],
                ],
                'expectedrendercontains' => ['slot_selection'],
                'expectweekendgap' => true,
                'expectslotsatleast' => 2,
            ]],
            'user_defined_slots' => [[
                'slot_type' => 'userdefined',
                'booking_interface' => 'calendar',
                'slot_duration_minutes' => 180,
                'slot_interval_minutes' => 60,
                'slot_start_interval_minutes' => 30,
                'slot_max_days_per_slot' => 2,
                'opening_time' => '09:00',
                'closing_time' => '12:00',
                'valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
                'valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
                'days_of_week' => '1,5',
                'max_participants_per_slot' => 3,
                'max_slots_per_user' => 1,
                'expectedrendercontains' => ['booking-slot-calendar-picker', 'booking-slot-custom-editor'],
                'expectweekendgap' => true,
                'expectslotsatleast' => 0,
            ]],
        ];
    }

    /**
     * Verify supported slot modes and render output.
     *
     * @dataProvider slot_mode_provider
     * @param array $slotconfig
     */
    public function test_slot_modes_and_rendering(array $slotconfig): void {
        [$option, $userid] = $this->create_slot_option_with_config($slotconfig);

        $form = new slotbooking_form(null, [], 'post', '', [], true, [
            'id' => $option->id,
            'userid' => $userid,
        ], true);

        $html = $form->render();
        foreach ($slotconfig['expectedrendercontains'] as $fragment) {
            $this->assertStringContainsString($fragment, $html);
        }

        if ($slotconfig['slot_type'] === 'userdefined') {
            $customdays = $this->invoke_private_static(
                slotbooking_form::class,
                'get_custom_open_days',
                [$option->id, $userid]
            );
            $this->assertNotEmpty($customdays);
            foreach ($customdays as $entry) {
                $dayofweek = (int)date('N', (int)$entry['start']);
                $this->assertContains($dayofweek, [1, 5]);
            }
            return;
        }

        $openslots = $this->invoke_private_static(slotbooking_form::class, 'get_open_slots', [$option->id, $userid]);
        $this->assertGreaterThanOrEqual($slotconfig['expectslotsatleast'], count($openslots));

        if (!empty($slotconfig['expectweekendgap'])) {
            $days = array_values(array_unique(array_map(static function (array $slot): string {
                return date('N', (int)$slot['start']);
            }, $openslots)));
            sort($days);
            $this->assertSame(['1', '5'], $days);
        }
    }

    /**
     * Data provider for validation scenarios.
     *
     * @return array[]
     */
    public static function validation_provider(): array {
        return [
            'fixed_valid_two_slots_with_weekend_gap' => [[
                'slot_type' => 'fixed',
                'max_slots_per_user' => 2,
                'days_of_week' => '1,5',
                'selectionmode' => 'first_two',
                'expecterror' => false,
            ]],
            'fixed_too_many_selected' => [[
                'slot_type' => 'fixed',
                'max_slots_per_user' => 1,
                'days_of_week' => '1,5',
                'selectionmode' => 'first_two',
                'expecterror' => true,
            ]],
            'userdefined_invalid_duration' => [[
                'slot_type' => 'userdefined',
                'max_slots_per_user' => 1,
                'days_of_week' => '1,5',
                'selectionmode' => 'invalid_userdefined_duration',
                'expecterror' => true,
            ]],
        ];
    }

    /**
     * Validation behavior for different slot configurations.
     *
     * @dataProvider validation_provider
     * @param array $scenario
     */
    public function test_validation_scenarios(array $scenario): void {
        [$option, $userid] = $this->create_slot_option_with_config([
            'slot_type' => $scenario['slot_type'],
            'booking_interface' => 'list',
            'slot_duration_minutes' => 60,
            'slot_interval_minutes' => 30,
            'slot_start_interval_minutes' => 30,
            'slot_max_days_per_slot' => 2,
            'opening_time' => '09:00',
            'closing_time' => '12:00',
            'valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
            'valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
            'days_of_week' => $scenario['days_of_week'],
            'max_participants_per_slot' => 3,
            'max_slots_per_user' => $scenario['max_slots_per_user'],
        ]);

        $form = new slotbooking_form(null, [], 'post', '', [], true, [
            'id' => $option->id,
            'userid' => $userid,
        ], true);

        $submitted = [
            'id' => $option->id,
            'userid' => $userid,
            'slot_validation_error_target' => 'slot_selection',
            'slot_max_selection' => $scenario['max_slots_per_user'],
            'slot_teachers_required_count' => 0,
            'slot_teacher_selection' => json_encode([]),
        ];

        if ($scenario['slot_type'] === 'userdefined') {
            $submitted['slot_custom_start'] = strtotime('2050-01-07 09:00:00 UTC');
            $submitted['slot_custom_duration'] = ($scenario['selectionmode'] === 'invalid_userdefined_duration') ? 123 : 3600;
        } else {
            $openslots = $this->invoke_private_static(slotbooking_form::class, 'get_open_slots', [$option->id, $userid]);
            $this->assertNotEmpty($openslots);

            $selected = [$openslots[0]['key']];
            if ($scenario['selectionmode'] === 'first_two' && !empty($openslots[1]['key'])) {
                $selected[] = $openslots[1]['key'];
            }

            $submitted['slot_selection'] = implode(',', $selected);
            $submitted['slot_calendar_data'] = json_encode($openslots);
        }

        $errors = $form->validation($submitted, []);

        if (!empty($scenario['expecterror'])) {
            $this->assertArrayHasKey('slot_selection', $errors);
        } else {
            $this->assertSame([], $errors);
        }
    }

    /**
     * Create slot booking option and attach slot config.
     *
     * @param array $slotconfig
     * @return array{0:\stdClass, 1:int}
     */
    private function create_slot_option_with_config(array $slotconfig): array {
        $course = self::getDataGenerator()->create_course();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();

        $days = array_map('intval', array_filter(array_map('trim', explode(',', (string)$slotconfig['days_of_week']))));

        $record = [
            'bookingid' => $booking->id,
            'text' => 'Slot option ' . uniqid('', true),
            'course' => $course->id,
            'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
            'maxanswers' => 20,
            'slot_enabled' => 1,
            'slot_type' => (string)$slotconfig['slot_type'],
            'slot_duration_minutes' => (int)$slotconfig['slot_duration_minutes'],
            'slot_interval_minutes' => (int)$slotconfig['slot_interval_minutes'],
            'slot_custom_max_duration' => (int)$slotconfig['slot_duration_minutes'] * MINSECS,
            'slot_custom_min_duration' => (int)$slotconfig['slot_interval_minutes'] * MINSECS,
            'slot_custom_max_days' => (int)$slotconfig['slot_max_days_per_slot'] * DAYSECS,
            'slot_custom_start_interval_minutes' => (int)$slotconfig['slot_start_interval_minutes'],
            'slot_opening_time' => (string)$slotconfig['opening_time'],
            'slot_closing_time' => (string)$slotconfig['closing_time'],
            'slot_valid_from' => (int)$slotconfig['valid_from'],
            'slot_valid_until' => (int)$slotconfig['valid_until'],
            'slot_max_participants_per_slot' => (int)$slotconfig['max_participants_per_slot'],
            'slot_max_slots_per_user' => (int)$slotconfig['max_slots_per_user'],
            'slot_booking_view_mode' => (string)($slotconfig['booking_interface'] ?? 'list'),
            'slot_add_examiners' => 0,
            'slot_teachers_required' => 0,
        ];
        for ($day = 1; $day <= 7; $day++) {
            $record['slot_day_' . $day] = in_array($day, $days, true) ? 1 : 0;
        }

        if (!empty($slotconfig['optiondates'])) {
            foreach (array_values($slotconfig['optiondates']) as $index => $range) {
                $record['optiondateid_' . $index] = 0;
                $record['daystonotify_' . $index] = 0;
                $record['coursestarttime_' . $index] = $range[0];
                $record['courseendtime_' . $index] = $range[1];
            }
        }

        $option = $plugingenerator->create_option((object)$record);

        singleton_service::destroy_instance();
        return [$option, (int)$student->id];
    }

    /**
     * Call private static method via reflection.
     *
     * @param string $classname
     * @param string $methodname
     * @param array $args
     * @return mixed
     */
    private function invoke_private_static(string $classname, string $methodname, array $args = []) {
        $method = new \ReflectionMethod($classname, $methodname);
        $method->setAccessible(true);

        return $method->invokeArgs(null, $args);
    }
}

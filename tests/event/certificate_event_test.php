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
 * Certificate event tests for mod_booking
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\certificateclass;
use mod_booking\event\certificate_issued;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Test class for certificate issued event
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\event\certificate_issued
 * @covers \mod_booking\local\certificateclass::get_required_options_data
 */
final class certificate_event_test extends advanced_testcase {
    /**
     * Tests set up.
     *
     * @return void.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * Test certificate_issued event can be created.
     *
     * @covers \mod_booking\event\certificate_issued::create
     */
    public function test_certificate_issued_event_can_be_created(): void {
        $context = \context_system::instance();

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
            'other' => [
                'bookingoption_id' => 10,
                'bookingoption_name' => 'Test Option',
                'required_options' => [],
            ],
        ]);

        $this->assertInstanceOf(certificate_issued::class, $event);
        $data = $event->get_data();
        $this->assertEquals(1, $data['objectid']);
        $this->assertEquals(2, $data['relateduserid']);
    }

    /**
     * Test certificate_issued event contains booking option data.
     *
     * @covers \mod_booking\event\certificate_issued::create
     */
    public function test_certificate_issued_event_contains_booking_option_data(): void {
        $context = \context_system::instance();

        $requiredoptions = [
            [
                'optionid' => 5,
                'optionname' => 'Required Option 1',
                'completed' => true,
            ],
            [
                'optionid' => 6,
                'optionname' => 'Required Option 2',
                'completed' => true,
            ],
        ];

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
            'other' => [
                'bookingoption_id' => 10,
                'bookingoption_name' => 'Test Certificate Option',
                'required_options' => $requiredoptions,
            ],
        ]);

        $this->assertEquals(10, $event->other['bookingoption_id']);
        $this->assertEquals('Test Certificate Option', $event->other['bookingoption_name']);
        $this->assertCount(2, $event->other['required_options']);
        $this->assertEquals(5, $event->other['required_options'][0]['optionid']);
        $this->assertEquals('Required Option 1', $event->other['required_options'][0]['optionname']);
        $this->assertTrue($event->other['required_options'][0]['completed']);
    }

    /**
     * Test certificate_issued event get_name returns correct string key.
     *
     * @covers \mod_booking\event\certificate_issued::get_name
     */
    public function test_certificate_issued_event_get_name(): void {
        $context = \context_system::instance();

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
        ]);

        $this->assertEquals('Certificate issued', $event->get_name());
    }

    /**
     * Test certificate_issued event get_description.
     *
     * @covers \mod_booking\event\certificate_issued::get_description
     */
    public function test_certificate_issued_event_get_description(): void {
        $context = \context_system::instance();

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 123,
            'relateduserid' => 456,
            'userid' => 789,
        ]);

        $description = $event->get_description();

        $this->assertStringContainsString('123', $description);
        $this->assertStringContainsString('456', $description);
        $this->assertStringContainsString('789', $description);
    }

    /**
     * Test certificate_issued event get_url.
     *
     * @covers \mod_booking\event\certificate_issued::get_url
     */
    public function test_certificate_issued_event_get_url(): void {
        // Create a course and booking module instance.
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        $cm = get_coursemodule_from_instance('booking', $booking->id);
        $context = \context_module::instance($cm->id);

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
        ]);

        $url = $event->get_url();

        $this->assertStringContainsString('/mod/booking/view.php', $url->out(false));
        $this->assertStringContainsString('id=' . $cm->id, $url->out(false));
    }

    /**
     * Test certificate_issued event validation requires relateduserid.
     *
     * @covers \mod_booking\event\certificate_issued::validate_data
     */
    public function test_certificate_issued_event_validation_requires_relateduserid(): void {
        $context = \context_system::instance();

        $this->expectException(\coding_exception::class);

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
        ]);

        $event->trigger();
    }

    /**
     * Test certificate_issued event can be triggered.
     *
     * @covers \mod_booking\event\certificate_issued::trigger
     */
    public function test_certificate_issued_event_can_be_triggered(): void {
        $context = \context_system::instance();

        // Start event sink to capture events.
        $sink = $this->redirectEvents();

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
            'other' => [
                'bookingoption_id' => 10,
                'bookingoption_name' => 'Test Option',
                'required_options' => [],
            ],
        ]);

        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(certificate_issued::class, $events[0]);
    }

    /**
     * Test certificate_issued event CRUD constant.
     *
     * @covers \mod_booking\event\certificate_issued::init
     */
    public function test_certificate_issued_event_crud_constant(): void {
        $context = \context_system::instance();

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
        ]);

        // Create operation.
        $this->assertEquals('c', $event->crud);
    }

    /**
     * Test certificate_issued event edulevel constant.
     *
     * @covers \mod_booking\event\certificate_issued::init
     */
    public function test_certificate_issued_event_edulevel_constant(): void {
        $context = \context_system::instance();

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
        ]);

        // Participating level.
        $this->assertEquals(\core\event\base::LEVEL_PARTICIPATING, $event->edulevel);
    }

    /**
     * Test certificate_issued event object table.
     *
     * @covers \mod_booking\event\certificate_issued::init
     */
    public function test_certificate_issued_event_object_table(): void {
        $context = \context_system::instance();

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
        ]);

        $this->assertEquals('tool_certificate_issues', $event->objecttable);
    }

    /**
     * Test certificate_issued event with mixed required options status.
     *
     * @covers \mod_booking\event\certificate_issued::create
     * @covers \mod_booking\event\certificate_issued::trigger
     */
    public function test_certificate_issued_event_with_mixed_required_options_status(): void {
        $context = \context_system::instance();

        $requiredoptions = [
            [
                'optionid' => 5,
                'optionname' => 'Required Option 1',
                'completed' => true,
            ],
            [
                'optionid' => 6,
                'optionname' => 'Required Option 2',
                'completed' => false,
            ],
            [
                'optionid' => 7,
                'optionname' => 'Required Option 3',
                'completed' => true,
            ],
        ];

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 1,
            'relateduserid' => 2,
            'other' => [
                'bookingoption_id' => 10,
                'bookingoption_name' => 'Test Certificate Option',
                'required_options' => $requiredoptions,
            ],
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $triggerededevent = $events[0];

        // Verify all required options are in the event.
        $this->assertCount(3, $triggerededevent->other['required_options']);

        // Verify mixed completion status is preserved.
        $this->assertTrue($triggerededevent->other['required_options'][0]['completed']);
        $this->assertFalse($triggerededevent->other['required_options'][1]['completed']);
        $this->assertTrue($triggerededevent->other['required_options'][2]['completed']);
    }

    /**
     * Test certificate_issued event other field structure.
     *
     * @covers \mod_booking\event\certificate_issued::create
     */
    public function test_certificate_issued_event_other_field_structure(): void {
        $context = \context_system::instance();

        $event = certificate_issued::create([
            'context' => $context,
            'objectid' => 123,
            'relateduserid' => 456,
            'other' => [
                'bookingoption_id' => 10,
                'bookingoption_name' => 'Test Option',
                'required_options' => [
                    [
                        'optionid' => 5,
                        'optionname' => 'Required',
                        'completed' => true,
                    ],
                ],
            ],
        ]);

        // Verify the 'other' array has the expected keys.
        $this->assertArrayHasKey('bookingoption_id', $event->other);
        $this->assertArrayHasKey('bookingoption_name', $event->other);
        $this->assertArrayHasKey('required_options', $event->other);

        // Verify each required option has expected keys.
        foreach ($event->other['required_options'] as $requiredoption) {
            $this->assertArrayHasKey('optionid', $requiredoption);
            $this->assertArrayHasKey('optionname', $requiredoption);
            $this->assertArrayHasKey('completed', $requiredoption);
        }
    }
}

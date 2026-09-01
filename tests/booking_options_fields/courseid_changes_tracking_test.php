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
 * Tests for the change tracking of the connected Moodle course.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\event\bookingoption_updated;
use mod_booking\option\fields\courseid;
use mod_booking_generator;
use stdClass;

/**
 * Tests that creating a course from a template is recorded in the bookingoption_updated event.
 *
 * Creating a course from a template is a one-way operation that cannot be reconstructed later: the
 * copy runs asynchronously and the three form parameters steering it (which template, and whether
 * its users come along) are not persisted anywhere. They therefore have to be recorded at save
 * time, including the case where "transfer the users" was left unticked - which the generic
 * check_for_changes() would drop, because old and new value are then both empty.
 *
 * @package mod_booking
 * @category test
 * @covers \mod_booking\option\fields\courseid::prepare_save_field
 * @covers \mod_booking\option\fields\courseid::get_changes_description
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class courseid_changes_tracking_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * Tests tear down.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Switching an option to "create course from template" must record all three form parameters,
     * whatever the state of the "transfer the users" checkbox is - an unticked box is exactly the
     * information worth having on record afterwards.
     *
     * @param int|null $withusers value submitted for the checkbox, null meaning "not submitted at all"
     * @param string $expected the value expected in the event
     * @dataProvider withusers_provider
     */
    public function test_template_parameters_are_recorded_on_update(?int $withusers, string $expected): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $template = $this->getDataGenerator()->create_course(['fullname' => 'Template source']);

        [$option, $update] = $this->create_option_with_connected_course($course);

        $update->chooseorcreatecourse = 3; // Create new Moodle course from template.
        $update->coursetemplateid = $template->id;
        if ($withusers === null) {
            // An unticked checkbox can also be missing from the submitted data entirely.
            unset($update->createnewmoodlecoursefromtemplatewithusers);
        } else {
            $update->createnewmoodlecoursefromtemplatewithusers = $withusers;
        }

        $sink = $this->redirectEvents();
        booking_option::update($update);
        $events = $sink->get_events();
        $sink->close();

        $byformkey = $this->get_courseid_changes($events, (int) $option->id);

        $this->assertArrayHasKey(
            'chooseorcreatecourse',
            $byformkey,
            'The chosen kind of course connection must be recorded.'
        );
        $this->assertEquals('3', $byformkey['chooseorcreatecourse']['newvalue']);

        $this->assertArrayHasKey(
            'coursetemplateid',
            $byformkey,
            'The template the course was copied from must be recorded.'
        );
        $this->assertEquals((string) $template->id, $byformkey['coursetemplateid']['newvalue']);

        $this->assertArrayHasKey(
            'createnewmoodlecoursefromtemplatewithusers',
            $byformkey,
            'Whether the template users were transferred must be recorded even when the box was left unticked.'
        );
        $this->assertSame($expected, $byformkey['createnewmoodlecoursefromtemplatewithusers']['newvalue']);

        // The connected course itself changed too: away from the original course, and not to the template.
        $this->assertArrayHasKey('courseid', $byformkey);
        $this->assertEquals((string) $course->id, $byformkey['courseid']['oldvalue']);
        $this->assertNotEquals((string) $course->id, $byformkey['courseid']['newvalue']);
        $this->assertNotEquals((string) $template->id, $byformkey['courseid']['newvalue']);
    }

    /**
     * Provide the three ways the "transfer the users" checkbox can reach the save routine.
     *
     * @return array
     */
    public static function withusers_provider(): array {
        return [
            'users transferred' => [1, '1'],
            'users not transferred' => [0, '0'],
            'checkbox not submitted' => [null, ''],
        ];
    }

    /**
     * An update that leaves the course connection alone must not report any course change.
     *
     * Reporting one unconditionally would make booking_utils::react_on_changes() treat every single
     * save as a change and notify all booked users.
     */
    public function test_untouched_course_connection_is_not_reported(): void {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        [$option, $update] = $this->create_option_with_connected_course($course);

        // Change something unrelated, so an event is triggered at all.
        $update->text = 'Renamed option';

        $sink = $this->redirectEvents();
        booking_option::update($update);
        $events = $sink->get_events();
        $sink->close();

        $byformkey = $this->get_courseid_changes($events, (int) $option->id);

        $this->assertSame(
            [],
            $byformkey,
            'An unchanged course connection must not show up in the change log.'
        );
    }

    /**
     * Every recorded form key has to be rendered with its own label and a readable value.
     *
     * All entries share the fieldname 'courseid' - that is what the renderer resolves the field
     * class from - so it is the form key that has to decide how an entry is displayed.
     */
    public function test_changes_description_labels_each_form_key(): void {
        $this->setAdminUser();

        $template = $this->getDataGenerator()->create_course(['fullname' => 'Template source']);
        $field = new courseid();

        $choice = $field->get_changes_description([
            'fieldname' => 'courseid',
            'formkey' => 'chooseorcreatecourse',
            'oldvalue' => '',
            'newvalue' => '3',
        ]);
        $this->assertEquals(get_string('connectedmoodlecourse', 'mod_booking'), $choice['fieldname']);
        $this->assertEquals(get_string('createnewmoodlecoursefromtemplate', 'mod_booking'), $choice['newvalue']);

        $usedtemplate = $field->get_changes_description([
            'fieldname' => 'courseid',
            'formkey' => 'coursetemplateid',
            'oldvalue' => '',
            'newvalue' => (string) $template->id,
        ]);
        $this->assertEquals(get_string('createnewmoodlecoursefromtemplate', 'mod_booking'), $usedtemplate['fieldname']);
        $this->assertStringContainsString('Template source', $usedtemplate['newvalue']);
        $this->assertStringContainsString('(ID: ' . $template->id . ')', $usedtemplate['newvalue']);

        // Both checkbox states must be spelled out. An unticked box must not collapse into the
        // "nothing to see here" info line, which is what an empty value would produce.
        foreach ([['0', 'off'], ['', 'off'], ['1', 'on']] as [$value, $expected]) {
            $withusers = $field->get_changes_description([
                'fieldname' => 'courseid',
                'formkey' => 'createnewmoodlecoursefromtemplatewithusers',
                'oldvalue' => '',
                'newvalue' => $value,
            ]);
            $this->assertEquals(
                get_string('createnewmoodlecoursefromtemplatewithusers', 'mod_booking'),
                $withusers['fieldname']
            );
            $this->assertEquals(get_string($expected, 'mod_booking'), $withusers['newvalue']);
        }

        // The connected course itself is left to the generic implementation.
        $connected = $field->get_changes_description([
            'fieldname' => 'courseid',
            'formkey' => 'courseid',
            'oldvalue' => '5',
            'newvalue' => '7',
        ]);
        $this->assertEquals(get_string('courseid', 'mod_booking'), $connected['fieldname']);
        $this->assertEquals('5', $connected['oldvalue']);
        $this->assertEquals('7', $connected['newvalue']);
    }

    /**
     * Create a booking option that is connected to an existing Moodle course.
     *
     * @param stdClass $course the course the option is connected to
     * @return array the created option and a prefilled update record for it
     */
    private function create_option_with_connected_course(stdClass $course): array {

        $bdata = self::provide_bdata();
        $bdata['course'] = $course->id;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option with connected course';
        $record->description = 'Description';
        $record->chooseorcreatecourse = 1; // Choose an existing Moodle course.
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->optiondateid_0 = '0';
        $record->daystonotify_0 = '0';
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');

        $option = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $update = clone $record;
        $update->id = $option->id;
        $update->cmid = $settings->cmid;
        unset($update->importing);

        return [$option, $update];
    }

    /**
     * Pull the course related changes out of the bookingoption_updated event, keyed by form key.
     *
     * @param array $events all events collected by the sink
     * @param int $optionid the option that was updated
     * @return array the change entries of the courseid field, keyed by their form key
     */
    private function get_courseid_changes(array $events, int $optionid): array {

        $updateevents = array_values(array_filter($events, function ($event) use ($optionid) {
            return $event instanceof bookingoption_updated && (int) $event->objectid === $optionid;
        }));
        $this->assertCount(1, $updateevents, 'Exactly one option updated event is expected.');

        $data = reset($updateevents)->get_data();
        $this->assertIsArray($data['other']['changes']);

        $courseidchanges = array_filter($data['other']['changes'], function ($change) {
            return ($change['fieldname'] ?? '') === 'courseid';
        });

        return array_column($courseidchanges, null, 'formkey');
    }

    /**
     * Provide stable booking activity defaults.
     *
     * @return array
     */
    private static function provide_bdata(): array {
        return [
            'name' => 'Test booking courseid changes',
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
            'tags' => '',
        ];
    }
}

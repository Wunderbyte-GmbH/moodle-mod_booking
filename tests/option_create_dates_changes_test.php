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
use mod_booking\event\bookingoption_updated;
use stdClass;

/**
 * Creating an option with dates must not record a phantom change of its dates.
 *
 * While a new option is saved it has no optiondate yet, so booking_option_settings reports the option's
 * own coursestarttime and courseendtime as a session with id 0. That session was tracked as an old date,
 * so every new option logged "dates deleted" and "dates new" with the same values ("Show recent updates").
 * The bookingoption_updated event itself is still needed on creation: it creates the calendar entries.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\dates::save_optiondates_from_form
 */
final class option_create_dates_changes_test extends advanced_testcase {
    /**
     * A new option logs its dates as new only, without a deleted old date.
     *
     * @return void
     */
    public function test_new_option_logs_its_dates_as_new_only(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option with one date';
        $record->courseid = $course->id;
        $record->description = 'Description';
        $record->optiondateid_0 = 0;
        $record->daystonotify_0 = 0;
        $record->coursestarttime_0 = 2346937200;
        $record->courseendtime_0 = 2347110000;

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');

        $sink = $this->redirectEvents();
        $option = $plugingenerator->create_option($record);
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof bookingoption_updated && (int)$event->objectid === (int)$option->id
        ));
        $sink->close();

        // The event is still triggered: the calendar entries of the new option depend on it.
        $this->assertCount(1, $events);

        $datechanges = array_values(array_filter(
            array_map(fn($change) => (array)$change, $events[0]->other['changes'] ?? []),
            fn($change) => ($change['fieldname'] ?? '') === 'dates'
        ));
        $this->assertCount(1, $datechanges);
        $this->assertEmpty($datechanges[0]['oldvalue'], 'A new option has no old date that could have been deleted.');
        $this->assertCount(1, $datechanges[0]['newvalue']);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->assertCount(1, $settings->sessions);
        $this->assertNotEmpty(reset($settings->sessions)->id, 'The date is stored as a real optiondate.');
    }
}

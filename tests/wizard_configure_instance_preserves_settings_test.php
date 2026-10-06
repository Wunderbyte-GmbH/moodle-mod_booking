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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\configure_booking_instance_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A change through configure_booking_instance keeps every setting it was not asked to change.
 *
 * The skill passes the plain database record to booking_update_instance(), a handler for mod_form
 * data: settings that only exist as form fields (timerestrict) or live in the instance JSON
 * (viewparam, disablecancel, ...) are missing from the record and were reset on every change.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\configure_booking_instance_skill
 */
final class wizard_configure_instance_preserves_settings_test extends booking_advanced_testcase {
    /**
     * Changing one field leaves the other columns and every JSON setting of the instance untouched.
     */
    public function test_changing_one_field_keeps_all_other_settings(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        engine_component::ensure_engine_aliases();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Preserve Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $cmid = (int)$booking->cmid;
        $contextid = (int)context_module::instance($cmid)->id;

        $columns = [
            'timeopen' => 1893456000,
            'timeclose' => 1896134400,
            'beforebookedtext' => 'Text before booking',
            'beforecompletedtext' => 'Text before completion',
            'aftercompletedtext' => 'Text after completion',
        ];
        $json = [
            'viewparam' => MOD_BOOKING_VIEW_PARAM_CARDS,
            'disablecancel' => 1,
            'disablebooking' => 1,
            'overwriteblockingwarnings' => 1,
            'billboardtext' => 'Billboard',
            'slot_change_deadline_minutes' => 30,
            'fulltextsearchcolumns' => ['text', 'location'],
            'unenrolfromgroupofcurrentcourse' => 1,
        ];
        $record = ['id' => (int)$booking->id, 'json' => json_encode($json)] + $columns;
        $DB->update_record('booking', (object)$record);
        booking::purge_cache_for_booking_instance_by_cmid($cmid);

        $result = (new configure_booking_instance_skill())->execute(
            ['action' => 'update', 'changes' => [['field' => 'eventtype', 'value' => 'Seminar']]],
            $contextid,
            (int)$USER->id
        );
        $this->assertSame('executed', $result['status']);

        $stored = $DB->get_record('booking', ['id' => (int)$booking->id]);
        $this->assertSame('Seminar', (string)$stored->eventtype);

        $storedjson = (array)json_decode((string)$stored->json, true);
        $lost = [];
        foreach ($columns as $column => $expected) {
            if ((string)$stored->$column !== (string)$expected) {
                $lost[] = $column . ': ' . var_export($stored->$column, true);
            }
        }
        foreach ($json as $key => $expected) {
            if (!array_key_exists($key, $storedjson) || json_encode($storedjson[$key]) !== json_encode($expected)) {
                $lost[] = 'json.' . $key . ': ' . json_encode($storedjson[$key] ?? null);
            }
        }
        $this->assertSame([], $lost, 'Settings changed although only eventtype was requested.');
    }

    /**
     * The side effects of an instance update stay: the update event with its change list and the new name in modinfo.
     */
    public function test_rename_triggers_the_update_event_and_refreshes_modinfo(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        engine_component::ensure_engine_aliases();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Old name', 'bookingmanager' => 'admin',
        ]);
        $cmid = (int)$booking->cmid;
        $contextid = (int)context_module::instance($cmid)->id;
        get_fast_modinfo($course->id);

        $sink = $this->redirectEvents();
        $result = (new configure_booking_instance_skill())->execute(
            ['action' => 'update', 'changes' => [['field' => 'name', 'value' => 'New name']]],
            $contextid,
            (int)$USER->id
        );
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event): bool => $event instanceof \mod_booking\event\bookinginstance_updated
        ));
        $sink->close();

        $this->assertSame('executed', $result['status']);
        $this->assertCount(1, $events);
        $this->assertSame($cmid, (int)$events[0]->objectid);
        $this->assertNotEmpty($events[0]->other['changes']);
        $this->assertSame('New name', get_fast_modinfo($course->id)->get_cm($cmid)->name);
    }
}

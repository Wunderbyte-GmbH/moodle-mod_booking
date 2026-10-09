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
 * Optiondates with different entities end up in the calendar of their own entity only.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use local_entities\entities as localentities;
use local_entities\external\get_entity_calendardata;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * One booking option with two optiondates, each optiondate linked to a different entity.
 * Every entity calendar (local_entities/calendar.php, fed by get_entity_calendardata) has to show
 * exactly the optiondate that is linked to this entity and nothing from the other one.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class entities_optiondate_calendars_test extends booking_advanced_testcase {
    /** @var int Start of the first optiondate (in Room A). */
    private int $start1 = 0;

    /** @var int End of the first optiondate (in Room A). */
    private int $end1 = 0;

    /** @var int Start of the second optiondate (in Room B). */
    private int $start2 = 0;

    /** @var int End of the second optiondate (in Room B). */
    private int $end2 = 0;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        if (!class_exists('local_entities\entitiesrelation_handler')) {
            $this->markTestSkipped('local_entities is not installed.');
        }
        $this->start1 = make_timestamp(2050, 6, 20, 10, 0);
        $this->end1 = make_timestamp(2050, 6, 20, 12, 0);
        $this->start2 = make_timestamp(2050, 6, 27, 14, 0);
        $this->end2 = make_timestamp(2050, 6, 27, 16, 0);
    }

    /**
     * Each entity calendar contains only the optiondate that is linked to this entity.
     *
     * @covers \mod_booking\option\fields\entities::save_data
     * @covers \mod_booking\booking::return_array_of_entity_dates
     * @covers \local_entities\entities::get_all_dates_for_entity
     * @covers \local_entities\external\get_entity_calendardata::execute
     *
     * @param bool $optionentity the option itself is linked to the first entity too, otherwise it has no entity
     *
     * @dataProvider option_entity_provider
     */
    public function test_optiondates_with_different_entities_in_separate_calendars(bool $optionentity): void {
        global $DB;

        [$optionid, $roomaid, $roombid] = $this->create_option_with_two_dates_and_entities($optionentity);

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertCount(2, $settings->sessions, 'The option has two optiondates.');
        $sessions = array_values($settings->sessions);
        usort($sessions, fn($a, $b) => $a->coursestarttime <=> $b->coursestarttime);
        $optiondateid1 = (int)$sessions[0]->id;
        $optiondateid2 = (int)$sessions[1]->id;

        // The relations: every optiondate points to its own entity.
        $this->assertEquals(
            $roomaid,
            $DB->get_field('local_entities_relations', 'entityid', [
                'component' => 'mod_booking',
                'area' => 'optiondate',
                'instanceid' => $optiondateid1,
            ], MUST_EXIST)
        );
        $this->assertEquals(
            $roombid,
            $DB->get_field('local_entities_relations', 'entityid', [
                'component' => 'mod_booking',
                'area' => 'optiondate',
                'instanceid' => $optiondateid2,
            ], MUST_EXIST)
        );
        $this->assertSame(
            $optionentity ? 1 : 0,
            $DB->count_records('local_entities_relations', [
                'component' => 'mod_booking',
                'area' => 'option',
                'instanceid' => $optionid,
            ])
        );

        // Calendar of Room A: only the first date, also when the option itself is in Room A, because the
        // second date overrides the option entity with its own one.
        $datesa = localentities::get_all_dates_for_entity($roomaid);
        $this->assertCount(1, $datesa, 'Room A has exactly one date.');
        $datea = reset($datesa);
        $this->assertSame('mod_booking', $datea->component);
        $this->assertEquals($this->start1, $datea->starttime);
        $this->assertEquals($this->end1, $datea->endtime);
        $this->assertStringContainsString('Two rooms option', $datea->name);
        $this->assert_date_belongs_to_optiondate($datea, $optiondateid1, $optionid);

        // Calendar of Room B: only the second date.
        $datesb = localentities::get_all_dates_for_entity($roombid);
        $this->assertCount(1, $datesb, 'Room B has exactly one date.');
        $dateb = reset($datesb);
        $this->assertSame('mod_booking', $dateb->component);
        $this->assertEquals($this->start2, $dateb->starttime);
        $this->assertEquals($this->end2, $dateb->endtime);
        $this->assert_date_belongs_to_optiondate($dateb, $optiondateid2, $optionid);

        // The calendar pages load their events through the webservice: the same separation there.
        $eventsa = $this->get_calendar_events($roomaid);
        $this->assertCount(1, $eventsa);
        $this->assertSame($this->format_for_calendar($this->start1), $eventsa[0]->start);
        $this->assertSame($this->format_for_calendar($this->end1), $eventsa[0]->end);

        $eventsb = $this->get_calendar_events($roombid);
        $this->assertCount(1, $eventsb);
        $this->assertSame($this->format_for_calendar($this->start2), $eventsb[0]->start);
        $this->assertSame($this->format_for_calendar($this->end2), $eventsb[0]->end);
    }

    /**
     * Moving the second date to the first entity moves it between the calendars.
     *
     * @covers \mod_booking\option\fields\entities::save_data
     * @covers \mod_booking\booking::return_array_of_entity_dates
     * @covers \local_entities\entities::get_all_dates_for_entity
     */
    public function test_changing_entity_of_optiondate_moves_it_to_the_other_calendar(): void {
        [$optionid, $roomaid, $roombid] = $this->create_option_with_two_dates_and_entities(true);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $sessions = array_values($settings->sessions);
        usort($sessions, fn($a, $b) => $a->coursestarttime <=> $b->coursestarttime);

        // Warm both calendars.
        $this->assertCount(1, localentities::get_all_dates_for_entity($roomaid));
        $this->assertCount(1, localentities::get_all_dates_for_entity($roombid));

        // Now both dates take place in Room A.
        $record = new stdClass();
        $record->id = $optionid;
        $record->cmid = (int)$settings->cmid;
        $record->bookingid = (int)$settings->bookingid;
        $record->text = $settings->text;
        $record->identifier = $settings->identifier;
        $record->{LOCAL_ENTITIES_FORM_ENTITYID . 0} = $roomaid;
        foreach ($sessions as $i => $session) {
            $index = $i + 1;
            $record->{MOD_BOOKING_FORM_OPTIONDATEID . $index} = (int)$session->id;
            $record->{MOD_BOOKING_FORM_COURSESTARTTIME . $index} = (int)$session->coursestarttime;
            $record->{MOD_BOOKING_FORM_COURSEENDTIME . $index} = (int)$session->courseendtime;
            $record->{MOD_BOOKING_FORM_DAYSTONOTIFY . $index} = 0;
            $record->{LOCAL_ENTITIES_FORM_ENTITYAREA . $index} = 'optiondate';
            $record->{LOCAL_ENTITIES_FORM_ENTITYID . $index} = $roomaid;
        }
        booking_option::update($record);
        singleton_service::destroy_instance();

        $datesa = localentities::get_all_dates_for_entity($roomaid);
        $this->assertCount(2, $datesa, 'Room A now holds both dates.');
        $starts = array_map(fn($date) => (int)$date->starttime, $datesa);
        sort($starts);
        $this->assertSame([$this->start1, $this->start2], $starts);

        $this->assertCount(0, localentities::get_all_dates_for_entity($roombid), 'Room B is empty again.');
        $this->assertCount(0, $this->get_calendar_events($roombid));
    }

    /**
     * Data provider: the option itself is linked to the first entity or has no entity at all.
     *
     * @return array
     */
    public static function option_entity_provider(): array {
        return [
            'option in Room A, dates in Room A and Room B' => [true],
            'option without entity, dates in Room A and Room B' => [false],
        ];
    }

    /**
     * Create two entities and an option with two optiondates, the first in Room A, the second in Room B.
     *
     * @param bool $optionentity link the option itself to Room A
     * @return array [optionid, Room A id, Room B id]
     */
    private function create_option_with_two_dates_and_entities(bool $optionentity): array {
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Two rooms booking',
            'eventtype' => 'Test event',
            'course' => $course->id,
            'bookingmanager' => $manager->username,
        ]);
        $this->setAdminUser();

        /** @var \local_entities_generator $entitygenerator */
        $entitygenerator = self::getDataGenerator()->get_plugin_generator('local_entities');
        $roomaid = (int)$entitygenerator->create_entities(
            ['name' => 'Room A', 'shortname' => 'rooma', 'description' => 'Room A']
        );
        $roombid = (int)$entitygenerator->create_entities(
            ['name' => 'Room B', 'shortname' => 'roomb', 'description' => 'Room B']
        );
        $this->assertGreaterThan(0, $roomaid);
        $this->assertGreaterThan(0, $roombid);
        $this->assertNotEquals($roomaid, $roombid);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Two rooms option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->description = 'Description';
        $record->{LOCAL_ENTITIES_FORM_ENTITYID . 0} = $optionentity ? $roomaid : 0;
        $record->optiondateid_1 = "0";
        $record->daystonotify_1 = "0";
        $record->coursestarttime_1 = $this->start1;
        $record->courseendtime_1 = $this->end1;
        $record->{LOCAL_ENTITIES_FORM_ENTITYAREA . 1} = 'optiondate';
        $record->{LOCAL_ENTITIES_FORM_ENTITYID . 1} = $roomaid;
        $record->optiondateid_2 = "0";
        $record->daystonotify_2 = "0";
        $record->coursestarttime_2 = $this->start2;
        $record->courseendtime_2 = $this->end2;
        $record->{LOCAL_ENTITIES_FORM_ENTITYAREA . 2} = 'optiondate';
        $record->{LOCAL_ENTITIES_FORM_ENTITYID . 2} = $roombid;
        $option = $plugingenerator->create_option($record);

        singleton_service::destroy_instance();
        return [(int)$option->id, $roomaid, $roombid];
    }

    /**
     * The entity date is the given optiondate of the given option.
     *
     * @param \local_entities\local\entities\entitydate $date
     * @param int $optiondateid
     * @param int $optionid
     * @return void
     */
    private function assert_date_belongs_to_optiondate($date, int $optiondateid, int $optionid): void {
        $this->assertSame('optiondate', $date->area);
        $this->assertEquals($optiondateid, $date->itemid);
        $this->assertEquals($optionid, (int)$date->link->get_param('optionid'));
    }

    /**
     * The events of an entity calendar as the calendar page receives them from the webservice.
     *
     * @param int $entityid
     * @return array of event objects, sorted by start
     */
    private function get_calendar_events(int $entityid): array {
        $result = get_entity_calendardata::execute($entityid);
        $this->assertSame('', $result['error']);
        $events = json_decode($result['json']);
        $this->assertIsArray($events);
        usort($events, fn($a, $b) => strcmp($a->start, $b->start));
        return $events;
    }

    /**
     * Timestamp in the format the calendar JSON uses.
     *
     * @param int $timestamp
     * @return string
     */
    private function format_for_calendar(int $timestamp): string {
        // Same as local_entities\entities::prepare_datearray_for_calendar(): default timezone of PHP.
        $date = new \DateTime();
        $date->setTimestamp($timestamp);
        return $date->format('Y-m-d') . 'T' . $date->format('H:i:s');
    }
}

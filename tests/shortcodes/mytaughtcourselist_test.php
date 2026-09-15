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
 * Tests for the mytaughtcourselist shortcode.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use context_user;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class handling tests for the mytaughtcourselist shortcode.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mytaughtcourselist_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * The shortcode lists only the options the user teaches, not the ones the user booked.
     *
     * @covers \mod_booking\shortcodes::mytaughtcourselist
     *
     * @param array $data
     * @param array $expected
     *
     * @dataProvider mytaughtcourselist_provider
     */
    public function test_mytaughtcourselist_shortcode(array $data, array $expected): void {
        global $PAGE;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $teacher1 = $this->getDataGenerator()->create_user();
        $teacher2 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($teacher1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher2->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $bdata = [
            'name' => 'Test Booking',
            'eventtype' => 'Test event',
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'enablecompletion' => 1,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Two options taught by teacher1, one taught by teacher2, one without teacher.
        $definitions = [
            ['text' => 'Taught by teacher1 A', 'teachers' => $teacher1->username],
            ['text' => 'Taught by teacher1 B', 'teachers' => $teacher1->username],
            ['text' => 'Taught by teacher2', 'teachers' => $teacher2->username],
            ['text' => 'No teacher', 'teachers' => ''],
        ];
        $options = [];
        foreach ($definitions as $definition) {
            $record = new stdClass();
            $record->bookingid = $booking->id;
            $record->text = $definition['text'];
            $record->chooseorcreatecourse = 1;
            $record->courseid = $course->id;
            $record->maxanswers = 5;
            if (!empty($definition['teachers'])) {
                $record->teachersforoption = $definition['teachers'];
            }
            $options[] = $plugingenerator->create_option($record);
        }

        // Teacher1 also books the option without teacher: it must not show up in the taught list.
        $this->setUser($teacher1);
        booking_bookit::bookit('option', $options[3]->id, $teacher1->id);
        booking_bookit::bookit('option', $options[3]->id, $teacher1->id);

        if (isset($data['settings'])) {
            foreach ($data['settings'] as $key => $value) {
                set_config($key, $value, 'booking');
            }
        }

        $args = $data['args'];
        if (!empty($data['cmid'])) {
            $args['cmid'] = $booking->cmid;
        }
        if (!empty($data['userid'])) {
            $userids = ['teacher1' => $teacher1->id, 'teacher2' => $teacher2->id];
            $args['userid'] = $userids[$data['userid']];
        }

        $this->setUser($teacher1);
        $PAGE->set_context(context_user::instance($teacher1->id));
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/mytaughtcourselist_test.php'));

        $env = new stdClass();
        $next = function () {
        };
        $out = shortcodes::mytaughtcourselist('mytaughtcourselist', $args, null, $env, $next);
        $this->assertNotEmpty($out);
        $this->assertStringContainsString($expected['tablestringcontains'], $out);

        $pregmatch = preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $out, $matches);
        $this->assertEquals($expected['displaytable'], $pregmatch);
        if (!$expected['displaytable']) {
            return;
        }
        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        $this->assertEquals($expected['numberofrecords'], $table->totalrows);

        $texts = array_map(fn($row) => $row->text, $table->rawdata ?? []);
        sort($texts);
        $this->assertEquals($expected['texts'], array_values($texts));
    }

    /**
     * Data provider for test_mytaughtcourselist_shortcode.
     *
     * @return array
     */
    public static function mytaughtcourselist_provider(): array {
        return [
            'settingoff' => [
                [
                    'args' => [],
                    'settings' => [
                        'shortcodesoff' => 1,
                    ],
                ],
                [
                    'tablestringcontains' => "shortcodes are turned off",
                    'displaytable' => false,
                ],
            ],
            'currentuser' => [
                [
                    'args' => [],
                ],
                [
                    'displaytable' => true,
                    'tablestringcontains' => 'wunderbyte_table_container',
                    'numberofrecords' => 2,
                    'texts' => ['Taught by teacher1 A', 'Taught by teacher1 B'],
                ],
            ],
            'currentuserwithcmid' => [
                [
                    'args' => [],
                    'cmid' => true,
                ],
                [
                    'displaytable' => true,
                    'tablestringcontains' => 'wunderbyte_table_container',
                    'numberofrecords' => 2,
                    'texts' => ['Taught by teacher1 A', 'Taught by teacher1 B'],
                ],
            ],
            'otheruser' => [
                [
                    'args' => [],
                    'userid' => 'teacher2',
                ],
                [
                    'displaytable' => true,
                    'tablestringcontains' => 'wunderbyte_table_container',
                    'numberofrecords' => 1,
                    'texts' => ['Taught by teacher2'],
                ],
            ],
        ];
    }
}

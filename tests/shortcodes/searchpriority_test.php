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
 * Tests for the shortcode argument priority (order of the search results).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use local_wunderbyte_table\local\customfield\wbt_field_controller_info;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/../classes/booking_advanced_testcase.php');

/**
 * Tests for the shortcode argument priority (order of the search results).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_booking\shortcodes::set_common_table_options_from_arguments
 * @covers \mod_booking\booking::get_valid_fulltextsearchcolumns
 */
final class searchpriority_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
        // The customfield lookups of wunderbyte table are request-static; isolate from other tests in this process.
        wbt_field_controller_info::purge_static_caches();
        wbt_field_controller_info::purge_resolved_value_mapping_cache();
    }

    /**
     * The argument priority orders the search results of [courselist] by the given columns.
     *
     * @dataProvider priority_provider
     * @param array $args shortcode arguments (without cmid)
     * @param array $expected expected priority columns and search results in their order
     */
    public function test_priority_argument(array $args, array $expected): void {
        $cmid = $this->create_booking_with_options();

        $next = function () {
        };
        $args = array_merge(['cmid' => $cmid, 'all' => 1], $args);
        $html = shortcodes::courselist('courselist', $args, null, new stdClass(), $next);
        if (!empty($expected['debugging'])) {
            $this->assertDebuggingCalled($expected['debugging']);
        }
        $this->assertSame(1, preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $html, $matches));

        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $this->assertSame($expected['prioritycolumns'], $table->prioritycolumns);

        foreach ($expected['searchresults'] as $searchtext => $texts) {
            $searchtable = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
            $searchtable->apply_searchtext($searchtext);
            $searchtable->printtable($searchtable->pagesize, $searchtable->useinitialsbar, $searchtable->downloadhelpbutton);
            $this->assertSame(
                $texts,
                array_values(array_map(fn($row) => $row->text, $searchtable->rawdata)),
                "Unexpected order of the results when searching for '$searchtext'"
            );
        }
    }

    /**
     * Data provider for test_priority_argument.
     *
     * The booking options are (titleprefix | text | description | select customfield sport):
     * 424 | Pilates, 4240 | Tanz, A3 | 424, A4 | Kurs 424 Yoga, A5 | Yoga | Trainer 424, A6 | Zumba | | Yoga.
     * Without priority, the results are sorted by text.
     *
     * @return array
     */
    public static function priority_provider(): array {
        $fulltextsearchcolumns = 'description,sport';
        return [
            'without priority the order does not change' => [
                ['fulltextsearchcolumns' => $fulltextsearchcolumns],
                [
                    'prioritycolumns' => [],
                    'searchresults' => [
                        '424' => ['424', 'Kurs 424 Yoga', 'Pilates', 'Tanz', 'Yoga'],
                        'Yoga' => ['Kurs 424 Yoga', 'Yoga', 'Zumba'],
                    ],
                ],
            ],
            'priority titleprefix, text' => [
                ['fulltextsearchcolumns' => $fulltextsearchcolumns, 'priority' => 'titleprefix,text'],
                [
                    'prioritycolumns' => ['titleprefix', 'text'],
                    'searchresults' => [
                        // Titleprefix equals, titleprefix contains, text equals, text contains, only in the description.
                        '424' => ['Pilates', 'Tanz', '424', 'Kurs 424 Yoga', 'Yoga'],
                        // Text equals, text contains, only in the customfield.
                        'Yoga' => ['Yoga', 'Kurs 424 Yoga', 'Zumba'],
                    ],
                ],
            ],
            'a select customfield compares its label' => [
                ['fulltextsearchcolumns' => $fulltextsearchcolumns, 'priority' => 'sport, text'],
                [
                    'prioritycolumns' => ['sport', 'text'],
                    'searchresults' => [
                        // The label of the option equals, text equals, text contains.
                        'Yoga' => ['Zumba', 'Yoga', 'Kurs 424 Yoga'],
                    ],
                ],
            ],
            'invalid columns are ignored' => [
                ['fulltextsearchcolumns' => $fulltextsearchcolumns, 'priority' => 'titleprefix,doesnotexist'],
                [
                    'prioritycolumns' => ['titleprefix'],
                    'debugging' => "Invalid column 'doesnotexist' in priority shortcode argument was ignored.",
                    'searchresults' => [
                        '424' => ['Pilates', 'Tanz', '424', 'Kurs 424 Yoga', 'Yoga'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Creates a booking instance with the booking options of the tests.
     *
     * @return int cmid of the booking instance
     */
    private function create_booking_with_options(): int {
        $this->setAdminUser();

        // A select customfield stores the index of the option: 1 => Yoga, 2 => Tennis.
        $bookingcat = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'BookCustomCat1',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $bookingcat->save();
        $bookingfield = $this->getDataGenerator()->create_custom_field([
            'categoryid' => $bookingcat->get('id'),
            'name' => 'Sport',
            'shortname' => 'sport',
            'type' => 'select',
            'configdata' => '{"required":"0","uniquevalues":"0","options":"Yoga\r\nTennis",'
                . '"defaultvalue":"","locked":"0","visibility":"0"}',
        ]);
        $bookingfield->save();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Test Booking Search Priority',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
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
            'completion' => 2,
            'showviews' => ['showall'],
            'course' => $course->id,
        ]);

        $options = [
            ['titleprefix' => '424', 'text' => 'Pilates', 'description' => ''],
            ['titleprefix' => '4240', 'text' => 'Tanz', 'description' => ''],
            ['titleprefix' => 'A3', 'text' => '424', 'description' => ''],
            ['titleprefix' => 'A4', 'text' => 'Kurs 424 Yoga', 'description' => ''],
            ['titleprefix' => 'A5', 'text' => 'Yoga', 'description' => 'Trainer 424', 'customfield_sport' => '2'],
            ['titleprefix' => 'A6', 'text' => 'Zumba', 'description' => '', 'customfield_sport' => '1'],
        ];
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        foreach ($options as $option) {
            $record = (object) $option;
            $record->bookingid = $booking->id;
            $record->maxanswers = 1;
            $plugingenerator->create_option($record);
        }
        return (int)$booking->cmid;
    }
}

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
 * Tests for the order of the search results: shortcode argument priority and the instance setting
 * "Priority of the search results".
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
     * The option "Tanz" has the teacher Tina Lehrer424.
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
            'the names of the teachers can have priority' => [
                ['fulltextsearchcolumns' => $fulltextsearchcolumns, 'priority' => 'teachernames,text'],
                [
                    'prioritycolumns' => ['teachernames', 'text'],
                    'searchresults' => [
                        // Teacher contains, text equals, text contains, all others sorted by text.
                        '424' => ['Tanz', '424', 'Kurs 424 Yoga', 'Pilates', 'Yoga'],
                    ],
                ],
            ],
            'teacher and teachers are aliases of teachernames' => [
                ['fulltextsearchcolumns' => $fulltextsearchcolumns, 'priority' => 'teacher,text,teachers'],
                [
                    'prioritycolumns' => ['teachernames', 'text'],
                    'searchresults' => [
                        '424' => ['Tanz', '424', 'Kurs 424 Yoga', 'Pilates', 'Yoga'],
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
     * The instance setting "Priority of the search results" orders the search results of the instance.
     *
     * It applies to the view of the booking instance and to shortcodes of the instance without the argument
     * priority. The argument replaces the setting.
     *
     * @covers \mod_booking\output\view::apply_standard_params_for_bookingtable
     * @covers \mod_booking\booking::prepare_searchprioritycolumns
     */
    public function test_instance_setting(): void {
        global $PAGE;

        // The setting comes as a comma separated string from the form.
        $cmid = $this->create_booking_with_options(['searchprioritycolumns' => 'titleprefix, text']);
        singleton_service::destroy_instance();
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $this->assertSame(['titleprefix', 'text'], (array)$bookingsettings->searchprioritycolumns);

        $next = function () {
        };
        $priorityresults = ['Pilates', 'Tanz', '424', 'Kurs 424 Yoga', 'Yoga'];

        // 1. A shortcode of the instance without the argument priority uses the setting.
        $args = ['cmid' => $cmid, 'all' => 1, 'fulltextsearchcolumns' => 'description,sport'];
        $html = shortcodes::courselist('courselist', $args, null, new stdClass(), $next);
        $this->assertSame($priorityresults, $this->search_table($html, '424', ['titleprefix', 'text']));

        // 2. The argument priority replaces the setting, its columns are not appended to it.
        // The table of a shortcode is cached per page, so the cache is emptied between the shortcodes of this test.
        \cache::make('local_wunderbyte_table', 'encodedtables')->purge();
        $html = shortcodes::courselist('courselist', $args + ['priority' => 'text'], null, new stdClass(), $next);
        $this->assertSame(
            ['424', 'Kurs 424 Yoga', 'Pilates', 'Tanz', 'Yoga'],
            $this->search_table($html, '424', ['text'])
        );

        // 3. The view of the booking instance uses the setting.
        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $cmid]));
        $_GET['id'] = (string) $cmid;
        $html = (new \mod_booking\output\view($cmid, 'showall'))->get_rendered_all_options_table();
        // The view also searches the description ("Trainer 424" of the option "Yoga").
        $this->assertSame($priorityresults, $this->search_table($html, '424', ['titleprefix', 'text']));

        // 4. A shortcode of several booking instances has no single setting to use.
        \cache::make('local_wunderbyte_table', 'encodedtables')->purge();
        $othercmid = $this->create_booking_with_options(['searchprioritycolumns' => 'text'], false);
        $args = ['cmid' => "$cmid,$othercmid", 'all' => 1, 'search' => 1];
        $html = shortcodes::courselist('courselist', $args, null, new stdClass(), $next);
        $this->search_table($html, '424', []);
    }

    /**
     * Without the instance setting, nothing changes: the search results keep the sorting of the table.
     *
     * This is the state of all booking instances that existed before the setting, and of instances
     * created by code. Only the form of a new instance presets "titleprefix,text".
     *
     * @covers \mod_booking\output\view::apply_standard_params_for_bookingtable
     */
    public function test_instance_without_setting_has_no_priority(): void {
        global $DB, $PAGE;

        $cmid = $this->create_booking_with_options();
        $json = (string)$DB->get_field('booking', 'json', ['id' => $DB->get_field('course_modules', 'instance', ['id' => $cmid])]);
        $this->assertStringNotContainsString('searchprioritycolumns', $json);

        $next = function () {
        };
        // The search also reads the description ("Trainer 424" of the option "Yoga").
        $html = shortcodes::courselist('courselist', ['cmid' => $cmid, 'all' => 1, 'search' => 1], null, new stdClass(), $next);
        $this->assertSame(['424', 'Kurs 424 Yoga', 'Pilates', 'Tanz', 'Yoga'], $this->search_table($html, '424', []));

        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $cmid]));
        $_GET['id'] = (string) $cmid;
        $html = (new \mod_booking\output\view($cmid, 'showall'))->get_rendered_all_options_table();
        // The view also searches the description ("Trainer 424" of the option "Yoga").
        $this->assertSame(['424', 'Kurs 424 Yoga', 'Pilates', 'Tanz', 'Yoga'], $this->search_table($html, '424', []));

        $this->assertSame(['titleprefix', 'text'], booking::DEFAULT_SEARCHPRIORITYCOLUMNS);
    }

    /**
     * Saving a booking instance only changes the setting when it is submitted.
     *
     * @covers ::booking_update_instance
     * @covers \mod_booking\booking::prepare_searchprioritycolumns
     * @covers \mod_booking\booking::split_fulltextsearchcolumns
     */
    public function test_update_instance_setting(): void {
        global $DB;

        $cmid = $this->create_booking_with_options(['searchprioritycolumns' => ['titleprefix', 'text']]);
        $bookingid = (int)$DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $stored = function () use ($DB, $bookingid) {
            $json = json_decode((string)$DB->get_field('booking', 'json', ['id' => $bookingid]), true) ?? [];
            return $json['searchprioritycolumns'] ?? null;
        };
        $update = function (array $changes) use ($DB, $bookingid) {
            $record = $DB->get_record('booking', ['id' => $bookingid], '*', MUST_EXIST);
            $record->instance = $record->id;
            foreach ($changes as $key => $value) {
                $record->$key = $value;
            }
            booking_update_instance($record);
        };
        $this->assertSame(['titleprefix', 'text'], $stored());

        // An update that does not submit the setting (code, web service) keeps it.
        $update(['name' => 'Renamed']);
        $this->assertSame(['titleprefix', 'text'], $stored());

        // A new order with a custom field. Unknown columns never reach the database.
        $update(['searchprioritycolumns' => 'sport,text, doesnotexist']);
        $this->assertDebuggingCalled("Invalid column 'doesnotexist' in searchprioritycolumns shortcode argument was ignored.");
        $this->assertSame(['sport', 'text'], $stored());

        // An empty setting removes the priority.
        $update(['searchprioritycolumns' => '']);
        $this->assertNull($stored());

        // The form reports unknown columns instead of ignoring them.
        $this->assertSame(
            [['text', 'sport', 'teachernames'], ['doesnotexist', 'teacherobjects']],
            booking::split_fulltextsearchcolumns(
                ['text', ' sport', 'doesnotexist', 'text', '', 'teachernames', 'teacherobjects']
            )
        );
    }

    /**
     * The aliases teacher and teachers mean the column teachernames, unless a customfield has that shortname.
     *
     * @covers \mod_booking\booking::split_fulltextsearchcolumns
     * @covers \mod_booking\booking::prepare_searchprioritycolumns
     */
    public function test_teacher_aliases(): void {
        $this->create_sport_customfield();

        $this->assertSame(
            [['teachernames', 'text'], []],
            booking::split_fulltextsearchcolumns(['teacher', 'text', ' teachers ', 'teachernames'])
        );
        // The instance setting stores the name of the column, not the alias.
        $this->assertSame(['text', 'teachernames'], booking::prepare_searchprioritycolumns('text,teachers'));

        // A booking customfield with the shortname "teacher" is a column of its own and keeps its meaning.
        $category = \core_customfield\category::get_record(['component' => 'mod_booking', 'area' => 'booking']);
        $field = $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'Teacher',
            'shortname' => 'teacher',
            'type' => 'text',
            'configdata' => '',
        ]);
        $field->save();
        \cache_helper::purge_all();
        $this->assertSame(
            [['teacher', 'teachernames'], []],
            booking::split_fulltextsearchcolumns(['teacher', 'teachers'])
        );
    }

    /**
     * Searches in the booking options table of the rendered html.
     *
     * @param string $html rendered table
     * @param string $searchtext
     * @param array $prioritycolumns expected priority columns of the table
     * @return array the names of the booking options found, in their order
     */
    private function search_table(string $html, string $searchtext, array $prioritycolumns): array {
        $this->assertSame(1, preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $html, $matches));
        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $this->assertSame($prioritycolumns, $table->prioritycolumns);
        $table->apply_searchtext($searchtext);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        return array_values(array_map(fn($row) => $row->text, $table->rawdata));
    }

    /**
     * Creates the select customfield "sport" of the tests.
     */
    private function create_sport_customfield(): void {

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
    }

    /**
     * Creates a booking instance with the booking options of the tests.
     *
     * @param array $instancesettings additional settings of the booking instance
     * @param bool $createcustomfield false for a second booking instance, the customfield exists then
     * @return int cmid of the booking instance
     */
    private function create_booking_with_options(array $instancesettings = [], bool $createcustomfield = true): int {
        $this->setAdminUser();

        if ($createcustomfield) {
            $this->create_sport_customfield();
        }

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
        ] + $instancesettings);

        // The teacher of the option "Tanz": the search text "424" is part of the last name.
        $teacher = $this->getDataGenerator()->create_user(['firstname' => 'Tina', 'lastname' => 'Lehrer424']);

        $options = [
            ['titleprefix' => '424', 'text' => 'Pilates', 'description' => ''],
            ['titleprefix' => '4240', 'text' => 'Tanz', 'description' => '', 'teachersforoption' => $teacher->username],
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

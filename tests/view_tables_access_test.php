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
use context_module;
use local_wunderbyte_table\external\load_data;
use mod_booking\output\view;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The tables with the booking options of an instance are cached and reloaded by their hash
 * (paging, filters, lazy loading). On this way the table has to check what the page checked
 * that showed it: the right to view the instance, to see invisible options or to run bulk
 * operations, and for personal lists that the user is the one the list was built for.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\output\view
 * @covers \mod_booking\table\bulkoperations_table
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class view_tables_access_test extends advanced_testcase {
    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /** @var int Course module id of the booking instance. */
    private int $cmid;

    /**
     * Tests set up: an instance with a visible and an invisible option.
     */
    public function setUp(): void {
        global $DB, $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        singleton_service::destroy_instance();

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        foreach (['student', 'otherstudent', 'noview', 'seesinvisible', 'bulkoperator', 'teacher'] as $name) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'student');
        }

        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $this->cmid = (int)$booking->cmid;
        $modulecontext = context_module::instance($this->cmid);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Visible option',
            'courseid' => $course->id,
            'maxanswers' => 5,
            'teachersforoption' => $this->users['teacher']->username,
        ]);
        $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Invisible option',
            'courseid' => $course->id,
            'maxanswers' => 5,
            'invisible' => 1,
        ]);

        $DB->insert_record('booking_answers', (object)[
            'bookingid' => (int)$booking->id,
            'optionid' => (int)$option->id,
            'userid' => (int)$this->users['student']->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'places' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->set_capabilities('noview', ['mod/booking:view' => CAP_PROHIBIT], $modulecontext);
        $this->set_capabilities('seesinvisible', ['mod/booking:canseeinvisibleoptions' => CAP_ALLOW], $modulecontext);
        $this->set_capabilities('bulkoperator', ['mod/booking:executebulkoperations' => CAP_ALLOW], $modulecontext);

        $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $this->cmid]));
        $PAGE->set_context($modulecontext);

        singleton_service::destroy_instance();
        \cache_helper::purge_by_event('changesinwunderbytetable');
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Set capabilities for a user in a context.
     *
     * @param string $username
     * @param int[] $capabilities capability => permission
     * @param \context $context
     * @return void
     */
    private function set_capabilities(string $username, array $capabilities, \context $context): void {
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability => $permission) {
            assign_capability($capability, $permission, $roleid, $context->id, true);
        }
        role_assign($roleid, $this->users[$username]->id, $context->id);
    }

    /**
     * Render one table of the instance as the current user, like view.php does, and return the
     * hash the browser uses to reload it.
     *
     * @param string $method name of the method of the view class that renders the table
     * @param array $params parameters of the method
     * @return string
     */
    private function render_table_and_get_hash(string $method, array $params = []): string {
        // The view class without any table; the table is rendered by the method.
        $view = new view($this->cmid, 'shownothing', 0, true);
        $html = (string)$view->{$method}(...$params);

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $hash = '';
        foreach ((new \DOMXPath($dom))->query('//*[@data-encodedtable]') as $node) {
            $hash = $node->getAttribute('data-encodedtable');
            break;
        }
        $this->assertNotEmpty($hash, "The table of $method was not rendered.");
        return $hash;
    }

    /**
     * Number of rows the data webservice delivers to the current user for a cached table.
     *
     * @param string $hash
     * @return int
     */
    private function count_loaded_rows(string $hash): int {
        $result = load_data::execute($hash, 0, '', '', '', 0, 0, '', '');
        $content = json_decode($result['content'], true);
        return count($content['table']['rows'] ?? []);
    }

    /**
     * Assert who gets a cached table.
     *
     * @param string $hash
     * @param string[] $allowed names of users who get rows
     * @param string[] $denied names of users who get nothing
     * @param string $label
     * @return void
     */
    private function assert_audience(string $hash, array $allowed, array $denied, string $label): void {
        foreach ($allowed as $username) {
            $this->setUser($this->users[$username]);
            $this->assertGreaterThan(0, $this->count_loaded_rows($hash), "User $username has to get the table: $label.");
        }
        foreach ($denied as $username) {
            $this->setUser($this->users[$username]);
            $this->assertSame(0, $this->count_loaded_rows($hash), "User $username must not get the table: $label.");
        }
    }

    /**
     * The list of all options is for everybody who may view the instance.
     */
    public function test_option_list_needs_the_right_to_view_the_instance(): void {
        $this->setAdminUser();
        $hash = $this->render_table_and_get_hash('get_rendered_all_options_table');

        $this->assert_audience($hash, ['student', 'otherstudent', 'seesinvisible'], ['noview'], 'all options');
    }

    /**
     * The list of invisible options is for users who may see invisible options.
     */
    public function test_list_of_invisible_options_needs_the_right_to_see_them(): void {
        $this->setAdminUser();
        $hash = $this->render_table_and_get_hash('get_rendered_invisible_options_table');

        $this->assert_audience($hash, ['seesinvisible'], ['student', 'noview'], 'invisible options');
    }

    /**
     * The list of the own bookings is only delivered to the user it was built for.
     */
    public function test_list_of_own_bookings_belongs_to_one_user(): void {
        $this->setUser($this->users['student']);
        $hash = $this->render_table_and_get_hash('get_rendered_my_booked_options_table');

        $this->assert_audience($hash, ['student'], ['otherstudent', 'seesinvisible'], 'my bookings');
    }

    /**
     * The table for bulk operations is for users who may run bulk operations.
     */
    public function test_bulk_operations_table_needs_the_right_for_bulk_operations(): void {
        $this->setAdminUser();
        $hash = $this->render_table_and_get_hash('get_rendered_bulkoperations_table');

        $this->assert_audience($hash, ['bulkoperator'], ['student', 'seesinvisible'], 'bulk operations');
    }

    /**
     * The options of a teacher are public, like the page of the teacher.
     */
    public function test_options_of_a_teacher_stay_public(): void {
        $this->setUser($this->users['student']);
        $hash = $this->render_table_and_get_hash(
            'get_rendered_table_for_teacher',
            [(int)$this->users['teacher']->id, false, false, false]
        );

        $this->assert_audience($hash, ['student', 'otherstudent', 'noview'], [], 'options of a teacher');
    }
}

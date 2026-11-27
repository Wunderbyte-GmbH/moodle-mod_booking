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
use context_course;
use context_system;
use local_wunderbyte_table\external\load_data;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The tables of the shortcodes are cached and reloaded by their hash. The lists of all booking
 * options are for every logged in user. The list of the bookings of a user is personal: it is
 * only built for and delivered to this user or to users who may act for others.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\shortcodes
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class shortcode_tables_access_test extends advanced_testcase {
    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /**
     * Tests set up: an instance with two options, one of them booked by the student.
     */
    public function setUp(): void {
        global $DB, $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        singleton_service::destroy_instance();

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        foreach (['student', 'otherstudent', 'booksforothers'] as $name) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'student');
        }
        // A user of the site who is not in the course.
        $this->users['siteuser'] = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $roleid, context_system::instance()->id, true);
        role_assign($roleid, $this->users['booksforothers']->id, context_system::instance()->id);

        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Booked option',
            'courseid' => $course->id,
            'maxanswers' => 5,
        ]);
        $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Other option',
            'courseid' => $course->id,
            'maxanswers' => 5,
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

        // Shortcodes are used on pages outside of the booking instance.
        $PAGE->set_context(context_system::instance());
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/shortcode_tables_access_test.php'));

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
     * Render a shortcode as the current user and return the hash the browser uses to reload its table.
     * The hash is empty when the shortcode has no rows and therefore shows no table.
     *
     * @param string $shortcode
     * @param array $args
     * @return string
     */
    private function render_shortcode_and_get_hash(string $shortcode, array $args = []): string {
        $env = new stdClass();
        $next = function () {
        };
        $html = (string)shortcodes::{$shortcode}($shortcode, $args, null, $env, $next);

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $hash = '';
        foreach ((new \DOMXPath($dom))->query('//*[@data-encodedtable]') as $node) {
            $hash = $node->getAttribute('data-encodedtable');
            break;
        }
        return $hash;
    }

    /**
     * Number of rows the data webservice delivers to a user for a cached table.
     *
     * @param string $username
     * @param string $hash
     * @return int
     */
    private function count_loaded_rows(string $username, string $hash): int {
        $this->setUser($this->users[$username]);
        $result = load_data::execute($hash, 0, '', '', '', 0, 0, '', '');
        $content = json_decode($result['content'], true);
        return count($content['table']['rows'] ?? []);
    }

    /**
     * The list of all booking options stays available for every logged in user, also for users
     * who are not in the course of the booking instance.
     */
    public function test_list_of_all_options_is_for_every_logged_in_user(): void {
        $this->setUser($this->users['student']);
        $hash = $this->render_shortcode_and_get_hash('allbookingoptions', ['all' => 1]);
        $this->assertNotEmpty($hash);

        $this->assertSame(2, $this->count_loaded_rows('student', $hash));
        $this->assertSame(2, $this->count_loaded_rows('otherstudent', $hash));
        $this->assertSame(2, $this->count_loaded_rows('siteuser', $hash));
    }

    /**
     * The list of the own bookings is delivered to its user and to users who may act for others.
     */
    public function test_list_of_own_bookings_is_personal(): void {
        $this->setUser($this->users['student']);
        $hash = $this->render_shortcode_and_get_hash('mycourselist');
        $this->assertNotEmpty($hash);

        $this->assertSame(1, $this->count_loaded_rows('student', $hash));
        $this->assertSame(1, $this->count_loaded_rows('booksforothers', $hash));
        $this->assertSame(0, $this->count_loaded_rows('otherstudent', $hash));
        $this->assertSame(0, $this->count_loaded_rows('siteuser', $hash));
    }

    /**
     * The shortcode only lists the bookings of another user for users who may act for others.
     * Everybody else gets the list of the own bookings.
     */
    public function test_bookings_of_another_user_need_the_right_to_act_for_others(): void {
        $args = ['userid' => (string)$this->users['student']->id];

        $this->setUser($this->users['otherstudent']);
        $hash = $this->render_shortcode_and_get_hash('mycourselist', $args);
        // The other student has no bookings: there is no table, or a table without rows.
        $this->assertSame(0, $hash === '' ? 0 : $this->count_loaded_rows('otherstudent', $hash));

        $this->setUser($this->users['booksforothers']);
        $hash = $this->render_shortcode_and_get_hash('mycourselist', $args);
        $this->assertNotEmpty($hash);
        $this->assertSame(1, $this->count_loaded_rows('booksforothers', $hash));
        $this->assertSame(0, $this->count_loaded_rows('otherstudent', $hash));
    }
}

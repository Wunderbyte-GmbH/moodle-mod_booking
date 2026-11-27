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
use mod_booking\local\download_access;
use mod_booking\local\table_access;
use mod_booking\output\booked_users;
use mod_booking\output\view;
use mod_booking\table\optiondates_teachers_table;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The download scripts export a cached table that the request refers to. The rights are checked
 * for the table that is exported, and a right in one booking instance does not export the table
 * of another.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\download_access
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class download_access_test extends advanced_testcase {
    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /** @var int[] Course module ids of the two booking instances. */
    private array $cmids = [];

    /** @var int[] Ids of the booking options, one per instance. */
    private array $optionids = [];

    /**
     * Tests set up: two booking instances in two courses, each with a booked option.
     */
    public function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        singleton_service::destroy_instance();

        $this->setAdminUser();

        foreach (['student', 'privileged'] as $name) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        foreach ([0, 1] as $index) {
            $course = $this->getDataGenerator()->create_course();
            $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
            $this->cmids[$index] = (int)$booking->cmid;
            foreach ($this->users as $user) {
                $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            }
            $option = $plugingenerator->create_option((object)[
                'bookingid' => $booking->id,
                'text' => 'Option ' . $index,
                'courseid' => $course->id,
                'maxanswers' => 5,
            ]);
            $this->optionids[$index] = (int)$option->id;
            $DB->insert_record('booking_answers', (object)[
                'bookingid' => (int)$booking->id,
                'optionid' => (int)$option->id,
                'userid' => (int)$this->users['student']->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
                'places' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        // The privileged user has all rights of the downloads, but only in the first booking instance.
        $context = context_module::instance($this->cmids[0]);
        $roleid = $this->getDataGenerator()->create_role();
        foreach (['mod/booking:viewreports', 'mod/booking:downloadresponses', 'mod/booking:updatebooking'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $this->users['privileged']->id, $context->id);

        singleton_service::destroy_instance();
        \cache_helper::purge_by_event('changesinwunderbytetable');
        \cache::make('mod_booking', 'bookedusertable')->purge();
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
     * Whether a download is refused for a user.
     *
     * @param string $username
     * @param callable $download loads the table of the download
     * @return bool
     */
    private function is_refused(string $username, callable $download): bool {
        $this->setUser($this->users[$username]);
        try {
            $download();
        } catch (\moodle_exception $e) {
            return true;
        }
        return false;
    }

    /**
     * The download of the Bookings tracker exports the table of the scope the right was checked for.
     */
    public function test_tracker_download_is_bound_to_the_scope_of_the_table(): void {
        $this->setAdminUser();
        $hashes = [];
        foreach ([0, 1] as $index) {
            $table = (new booked_users())->return_raw_table('option', $this->optionids[$index], MOD_BOOKING_STATUSPARAM_BOOKED);
            $hashes[$index] = $table->tablecachehash;
        }
        [$own, $other] = $this->optionids;

        $this->assertFalse($this->is_refused(
            'privileged',
            fn() => download_access::users_table_of_scope($hashes[0], 'option', $own)
        ));
        // The right in the own option does not export the table of the other option.
        $this->assertTrue($this->is_refused(
            'privileged',
            fn() => download_access::users_table_of_scope($hashes[1], 'option', $own)
        ));
        $this->assertTrue($this->is_refused(
            'privileged',
            fn() => download_access::users_table_of_scope($hashes[1], 'option', $other)
        ));
        $this->assertTrue($this->is_refused(
            'student',
            fn() => download_access::users_table_of_scope($hashes[0], 'option', $own)
        ));
        // A reference without a cached table.
        $this->assertTrue($this->is_refused(
            'privileged',
            fn() => download_access::users_table_of_scope(md5('unknown'), 'option', $own)
        ));
    }

    /**
     * The download of the booking options needs the right to edit the booking instance and
     * exports the table of this instance.
     */
    public function test_options_download_is_bound_to_the_booking_instance(): void {
        global $PAGE;

        $this->setAdminUser();
        $hashes = [];
        foreach ([0, 1] as $index) {
            $PAGE->set_url(new \moodle_url('/mod/booking/view.php', ['id' => $this->cmids[$index]]));
            $html = (new view($this->cmids[$index], 'shownothing', 0, true))->get_rendered_all_options_table();
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            foreach ((new \DOMXPath($dom))->query('//*[@data-encodedtable]') as $node) {
                $hashes[$index] = $node->getAttribute('data-encodedtable');
                break;
            }
            $this->assertNotEmpty($hashes[$index] ?? '', 'The table of the options was not rendered.');
        }
        [$own, $other] = $this->cmids;

        $this->assertFalse($this->is_refused(
            'privileged',
            fn() => download_access::options_table_of_instance($hashes[0], $own)
        ));
        // The right in the own instance does not export the table of the other instance.
        $this->assertTrue($this->is_refused(
            'privileged',
            fn() => download_access::options_table_of_instance($hashes[1], $own)
        ));
        $this->assertTrue($this->is_refused(
            'privileged',
            fn() => download_access::options_table_of_instance($hashes[1], $other)
        ));
        $this->assertTrue($this->is_refused(
            'student',
            fn() => download_access::options_table_of_instance($hashes[0], $own)
        ));
    }

    /**
     * The download of the teachers report follows the audience of its table, and a reference to
     * another kind of table is refused.
     */
    public function test_teachers_report_download_follows_the_table(): void {
        $this->setAdminUser();
        $table = new optiondates_teachers_table('optiondates_teachers_table_' . $this->optionids[0]);
        table_access::declare_any_capability(
            $table,
            context_module::instance($this->cmids[0]),
            ['mod/booking:viewreports']
        );
        $table->set_sql('*', '{booking_optiondates}', '1=1', []);
        $hash = $table->return_encoded_table();

        $this->assertFalse($this->is_refused('privileged', fn() => download_access::teachers_report_table($hash)));
        $this->assertTrue($this->is_refused('student', fn() => download_access::teachers_report_table($hash)));

        // The reference to a table with booked users is not exported as teachers report.
        $this->setAdminUser();
        $userstable = (new booked_users())->return_raw_table('option', $this->optionids[0], MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertTrue($this->is_refused(
            'privileged',
            fn() => download_access::teachers_report_table($userstable->tablecachehash)
        ));
    }
}

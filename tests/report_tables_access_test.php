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
use context_system;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking\local\performance\performance_renderer;
use mod_booking\local\table_access;
use mod_booking\output\booked_users;
use mod_booking\output\bulkcheck;
use mod_booking\output\eventslist;
use mod_booking\output\scheduledmails;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The tables of the reports (scheduled mails, logs, booking history, performance, bulk check)
 * are cached and reloaded by their hash. On this way the table has to check the capability the
 * page checked that showed it.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\table_access
 * @covers \mod_booking\output\scheduledmails
 * @covers \mod_booking\output\eventslist
 * @covers \mod_booking\output\booked_users
 * @covers \mod_booking\output\bulkcheck
 * @covers \mod_booking\local\performance\performance_renderer
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class report_tables_access_test extends advanced_testcase {
    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /** @var context_module The context of the booking instance. */
    private context_module $modulecontext;

    /** @var int Id of the booking option. */
    private int $optionid;

    /**
     * Tests set up: a booking option and users with the capabilities of the report pages.
     */
    public function setUp(): void {
        global $DB, $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        singleton_service::destroy_instance();

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $this->modulecontext = context_module::instance($booking->cmid);

        $capabilities = [
            'student' => [],
            'reportviewer' => ['mod/booking:viewreports' => $this->modulecontext],
            'mailviewer' => ['mod/booking:viewscheduledmails' => $this->modulecontext],
            'ruleeditor' => ['mod/booking:editbookingrules' => $this->modulecontext],
            'performanceviewer' => ['mod/booking:viewperformance' => context_system::instance()],
            'bulkcheckmanager' => ['mod/booking:managebulkcheck' => context_system::instance()],
        ];
        foreach ($capabilities as $name => $usercapabilities) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'student');
            foreach ($usercapabilities as $capability => $context) {
                $roleid = $this->getDataGenerator()->create_role();
                assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
                role_assign($roleid, $this->users[$name]->id, $context->id);
            }
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option with reports',
            'courseid' => $course->id,
            'maxanswers' => 5,
        ]);
        $this->optionid = (int)$option->id;

        $DB->insert_record('booking_history', (object)[
            'bookingid' => (int)$booking->id,
            'optionid' => $this->optionid,
            'userid' => (int)$this->users['student']->id,
            'status' => 0,
            'usermodified' => (int)$this->users['student']->id,
            'timecreated' => time(),
            'json' => '{}',
        ]);

        $PAGE->set_url(new \moodle_url('/mod/booking/tests/report_tables_access_test.php'));
        $PAGE->set_context($this->modulecontext);

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
     * The hash of the first table in rendered html. A table that loads its rows later has no
     * hash in its html: the page hands it to the javascript of the table.
     *
     * @param string|null $html
     * @return string
     */
    private function hash_from_html(?string $html): string {
        global $PAGE;

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . (string)$html);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@data-encodedtable]') as $node) {
            return $node->getAttribute('data-encodedtable');
        }
        foreach ($xpath->query('//*[contains(@class, "wunderbyteTableClass")]') as $node) {
            // The id of the element is the id of the table with a leading letter.
            $idstring = substr($node->getAttribute('id'), 1);
            $pattern = '/[\'"]' . preg_quote($idstring, '/') . '[\'"]\s*,\s*[\'"]([0-9a-f]{32})[\'"]/';
            if (preg_match($pattern, $PAGE->requires->get_end_code(), $matches)) {
                return $matches[1];
            }
        }
        $this->fail('No table was rendered: ' . substr((string)$html, 0, 700));
    }

    /**
     * The hash of a table that an output class keeps in a property.
     *
     * @param object $output
     * @return string
     */
    private function hash_from_table_property(object $output): string {
        $property = new \ReflectionProperty($output, 'table');
        $property->setAccessible(true);
        $hash = (string)$property->getValue($output)->tablecachehash;
        $this->assertNotEmpty($hash, 'The table was not cached.');
        return $hash;
    }

    /**
     * Assert who may load a cached table. This is the check the web services and the download
     * script run before they deliver rows or execute an action.
     *
     * @param string $hash
     * @param string[] $allowed names of users who may load the table
     * @param string[] $denied names of users who may not
     * @param string $label
     * @return void
     */
    private function assert_audience(string $hash, array $allowed, array $denied, string $label): void {
        foreach (['allowed' => $allowed, 'denied' => $denied] as $expectation => $usernames) {
            foreach ($usernames as $username) {
                $this->setUser($this->users[$username]);
                $table = wunderbyte_table::instantiate_from_tablecache_hash($hash);
                $this->assertInstanceOf(wunderbyte_table::class, $table, "The table is not cached: $label.");
                $this->assertSame(
                    $expectation === 'allowed',
                    $table->user_has_access(),
                    "User $username, expected to be $expectation: $label."
                );
            }
        }
    }

    /**
     * The scheduled mails are for users who may view them or edit the booking rules.
     */
    public function test_scheduled_mails(): void {
        $this->setAdminUser();
        $hash = $this->hash_from_table_property(new scheduledmails($this->modulecontext->id));

        $this->assert_audience($hash, ['mailviewer', 'ruleeditor'], ['student', 'reportviewer'], 'scheduled mails');
    }

    /**
     * A log table without a stated audience is for users who may view the reports of the site;
     * with a stated audience it is for the users with one of the capabilities.
     */
    public function test_event_logs(): void {
        $this->setAdminUser();
        $events = ['\mod_booking\event\message_sent'];

        $hash = $this->hash_from_html((new eventslist($this->optionid, $events))->eventstable);
        $this->assert_audience($hash, [], ['student', 'reportviewer', 'mailviewer'], 'log without audience');

        $eventslist = new eventslist(
            $this->optionid,
            $events,
            '',
            [],
            0,
            '',
            [],
            table_access::any_capability($this->modulecontext, ['mod/booking:viewreports'])
        );
        $hash = $this->hash_from_html($eventslist->eventstable);
        $this->assert_audience($hash, ['reportviewer'], ['student', 'mailviewer'], 'log of the report of an option');
    }

    /**
     * The booking history and the sent messages of an option follow the tracker of the option.
     */
    public function test_history_and_messages_of_the_tracker(): void {
        global $PAGE;

        $this->setAdminUser();
        $data = new booked_users(
            'option',
            $this->optionid,
            false,
            false,
            false,
            false,
            false,
            true,
            false,
            false,
            (int)$this->modulecontext->instanceid,
            false,
            [],
            true
        );
        $exported = $data->export_for_template($PAGE->get_renderer('mod_booking'));

        $hash = $this->hash_from_html($exported['bookinghistory'] ?? null);
        $this->assert_audience($hash, ['reportviewer'], ['student', 'mailviewer'], 'booking history');

        $hash = $this->hash_from_html($exported['sentmessages'] ?? null);
        $this->assert_audience($hash, ['reportviewer'], ['student', 'mailviewer'], 'sent messages');
    }

    /**
     * The performance measurements are for users who may view the performance page.
     */
    public function test_performance_tables(): void {
        global $DB;

        $this->setAdminUser();
        $DB->insert_record('booking_performance_measurements', (object)[
            'starttime' => time() - 10,
            'endtime' => time(),
            'shortcodename' => 'allbookingoptions',
            'shortcodehash' => md5('allbookingoptions'),
            'measurementname' => 'Entire time',
        ]);
        $hash = $this->hash_from_html((new performance_renderer())->get_sidebar()['sidebar']);

        $this->assert_audience($hash, ['performanceviewer'], ['student', 'reportviewer'], 'performance');
    }

    /**
     * The parked mails of the bulk check are for users who may manage the bulk check.
     */
    public function test_bulk_check(): void {
        $this->setAdminUser();
        $hash = $this->hash_from_table_property(new bulkcheck());

        $this->assert_audience($hash, ['bulkcheckmanager'], ['student', 'reportviewer'], 'bulk check');
    }
}

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
use local_wunderbyte_table\external\execute_action;
use mod_booking\output\booked_users;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The order of a waiting list decides who moves up. Reordering it changes booking answers, so it
 * needs the right to manage the booked users of the option and only touches the rows of the table.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\table\manageusers_table::action_reorderrows
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class reorderrows_access_test extends advanced_testcase {
    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /** @var int Id of the option whose waiting list is reordered. */
    private int $optionid;

    /** @var int[] Ids of the booking answers on the waiting list, in their order. */
    private array $answerids = [];

    /** @var int Id of a booking answer on the waiting list of another option. */
    private int $foreignanswerid;

    /**
     * Tests set up: two options with a waiting list.
     */
    public function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        singleton_service::destroy_instance();

        $this->setAdminUser();
        set_config('waitinglistshowplaceonwaitinglist', 1, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $modulecontext = context_module::instance($booking->cmid);

        foreach (
            [
                'reportviewer' => 'mod/booking:viewreports',
                'manager' => 'mod/booking:managebookedusers',
            ] as $name => $capability
        ) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'student');
            $roleid = $this->getDataGenerator()->create_role();
            assign_capability('mod/booking:viewreports', CAP_ALLOW, $roleid, $modulecontext->id, true);
            assign_capability($capability, CAP_ALLOW, $roleid, $modulecontext->id, true);
            role_assign($roleid, $this->users[$name]->id, $modulecontext->id);
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $options = [];
        foreach (['Option with waiting list', 'Other option'] as $text) {
            $options[] = $plugingenerator->create_option((object)[
                'bookingid' => $booking->id,
                'text' => $text,
                'courseid' => $course->id,
                'maxanswers' => 1,
                'maxoverbooking' => 5,
            ]);
        }
        $this->optionid = (int)$options[0]->id;

        $time = time() - DAYSECS;
        foreach ([$options[0], $options[0], $options[0], $options[1]] as $index => $option) {
            $user = $this->getDataGenerator()->create_user();
            $answerid = (int)$DB->insert_record('booking_answers', (object)[
                'bookingid' => (int)$booking->id,
                'optionid' => (int)$option->id,
                'userid' => (int)$user->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                'places' => 1,
                'timecreated' => $time + $index * 100,
                'timemodified' => $time + $index * 100,
            ]);
            if ((int)$option->id === $this->optionid) {
                $this->answerids[] = $answerid;
            } else {
                $this->foreignanswerid = $answerid;
            }
        }

        \cache::make('local_wunderbyte_table', 'cachedrawdata')->purge();
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
     * Render the waiting list of the option as administrator and return the hash of its table.
     *
     * @return string
     */
    private function render_waiting_list_and_get_hash(): string {
        $this->setAdminUser();
        $table = (new booked_users())->return_raw_table('option', $this->optionid, MOD_BOOKING_STATUSPARAM_WAITINGLIST);
        $this->assertCount(3, $table->rawdata);
        return $table->tablecachehash;
    }

    /**
     * Ask the table to put booking answers into a new order, as a user.
     *
     * @param string $username
     * @param string $hash
     * @param int[] $ids
     * @return int 1 if the action reports success, 0 if it reports a failure, -1 if it was rejected
     */
    private function reorder(string $username, string $hash, array $ids): int {
        $this->setUser($this->users[$username]);
        try {
            $result = execute_action::execute('reorderrows', $hash, -1, json_encode(['ids' => $ids]));
        } catch (\moodle_exception $e) {
            return -1;
        }
        return (int)$result['success'];
    }

    /**
     * The ids of the booking answers of the test, ordered like the waiting lists.
     *
     * @return int[]
     */
    private function answers_in_order(): array {
        global $DB;

        $ids = array_merge($this->answerids, [$this->foreignanswerid]);
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $select = "id $insql ORDER BY timemodified, id";
        return array_map('intval', $DB->get_fieldset_select('booking_answers', 'id', $select, $params));
    }

    /**
     * Users who may read the report of the option cannot change the order of the waiting list.
     */
    public function test_reading_the_report_does_not_allow_reordering(): void {
        $hash = $this->render_waiting_list_and_get_hash();
        $before = $this->answers_in_order();

        [$first, $second, $third] = $this->answerids;
        $this->assertSame(-1, $this->reorder('reportviewer', $hash, [$third, $first, $second]));

        $this->assertSame($before, $this->answers_in_order());
    }

    /**
     * Booking answers that are not in the table are not touched.
     */
    public function test_answers_of_another_option_are_not_reordered(): void {
        global $DB;

        $hash = $this->render_waiting_list_and_get_hash();
        $before = $DB->get_field('booking_answers', 'timemodified', ['id' => $this->foreignanswerid]);

        $this->assertSame(0, $this->reorder('manager', $hash, [$this->answerids[0], $this->foreignanswerid]));

        $this->assertSame($before, $DB->get_field('booking_answers', 'timemodified', ['id' => $this->foreignanswerid]));
    }

    /**
     * Users who may manage the booked users reorder the waiting list.
     */
    public function test_managers_reorder_the_waiting_list(): void {
        $hash = $this->render_waiting_list_and_get_hash();

        [$first, $second, $third] = $this->answerids;
        $this->assertSame(1, $this->reorder('manager', $hash, [$third, $first, $second]));

        $this->assertSame([$third, $first, $second, $this->foreignanswerid], $this->answers_in_order());
    }
}

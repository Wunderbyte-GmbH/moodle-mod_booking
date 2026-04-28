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
 * Shared base test case for AI agent tests.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\executor;
use mod_booking\local\wbagent\task_registry;
use mod_booking\singleton_service;
use stdClass;

/**
 * Abstract base for AI agent PHPUnit tests.
 *
 * Provides helpers to build a course + booking instance + options and to call
 * the executor directly (without a real LLM) so that Prompt→Result tests are
 * fully deterministic.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_agent_testcase extends advanced_testcase {
    /** @var stdClass Course record. */
    protected stdClass $course;

    /** @var stdClass Booking module record (has ->cmid, ->id as bookingid). */
    protected stdClass $booking;

    /** @var stdClass Teacher user with mod/booking:useaiinstructions capability. */
    protected stdClass $teacher;

    /** @var stdClass Student user without mod/booking:useaiinstructions capability. */
    protected stdClass $student;

    /** @var \mod_booking_generator */
    protected $gen;

    // -------------------------------------------------------------------------
    // Life-cycle.

    /**
     * Shared setup: course, booking instance, teacher, student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();

        $this->course  = $this->getDataGenerator()->create_course();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course'          => $this->course->id,
            'name'            => 'Agent Test Booking',
            'eventtype'       => 'Webinar',
            'bookingmanager'  => 'admin',
        ]);

        $this->teacher = $this->getDataGenerator()->create_user();
        $this->student = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        global $PAGE;
        $PAGE->set_url('/mod/booking/view.php', ['id' => (int)$this->booking->cmid]);

        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
    }

    /**
     * Clean up singleton cache after each test.
     */
    protected function tearDown(): void {
        parent::tearDown();
        $this->gen->teardown();
        singleton_service::destroy_instance();
    }

    // -------------------------------------------------------------------------
    // Helpers: data creation.

    /**
     * Create a booking option in the shared booking instance.
     *
     * @param string $name
     * @param array  $extra Optional extra fields.
     * @return stdClass Option record (with ->id).
     */
    protected function create_option(string $name, array $extra = []): stdClass {
        $result = $this->exec_command('booking.create_option', array_merge(
            [
                'text'            => $name,
                'maxanswers'      => 10,
                'coursestarttime' => '2045-03-15T09:00:00',
                'courseendtime'   => '2045-03-15T17:00:00',
                'teacherquery'    => 'current',
            ],
            $extra
        ));
        if (($result['status'] ?? '') !== 'executed' || empty($result['resultid'])) {
            throw new \coding_exception(
                'abstract_agent_testcase::create_option failed: ' . ($result['detail'] ?? 'unknown error')
            );
        }
        return $this->get_option_from_db((int)$result['resultid']);
    }

    // -------------------------------------------------------------------------
    // Helpers: executor.

    /**
     * Build a default executor instance.
     *
     * @return executor
     */
    protected function make_executor(): executor {
        $registry = task_registry::make_default();
        $store    = new conversation_store();
        $authz    = new authorization_service();
        return new executor($registry, $store, $authz);
    }

    /**
     * Run a single agent command and return the result array.
     *
     * Sets the current user to $userid before calling (required for capability
     * checks that use the global $USER inside Moodle helper functions).
     *
     * @param string   $taskname   e.g. 'booking.create_option'
     * @param array    $input      Command input fields.
     * @param int|null $cmid       Defaults to the shared booking instance cmid.
     * @param int|null $userid     Defaults to the teacher user.
     * @return array Single result entry from the executor.
     */
    protected function exec_command(
        string $taskname,
        array $input,
        ?int $cmid = null,
        ?int $userid = null
    ): array {
        $cmid   = $cmid ?? (int)$this->booking->cmid;
        $userid = $userid ?? (int)$this->teacher->id;

        $this->setUser($userid);

        $store   = new conversation_store();
        $thread  = $store->get_or_create_thread($userid, $cmid, (int)$this->booking->id);
        $key     = hash('sha256', $taskname . ':' . $userid . ':' . uniqid('', true));
        $runid   = $store->create_run($thread->id, $userid, $cmid, $key, []);

        $exec    = $this->make_executor();
        $results = $exec->execute_commands(
            [['task' => $taskname, 'version' => 1, 'input' => $input]],
            $cmid,
            $userid,
            $key,
            $runid
        );

        return $results[0];
    }

    /**
     * Load a booking option from the DB by its id.
     *
     * @param int $optionid
     * @return stdClass
     */
    protected function get_option_from_db(int $optionid): stdClass {
        global $DB;
        return $DB->get_record('booking_options', ['id' => $optionid], '*', MUST_EXIST);
    }

    /**
     * Return all booking options that belong to the shared booking instance.
     *
     * @return stdClass[]  Indexed by option id.
     */
    protected function get_all_options(): array {
        global $DB;
        return $DB->get_records('booking_options', ['bookingid' => $this->booking->id]);
    }
}

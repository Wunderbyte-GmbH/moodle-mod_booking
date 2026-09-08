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
 * Tests for booking option events.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use core_reportbuilder\tests\core_reportbuilder_testcase;
use core_reportbuilder_generator;
use mod_booking\reportbuilder\datasource\booking_answers_datasource;
use mod_booking\reportbuilder\datasource\booking_options_datasource;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once("{$CFG->dirroot}/reportbuilder/tests/helpers.php");

/**
 * PHPUnit test case for the class.
 */
final class reportbuilder_test extends core_reportbuilder_testcase {
    /**
     * Booking option 1.
     *
     * @var stdClass
     */
    private $option1;
    /**
     * Booking option 2.
     *
     * @var stdClass
     */
    private $option2;
    /**
     * Booking option 3.
     *
     * @var stdClass
     */
    private $option3;
    /**
     * User 1.
     *
     * @var stdClass
     */
    private $user1;
    /**
     * User 2.
     *
     * @var stdClass
     */
    /**
     * User 2.
     *
     * @var stdClass
     */
    private $user2;
    /**
     * User 3.
     *
     * @var stdClass
     */
    private $user3;

    /** @var stdClass Booking instance holding the options. */
    private $booking;

    /** @var stdClass Custom profile field holding the supervisor's user ID. */
    private $supervisorfield;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     * @covers \mod_booking\settings\optionformconfig\optionformconfig_info
     */
    public function test_datasource_default(): void {
        global $DB;
        $this->set_up_scenario();
        $this->setUser($this->user1);
        $result = booking_bookit::bookit('option', $this->option1->id, $this->user1->id);
        $result = booking_bookit::bookit('option', $this->option1->id, $this->user1->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Completions',
            'source' => booking_answers_datasource::class,
            'default' => 1,
        ]);
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertNotEmpty($content);
        $this->tearDown();
    }
    /**
     * Test all columns of the datasource by creating a report with each column and asserting we get content without errors.
     *
     * @covers \mod_booking\reportbuilder\local\entities\booking_answers
     *
     */
    public function test_stresstest_columns(): void {
        $this->set_up_scenario();
        $this->setUser($this->user1);
        booking_bookit::bookit('option', $this->option1->id, $this->user1->id);
        booking_bookit::bookit('option', $this->option1->id, $this->user1->id);
        $this->setAdminUser();
        $optionobject = singleton_service::get_instance_of_booking_option($this->option1->cmid, $this->option1->id);
        $optionobject->toggle_user_completion($this->user1->id);
        $this->setUser($this->user2);
        booking_bookit::bookit('option', $this->option2->id, $this->user2->id);
        booking_bookit::bookit('option', $this->option2->id, $this->user2->id);
        $this->datasource_stress_test_columns(booking_answers_datasource::class);
        $this->datasource_stress_test_columns(booking_options_datasource::class);
    }

    /**
     * Test all conditions of the datasource by creating a report with each condition and asserting we get content without errors.
     *
     * @covers \mod_booking\reportbuilder\local\entities\booking_answers
     */
    public function test_stresstest_condition(): void {
        $this->set_up_scenario();
        $this->setUser($this->user1);
        booking_bookit::bookit('option', $this->option1->id, $this->user1->id);
        booking_bookit::bookit('option', $this->option1->id, $this->user1->id);
        $this->setAdminUser();
        $optionobject = singleton_service::get_instance_of_booking_option($this->option1->cmid, $this->option1->id);
        $optionobject->toggle_user_completion($this->user1->id);
        $this->setUser($this->user2);
        booking_bookit::bookit('option', $this->option2->id, $this->user2->id);
        booking_bookit::bookit('option', $this->option2->id, $this->user2->id);
        $this->datasource_stress_test_conditions(booking_answers_datasource::class, 'booking_answers:timecreated');
        $this->datasource_stress_test_conditions(booking_options_datasource::class, 'booking_options:text');
    }

    /**
     * Supervisor, "is supervisor", booking instance and competency columns of the booking answers datasource.
     *
     * @covers \mod_booking\reportbuilder\datasource\booking_answers_datasource
     * @covers \mod_booking\reportbuilder\local\entities\booking_options
     */
    public function test_supervisor_and_booking_instance_columns(): void {
        global $DB;
        $this->set_up_scenario();

        // User 3 is the supervisor of user 1.
        $DB->delete_records('user_info_data', ['fieldid' => $this->supervisorfield->id]);
        $DB->insert_record('user_info_data', [
            'userid' => $this->user1->id,
            'fieldid' => $this->supervisorfield->id,
            'data' => (string) $this->user3->id,
            'dataformat' => 0,
        ]);

        // Completing option 1 grants a competency.
        $competencygenerator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $competencygenerator->create_framework();
        $competency = $competencygenerator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'First aid',
        ]);
        $DB->set_field('booking_options', 'competencies', (string) $competency->get('id'), ['id' => $this->option1->id]);

        $this->setUser($this->user1);
        booking_bookit::bookit('option', $this->option1->id, $this->user1->id);
        booking_bookit::bookit('option', $this->option1->id, $this->user1->id);
        $this->setUser($this->user3);
        booking_bookit::bookit('option', $this->option2->id, $this->user3->id);
        booking_bookit::bookit('option', $this->option2->id, $this->user3->id);
        $this->setAdminUser();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Supervisors',
            'source' => booking_answers_datasource::class,
            'default' => 0,
        ]);
        $columns = [
            'user:fullname',
            'user:issupervisor',
            'supervisor:fullname',
            'supervisor:email',
            'booking_options:bookingid',
            'booking_options:bookinginstance',
            'booking_options:competencies',
        ];
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        $content = array_map('array_values', $this->get_custom_report_content($report->get('id')));
        usort($content, fn($a, $b) => strcmp($a[0], $b[0]));

        $this->assertEquals([
            ['User 1', 'No', 'User 3', 'user3@sample.com', $this->booking->id, $this->booking->name, 'First aid'],
            ['User 3', 'Yes', '', '', $this->booking->id, $this->booking->name, ''],
        ], $content);
    }

    /**
     * We create 3 Users and 3 Bookingoptions with user and BO Customfields.
     *
     * @return void
     *
     */
    private function set_up_scenario() {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Supervisor profile field used by the supervisor entity and condition.
        $this->supervisorfield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'supervisor',
            'name' => 'Supervisor',
        ]);
        set_config('supervisor', 'supervisor', 'bookingextension_confirmation_supervisor');

        $users = [
            ['username' => 'user1', 'firstname' => 'User', 'lastname' => '1', 'email' => 'user1@example.com'],
            ['username' => 'user2', 'firstname' => 'User', 'lastname' => '2', 'email' => 'user2@sample.com'],
            ['username' => 'user3', 'firstname' => 'User', 'lastname' => '3', 'email' => 'user3@sample.com'],
        ];
        $this->user1 = $this->getDataGenerator()->create_user($users[0]);
        $this->user2 = $this->getDataGenerator()->create_user($users[1]);
        $this->user3 = $this->getDataGenerator()->create_user($users[2]);

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $this->user2->username;
        $bdata['completed'] = 1;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        $this->booking = $booking;

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($this->user1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->user3->id, $course->id, 'student');

        for ($opt = 1; $opt < 4; $opt++) {
            $record = new stdClass();
            $record->bookingid = $booking->id;
            $record->text = 'Option' . $opt;
            $record->chooseorcreatecourse = 1; // Reqiured.
            $record->courseid = $course->id;
            $record->description = 'Deskr-created';
            $record->optiondateid_0 = "0";
            $record->daystonotify_0 = "0";
            $record->coursestarttime_0 = strtotime('20 June 2050');
            $record->courseendtime_0 = strtotime('20 July 2050');
            /** @var mod_booking_generator $plugingenerator */
            $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
            $options[$opt] = $plugingenerator->create_option($record);
        }
        $this->option1 = $options[1];
        $this->option2 = $options[2];
        $this->option3 = $options[3];
    }
}

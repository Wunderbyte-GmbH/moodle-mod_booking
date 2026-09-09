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

use context_system;
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use core_reportbuilder_generator;
use mod_booking\event\custom_message_sent;
use mod_booking\event\message_sent;
use mod_booking\reportbuilder\datasource\booking_answers_datasource;
use mod_booking\reportbuilder\datasource\booking_messages_datasource;
use mod_booking\local\certificate_conditions\certificate_conditions;
use mod_booking\reportbuilder\datasource\booking_options_datasource;
use mod_booking\reportbuilder\local\entities\booking_messages;
use mod_booking\reportbuilder\local\helpers\certificate_helper;
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
     * Supervisor, booking instance, competency, option ID and visibility columns of the booking answers datasource.
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
        // Option 2 is only reachable via direct link.
        $DB->set_field('booking_options', 'invisible', MOD_BOOKING_OPTION_VISIBLEWITHLINK, ['id' => $this->option2->id]);

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
            'booking_options:id',
            'booking_options:invisible',
        ];
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        $content = array_map('array_values', $this->get_custom_report_content($report->get('id')));
        usort($content, fn($a, $b) => strcmp($a[0], $b[0]));

        $this->assertEquals([
            [
                'User 1', 'No', 'User 3', 'user3@sample.com', $this->booking->id, $this->booking->name, 'First aid',
                $this->option1->id, get_string('optionvisible', 'mod_booking'),
            ],
            [
                'User 3', 'Yes', '', '', $this->booking->id, $this->booking->name, '',
                $this->option2->id, get_string('optionvisibledirectlink', 'mod_booking'),
            ],
        ], $content);
    }

    /**
     * Certificate columns of the booking options datasource: templates, applying conditions and issued count.
     *
     * @covers \mod_booking\reportbuilder\local\entities\booking_options
     * @covers \mod_booking\reportbuilder\local\helpers\certificate_helper
     */
    public function test_certificate_columns(): void {
        global $DB;
        $this->set_up_scenario();

        // Condition A targets option 1 directly (bookingoption logic), template 5.
        $conditiona = $this->create_certificate_condition('Condition A', 'bookingoption', 5, 1);
        $this->add_certificate_condition_item($conditiona, 'bookingoption', $this->option1->id);

        // Condition B covers the whole booking instance (instance logic), template 6.
        $conditionb = $this->create_certificate_condition('Condition B', 'instance', 6, 1);
        $this->add_certificate_condition_item($conditionb, 'bookinginstance', $this->booking->id);

        // Condition C targets option 2 but is inactive, so it must not show up.
        $conditionc = $this->create_certificate_condition('Condition C', 'bookingoption', 8, 0);
        $this->add_certificate_condition_item($conditionc, 'bookingoption', $this->option2->id);

        // Option 3 has a legacy certificate template configured in its JSON.
        $DB->set_field('booking_options', 'json', json_encode(['certificate' => 7]), ['id' => $this->option3->id]);

        certificate_helper::reset_caches();
        $this->setAdminUser();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Certificates',
            'source' => booking_options_datasource::class,
            'default' => 0,
        ]);
        $columns = [
            'booking_options:text',
            'booking_options:certificateconditions',
            'booking_options:certificate',
        ];
        // No templates exist in the test DB, so template ids are rendered as "#<id>".
        $expected = [
            ['Option1', 'Condition A, Condition B', '#5, #6'],
            ['Option2', 'Condition B', '#6'],
            ['Option3', 'Condition B', '#7, #6'],
        ];

        // The issued-certificates column needs tool_certificate (installed in CI) and a DB with JSON support.
        $issuedcolumn = manager::get_report_from_persistent($report)->get_column('booking_options:certificatesissued');
        if (class_exists('tool_certificate\certificate') && in_array($DB->get_dbfamily(), ['postgres', 'mysql'])) {
            $this->assertNotNull($issuedcolumn);
            $columns[] = 'booking_options:certificatesissued';
            foreach ($expected as &$row) {
                $row[] = 0;
            }
            unset($row);
        } else {
            $this->assertNull($issuedcolumn);
        }

        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        $content = array_map('array_values', $this->get_custom_report_content($report->get('id')));
        usort($content, fn($a, $b) => strcmp($a[0], $b[0]));

        $this->assertEquals($expected, $content);
    }

    /**
     * Booking messages datasource: sent messages from the standard log with recipient, sender, option and rule.
     *
     * @covers \mod_booking\reportbuilder\datasource\booking_messages_datasource
     * @covers \mod_booking\reportbuilder\local\entities\booking_messages
     */
    public function test_messages_datasource(): void {
        global $DB, $USER;
        $this->set_up_scenario();

        // Sent messages are only recorded as events, so the standard log store must be on and unbuffered.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('logguests', 1, 'logstore_standard');
        get_log_manager(true);

        $ruleid = $DB->insert_record('booking_rules', (object) [
            'contextid' => 1,
            'rulename' => 'rule_react_on_event',
            'rulejson' => json_encode(['name' => 'Welcome mail']),
            'isactive' => 1,
            'useastemplate' => 0,
        ]);
        booking_messages::reset_caches();
        $this->setAdminUser();

        // A rule mail to user 1 and an automatic confirmation to user 2.
        $this->trigger_message_sent($this->user1->id, $this->option1->id, [
            'messageparam' => MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE,
            'subject' => 'Welcome',
            'message' => 'Hello',
            'messagehtml' => '<p>Hello</p>',
            'bookingruleid' => $ruleid,
        ]);
        $this->trigger_message_sent($this->user2->id, $this->option2->id, [
            'messageparam' => MOD_BOOKING_MSGPARAM_CONFIRMATION,
            'subject' => 'Booked',
            'message' => 'You are booked',
            'messagehtml' => '',
            'bookingruleid' => null,
        ]);
        // A manually sent custom message is a different event and must not show up.
        custom_message_sent::create([
            'context' => context_system::instance(),
            'userid' => $USER->id,
            'relateduserid' => $this->user3->id,
            'objectid' => $this->option3->id,
            'other' => ['messageparam' => MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE, 'subject' => 'Manual', 'message' => 'x'],
        ])->trigger();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Messages',
            'source' => booking_messages_datasource::class,
            'default' => 0,
        ]);
        $columns = [
            'user:fullname',
            'booking_options:text',
            'booking_messages:messagetype',
            'booking_messages:subject',
            'booking_messages:bookingrule',
            'booking_messages:message',
            'sender:fullname',
        ];
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        $content = array_map('array_values', $this->get_custom_report_content($report->get('id')));
        usort($content, fn($a, $b) => strcmp($a[0], $b[0]));

        $this->assertEquals([
            [
                'User 1', 'Option1', get_string('messagetype:custommessage', 'mod_booking'), 'Welcome', 'Welcome mail',
                '<p>Hello</p>', fullname($USER),
            ],
            [
                'User 2', 'Option2', get_string('messagetype:confirmation', 'mod_booking'), 'Booked', '',
                format_text('You are booked', FORMAT_PLAIN), fullname($USER),
            ],
        ], $content);

        // Default report and every column / condition must render without errors.
        $defaultreport = $generator->create_report([
            'name' => 'Messages default',
            'source' => booking_messages_datasource::class,
            'default' => 1,
        ]);
        $this->assertCount(2, $this->get_custom_report_content($defaultreport->get('id')));
        $this->datasource_stress_test_columns(booking_messages_datasource::class);
        $this->datasource_stress_test_conditions(booking_messages_datasource::class, 'booking_messages:timecreated');
    }

    /**
     * Trigger a message_sent event as the current user, the way message_controller does.
     *
     * @param int $recipientid
     * @param int $optionid
     * @param array $other Event data (messageparam, subject, message, messagehtml, bookingruleid)
     * @return void
     */
    private function trigger_message_sent(int $recipientid, int $optionid, array $other): void {
        global $USER;
        message_sent::create([
            'context' => context_system::instance(),
            'userid' => $USER->id,
            'relateduserid' => $recipientid,
            'objectid' => $optionid,
            'other' => $other + ['objectid' => $optionid],
        ])->trigger();
    }

    /**
     * Create a certificate condition with a createcertificate action.
     *
     * @param string $name
     * @param string $logic Condition logic name (bookingoption, taggedoptions, instance)
     * @param int $certid Certificate template id of the action
     * @param int $isactive
     * @return int Condition id
     */
    private function create_certificate_condition(string $name, string $logic, int $certid, int $isactive): int {
        global $DB;
        $data = new stdClass();
        $data->name = $name;
        $data->contextid = 1;
        $data->isactive = $isactive;
        $data->logicjson = json_encode(['conditionname' => $logic, 'requiredcount' => 1]);
        $data->actionjson = json_encode([
            'actionname' => 'createcertificate',
            'certid' => $certid,
            'expirydatetype' => 0,
            'expirydateabsolute' => 0,
            'expirydaterelative' => 0,
        ]);
        $conditionid = certificate_conditions::save_certificate_condition($data);

        // Save_certificate_condition() only stores the JSON when a form type is given, so set it directly.
        $DB->set_field('booking_cert_cond', 'logicjson', $data->logicjson, ['id' => $conditionid]);
        $DB->set_field('booking_cert_cond', 'actionjson', $data->actionjson, ['id' => $conditionid]);
        return $conditionid;
    }

    /**
     * Link a certificate condition to a booking option or booking instance.
     *
     * @param int $conditionid
     * @param string $area bookingoption or bookinginstance
     * @param int $itemid Option id or booking instance id
     * @return void
     */
    private function add_certificate_condition_item(int $conditionid, string $area, int $itemid): void {
        global $DB;
        $DB->insert_record('booking_cert_cond_item', [
            'conditionid' => $conditionid,
            'component' => 'mod_booking',
            'area' => $area,
            'itemid' => $itemid,
            'sortorder' => 0,
        ]);
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

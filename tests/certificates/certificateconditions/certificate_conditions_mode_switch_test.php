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
 * Tests for switching between the per-option certificate and certificate conditions.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_module;
use context_system;
use mod_booking\local\certificate_conditions\certificate_conditions;
use mod_booking\table\manageusers_table;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A certificate stored on the booking option must not be issued in addition to a certificate condition.
 *
 * Scenario (Wunderbyte-GmbH/moodle-mod_booking#1630): a booking option has a certificate template in its own
 * settings. The site is then switched to certificate conditions and a condition for the booking instance is
 * created. A user who completes the option must receive exactly one certificate, the one of the condition.
 * After switching back to the per-option certificate, the saved condition must not issue a certificate either.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class certificate_conditions_mode_switch_test extends booking_advanced_testcase {
    /** @var stdClass the student who completes the option */
    private stdClass $student;

    /** @var stdClass the booking module */
    private stdClass $booking;

    /** @var stdClass the booking option with a certificate stored in its settings */
    private stdClass $option;

    /** @var int id of the certificate template */
    private int $templateid;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest(true);
        certificate_conditions::reset_caches();
    }

    /**
     * Tear down.
     */
    public function tearDown(): void {
        certificate_conditions::reset_caches();
        parent::tearDown();
    }

    /**
     * Contexts a certificate condition can be saved in.
     *
     * @return array
     */
    public static function condition_context_provider(): array {
        return [
            'condition saved in the booking instance' => ['module'],
            'condition saved globally' => ['system'],
        ];
    }

    /**
     * Completing the option after the switch issues only the certificate of the condition.
     *
     * @covers \mod_booking\booking_option::toggle_user_completion
     * @covers \mod_booking\local\certificate_conditions\certificate_conditions::evaluate_certificate_conditions
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate::execute_action
     * @dataProvider condition_context_provider
     *
     * @param string $contextlevel
     */
    public function test_completion_after_switch_issues_one_certificate(string $contextlevel): void {
        global $DB;
        $this->create_option_with_certificate();
        $conditionid = $this->switch_to_conditions($contextlevel);

        $this->book_student();
        $this->setAdminUser();
        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->toggle_user_completion($this->student->id);

        $this->assert_only_condition_certificate($conditionid);
        $this->assertEquals(1, $DB->count_records('booking_answers', [
            'optionid' => $this->option->id,
            'userid' => $this->student->id,
            'completed' => 1,
        ]));
    }

    /**
     * Triggering certificates manually after the switch issues only the certificate of the condition.
     *
     * @covers \mod_booking\table\manageusers_table::action_trigger_certificate_booking_answers
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate::execute_action
     * @dataProvider condition_context_provider
     *
     * @param string $contextlevel
     */
    public function test_manual_trigger_after_switch_issues_one_certificate(string $contextlevel): void {
        global $DB;
        set_config('certificatemanualtrigger', 1, 'booking');
        $this->create_option_with_certificate();
        $conditionid = $this->switch_to_conditions($contextlevel);

        $this->book_student();
        $this->setAdminUser();
        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->toggle_user_completion($this->student->id);
        singleton_service::destroy_booking_answers($this->option->id);

        // The manual trigger setting keeps completion from issuing anything.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->student->id]));

        $answerid = $DB->get_field('booking_answers', 'id', [
            'optionid' => $this->option->id,
            'userid' => $this->student->id,
        ]);
        $result = (new manageusers_table('certificatemodeswitchtest'))
            ->action_trigger_certificate_booking_answers(0, json_encode(['checkedids' => [$answerid]]));

        $this->assertEquals(1, $result['success']);
        $this->assert_only_condition_certificate($conditionid);
    }

    /**
     * Without the switch, the certificate stored on the option is still issued once on completion.
     *
     * @covers \mod_booking\booking_option::toggle_user_completion
     * @covers \mod_booking\local\certificateclass::issue_certificate
     */
    public function test_completion_without_switch_issues_option_certificate(): void {
        global $DB;
        $this->create_option_with_certificate();

        $this->book_student();
        $this->setAdminUser();
        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->toggle_user_completion($this->student->id);

        $this->assert_only_option_certificate();
    }

    /**
     * Completing the option after switching back to the per-option certificate issues only the option certificate.
     *
     * @covers \mod_booking\booking_option::toggle_user_completion
     * @covers \mod_booking\local\certificate_conditions\certificate_conditions::evaluate_certificate_conditions
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @dataProvider condition_context_provider
     *
     * @param string $contextlevel
     */
    public function test_completion_after_switch_back_issues_option_certificate(string $contextlevel): void {
        $this->create_option_with_certificate();
        $this->switch_to_conditions($contextlevel);
        $this->switch_back_to_option_certificate();

        $this->book_student();
        $this->setAdminUser();
        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->toggle_user_completion($this->student->id);

        $this->assert_only_option_certificate();
    }

    /**
     * Triggering certificates manually after switching back issues only the option certificate.
     *
     * @covers \mod_booking\table\manageusers_table::action_trigger_certificate_booking_answers
     * @covers \mod_booking\local\certificate_conditions\certificate_conditions::evaluate_certificate_conditions_with_result
     * @dataProvider condition_context_provider
     *
     * @param string $contextlevel
     */
    public function test_manual_trigger_after_switch_back_issues_option_certificate(string $contextlevel): void {
        global $DB;
        set_config('certificatemanualtrigger', 1, 'booking');
        $this->create_option_with_certificate();
        $this->switch_to_conditions($contextlevel);
        $this->switch_back_to_option_certificate();

        $this->book_student();
        $this->setAdminUser();
        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $option->toggle_user_completion($this->student->id);
        singleton_service::destroy_booking_answers($this->option->id);

        // The manual trigger setting keeps completion from issuing anything.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->student->id]));

        $answerid = $DB->get_field('booking_answers', 'id', [
            'optionid' => $this->option->id,
            'userid' => $this->student->id,
        ]);
        $result = (new manageusers_table('certificatemodeswitchtest'))
            ->action_trigger_certificate_booking_answers(0, json_encode(['checkedids' => [$answerid]]));

        $this->assertEquals(1, $result['success']);
        $this->assert_only_option_certificate();
    }

    /**
     * Assert that the student holds exactly one certificate and that it is the certificate stored on the option.
     */
    private function assert_only_option_certificate(): void {
        global $DB;
        $issues = $DB->get_records('tool_certificate_issues', ['userid' => $this->student->id]);
        $this->assertCount(1, $issues);
        $issue = reset($issues);
        $this->assertEquals($this->templateid, $issue->templateid);
        $this->assertObjectNotHasProperty('conditionid', json_decode($issue->data));
    }

    /**
     * Assert that the student holds exactly one certificate and that it was issued by the given condition.
     *
     * @param int $conditionid
     */
    private function assert_only_condition_certificate(int $conditionid): void {
        global $DB;
        $issues = $DB->get_records('tool_certificate_issues', ['userid' => $this->student->id]);
        $this->assertCount(1, $issues);
        $issue = reset($issues);
        $this->assertEquals($this->templateid, $issue->templateid);
        $this->assertEquals($conditionid, json_decode($issue->data)->conditionid ?? 0);
    }

    /**
     * Create course, student, booking instance and a booking option with a certificate in its settings.
     * Certificates are on and the site uses the per-option certificate.
     */
    private function create_option_with_certificate(): void {
        $this->setAdminUser();
        set_config('certificateon', 1, 'booking');
        set_config('certificateoptions', 0, 'booking');
        set_config('issuemultiplecertificates', 0, 'booking');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Certificate booking',
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
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
            'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ]);

        $template = $this->getDataGenerator()->get_plugin_generator('tool_certificate')
            ->create_template((object)['name' => 'Certificate 1']);
        $this->templateid = (int)$template->get_id();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $this->option = $plugingenerator->create_option((object)[
            'bookingid' => $this->booking->id,
            'courseid' => $course->id,
            'text' => 'Option with certificate',
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'importing' => 1,
            'useprice' => 0,
            'json' => json_encode([
                'certificate' => $this->templateid,
                'expirydatetype' => 0,
                'expirydateabsolute' => 0,
                'expirydaterelative' => 0,
            ]),
        ]);
    }

    /**
     * Switch the site to certificate conditions and add a condition for the booking instance that issues
     * the same certificate template on completion.
     *
     * @param string $contextlevel 'module' or 'system'
     * @return int id of the condition
     */
    private function switch_to_conditions(string $contextlevel): int {
        global $DB;
        set_config('certificateoptions', 1, 'booking');

        $cm = get_coursemodule_from_instance('booking', $this->booking->id);
        $contextid = $contextlevel === 'module'
            ? context_module::instance($cm->id)->id
            : context_system::instance()->id;

        $conditionid = $DB->insert_record('booking_cert_cond', (object)[
            'contextid' => $contextid,
            'name' => 'Instance condition',
            'filterjson' => json_encode([]),
            'logicjson' => json_encode([
                'conditionname' => 'instance',
                'bookingid' => $this->booking->id,
                'requiredcount' => 1,
            ]),
            'actionjson' => json_encode([
                'actionname' => 'createcertificate',
                'certid' => $this->templateid,
                'expirydatetype' => 0,
                'expirydateabsolute' => 0,
                'expirydaterelative' => 0,
            ]),
            'isactive' => 1,
            'useastemplate' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('booking_cert_cond_item', (object)[
            'conditionid' => $conditionid,
            'component' => 'mod_booking',
            'area' => 'bookinginstance',
            'itemid' => $this->booking->id,
            'sortorder' => 0,
            'configjson' => json_encode([]),
        ]);
        certificate_conditions::reset_caches();

        return (int)$conditionid;
    }

    /**
     * Switch the site back to the per-option certificate. The saved condition stays active.
     */
    private function switch_back_to_option_certificate(): void {
        set_config('certificateoptions', 0, 'booking');
        certificate_conditions::reset_caches();
    }

    /**
     * Book the student into the option.
     */
    private function book_student(): void {
        $this->setUser($this->student);
        booking_bookit::bookit('option', $this->option->id, $this->student->id);
        booking_bookit::bookit('option', $this->option->id, $this->student->id);
    }
}

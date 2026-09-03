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
use stdClass;
use mod_booking\local\wizard\options\skills\diagnose_user_booking_skill;

/**
 * Tests that diagnose_user_booking surfaces tool_certificate certificates, the certificate-field
 * change time used to explain why a completed user got no certificate, and the host-course context
 * (course id/name + booking instance) of the reported options.
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_diagnose_user_certificate_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * A certificate issued to the user is reported, and the option's configured template is matched.
     */
    public function test_option_report_includes_issued_certificate(): void {
        global $DB;
        $this->resetAfterTest();

        [$booking, $option, $student] = $this->setup_booking();
        $templateid = $this->create_cert_template();
        $this->set_option_certificate((int)$option->id, $templateid);
        $this->book_user((int)$option->id, (int)$student->id);

        // Issue a certificate of that template to the user.
        $DB->insert_record('tool_certificate_issues', (object)[
            'userid' => (int)$student->id,
            'templateid' => $templateid,
            'code' => 'TESTCODE01',
            'emailed' => 0,
            'timecreated' => time() - DAYSECS,
            'expires' => 0,
            'data' => '{}',
            'component' => 'tool_certificate',
            'courseid' => (int)$booking->course,
            'archived' => 0,
        ]);

        $this->setAdminUser();
        $result = (new diagnose_user_booking_skill())->execute(
            ['userid' => (int)$student->id, 'optionid' => (int)$option->id, 'includemessages' => false],
            (int)\context_module::instance($booking->cmid)->id,
            (int)get_admin()->id
        );

        $this->assertSame('executed', $result['status']);
        $report = $this->decode_report($result);
        // The report names the host course and booking instance the option lives in.
        $this->assertSame((int)$booking->course, $report['courseid']);
        $this->assertSame(format_string(get_course((int)$booking->course)->fullname), $report['coursename']);
        $this->assertSame('Cert Booking', $report['booking_instance']);
        $this->assertArrayHasKey('certificates', $report);
        $certs = $report['certificates'];
        $this->assertTrue($certs['tool_certificate_available']);
        $this->assertSame($templateid, $certs['configured_template_id']);
        $this->assertGreaterThanOrEqual(1, $certs['count']);
        $this->assertNotNull($certs['issued_for_configured_template']);
        $this->assertSame($templateid, $certs['issued_for_configured_template']['template_id']);
        // Timestamps in the report are rendered LLM-readable, not raw Unix timestamps.
        $this->assertIsString($certs['issued_for_configured_template']['timecreated']);
        $this->assertFalse(ctype_digit((string)$certs['issued_for_configured_template']['timecreated']));
    }

    /**
     * For a completed user with a configured certificate but no issued certificate, the report carries
     * the certificate field's last change time and flags that it changed after the user completed.
     */
    public function test_certificate_field_change_after_completion_flagged(): void {
        global $DB;
        $this->resetAfterTest();

        [$booking, $option, $student] = $this->setup_booking();
        $templateid = $this->create_cert_template();
        $this->set_option_certificate((int)$option->id, $templateid);

        // Book and mark the user completed at a point in the past (no certificate issued).
        $completiontime = time() - DAYSECS;
        $this->book_user((int)$option->id, (int)$student->id);
        $DB->set_field('booking_answers', 'completed', 1, ['optionid' => (int)$option->id, 'userid' => (int)$student->id]);
        $DB->set_field(
            'booking_answers',
            'timemodified',
            $completiontime,
            ['optionid' => (int)$option->id, 'userid' => (int)$student->id]
        );
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete((int)$option->id);
        singleton_service::destroy_instance();

        // Enable the standard logstore (after setup, so option/calendar creation is unaffected) so the
        // triggered update event is persisted and readable by the skill.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('logguests', 1, 'logstore_standard');
        get_log_manager(true);

        // The certificate field on the option is changed now — i.e. after the user already completed.
        $event = \mod_booking\event\bookingoption_updated::create([
            'context' => \context_module::instance($booking->cmid),
            'objectid' => (int)$option->id,
            'other' => [
                'changes' => [
                    ['fieldname' => 'certificate', 'oldvalue' => 0, 'newvalue' => (string)$templateid, 'formkey' => 'certificate'],
                ],
            ],
        ]);
        $event->trigger();

        $this->setAdminUser();
        $result = (new diagnose_user_booking_skill())->execute(
            ['userid' => (int)$student->id, 'optionid' => (int)$option->id, 'includemessages' => false],
            (int)\context_module::instance($booking->cmid)->id,
            (int)get_admin()->id
        );

        $this->assertSame('executed', $result['status']);
        $report = $this->decode_report($result);
        $this->assertArrayHasKey('certificate_field', $report);
        // The last_changed value is rendered LLM-readable (timezone-adjusted), not a raw Unix timestamp.
        $this->assertIsString($report['certificate_field']['last_changed']);
        $this->assertFalse(ctype_digit((string)$report['certificate_field']['last_changed']));
        $this->assertTrue($report['certificate_field']['changed_after_user_completion']);
    }

    /**
     * The instance-wide overview names the host course (id + name) and booking instance per option.
     */
    public function test_userwide_report_includes_host_course(): void {
        $this->resetAfterTest();

        [$booking, $option, $student] = $this->setup_booking();
        $this->book_user((int)$option->id, (int)$student->id);

        $this->setAdminUser();
        $result = (new diagnose_user_booking_skill())->execute(
            ['userid' => (int)$student->id, 'includemessages' => false],
            (int)\context_module::instance($booking->cmid)->id,
            (int)get_admin()->id
        );

        $this->assertSame('executed', $result['status']);
        $report = $this->decode_report($result);
        $this->assertSame('instance_wide', $report['mode']);
        $this->assertNotEmpty($report['options']);
        $entry = $report['options'][0];
        $this->assertSame((int)$option->id, $entry['optionid']);
        $this->assertSame((int)$booking->course, $entry['courseid']);
        $this->assertSame(format_string(get_course((int)$booking->course)->fullname), $entry['coursename']);
        $this->assertSame('Cert Booking', $entry['booking_instance']);
    }

    /**
     * Build a course + booking instance + one option and an enrolled student.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} [booking module, option, student]
     */
    private function setup_booking(): array {
        // Ensure a valid $USER during module/option (calendar) creation regardless of test order.
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = [
            'name' => 'Cert Booking',
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Cert option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $option = $plugingenerator->create_option($record);

        return [$booking, $option, $student];
    }

    /**
     * Insert a tool_certificate template and return its id.
     *
     * @return int
     */
    private function create_cert_template(): int {
        global $DB;
        return (int)$DB->insert_record('tool_certificate_templates', (object)[
            'name' => 'Test certificate',
            'contextid' => (int)\context_system::instance()->id,
            'shared' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Configure the certificate template id on a booking option's JSON and refresh caches.
     *
     * @param int $optionid
     * @param int $templateid
     */
    private function set_option_certificate(int $optionid, int $templateid): void {
        global $DB;
        $json = $DB->get_field('booking_options', 'json', ['id' => $optionid]);
        $obj = !empty($json) ? json_decode($json) : new stdClass();
        $obj->certificate = $templateid;
        $DB->set_field('booking_options', 'json', json_encode($obj), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Book a user into an option via the plugin generator.
     *
     * @param int $optionid
     * @param int $userid
     */
    private function book_user(int $optionid, int $userid): void {
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_answer(['optionid' => $optionid, 'userid' => $userid]);
        \cache::make('mod_booking', 'bookingoptionsanswers')->delete($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Decode the JSON diagnosis report embedded in the skill's observation_full.
     *
     * @param array $result
     * @return array<string,mixed>
     */
    private function decode_report(array $result): array {
        $obs = (string)($result['observation_full'] ?? '');
        $pos = strpos($obs, '(JSON):');
        $json = $pos !== false ? substr($obs, $pos + strlen('(JSON):')) : $obs;
        $decoded = json_decode(trim($json), true);
        return is_array($decoded) ? $decoded : [];
    }
}

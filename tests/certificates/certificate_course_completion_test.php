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
 * Tests for certificates issued when the connected course is completed.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use completion_completion;
use context_system;
use core\event\course_completed;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * The whole chain from the completion of the connected course to the certificate.
 *
 * The existing tests cover the two halves separately: course_completion_test checks that the course completion
 * completes the booking answer, certificate_bo_completed_test calls toggle_user_completion() itself and checks
 * the certificate. Here nothing in between is called by the test: the only trigger is the core event
 * course_completed, the booking answer and the certificate have to follow from it.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class certificate_course_completion_test extends booking_advanced_testcase {
    /**
     * The completion of the connected course completes the booking answer and issues the certificate.
     *
     * @covers \mod_booking\observer::course_completed
     * @covers \mod_booking\booking_option::toggle_user_completion
     * @covers \mod_booking\local\certificateclass::issue_certificate
     *
     * @param array $config settings of mod_booking, name => value
     * @param int $expectedcompleted value of booking_answers.completed of the student who completed the course
     * @param int $expectedcertificates number of certificates the site has issued afterwards
     *
     * @dataProvider course_completion_provider
     */
    public function test_course_completion_issues_certificate(
        array $config,
        int $expectedcompleted,
        int $expectedcertificates
    ): void {
        global $DB;

        if (!class_exists('tool_certificate\certificate')) {
            $this->markTestSkipped('tool_certificate is not installed.');
        }

        foreach ($config as $name => $value) {
            set_config($name, $value, 'booking');
        }

        [$settings, $connectedcourse, $template, $student1, $student2] = $this->create_booked_option_with_certificate();

        // Nothing happens at booking time: no completion, no certificate.
        $this->assertSame(0, $this->get_completed($settings, $student1->id));
        $this->assertSame(0, $DB->count_records('tool_certificate_issues'));

        // The one and only trigger. No event sink here: a sink would swallow course_completed before it
        // reaches the observer of mod_booking, and the observer is what we test.
        $completion = new completion_completion(['userid' => $student1->id, 'course' => $connectedcourse->id]);
        $completion->mark_complete();

        // The booking answer follows the course completion.
        $this->assertSame($expectedcompleted, $this->get_completed($settings, $student1->id));
        $answer = $DB->get_record(
            'booking_answers',
            ['optionid' => $settings->id, 'userid' => $student1->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(empty($expectedcompleted), empty($answer->completeddate));

        // The certificate follows the completed booking answer.
        $issues = $DB->get_records('tool_certificate_issues');
        $this->assertCount($expectedcertificates, $issues);
        foreach ($issues as $issue) {
            $this->assertEquals($student1->id, $issue->userid);
            $this->assertEquals($template->get_id(), $issue->templateid);
            $this->assertEquals($connectedcourse->id, $issue->courseid);
            // The user gets a real document, not only a database record.
            $file = get_file_storage()->get_file(
                context_system::instance()->id,
                'tool_certificate',
                'issues',
                $issue->id,
                '/',
                $issue->code . '.pdf'
            );
            $this->assertNotEmpty($file, 'The PDF of the certificate is missing.');
        }

        // The second student booked the same option but did not complete the course.
        $this->assertSame(0, $this->get_completed($settings, $student2->id));
        $this->assertSame(0, $DB->count_records('tool_certificate_issues', ['userid' => $student2->id]));
    }

    /**
     * Settings and the result expected from them.
     *
     * @return array
     */
    public static function course_completion_provider(): array {
        return [
            'automatic completion and certificates on' => [
                ['automaticbookingoptioncompletion' => 1, 'certificateon' => 1],
                1,
                1,
            ],
            'automatic completion off' => [
                ['automaticbookingoptioncompletion' => 0, 'certificateon' => 1],
                0,
                0,
            ],
            'certificates off' => [
                ['automaticbookingoptioncompletion' => 1, 'certificateon' => 0],
                1,
                0,
            ],
            'certificate is issued on a presence status, not on completion' => [
                ['automaticbookingoptioncompletion' => 1, 'certificateon' => 1, 'presencestatustoissuecertificate' => 1],
                1,
                0,
            ],
            'certificate is only issued manually' => [
                ['automaticbookingoptioncompletion' => 1, 'certificateon' => 1, 'certificatemanualtrigger' => 1],
                1,
                0,
            ],
        ];
    }

    /**
     * A course_completed event for a booking answer that is already completed changes nothing.
     *
     * toggle_user_completion() toggles: called a second time it would take the completion away again. The
     * observer therefore only calls it for answers that are not completed yet.
     *
     * @covers \mod_booking\observer::course_completed
     */
    public function test_repeated_course_completion_keeps_completion_and_certificate(): void {
        global $DB;

        if (!class_exists('tool_certificate\certificate')) {
            $this->markTestSkipped('tool_certificate is not installed.');
        }

        set_config('automaticbookingoptioncompletion', 1, 'booking');
        set_config('certificateon', 1, 'booking');

        [$settings, $connectedcourse, , $student1] = $this->create_booked_option_with_certificate();

        $completion = new completion_completion(['userid' => $student1->id, 'course' => $connectedcourse->id]);
        $completion->mark_complete();
        $this->assertSame(1, $this->get_completed($settings, $student1->id));
        $this->assertSame(1, $DB->count_records('tool_certificate_issues'));

        // Core only triggers the event once per completion, so we trigger it a second time ourselves.
        $record = $DB->get_record(
            'course_completions',
            ['userid' => $student1->id, 'course' => $connectedcourse->id],
            '*',
            MUST_EXIST
        );
        course_completed::create_from_completion($record)->trigger();

        $this->assertSame(1, $this->get_completed($settings, $student1->id));
        $this->assertSame(1, $DB->count_records('tool_certificate_issues'));
    }

    /**
     * Create a booking option with a connected course and a certificate and book two students.
     *
     * The booking instance lives in one course, the booking option is connected to another one: the observer
     * has to find the answers by the connected course of the option, not by the course of the instance.
     *
     * @return array [booking option settings, connected course, certificate template, student1, student2]
     */
    private function create_booked_option_with_certificate(): array {
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $template = $generator->get_plugin_generator('tool_certificate')
            ->create_template((object)['name' => 'Certificate 1']);

        $instancecourse = $generator->create_course();
        $connectedcourse = $generator->create_course(['enablecompletion' => 1]);
        $student1 = $generator->create_user();
        $student2 = $generator->create_user();
        $bookingmanager = $generator->create_user();
        $generator->enrol_user($student1->id, $instancecourse->id);
        $generator->enrol_user($student2->id, $instancecourse->id);

        $booking = $generator->create_module('booking', [
            'course' => $instancecourse->id,
            'name' => 'Test',
            'bookingmanager' => $bookingmanager->username,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $generator->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option with certificate',
            'courseid' => $connectedcourse->id,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'importing' => 1,
            'useprice' => 0,
            // The certificate of a booking option is stored in its json. Expiry date type 0: it never expires.
            'json' => json_encode(['certificate' => $template->get_id(), 'expirydatetype' => 0]),
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        foreach ([$student1, $student2] as $student) {
            $this->setUser($student);
            // The first call asks for confirmation, the second one books.
            booking_bookit::bookit('option', $settings->id, $student->id);
            booking_bookit::bookit('option', $settings->id, $student->id);
        }
        $this->setAdminUser();

        $bookedusers = singleton_service::get_instance_of_booking_answers($settings)->get_usersonlist();
        $this->assertArrayHasKey($student1->id, $bookedusers);
        $this->assertArrayHasKey($student2->id, $bookedusers);

        return [$settings, $connectedcourse, $template, $student1, $student2];
    }

    /**
     * The completion state of a booked user, read like the booking option reads it (through the answers cache).
     *
     * Reading through booking_answers instead of the table also proves that the cache was purged.
     *
     * @param booking_option_settings|stdClass $settings
     * @param int $userid
     * @return int
     */
    private function get_completed($settings, int $userid): int {
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($settings->id);
        $users = singleton_service::get_instance_of_booking_answers($settings)->get_usersonlist();
        $this->assertArrayHasKey($userid, $users);
        return (int)$users[$userid]->completed;
    }
}

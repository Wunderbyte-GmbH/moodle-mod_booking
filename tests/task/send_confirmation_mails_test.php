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
 * Tests for the cleanup of ical attachments by the send_confirmation_mails task.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\task\send_confirmation_mails;
use stdClass;

/**
 * Tests for the cleanup of ical attachments by the send_confirmation_mails task.
 *
 * The attachment is written by ical::generate_tempfile() into a subdirectory of the temp
 * directory, while its path is stored JSON-encoded (with escaped slashes) in the customdata
 * of this task. The cleanup must therefore match on the file name only, and it must not
 * delete a file that another queued task still needs.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class send_confirmation_mails_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        // The task returns early unless the legacy mail templates are switched on.
        set_config('uselegacymailtemplates', 1, 'booking');
    }

    /**
     * Create an ical attachment where the real code creates it: in a subfolder of the temp dir.
     *
     * @return string the path of the created file
     */
    private function create_ical_attachment(): string {
        $path = make_temp_directory('mod_booking/ical') . '/' . md5('ical' . microtime());
        file_put_contents($path, "BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR");
        return $path;
    }

    /**
     * Queue a send_confirmation_mails task carrying the given attachment.
     *
     * @param string $attachmentpath
     * @return void
     */
    private function queue_mail_task(string $attachmentpath): void {
        $userto = $this->getDataGenerator()->create_user();
        $userfrom = $this->getDataGenerator()->create_user();

        $data = new stdClass();
        $data->userto = $userto;
        $data->userfrom = $userfrom;
        $data->subject = 'Booking confirmation';
        $data->messagetext = 'Your booking is confirmed.';
        $data->messagehtml = '<p>Your booking is confirmed.</p>';
        $data->attachment = (object)['booking.ics' => $attachmentpath];
        $data->attachname = 'booking.ics';
        $data->optionid = 0;
        $data->messageparam = MOD_BOOKING_MSGPARAM_CONFIRMATION;

        $task = new send_confirmation_mails();
        $task->set_custom_data($data);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Execute exactly one of the queued send_confirmation_mails tasks.
     *
     * Mirrors what advanced_testcase::runAdhocTasks() does per task, but stops after the first
     * one, so the state while a second task is still queued can be asserted.
     *
     * @return void
     */
    private function run_one_mail_task(): void {
        global $DB;

        $record = $DB->get_record_select(
            'task_adhoc',
            'classname = :classname',
            ['classname' => '\\mod_booking\\task\\send_confirmation_mails'],
            '*',
            IGNORE_MULTIPLE
        );
        $this->assertNotEmpty($record);

        $task = \core\task\manager::adhoc_task_from_record($record);
        $this->assertInstanceOf(send_confirmation_mails::class, $task);
        $task->set_lock($this->createStub(\core\lock\lock::class));
        $task->execute();
        \core\task\manager::adhoc_task_complete($task);
    }

    /**
     * The ical attachment is deleted once the mail has been sent.
     *
     * This is a regression test: the path stored in customdata is JSON-encoded, so its slashes
     * are escaped. A cleanup that searches for the path including its directories would never
     * match, and the attachment would be left behind forever.
     *
     * @covers \mod_booking\task\send_confirmation_mails::execute
     * @return void
     */
    public function test_ical_attachment_is_deleted_after_the_mail_was_sent(): void {
        $path = $this->create_ical_attachment();
        $this->assertFileExists($path);

        $this->queue_mail_task($path);

        $sink = $this->redirectEmails();
        $this->runAdhocTasks('\mod_booking\task\send_confirmation_mails');
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertFileDoesNotExist($path);
    }

    /**
     * An attachment shared by several queued mails survives until the last of them was sent.
     *
     * @covers \mod_booking\task\send_confirmation_mails::execute
     * @return void
     */
    public function test_shared_attachment_is_kept_until_the_last_mail_was_sent(): void {
        $path = $this->create_ical_attachment();

        // Two mails (e.g. the participant and the booking manager) share one ical file.
        $this->queue_mail_task($path);
        $this->queue_mail_task($path);

        $sink = $this->redirectEmails();

        // Run only the first of the two tasks.
        $this->run_one_mail_task();

        // The second task still needs the attachment, so it must still be there.
        $this->assertFileExists($path);

        // After the second task ran, nothing references the file any more.
        $this->runAdhocTasks('\mod_booking\task\send_confirmation_mails');
        $sink->close();

        $this->assertFileDoesNotExist($path);
    }
}

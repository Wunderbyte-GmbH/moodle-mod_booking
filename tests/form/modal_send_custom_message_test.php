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
use context_system;
use context_user;
use mod_booking\form\modal_send_custom_message;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

/**
 * Tests for modal_send_custom_message dynamic form.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class modal_send_custom_message_test extends advanced_testcase {
    /**
     * Cleanup after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Ensure only explicitly selected users receive a custom message.
     *
     * @covers \mod_booking\form\modal_send_custom_message::process_dynamic_submission
     */
    public function test_process_dynamic_submission_uses_selected_recipients_only(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Message filter test booking',
            'course' => $course->id,
        ]);

        $user1 = $this->getDataGenerator()->create_user([
            'firstname' => 'Max',
            'lastname' => 'Mustermann',
            'email' => 'max@example.com',
        ]);
        $user2 = $this->getDataGenerator()->create_user([
            'firstname' => 'Erika',
            'lastname' => 'Mustermann',
            'email' => 'erika@example.com',
        ]);
        $user3 = $this->getDataGenerator()->create_user([
            'firstname' => 'John',
            'lastname' => 'Doe',
            'email' => 'john@example.com',
        ]);

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'student');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $optionrecord = new stdClass();
        $optionrecord->bookingid = $booking->id;
        $optionrecord->courseid = $course->id;
        $optionrecord->text = 'Option for custom message';
        $optionrecord->chooseorcreatecourse = 1;
        $option = $plugingenerator->create_option($optionrecord);

        // Create booked answers for all three users.
        $plugingenerator->create_answer([
            'optionid' => $option->id,
            'userid' => $user1->id,
        ]);
        $plugingenerator->create_answer([
            'optionid' => $option->id,
            'userid' => $user2->id,
        ]);
        $plugingenerator->create_answer([
            'optionid' => $option->id,
            'userid' => $user3->id,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $selecteduserids = [(int)$user1->id, (int)$user3->id];

        $ajaxargs = [
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$option->id,
            'checkedids' => '',
            'selecteduserids' => $selecteduserids,
            'subject' => 'Filtered message subject',
            'message' => [
                'text' => 'Filtered message body',
                'format' => FORMAT_HTML,
            ],
        ];

        $submitdata = modal_send_custom_message::mock_ajax_submit($ajaxargs);
        $mform = new modal_send_custom_message(null, null, 'post', '', [], true, $submitdata, true);

        $this->assertTrue($mform->is_validated(), 'The custom message dynamic form should validate.');
        $result = $mform->process_dynamic_submission();

        $this->assertEquals(1, (int)($result->success ?? 0));

        $sentmessage = (string)($result->message ?? '');
        $this->assertStringContainsString('Max Mustermann', $sentmessage);
        $this->assertStringContainsString('John Doe', $sentmessage);
        $this->assertStringNotContainsString('Erika Mustermann', $sentmessage);
    }

    /**
     * Helper: set up a booking module with $numusers booked users.
     *
     * Returns the option settings and a userid-indexed array of user objects.
     *
     * @param int $numusers
     * @return array{0: \mod_booking\booking_option_settings, 1: array<int, \stdClass>}
     */
    private function create_booked_option(int $numusers = 2): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name'   => 'Attachment test booking',
            'course' => $course->id,
        ]);

        /** @var mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $optionrecord              = new stdClass();
        $optionrecord->bookingid   = $booking->id;
        $optionrecord->courseid    = $course->id;
        $optionrecord->text        = 'Option for attachment test';
        $optionrecord->chooseorcreatecourse = 1;
        $option = $gen->create_option($optionrecord);

        $users = [];
        for ($i = 0; $i < $numusers; $i++) {
            $user = $this->getDataGenerator()->create_user([
                'firstname' => 'User' . $i,
                'lastname'  => 'Attachment',
                'email'     => 'attachmentuser' . $i . '@example.com',
            ]);
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $gen->create_answer(['optionid' => $option->id, 'userid' => $user->id]);
            $users[(int)$user->id] = $user;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return [$settings, $users];
    }

    /**
     * Helper: create a draft file in the current admin user's draft area.
     *
     * @param string $filename  Display file name.
     * @param string $content   File content.
     * @return int              Draft item ID.
     */
    private function create_draft_file(string $filename, string $content): int {
        $fs          = get_file_storage();
        $adminuser   = get_admin();
        $usercontext = context_user::instance($adminuser->id);
        $draftitemid = file_get_unused_draft_itemid();

        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => $filename,
        ], $content);

        return $draftitemid;
    }

    /**
     * Test: a single message with attachment is dispatched and the draft area is
     * cleared afterwards.  The stored_file created for the recipient in
     * mod_booking/message_attachments persists in PHPUnit (same pattern as iCal).
     *
     * @covers \mod_booking\form\modal_send_custom_message::process_dynamic_submission
     * @covers \mod_booking\message_controller::set_custom_attachment
     */
    public function test_send_with_attachment_to_single_user(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings, $users] = $this->create_booked_option(1);
        $recipient = reset($users);

        $filename    = 'singleuser.pdf';
        $content     = '%PDF-1.4 single-user attachment';
        $draftitemid = $this->create_draft_file($filename, $content);

        $sink = $this->redirectMessages();

        $submitdata = modal_send_custom_message::mock_ajax_submit([
            'cmid'            => (int)$settings->cmid,
            'optionid'        => (int)$settings->id,
            'checkedids'      => '',
            'selecteduserids' => [(int)$recipient->id],
            'subject'         => 'Attachment single-user subject',
            'message'         => ['text' => 'Attachment single-user body', 'format' => FORMAT_HTML],
            'attachment'      => $draftitemid,
        ]);
        $mform  = new modal_send_custom_message(null, null, 'post', '', [], true, $submitdata, true);
        $result = $mform->process_dynamic_submission();

        $messages = $sink->get_messages();
        $sink->close();

        // One message must have been dispatched.
        $this->assertCount(1, $messages, 'Exactly one message should be sent for one recipient.');

        // Successful result flag.
        $this->assertEquals(1, (int)($result->success ?? 0));

        // Draft area must be empty — the uploaded file was consumed.
        $adminuser   = get_admin();
        $usercontext = context_user::instance($adminuser->id);
        $fs          = get_file_storage();
        $remaining   = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        $this->assertEmpty($remaining, 'Draft file area must be empty after submission.');

        /* The stored_file for the recipient must have been created in Moodle file storage.
        (In non-PHPUNIT context it is deleted right after send_or_queue() returns,
        identical to the iCal cleanup pattern.  In PHPUnit it intentionally persists.) */
        $syscontext = context_system::instance();
        $stored = $fs->get_file(
            $syscontext->id,
            'mod_booking',
            'message_attachments',
            (int)$recipient->id,
            '/',
            $filename
        );
        $this->assertNotFalse($stored, 'Attachment stored_file must be created during send.');
        $this->assertEquals(
            $content,
            $stored->get_content(),
            'Stored_file content must match the original uploaded file content.'
        );
    }

    /**
     * Test: one message per recipient is dispatched when multiple users are selected,
     * each receiving their own stored_file in mod_booking/message_attachments.
     *
     * @covers \mod_booking\form\modal_send_custom_message::process_dynamic_submission
     * @covers \mod_booking\message_controller::set_custom_attachment
     */
    public function test_send_with_attachment_to_multiple_users(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings, $users] = $this->create_booked_option(2);
        $userids = array_keys($users);

        $filename    = 'multiuser.pdf';
        $content     = '%PDF-1.4 multi-user attachment';
        $draftitemid = $this->create_draft_file($filename, $content);

        $sink = $this->redirectMessages();

        $submitdata = modal_send_custom_message::mock_ajax_submit([
            'cmid'            => (int)$settings->cmid,
            'optionid'        => (int)$settings->id,
            'checkedids'      => '',
            'selecteduserids' => $userids,
            'subject'         => 'Attachment multi-user subject',
            'message'         => ['text' => 'Attachment multi-user body', 'format' => FORMAT_HTML],
            'attachment'      => $draftitemid,
        ]);
        $mform  = new modal_send_custom_message(null, null, 'post', '', [], true, $submitdata, true);
        $result = $mform->process_dynamic_submission();

        $messages = $sink->get_messages();
        $sink->close();

        // One message per recipient.
        $this->assertCount(
            count($userids),
            $messages,
            'One message should be dispatched per selected recipient.'
        );

        $this->assertEquals(1, (int)($result->success ?? 0));

        // Draft area must be empty after send.
        $adminuser   = get_admin();
        $usercontext = context_user::instance($adminuser->id);
        $fs          = get_file_storage();
        $remaining   = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        $this->assertEmpty($remaining, 'Draft file area must be empty after submission.');

        // A stored_file must have been created for every recipient.
        $syscontext = context_system::instance();
        foreach ($userids as $uid) {
            $stored = $fs->get_file(
                $syscontext->id,
                'mod_booking',
                'message_attachments',
                $uid,
                '/',
                $filename
            );
            $this->assertNotFalse(
                $stored,
                "Attachment stored_file must be created for user {$uid}."
            );
            $this->assertEquals(
                $content,
                $stored->get_content(),
                "Stored_file content must match original for user {$uid}."
            );
        }
    }

    /**
     * Test: submitting without a draft file (no attachment) still sends messages normally.
     * Ensures the attachment code path is bypassed cleanly when no file is uploaded.
     *
     * @covers \mod_booking\form\modal_send_custom_message::process_dynamic_submission
     */
    public function test_send_without_attachment_still_works(): void {
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        $this->setAdminUser();

        [$settings, $users] = $this->create_booked_option(1);
        $recipient = reset($users);

        $sink = $this->redirectMessages();

        $submitdata = modal_send_custom_message::mock_ajax_submit([
            'cmid'            => (int)$settings->cmid,
            'optionid'        => (int)$settings->id,
            'checkedids'      => '',
            'selecteduserids' => [(int)$recipient->id],
            'subject'         => 'No-attachment subject',
            'message'         => ['text' => 'No-attachment body', 'format' => FORMAT_HTML],
            // No 'attachment' key — filepicker left empty.
        ]);
        $mform  = new modal_send_custom_message(null, null, 'post', '', [], true, $submitdata, true);
        $result = $mform->process_dynamic_submission();

        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages, 'Message should be sent even without attachment.');
        $this->assertEquals(1, (int)($result->success ?? 0));

        // No stored_file should have been created in message_attachments.
        $fs         = get_file_storage();
        $syscontext = context_system::instance();
        $stored = $fs->get_file(
            $syscontext->id,
            'mod_booking',
            'message_attachments',
            (int)$recipient->id,
            '/',
            '.'
        );
        // The directory entry '.' may exist; verify no actual file was stored.
        $allfiles = $fs->get_area_files(
            $syscontext->id,
            'mod_booking',
            'message_attachments',
            (int)$recipient->id,
            'id',
            false
        );
        $this->assertEmpty(
            $allfiles,
            'No attachment stored_file should be created when no file is uploaded.'
        );
    }
}

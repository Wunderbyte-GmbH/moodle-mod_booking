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
 * Tests for the customform edit modal of the bookings tracker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\bo_info;
use mod_booking\booking_answers\scopes\option;
use mod_booking\form\option\modal_change_customform;
use mod_booking\local\mobile\customformstore;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\utils\wb_payment;
use mod_booking_generator;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * The modal edits the customform values stored in the json of a single
 * booking answer (condition_customform). It is a PRO feature, gated by
 * mod/booking:changecustomformofotherusers (button and submission), limited
 * to exactly one answer, keeps non-editable formtypes and timemodified
 * untouched and logs a diff to the booking history.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class modal_change_customform_test extends booking_advanced_testcase {
    /**
     * Static PRO override survives between tests, so always remove it.
     *
     * @return void
     */
    public function tearDown(): void {
        wb_payment::override_pro_version_for_tests(null);
        parent::tearDown();
    }

    /**
     * The action button is registered on the booked and the waiting list
     * tables for users with the capability while PRO is active - and only
     * if the option has editable customform fields at all.
     *
     * @covers \mod_booking\booking_answers\scopes\option::return_users_table
     * @covers \mod_booking\form\option\modal_change_customform::editable_formelements
     */
    public function test_button_visibility_gates(): void {
        $this->setAdminUser();
        wb_payment::override_pro_version_for_tests(true);
        [$settings, , , $course] = $this->create_booked_option_with_customform();

        // Admin with PRO: button on the booked and the waiting list tables.
        $this->assertContains(
            'mod_booking\\form\\option\\modal_change_customform',
            $this->button_identifiers($settings, MOD_BOOKING_STATUSPARAM_BOOKED, 'adminbooked')
        );
        $this->assertContains(
            'mod_booking\\form\\option\\modal_change_customform',
            $this->button_identifiers($settings, MOD_BOOKING_STATUSPARAM_WAITINGLIST, 'adminwl')
        );

        // Without PRO the button disappears (same user, same capability).
        wb_payment::override_pro_version_for_tests(false);
        $this->assertNotContains(
            'mod_booking\\form\\option\\modal_change_customform',
            $this->button_identifiers($settings, MOD_BOOKING_STATUSPARAM_BOOKED, 'adminnopro')
        );
        wb_payment::override_pro_version_for_tests(true);

        // Editing teachers hold managebookedusers (notes button) but not
        // changecustomformofotherusers (fresh-install default: manager only).
        $editingteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($editingteacher->id, $course->id, 'editingteacher');
        $this->setUser($editingteacher);
        $buttons = $this->button_identifiers($settings, MOD_BOOKING_STATUSPARAM_BOOKED, 'teacher');
        $this->assertContains('mod_booking\\form\\optiondates\\modal_change_notes', $buttons);
        $this->assertNotContains('mod_booking\\form\\option\\modal_change_customform', $buttons);

        // An option without customform fields offers no button either.
        $this->setAdminUser();
        $plainsettings = $this->create_plain_option((int)$settings->bookingid);
        $this->assertNotContains(
            'mod_booking\\form\\option\\modal_change_customform',
            $this->button_identifiers($plainsettings, MOD_BOOKING_STATUSPARAM_BOOKED, 'plain')
        );
    }

    /**
     * Submission is rejected without the capability (editing teachers keep
     * managebookedusers but must not edit customform values).
     *
     * @covers \mod_booking\form\option\modal_change_customform::check_access_for_dynamic_submission
     */
    public function test_submission_requires_capability(): void {
        $this->setAdminUser();
        wb_payment::override_pro_version_for_tests(true);
        [$settings, $answerids, , $course] = $this->create_booked_option_with_customform();

        $editingteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($editingteacher->id, $course->id, 'editingteacher');
        $this->setUser($editingteacher);

        $this->expectException(moodle_exception::class);
        $this->submit_modal($settings, (string)$answerids[0], [
            'customform_shorttext_1' => 'Changed by teacher',
        ]);
    }

    /**
     * Submission is rejected without an active PRO version, even with the
     * capability (UI hiding is not enough).
     *
     * @covers \mod_booking\form\option\modal_change_customform::check_access_for_dynamic_submission
     */
    public function test_submission_requires_pro_version(): void {
        $this->setAdminUser();
        wb_payment::override_pro_version_for_tests(true);
        [$settings, $answerids] = $this->create_booked_option_with_customform();

        wb_payment::override_pro_version_for_tests(false);
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('proversiononly', 'mod_booking'));
        $this->submit_modal($settings, (string)$answerids[0], [
            'customform_shorttext_1' => 'Changed without PRO',
        ]);
    }

    /**
     * An answer id of a foreign option is rejected (no IDOR via manipulated
     * checkedids), and more than one checked answer is rejected too.
     *
     * @covers \mod_booking\form\option\modal_change_customform::process_dynamic_submission
     */
    public function test_foreign_answer_and_multi_selection_are_rejected(): void {
        global $DB;

        $this->setAdminUser();
        wb_payment::override_pro_version_for_tests(true);
        [$settings, $answerids, $students, $course] = $this->create_booked_option_with_customform();

        // A second option in the same instance with its own answer.
        $plainsettings = $this->create_plain_option((int)$settings->bookingid);
        booking_bookit::bookit('option', $plainsettings->id, $students[0]->id);
        booking_bookit::bookit('option', $plainsettings->id, $students[0]->id);
        $foreignanswerid = $DB->get_field('booking_answers', 'id', [
            'optionid' => $plainsettings->id,
            'userid' => $students[0]->id,
        ], MUST_EXIST);

        try {
            $this->submit_modal($settings, (string)$foreignanswerid, [
                'customform_shorttext_1' => 'IDOR attempt',
            ]);
            $this->fail('A foreign answer id must be rejected.');
        } catch (moodle_exception $e) {
            $this->assertStringContainsString('error:answernotinthisoption', $e->errorcode);
        }

        // Editing several answers at once is not allowed either.
        try {
            $this->submit_modal($settings, implode(',', $answerids), [
                'customform_shorttext_1' => 'Mass edit attempt',
            ]);
            $this->fail('More than one checked answer must be rejected.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:selectonlyonerow', $e->errorcode);
        }
    }

    /**
     * Roundtrip: the submitted values replace the stored ones in the answer
     * json, foreign json keys and timemodified stay untouched and the diff
     * is logged to the booking history.
     *
     * @covers \mod_booking\form\option\modal_change_customform::process_dynamic_submission
     */
    public function test_roundtrip_updates_json_and_history(): void {
        global $DB, $USER;

        $this->setAdminUser();
        wb_payment::override_pro_version_for_tests(true);
        [$settings, $answerids, $students] = $this->create_booked_option_with_customform();
        $answerid = $answerids[0];

        // Fix timemodified: it is the sort key of the waiting list and must not change.
        $DB->set_field('booking_answers', 'timemodified', 1234567890, ['id' => $answerid]);

        $this->submit_modal($settings, (string)$answerid, [
            'customform_shorttext_1' => 'L',
            'customform_select_2' => 'meat',
            'customform_mail_3' => 'student@example.com',
        ]);

        $answer = $DB->get_record('booking_answers', ['id' => $answerid]);
        $customform = json_decode($answer->json)->condition_customform;
        $this->assertSame('L', $customform->customform_shorttext_1);
        $this->assertSame('meat', $customform->customform_select_2);
        // Unchanged submitted values and foreign json keys stay as they are.
        $this->assertSame('student@example.com', $customform->customform_mail_3);
        $this->assertEquals($settings->id, $customform->id);
        $this->assertEquals(1234567890, $answer->timemodified, 'timemodified must not be changed.');

        // The history entry holds the editor, the user and the diff of the changed fields.
        $history = $DB->get_record('booking_history', [
            'answerid' => $answerid,
            'status' => MOD_BOOKING_STATUSPARAM_CUSTOMFORM_EDITED,
        ], '*', MUST_EXIST);
        $this->assertEquals($students[0]->id, $history->userid);
        $this->assertEquals($USER->id, $history->usermodified);
        $this->assertEquals($settings->id, $history->optionid);
        $diff = json_decode($history->json, true);
        $this->assertSame(
            ['label' => 'T-shirt size', 'oldvalue' => 'M', 'newvalue' => 'L'],
            $diff['customform_shorttext_1']
        );
        $this->assertSame(
            ['label' => 'Meal choice', 'oldvalue' => 'vegetarian', 'newvalue' => 'meat'],
            $diff['customform_select_2']
        );
        // The unchanged mail field is not part of the diff.
        $this->assertArrayNotHasKey('customform_mail_3', $diff);
    }

    /**
     * Non-editable formtypes are ignored on the server, even if they are part
     * of the submitted payload: the enrolusersaction value (coupled to the
     * booked places) stays untouched in json and places column.
     *
     * @covers \mod_booking\form\option\modal_change_customform::process_dynamic_submission
     */
    public function test_excluded_formtypes_are_not_changed(): void {
        global $DB;

        $this->setAdminUser();
        wb_payment::override_pro_version_for_tests(true);
        [$settings, $answerids] = $this->create_booked_option_with_customform();
        $answerid = $answerids[0];

        $this->submit_modal($settings, (string)$answerid, [
            'customform_shorttext_1' => 'XL',
            'customform_select_2' => 'vegetarian',
            'customform_mail_3' => 'student@example.com',
            // Not editable: must be ignored although it is submitted.
            'customform_enrolusersaction_4' => '5',
        ]);

        $answer = $DB->get_record('booking_answers', ['id' => $answerid]);
        $customform = json_decode($answer->json)->condition_customform;
        $this->assertSame('XL', $customform->customform_shorttext_1);
        $this->assertEquals('1', $customform->customform_enrolusersaction_4);
        $this->assertEquals(1, $answer->places, 'The booked places must not be changed.');

        $diff = json_decode($DB->get_field('booking_history', 'json', [
            'answerid' => $answerid,
            'status' => MOD_BOOKING_STATUSPARAM_CUSTOMFORM_EDITED,
        ]), true);
        $this->assertArrayNotHasKey('customform_enrolusersaction_4', $diff);
    }

    /**
     * Server-side validation: notempty rules of the field definition are
     * enforced, mail values have to be valid mail addresses and select
     * values have to be one of the configured options (no free text).
     *
     * @covers \mod_booking\form\option\modal_change_customform::validation
     */
    public function test_validation_rules(): void {
        $this->setAdminUser();
        wb_payment::override_pro_version_for_tests(true);
        [$settings, $answerids] = $this->create_booked_option_with_customform();

        $form = new modal_change_customform(null, null, 'post', '', [], true, [
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'scope' => 'option',
            'checkedids' => (string)$answerids[0],
            'confirmoverwrite' => 1,
            'confirmsave' => 1,
        ]);

        $data = [
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'scope' => 'option',
            'checkedids' => (string)$answerids[0],
            'customform_shorttext_1' => '',
            'customform_select_2' => 'pizza',
            'customform_mail_3' => 'not-a-mail-address',
        ];
        $errors = $form->validation($data, []);

        $this->assertSame(get_string('error:mustnotbeempty', 'mod_booking'), $errors['customform_shorttext_1']);
        $this->assertSame(get_string('error:choosevalue', 'mod_booking'), $errors['customform_select_2']);
        $this->assertSame(get_string('bocondcustomformmailerror', 'mod_booking'), $errors['customform_mail_3']);
        // The overwrite acknowledgement checkbox is mandatory as well.
        $this->assertSame(get_string('error:confirmthatyouaresure', 'mod_booking'), $errors['confirmoverwrite']);

        $valid = $data;
        $valid['customform_shorttext_1'] = 'M';
        $valid['customform_select_2'] = 'meat';
        $valid['customform_mail_3'] = 'valid@example.com';
        $valid['confirmoverwrite'] = 1;
        $this->assertSame([], $form->validation($valid, []));
    }

    /**
     * The two overwrite safeguards block saving: without the acknowledgement
     * checkbox validation fails, and the first "Save" click (no confirmsave
     * key submitted yet) only redisplays the form with the explicit question,
     * armed with confirmsave=1 - only that resubmission actually saves.
     *
     * @covers \mod_booking\form\option\modal_change_customform::validation
     * @covers \mod_booking\form\option\modal_change_customform::definition
     */
    public function test_overwrite_safeguards_block_saving(): void {
        global $DB;

        $this->setAdminUser();
        wb_payment::override_pro_version_for_tests(true);
        [$settings, $answerids] = $this->create_booked_option_with_customform();
        $answerid = $answerids[0];
        $originaljson = $DB->get_field('booking_answers', 'json', ['id' => $answerid]);

        $newvalues = [
            'customform_shorttext_1' => 'S',
            'customform_select_2' => 'vegetarian',
            'customform_mail_3' => 'student@example.com',
        ];

        // Safeguard 1: unchecked acknowledgement checkbox - validation fails.
        $form = $this->build_submitted_modal($settings, (string)$answerid, $newvalues + [
            'confirmoverwrite' => 0,
        ]);
        $this->assertFalse($form->is_validated());

        // Safeguard 2: the first "Save" click submits no confirmsave key
        // (the initially loaded form does not contain the element) and fails;
        // the redisplayed form carries the question and the armed flag.
        $form = $this->build_submitted_modal($settings, (string)$answerid, $newvalues + [
            'confirmsave' => null,
        ]);
        $this->assertFalse($form->is_validated());
        $html = $form->render();
        $this->assertStringContainsString(
            get_string('confirmcustomformoverwritequestion', 'mod_booking'),
            $html
        );
        $this->assertMatchesRegularExpression('/name="confirmsave"[^>]*value="1"/', $html);

        // Neither attempt saved anything.
        $this->assertSame($originaljson, $DB->get_field('booking_answers', 'json', ['id' => $answerid]));
        $this->assertFalse($DB->record_exists('booking_history', [
            'answerid' => $answerid,
            'status' => MOD_BOOKING_STATUSPARAM_CUSTOMFORM_EDITED,
        ]));

        // The confirmed resubmission (both safeguards passed) saves.
        $this->submit_modal($settings, (string)$answerid, $newvalues);
        $customform = json_decode($DB->get_field('booking_answers', 'json', ['id' => $answerid]))->condition_customform;
        $this->assertSame('S', $customform->customform_shorttext_1);
    }

    /**
     * Helper: submit the modal like the dynamic form endpoint does
     * (including the access check on construction). Unless overridden in
     * $values, both overwrite safeguards are passed (acknowledgement checkbox
     * checked, second "Save" click simulated via confirmsave=1).
     *
     * @param \mod_booking\booking_option_settings $settings
     * @param string $checkedids
     * @param array $values submitted customform values
     * @return void
     */
    private function submit_modal($settings, string $checkedids, array $values): void {
        $form = $this->build_submitted_modal($settings, $checkedids, $values);
        $this->assertTrue($form->is_validated());
        $form->process_dynamic_submission();
    }

    /**
     * Helper: build the modal form from mocked ajax submit data
     * (including the access check on construction).
     *
     * @param \mod_booking\booking_option_settings $settings
     * @param string $checkedids
     * @param array $values submitted customform values
     * @return modal_change_customform
     */
    private function build_submitted_modal($settings, string $checkedids, array $values): modal_change_customform {
        $submitdata = modal_change_customform::mock_ajax_submit(array_merge([
            'cmid' => (int)$settings->cmid,
            'optionid' => (int)$settings->id,
            'scope' => 'option',
            'checkedids' => $checkedids,
            'confirmoverwrite' => 1,
            'confirmsave' => 1,
        ], $values));
        // The first "Save" click of the real dialog submits no confirmsave key at all.
        if (array_key_exists('confirmsave', $values) && $values['confirmsave'] === null) {
            unset($submitdata['confirmsave']);
        }
        return new modal_change_customform(null, null, 'post', '', [], true, $submitdata, true);
    }

    /**
     * Helper: identifiers (methodname or formname) of the action buttons of
     * the users table of the option scope for one status param.
     *
     * @param \mod_booking\booking_option_settings $settings
     * @param int $statusparam
     * @param string $prefix unique table name prefix
     * @return array
     */
    private function button_identifiers($settings, int $statusparam, string $prefix): array {
        $scope = new option();
        $table = $scope->return_users_table(
            'option',
            (int)$settings->id,
            $statusparam,
            'cfmodal' . $prefix,
            ['firstname'],
            [get_string('firstname')]
        );
        return array_map(
            fn($button) => $button['methodname'] ?? $button['formname'],
            $table->actionbuttons ?? []
        );
    }

    /**
     * Helper: booking option with a customform condition (shorttext with
     * notempty, select, mail and the excluded enrolusersaction) and two
     * students booked with filled-in values.
     *
     * @return array{0: \mod_booking\booking_option_settings, 1: int[], 2: stdClass[], 3: stdClass}
     */
    private function create_booked_option_with_customform(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Customform modal test booking',
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
        ]);

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $booking->id;
        $record->text = 'Option with customform';
        $record->useprice = 0;
        $record->maxanswers = 5;
        $record->bo_cond_customform_restrict = 1;
        $record->bo_cond_customform_select_1_1 = 'shorttext';
        $record->bo_cond_customform_label_1_1 = 'T-shirt size';
        $record->bo_cond_customform_notempty_1_1 = 1;
        $record->bo_cond_customform_select_1_2 = 'select';
        $record->bo_cond_customform_label_1_2 = 'Meal choice';
        $record->bo_cond_customform_value_1_2 = "vegetarian => Vegetarian" . PHP_EOL . "meat => Meat";
        $record->bo_cond_customform_select_1_3 = 'mail';
        $record->bo_cond_customform_label_1_3 = 'Contact mail';
        $record->bo_cond_customform_select_1_4 = 'enrolusersaction';
        $record->bo_cond_customform_label_1_4 = 'Number of users';
        $record->bo_cond_customform_value_1_4 = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $answerids = [];
        foreach ([$student1, $student2] as $student) {
            $this->setUser($student);
            $boinfo = new bo_info($settings);
            booking_bookit::bookit('option', $settings->id, $student->id);
            [$condid] = $boinfo->is_available($settings->id, $student->id, false);
            $this->assertSame(MOD_BOOKING_BO_COND_JSON_CUSTOMFORM, $condid);

            $customformstore = new customformstore($student->id, $settings->id);
            $customformstore->set_customform_data((object)[
                'id' => $settings->id,
                'userid' => $student->id,
                'customform_shorttext_1' => 'M',
                'customform_select_2' => 'vegetarian',
                'customform_mail_3' => 'student@example.com',
                'customform_enrolusersaction_4' => '1',
            ]);
            booking_bookit::bookit('option', $settings->id, $student->id);

            $answerids[] = (int)$DB->get_field('booking_answers', 'id', [
                'optionid' => $settings->id,
                'userid' => $student->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ], MUST_EXIST);
        }

        $this->setAdminUser();
        return [$settings, $answerids, [$student1, $student2], $course];
    }

    /**
     * Helper: a second option without customform condition in the same instance.
     *
     * @param int $bookingid
     * @return \mod_booking\booking_option_settings
     */
    private function create_plain_option(int $bookingid) {
        $record = new stdClass();
        $record->importing = 1;
        $record->bookingid = $bookingid;
        $record->text = 'Option without customform';
        $record->useprice = 0;
        $record->maxanswers = 5;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        return singleton_service::get_instance_of_booking_option_settings($option->id);
    }
}

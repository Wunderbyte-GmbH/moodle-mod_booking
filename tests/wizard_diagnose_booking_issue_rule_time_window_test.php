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
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;

/**
 * The time window of "react on event" mail rules must surface in the booking diagnosis.
 *
 * Pinned case: the setting "Number of days after end of booking option, where rule still applies"
 * (ruledata->aftercompletion, 1 day in every rule template) makes the mail task skip a rule once
 * option end plus the configured days has passed. The skip only reaches the cron log, so the
 * diagnosis reported "all rules apply" although no confirmation mail could go out.
 *
 * The confirmation goes out when the person books, so the window is checked at the time of the
 * person's booking answer, and at the current time when there is none.
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\option_rules_diagnostics
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_checklist_builder
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_diagnose_booking_issue_rule_time_window_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** New user-facing string keys; each must exist in every shipped language pack. */
    private const NEW_STRINGS = [
        'agent_booking_diagnose_check_ruletimewindow',
        'agent_booking_diagnose_finding_ruletimewindow_ok',
        'agent_booking_diagnose_finding_ruletimewindow_confirmation_expired',
        'agent_booking_diagnose_finding_ruletimewindow_confirmation_expired_now',
        'agent_booking_diagnose_finding_ruletimewindow_other_expired',
        'agent_booking_diagnose_reason_rules_mail_expired',
        'agent_booking_diagnose_reason_rules_mail_expired_other',
        'agent_booking_diagnose_reason_rules_mail_expired_now',
        'agent_booking_diagnose_reason_rules_mail_expired_concrete',
        'agent_booking_diagnose_reason_rules_othermail_expired',
    ];

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Course, booking instance, one option ending at $end and an enrolled student.
     *
     * @param int $end
     * @param bool $selflearning
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass} [booking module, option, student]
     */
    private function setup_booking(int $end, bool $selflearning = false): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Time window booking',
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
        ]);

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Time window option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = $end - 2 * HOURSECS;
        $record->courseendtime_0 = $end;
        if ($selflearning) {
            $record->selflearningcourse = 1;
            $record->duration = DAYSECS * 4;
        }
        $option = $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);

        return [$booking, $option, $student];
    }

    /**
     * Add a mail rule to the booking instance.
     *
     * @param \stdClass $booking
     * @param string|int $aftercompletion Stored value of the time window setting.
     * @param string $event Event class the rule reacts to.
     * @param string $name Distinct rule name; the diagnosis deduplicates by name.
     * @return \stdClass
     */
    private function add_rule(
        stdClass $booking,
        $aftercompletion,
        string $event = '\\mod_booking\\event\\bookingoption_booked',
        string $name = 'Booking confirmation'
    ): stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_rule([
            'contextid' => (int)\context_module::instance($booking->cmid)->id,
            'rulename' => 'rule_react_on_event',
            'name' => $name,
            'conditionname' => 'select_user_from_event',
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"mail","template":"mail","templateformat":"1"}',
            'eventname' => $event,
            'aftercompletion' => $aftercompletion,
        ]);
    }

    /**
     * Book the student and pin the booking answer to the given time.
     *
     * @param \stdClass $option
     * @param \stdClass $student
     * @param int $bookedat
     * @return void
     */
    private function book_at(stdClass $option, stdClass $student, int $bookedat): void {
        global $DB;

        $this->getDataGenerator()->get_plugin_generator('mod_booking')->create_answer([
            'optionid' => (int)$option->id,
            'userid' => (int)$student->id,
        ]);
        $DB->set_field(
            'booking_answers',
            'timemodified',
            $bookedat,
            ['optionid' => (int)$option->id, 'userid' => (int)$student->id]
        );
        booking_option::purge_cache_for_answers((int)$option->id);
        singleton_service::destroy_booking_answers((int)$option->id);
    }

    /**
     * Write the per-option rule restriction into the option JSON.
     *
     * @param int $optionid
     * @param int $mode 0 = opt out, 1 = opt in.
     * @param array $ruleids
     * @return void
     */
    private function set_rule_restriction(int $optionid, int $mode, array $ruleids): void {
        global $DB;

        $json = json_decode((string)$DB->get_field('booking_options', 'json', ['id' => $optionid])) ?: new stdClass();
        $json->skipbookingrulesmode = $mode;
        $json->skipbookingrules = array_map('strval', $ruleids);
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
    }

    /**
     * Run the diagnosis for the missing-mail issue as the student.
     *
     * @param \stdClass $booking
     * @param \stdClass $option
     * @param \stdClass $student
     * @param string $lang
     * @return array
     */
    private function diagnose(stdClass $booking, stdClass $option, stdClass $student, string $lang = 'en'): array {
        $this->setUser($student);

        return (new diagnose_booking_issue_skill())->execute(
            [
                'optionid' => (int)$option->id,
                'issue' => 'missing_email',
                'question' => 'I booked but received no confirmation.',
                'outputlang' => $lang,
            ],
            (int)\context_module::instance($booking->cmid)->id,
            (int)$student->id
        );
    }

    /**
     * Rule ids of a list of diagnosis entries.
     *
     * @param array $entries
     * @return int[]
     */
    private static function ids(array $entries): array {
        return array_map(static fn(array $entry): int => (int)$entry['id'], $entries);
    }

    /**
     * One checklist row by its check key.
     *
     * @param array $result
     * @param string $checkkey
     * @return array
     */
    private function checklist_row(array $result, string $checkkey): array {
        $label = get_string('agent_booking_diagnose_check_' . $checkkey, 'mod_booking');
        foreach ((array)$result['checklist_rows'] as $row) {
            if ((string)$row['check'] === $label) {
                return $row;
            }
        }

        $this->fail('No checklist row for ' . $checkkey);
    }

    /**
     * Booked after the window of the confirmation rule closed: the rule is the cause and is named.
     */
    public function test_booking_after_closed_window_reports_expired_confirmation_rule(): void {
        $this->resetAfterTest();
        $end = time() - 3 * DAYSECS;
        [$booking, $option, $student] = $this->setup_booking($end);
        $rule = $this->add_rule($booking, 1);
        $this->book_at($option, $student, time() - HOURSECS);

        $result = $this->diagnose($booking, $option, $student);

        $this->assertSame('executed', $result['status']);
        $this->assertSame('booked', $result['diagnosis']['userstatus']);
        $rules = $result['diagnosis']['notification_rules'];
        $this->assertTrue($rules['confirmationcheckedatbooking']);
        $this->assertSame([(int)$rule->id], self::ids($rules['expiredconfirmationrules']));
        $this->assertSame($end + DAYSECS, $rules['expiredconfirmationrules'][0]['windowend']);
        $this->assertSame([], $rules['expiredothermailrules']);
        // The restriction is untouched, so the restriction row must not take the blame.
        $this->assertSame([], $rules['skippedmailrules']);

        $findings = implode("\n", $result['diagnosis']['reasons']);
        $this->assertStringContainsString('Booking confirmation', $findings);
        $row = $this->checklist_row($result, 'ruletimewindow');
        $this->assertSame('fail', $row['status']);
        $this->assertStringContainsString('Booking confirmation', $row['finding']);
        $this->assertNotNull($row['url']);
    }

    /**
     * Booked while the window was still open: the closed window now did not stop that mail.
     */
    public function test_booking_inside_window_does_not_blame_the_rule(): void {
        $this->resetAfterTest();
        $end = time() - 3 * DAYSECS;
        [$booking, $option, $student] = $this->setup_booking($end);
        $this->add_rule($booking, 1);
        $this->book_at($option, $student, $end - DAYSECS);

        $result = $this->diagnose($booking, $option, $student);

        $rules = $result['diagnosis']['notification_rules'];
        $this->assertSame([], $rules['expiredconfirmationrules']);
        $this->assertNotSame('fail', $this->checklist_row($result, 'ruletimewindow')['status']);
        $this->assertStringNotContainsString('Booking confirmation', implode("\n", $result['diagnosis']['reasons']));
    }

    /**
     * Without a booking the window is checked now: a booking made now would send no confirmation.
     */
    public function test_without_booking_window_is_checked_now(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 3 * DAYSECS);
        $rule = $this->add_rule($booking, 1);

        $result = $this->diagnose($booking, $option, $student);

        $rules = $result['diagnosis']['notification_rules'];
        $this->assertFalse($rules['confirmationcheckedatbooking']);
        $this->assertSame([(int)$rule->id], self::ids($rules['expiredconfirmationrules']));
        $this->assertSame('fail', $this->checklist_row($result, 'ruletimewindow')['status']);
    }

    /**
     * A closed window on a rule that does not confirm bookings is context (warning), never the cause.
     */
    public function test_closed_window_of_other_mail_rule_is_only_a_warning(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 3 * DAYSECS);
        $this->add_rule($booking, '');
        $cancel = $this->add_rule($booking, 1, '\\mod_booking\\event\\bookingoption_cancelled', 'Cancellation notice');
        $this->book_at($option, $student, time() - HOURSECS);

        $result = $this->diagnose($booking, $option, $student);

        $rules = $result['diagnosis']['notification_rules'];
        $this->assertSame([], $rules['expiredconfirmationrules']);
        $this->assertSame([(int)$cancel->id], self::ids($rules['expiredothermailrules']));
        $this->assertSame('warn', $this->checklist_row($result, 'ruletimewindow')['status']);
        $findings = implode("\n", $result['diagnosis']['reasons']);
        $this->assertStringNotContainsString('Cancellation notice', $findings);
        $this->assertStringContainsString(
            get_string('agent_booking_diagnose_reason_rules_othermail_expired', 'mod_booking', 1),
            $findings
        );
    }

    /**
     * Negative case: an empty setting keeps the rule forever, the row passes.
     */
    public function test_unlimited_rule_is_not_reported(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 30 * DAYSECS);
        $this->add_rule($booking, '');
        $this->book_at($option, $student, time() - HOURSECS);

        $result = $this->diagnose($booking, $option, $student);

        $rules = $result['diagnosis']['notification_rules'];
        $this->assertSame([], $rules['expiredconfirmationrules']);
        $this->assertSame([], $rules['expiredothermailrules']);
        $this->assertNull($rules['applied'][0]['windowend']);
        $this->assertSame('ok', $this->checklist_row($result, 'ruletimewindow')['status']);
    }

    /**
     * Negative case: self-learning courses are never cut off by the window, like the mail task.
     */
    public function test_selflearning_option_is_not_reported(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 10 * DAYSECS, true);
        $this->add_rule($booking, 1);

        $result = $this->diagnose($booking, $option, $student);

        $this->assertSame([], $result['diagnosis']['notification_rules']['expiredconfirmationrules']);
        $this->assertSame('ok', $this->checklist_row($result, 'ruletimewindow')['status']);
    }

    /**
     * A rule already switched off by the option's rule restriction is blamed there only, not twice.
     */
    public function test_rule_skipped_by_restriction_is_not_reported_as_expired(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 3 * DAYSECS);
        $rule = $this->add_rule($booking, 1);
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);

        $rules = $result['diagnosis']['notification_rules'];
        $this->assertSame([(int)$rule->id], self::ids($rules['skippedconfirmationrules']));
        $this->assertSame([], $rules['expiredconfirmationrules']);
    }

    /**
     * An inactive rule cannot have sent anything, so its closed window is irrelevant.
     */
    public function test_inactive_rule_is_not_reported_as_expired(): void {
        global $DB;
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 3 * DAYSECS);
        $rule = $this->add_rule($booking, 1);
        $DB->set_field('booking_rules', 'isactive', 0, ['id' => (int)$rule->id]);
        \mod_booking\booking_rules\booking_rules::$rules = [];

        $result = $this->diagnose($booking, $option, $student);

        $this->assertSame([], $result['diagnosis']['notification_rules']['expiredconfirmationrules']);
    }

    /**
     * No engine vocabulary (setting keys, rule class names, issue codes) in user-facing text.
     */
    public function test_user_facing_text_carries_no_engine_vocabulary(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 3 * DAYSECS);
        $this->add_rule($booking, 1);
        $this->add_rule($booking, 1, '\\mod_booking\\event\\bookingoption_cancelled', 'Cancellation notice');
        $this->book_at($option, $student, time() - HOURSECS);

        $result = $this->diagnose($booking, $option, $student);
        $text = implode(' ', array_merge(
            [(string)$result['usermessage']],
            (array)$result['diagnosis']['reasons'],
            (array)$result['diagnosis']['supplementary_context'],
            array_column((array)$result['checklist_rows'], 'finding')
        ));

        foreach (
            [
                'mod_booking.diagnose_booking_issue',
                'aftercompletion',
                'rule_react_on_event',
                'windowend',
                'send_mail',
                '{$a',
            ] as $forbidden
        ) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
    }

    /**
     * The new findings reach the synchronizer unabridged, via observation_full.
     */
    public function test_findings_are_exposed_as_observation_full(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 3 * DAYSECS);
        $this->add_rule($booking, 1);
        $this->book_at($option, $student, time() - HOURSECS);

        $result = $this->diagnose($booking, $option, $student);

        $observation = trim((string)($result['observation_full'] ?? ''));
        $this->assertNotSame('', $observation);
        foreach ($result['diagnosis']['reasons'] as $reason) {
            $this->assertStringContainsString($reason, $observation);
        }
    }

    /**
     * The diagnosis in another conversation language renders complete findings, without placeholder leftovers.
     *
     * The test site has no German core pack, so the strings resolve to English here; that the German
     * strings exist is pinned by test_new_strings_exist_in_all_language_packs().
     */
    public function test_diagnosis_in_other_language_renders_complete_findings(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking(time() - 3 * DAYSECS);
        $this->add_rule($booking, 1);
        $this->book_at($option, $student, time() - HOURSECS);

        $result = $this->diagnose($booking, $option, $student, 'de');

        $sm = get_string_manager();
        $row = $this->checklist_row_by_label(
            $result,
            $sm->get_string('agent_booking_diagnose_check_ruletimewindow', 'mod_booking', null, 'de')
        );
        $this->assertSame('fail', $row['status']);
        $this->assertStringNotContainsString('{$a', $row['finding']);
        foreach ($result['diagnosis']['reasons'] as $reason) {
            $this->assertStringNotContainsString('{$a', $reason);
        }
    }

    /**
     * Every new string exists in every shipped language pack, so no English fallback reaches the model.
     *
     * de_gs inherits from de and only carries the gender-slash variants (cli/generate_de_gs_lang.php).
     */
    public function test_new_strings_exist_in_all_language_packs(): void {
        global $CFG;

        foreach (['en', 'de'] as $lang) {
            $string = [];
            include($CFG->dirroot . '/mod/booking/lang/' . $lang . '/booking.php');
            foreach (self::NEW_STRINGS as $key) {
                $this->assertArrayHasKey($key, $string, "String {$key} missing in language pack {$lang}");
            }
        }
    }

    /**
     * The rule listing behind the rule analysis shows the window, and no window for an unlimited rule.
     */
    public function test_rule_listing_exposes_time_window(): void {
        $this->resetAfterTest();
        [$booking] = $this->setup_booking(time() + DAYSECS);
        $limited = $this->add_rule($booking, 1);
        $unlimited = $this->add_rule($booking, '', '\\mod_booking\\event\\bookingoption_cancelled', 'Cancellation notice');

        $listed = [];
        $service = new \mod_booking\local\wizard\booking\support\booking_rules_agent_service();
        foreach ($service->list_rules_for_context((int)\context_module::instance($booking->cmid)->id) as $rule) {
            $listed[(int)$rule['id']] = $rule;
        }

        $this->assertSame(1, $listed[(int)$limited->id]['aftercompletion']);
        $this->assertNull($listed[(int)$unlimited->id]['aftercompletion']);
    }

    /**
     * One checklist row by its translated label.
     *
     * @param array $result
     * @param string $label
     * @return array
     */
    private function checklist_row_by_label(array $result, string $label): array {
        foreach ((array)$result['checklist_rows'] as $row) {
            if ((string)$row['check'] === $label) {
                return $row;
            }
        }

        $this->fail('No checklist row labelled ' . $label);
    }
}

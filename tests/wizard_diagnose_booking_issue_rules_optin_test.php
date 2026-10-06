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
 * The per-option booking-rule restriction (opt in / opt out) must surface in the booking diagnosis.
 *
 * Pinned case: an option with skipbookingrulesmode = 1 (opt in) and an empty rule list silently
 * switches off every mail rule of the surrounding contexts, so a booked user never receives the
 * confirmation mail (thread 8132, option "aABCAbcr4", logstore 409094).
 *
 * @package    mod_booking
 * @category   test
 * @covers     \mod_booking\local\wizard\options\skills\option_rules_diagnostics
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class wizard_diagnose_booking_issue_rules_optin_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * Build course, booking instance, one option, an enrolled student and a mail rule.
     *
     * @return array{0:\stdClass,1:\stdClass,2:\stdClass,3:\stdClass}
     *         [booking module, option, student, confirmation rule]
     */
    private function setup_booking(): array {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = [
            'name' => 'Rules Booking',
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
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Rules option';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $option = $plugingenerator->create_option($record);

        $rule = $plugingenerator->create_rule([
            'bookingid' => 0,
            'contextid' => (int)\context_module::instance($booking->cmid)->id,
            'rulename' => 'rule_react_on_event',
            'name' => 'Booking confirmation',
            'conditionname' => 'select_user_from_event',
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"confirmation","template":"confirmation","templateformat":"1"}',
            'eventname' => '\\mod_booking\\event\\bookingoption_booked',
        ]);

        return [$booking, $option, $student, $rule];
    }

    /**
     * Add a mail rule that reacts to something other than a booking (a cancellation notice).
     *
     * @param \stdClass $booking
     * @param string $name Distinct rule name; the diagnosis deduplicates by name.
     * @return \stdClass
     */
    private function add_cancellation_rule(stdClass $booking, string $name = 'Cancellation notice'): stdClass {
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        return $plugingenerator->create_rule([
            'bookingid' => 0,
            'contextid' => (int)\context_module::instance($booking->cmid)->id,
            'rulename' => 'rule_react_on_event',
            'name' => $name,
            'conditionname' => 'select_user_from_event',
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"subject":"cancelled","template":"cancelled","templateformat":"1"}',
            'eventname' => '\\mod_booking\\event\\bookingoption_cancelled',
        ]);
    }

    /**
     * Write the per-option rule restriction into the option JSON.
     *
     * @param int $optionid
     * @param int $mode 0 = opt out, 1 = opt in.
     * @param array $ruleids Rule ids listed on the option.
     * @return void
     */
    private function set_rule_restriction(int $optionid, int $mode, array $ruleids): void {
        global $DB;

        $json = json_decode((string)$DB->get_field('booking_options', 'json', ['id' => $optionid])) ?: new stdClass();
        $json->skipbookingrulesmode = $mode;
        $json->skipbookingrules = array_map('strval', $ruleids);
        $DB->set_field('booking_options', 'json', json_encode($json), ['id' => $optionid]);
        // The option settings live in a MUC cache; destroying the singleton alone keeps the old JSON.
        \cache::make('mod_booking', 'bookingoptionsettings')->delete($optionid);
        singleton_service::destroy_booking_option_singleton($optionid);
    }

    /**
     * Run the diagnosis for the missing-mail issue.
     *
     * @param \stdClass $booking
     * @param \stdClass $option
     * @param \stdClass $student
     * @return array
     */
    private function diagnose(stdClass $booking, stdClass $option, stdClass $student): array {
        $this->setUser($student);

        return (new diagnose_booking_issue_skill())->execute(
            [
                'optionid' => (int)$option->id,
                'issue' => 'missing_email',
                'question' => 'I booked but received no confirmation.',
                'outputlang' => 'en',
            ],
            (int)\context_module::instance($booking->cmid)->id,
            (int)$student->id
        );
    }

    /**
     * Opt in with an empty rule list must report the confirmation rule as skipped.
     */
    public function test_optin_with_empty_list_reports_skipped_mail_rule(): void {
        $this->resetAfterTest();
        [$booking, $option, $student, $rule] = $this->setup_booking();
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);

        $this->assertSame('executed', $result['status']);
        $rules = $result['diagnosis']['notification_rules'];
        $this->assertTrue($rules['restrictionactive']);
        $this->assertSame(1, $rules['mode']);
        $this->assertSame(0, $rules['appliedmailrulecount']);
        $this->assertSame(
            [(int)$rule->id],
            array_map(static fn(array $r): int => $r['id'], $rules['skippedmailrules'])
        );
    }

    /**
     * The skipped mail rule must produce an additional finding the synchronizer can use.
     */
    public function test_optin_with_empty_list_adds_finding(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);
        $baseline = $this->reason_count_without_restriction($booking, $option, $student);

        $this->assertGreaterThan($baseline, count($result['diagnosis']['reasons']));
    }

    /**
     * Opt out that excludes the mail rule explicitly must be reported the same way.
     */
    public function test_optout_excluding_mail_rule_reports_it(): void {
        $this->resetAfterTest();
        [$booking, $option, $student, $rule] = $this->setup_booking();
        $this->set_rule_restriction((int)$option->id, 0, [(int)$rule->id]);

        $result = $this->diagnose($booking, $option, $student);

        $rules = $result['diagnosis']['notification_rules'];
        $this->assertTrue($rules['restrictionactive']);
        $this->assertSame(
            [(int)$rule->id],
            array_map(static fn(array $r): int => $r['id'], $rules['skippedmailrules'])
        );
    }

    /**
     * Negative case: an unrestricted option must not gain a rule finding.
     */
    public function test_unrestricted_option_reports_no_skipped_rule(): void {
        $this->resetAfterTest();
        [$booking, $option, $student, $rule] = $this->setup_booking();

        $result = $this->diagnose($booking, $option, $student);

        $rules = $result['diagnosis']['notification_rules'];
        $this->assertFalse($rules['restrictionactive']);
        $this->assertSame([], $rules['skippedmailrules']);
        $this->assertSame(1, $rules['appliedmailrulecount']);
        $this->assertSame(
            [(int)$rule->id],
            array_map(static fn(array $r): int => $r['id'], $rules['applied'])
        );
    }

    /**
     * An inactive rule cannot have sent anything, so it must not be blamed.
     */
    public function test_inactive_rule_is_not_reported_as_cause(): void {
        global $DB;
        $this->resetAfterTest();
        [$booking, $option, $student, $rule] = $this->setup_booking();
        $DB->set_field('booking_rules', 'isactive', 0, ['id' => (int)$rule->id]);
        \mod_booking\booking_rules\booking_rules::$rules = [];
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);

        $this->assertSame([], $result['diagnosis']['notification_rules']['skippedmailrules']);
    }

    /**
     * The findings must reach the synchronizer unabridged, via observation_full.
     */
    public function test_findings_are_exposed_as_observation_full(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);

        $observation = trim((string)($result['observation_full'] ?? ''));
        $this->assertNotSame('', $observation);
        foreach ($result['diagnosis']['reasons'] as $reason) {
            $this->assertStringContainsString($reason, $observation);
        }
    }

    /**
     * No engine vocabulary (issue codes, skill names, schema field names) in user-facing text.
     */
    public function test_user_facing_text_carries_no_engine_vocabulary(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);
        $text = implode(' ', array_merge(
            [(string)$result['usermessage']],
            (array)$result['diagnosis']['reasons'],
            (array)$result['diagnosis']['supplementary_context']
        ));

        foreach (
            [
                'mod_booking.diagnose_booking_issue',
                'skipbookingrulesmode',
                'skipbookingrules',
                'send_mail',
                'OPTION_REFERENCE_REQUIRED',
                'MISSING_QUESTION',
            ] as $forbidden
        ) {
            $this->assertStringNotContainsString($forbidden, $text);
        }
    }

    /**
     * Only a rule reacting to a booking may be named as the cause of a missing confirmation.
     */
    public function test_only_booking_rules_are_named_as_the_cause(): void {
        $this->resetAfterTest();
        [$booking, $option, $student, $rule] = $this->setup_booking();
        $cancelrule = $this->add_cancellation_rule($booking);
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);
        $rules = $result['diagnosis']['notification_rules'];

        // Both mail rules are off ...
        $this->assertSame(
            [(int)$rule->id, (int)$cancelrule->id],
            array_map(static fn(array $r): int => $r['id'], $rules['skippedmailrules'])
        );
        // ... but only the booking one can explain a missing confirmation.
        $this->assertSame(
            [(int)$rule->id],
            array_map(static fn(array $r): int => $r['id'], $rules['skippedconfirmationrules'])
        );
        $this->assertSame(0, $rules['appliedconfirmationrulecount']);

        $findings = implode("\n", $result['diagnosis']['reasons']);
        $this->assertStringContainsString('Booking confirmation', $findings);
        $this->assertStringNotContainsString('Cancellation notice', $findings);
        $this->assertStringContainsString(
            get_string('agent_booking_diagnose_reason_rules_othermail_skipped', 'mod_booking', 1),
            $findings
        );
    }

    /**
     * A restriction that only switches off non-booking mail rules must not claim a cause.
     */
    public function test_skipped_cancellation_rule_alone_is_not_the_cause(): void {
        $this->resetAfterTest();
        [$booking, $option, $student, $rule] = $this->setup_booking();
        $cancelrule = $this->add_cancellation_rule($booking);
        // Opt in that keeps the booking rule, but drops the cancellation rule.
        $this->set_rule_restriction((int)$option->id, 1, [(int)$rule->id]);

        $result = $this->diagnose($booking, $option, $student);
        $rules = $result['diagnosis']['notification_rules'];

        $this->assertSame([], $rules['skippedconfirmationrules']);
        $this->assertSame(1, $rules['appliedconfirmationrulecount']);
        $this->assertSame(
            [(int)$cancelrule->id],
            array_map(static fn(array $r): int => $r['id'], $rules['skippedmailrules'])
        );

        $findings = implode("\n", $result['diagnosis']['reasons']);
        $this->assertStringNotContainsString(
            get_string('agent_booking_diagnose_reason_rules_mail_skipped', 'mod_booking', 'Booking confirmation'),
            $findings
        );
    }

    /**
     * Long rule lists are summarized instead of dumped into the sentence.
     */
    public function test_rule_name_list_is_capped(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();
        for ($i = 0; $i < 7; $i++) {
            $this->add_cancellation_rule($booking, 'Cancellation notice ' . $i);
        }
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);
        $names = \mod_booking\local\wizard\options\skills\option_rules_diagnostics::rule_names(
            $result['diagnosis']['notification_rules']['skipped'],
            'en'
        );

        $this->assertStringContainsString(
            get_string('agent_booking_diagnose_rules_more', 'mod_booking', 3),
            $names
        );
    }

    /**
     * Every check of the diagnosis must appear as its own checklist row.
     */
    public function test_checklist_covers_all_checks(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();

        $result = $this->diagnose($booking, $option, $student);
        $checks = array_column((array)$result['checklist_rows'], 'check');

        foreach (
            [
                'enrolment', 'activityvisible', 'optionvisible', 'bookingenabled', 'maxperuser',
                'banned', 'status', 'capacity', 'conditions', 'rules',
            ] as $key
        ) {
            $this->assertContains(
                get_string('agent_booking_diagnose_check_' . $key, 'mod_booking'),
                $checks,
                'Checklist row missing for ' . $key
            );
        }
    }

    /**
     * The rule row flags a switched-off confirmation rule as a failing check and names it.
     */
    public function test_checklist_rule_row_fails_when_confirmation_rule_is_off(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);
        $row = $this->checklist_row($result, 'rules');

        $this->assertSame('fail', $row['status']);
        $this->assertStringContainsString('Booking confirmation', $row['finding']);
        $this->assertNotNull($row['url']);
    }

    /**
     * Without a restriction the same row passes.
     */
    public function test_checklist_rule_row_passes_without_restriction(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();

        $result = $this->diagnose($booking, $option, $student);
        $row = $this->checklist_row($result, 'rules');

        $this->assertSame('ok', $row['status']);
    }

    /**
     * A restriction that spares every confirmation rule is a warning, not a failure.
     */
    public function test_checklist_rule_row_warns_when_only_other_rules_are_off(): void {
        $this->resetAfterTest();
        [$booking, $option, $student, $rule] = $this->setup_booking();
        $this->add_cancellation_rule($booking);
        $this->set_rule_restriction((int)$option->id, 1, [(int)$rule->id]);

        $result = $this->diagnose($booking, $option, $student);
        $row = $this->checklist_row($result, 'rules');

        $this->assertSame('warn', $row['status']);
    }

    /**
     * The preview carries the rendered checklist, and no internal field names.
     */
    public function test_preview_contains_checklist(): void {
        $this->resetAfterTest();
        [$booking, $option, $student] = $this->setup_booking();
        $this->set_rule_restriction((int)$option->id, 1, []);

        $result = $this->diagnose($booking, $option, $student);
        $preview = (new diagnose_booking_issue_skill())->get_result_preview(
            $result,
            (int)\context_module::instance($booking->cmid)->id,
            (int)$student->id
        );
        // Rendering the option card walks the booking table, which still queries the deprecated
        // capability mod/booking:addeditownoption via the evasys extension. Unrelated to this
        // diagnosis; swallow it here rather than let it fail the case.
        $this->resetDebugging();

        $this->assertNotNull($preview);
        $this->assertSame('booking_option', $preview['type']);
        $html = (string)$preview['html'];
        $this->assertStringContainsString('wizard-diagnostic-checklist', $html);
        $this->assertStringContainsString(
            get_string('agent_booking_diagnose_check_rules', 'mod_booking'),
            $html
        );
        $this->assertStringContainsString(
            get_string('agent_booking_diagnose_check_status', 'mod_booking'),
            $html
        );
        $this->assertStringNotContainsString('skipbookingrules', $html);
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
     * Reason count of the same option without any rule restriction, as comparison baseline.
     *
     * @param \stdClass $booking
     * @param \stdClass $option
     * @param \stdClass $student
     * @return int
     */
    private function reason_count_without_restriction(stdClass $booking, stdClass $option, stdClass $student): int {
        $this->set_rule_restriction((int)$option->id, 0, []);
        $result = $this->diagnose($booking, $option, $student);

        return count($result['diagnosis']['reasons']);
    }
}

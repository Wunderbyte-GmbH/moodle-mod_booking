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

use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\wizard\booking\support\booking_rules_agent_service;
use mod_booking\local\wizard\options\skills\update_rule_from_template_skill;
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/classes/booking_advanced_testcase.php');

/**
 * update_rule_from_template changes the subject and text of the mail a rule sends (wave 32, URT-3 / R5).
 *
 * The skill promised "Edit the message text of my cancellation rule" in an example utterance but had no field for it;
 * URT-3 ("Modifie le texte du message de la règle de rappel existante") went to local_taskflow message templates in
 * 4 of 11 runs. A resolved rule with nothing to change no longer stages an empty update: it asks what should change
 * and shows the current subject and text.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\support\booking_rules_agent_service
 * @covers     \mod_booking\local\wizard\options\skills\update_rule_from_template_skill
 */
final class wizard_rule_mail_text_test extends booking_advanced_testcase {
    /** @var int */
    private int $cmid = 0;
    /** @var int */
    private int $contextid = 0;

    /**
     * Booking instance with rule templates active.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingruletemplatesactive', 1, 'booking');
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Rule Mail Text', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * Action data of a persisted rule.
     *
     * @param int $ruleid
     * @return \stdClass
     */
    private function actiondata_of(int $ruleid): \stdClass {
        global $DB;
        $json = json_decode((string)$DB->get_field('booking_rules', 'rulejson', ['id' => $ruleid]));
        return (object)($json->actiondata ?? []);
    }

    /**
     * A reminder rule of this instance.
     *
     * @return int Rule id.
     */
    private function reminder_rule(): int {
        $created = (new booking_rules_agent_service())->create_rule_from_template(
            $this->contextid,
            -ruletemplate_daysbeforestart::$templateid,
            ['days' => 2]
        );
        $this->assertSame('ok', (string)($created['status'] ?? ''), json_encode($created));
        return (int)$created['rule']['id'];
    }

    /**
     * The service writes a new subject and text into the rule's own action and keeps the days.
     */
    public function test_the_service_writes_subject_and_text(): void {
        $ruleid = $this->reminder_rule();
        $service = new booking_rules_agent_service();
        $before = $service->rule_mail_text($ruleid);
        $this->assertNotNull($before, 'a send_mail rule has a mail text');

        $updated = $service->update_rule_from_template($this->contextid, $ruleid, 0, [
            'mailsubject' => 'See you soon, {firstname}',
            'mailbody' => '<p>Hello {firstname}, we look forward to seeing you!</p>',
        ]);

        $this->assertSame('ok', (string)($updated['status'] ?? ''), json_encode($updated));
        $actiondata = $this->actiondata_of($ruleid);
        $this->assertSame('See you soon, {firstname}', (string)$actiondata->subject);
        $this->assertSame('<p>Hello {firstname}, we look forward to seeing you!</p>', (string)$actiondata->template);
        $this->assertSame(2, (int)($updated['rule']['days'] ?? -1), 'other settings stay as they were');
    }

    /**
     * The skill stages a text change and shows it on the confirm card.
     */
    public function test_the_skill_stages_a_text_change(): void {
        global $USER;
        $ruleid = $this->reminder_rule();
        $skill = new update_rule_from_template_skill();
        $dto = $skill->preflight(
            ['ruleid' => $ruleid, 'mailbody' => 'Hello {firstname}, see you soon!'],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertSame('pass', (string)$dto->status, json_encode($dto->to_array()));
        $descriptor = $skill->describe_proposed_action($dto->preparedinput);
        $this->assertStringContainsString('see you soon', json_encode($descriptor));
    }

    /**
     * A resolved rule and nothing to change: a question that shows the current subject, never an empty update.
     */
    public function test_nothing_to_change_asks_and_shows_the_current_text(): void {
        global $USER;
        $ruleid = $this->reminder_rule();
        $current = (new booking_rules_agent_service())->rule_mail_text($ruleid);

        $dto = (new update_rule_from_template_skill())->preflight(['ruleid' => $ruleid], $this->contextid, (int)$USER->id);

        $this->assertNotSame('pass', (string)$dto->status);
        $this->assertContains('RULE_CHANGE_REQUIRED', $dto->issuecodes, json_encode($dto->to_array()));
        $this->assertStringContainsString((string)$current['subject'], json_encode($dto->issues, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The card names the mail fields and stays inside the card budget (review w32s-b1: the NOT line was 184 characters,
     * over the 160 of skill_catalog_discrimination_test, and named taskflow skills without their counter-fence).
     */
    public function test_the_card_names_the_mail_fields_inside_the_budget(): void {
        $schema = (new update_rule_from_template_skill())->get_schema();
        $this->assertArrayHasKey('mailsubject', $schema['properties']);
        $this->assertArrayHasKey('mailbody', $schema['properties']);
        $this->assertLessThanOrEqual(160, \core_text::strlen(trim((string)$schema['not'])));
        $this->assertLessThanOrEqual(160, \core_text::strlen(trim((string)$schema['is'])));
        $this->assertLessThanOrEqual(240, \core_text::strlen((string)$schema['description']));
        $this->assertStringNotContainsString('local_taskflow.', (string)$schema['not'], 'no one-sided cross-plugin fence');
    }

    /**
     * A rule whose action sends no mail: a new text is asked back (needs_clarification), never dropped or an error.
     */
    public function test_a_text_for_a_rule_without_mail_is_asked_back(): void {
        global $DB, $USER;
        $ruleid = $this->reminder_rule();
        // Test data: the rule's action loses its mail fields (as an action without a mail has none).
        $json = json_decode((string)$DB->get_field('booking_rules', 'rulejson', ['id' => $ruleid]));
        $json->actiondata = (object)[];
        $DB->set_field('booking_rules', 'rulejson', json_encode($json), ['id' => $ruleid]);
        $this->assertNull((new booking_rules_agent_service())->rule_mail_text($ruleid));

        $dto = (new update_rule_from_template_skill())->preflight(
            ['ruleid' => $ruleid, 'mailbody' => 'Hello {firstname}'],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertNotSame('pass', (string)$dto->status);
        $this->assertNotSame('error', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertContains('RULE_MAIL_TEXT_NOT_APPLICABLE', $dto->issuecodes, json_encode($dto->issuecodes));
        $message = (string)(json_decode(json_encode($dto->issues), true)[0]['message'] ?? '');
        $this->assertNotSame('', $message);
        foreach (['mailbody', 'mailsubject', 'RULE_MAIL_TEXT_NOT_APPLICABLE'] as $internal) {
            $this->assertStringNotContainsString($internal, $message);
        }
    }

    /**
     * Nothing to change on a rule without a mail: the question alone, no empty subject line; templateid 0 is no change.
     */
    public function test_nothing_to_change_on_a_rule_without_mail_asks_without_a_mail_text(): void {
        global $DB, $USER;
        $ruleid = $this->reminder_rule();
        $json = json_decode((string)$DB->get_field('booking_rules', 'rulejson', ['id' => $ruleid]));
        $json->actiondata = (object)[];
        $DB->set_field('booking_rules', 'rulejson', json_encode($json), ['id' => $ruleid]);

        $dto = (new update_rule_from_template_skill())->preflight(
            ['ruleid' => $ruleid, 'templateid' => 0],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertContains('RULE_CHANGE_REQUIRED', $dto->issuecodes, json_encode($dto->to_array()));
        $issue = json_decode(json_encode($dto->issues), true)[0];
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
        $this->assertArrayNotHasKey('field', $issue, 'no mail field to point at');
        $withtext = (new update_rule_from_template_skill())->preflight(
            ['ruleid' => $this->reminder_rule()],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertGreaterThan(
            \core_text::strlen((string)$issue['message']),
            \core_text::strlen((string)(json_decode(json_encode($withtext->issues), true)[0]['message'] ?? '')),
            'only a mail rule adds its current text'
        );
    }
}

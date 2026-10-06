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
use mod_booking\local\wizard\options\skills\create_rule_from_template_skill;
use mod_booking\local\wizard\options\skills\update_rule_from_template_skill;
use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoption_booked;
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/classes/booking_advanced_testcase.php');

/**
 * A days value with no rule named means the one active days-before rule; template kinds are never guessed by words.
 *
 * CBI-4 (baseline runs 31-37): "Die Erinnerung soll künftig fünf Tage vor Kursbeginn rausgehen, nicht drei" names no
 * rule, and the constructor asked which one although the context holds exactly one active days-before rule. A days
 * value can only belong to a days-before rule: one such active rule is the rule meant (DB fact), several stay a question.
 * F83: create_rule_from_template auto-picked a "confirmation" template through an English needle list; that list is gone,
 * several matching templates are the user's choice.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\update_rule_from_template_skill
 * @covers     \mod_booking\local\wizard\options\skills\create_rule_from_template_skill
 */
final class wizard_rule_single_days_rule_default_test extends booking_advanced_testcase {
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
            'course' => $course->id, 'name' => 'Rule Default', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * One active days-before rule next to an event rule: {days: 5} without a rule updates that one.
     */
    public function test_days_without_a_rule_means_the_single_days_before_rule(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $daysrule = $service->create_rule_from_template(
            $this->contextid,
            -ruletemplate_daysbeforestart::$templateid,
            ['days' => 3]
        );
        $service->create_rule_from_template($this->contextid, -ruletemplate_bookingoption_booked::$templateid, []);
        $this->assertSame('ok', (string)($daysrule['status'] ?? ''), json_encode($daysrule));

        $dto = (new update_rule_from_template_skill())->preflight(['days' => 5], $this->contextid, (int)$USER->id);
        $this->assertSame('pass', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertSame((int)$daysrule['rule']['id'], (int)($dto->preparedinput['ruleid'] ?? 0));
    }

    /**
     * CBI-4, wave-30 Nachlauf 31 (thread 11891): since constructor rule 20 the model passes its own word for the
     * rule ("Erinnerung"), which names nothing. The name gives no information; the days value and the active flag
     * still do: one active days-before rule among inactive ones is the rule meant (DB fact, no wording).
     */
    public function test_a_query_that_names_nothing_is_narrowed_by_days_and_active(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $active = $service->create_rule_from_template(
            $this->contextid,
            -ruletemplate_daysbeforestart::$templateid,
            ['days' => 3]
        );
        $service->create_rule_from_template(
            $this->contextid,
            -ruletemplate_daysbeforestart::$templateid,
            ['days' => 3, 'isactive' => 0]
        );
        $service->create_rule_from_template($this->contextid, -ruletemplate_bookingoption_booked::$templateid, []);

        $dto = (new update_rule_from_template_skill())->preflight(
            ['rulequery' => 'Erinnerung', 'days' => 5],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertSame('pass', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertSame((int)$active['rule']['id'], (int)($dto->preparedinput['ruleid'] ?? 0));
    }

    /**
     * Several active days-before rules and a name that matches none: the choices are those rules, not every rule.
     */
    public function test_several_active_days_rules_narrow_the_choices(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $a = $service->create_rule_from_template($this->contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 3]);
        $b = $service->create_rule_from_template($this->contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 7]);
        $service->create_rule_from_template($this->contextid, -ruletemplate_bookingoption_booked::$templateid, []);

        $dto = (new update_rule_from_template_skill())->preflight(
            ['rulequery' => 'Erinnerung', 'days' => 5],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertNotSame('pass', (string)$dto->status);
        $offering = array_values(array_filter($dto->issues, static fn(array $i): bool => !empty($i['candidates'])));
        $this->assertCount(1, $offering, json_encode($dto->issues));
        $this->assertEqualsCanonicalizing(
            [(int)$a['rule']['id'], (int)$b['rule']['id']],
            array_column($offering[0]['candidates'], 'id')
        );
    }

    /**
     * Two active days-before rules stay a question.
     */
    public function test_two_days_before_rules_stay_a_question(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $service->create_rule_from_template($this->contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 3]);
        $service->create_rule_from_template($this->contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 7]);

        $dto = (new update_rule_from_template_skill())->preflight(['days' => 5], $this->contextid, (int)$USER->id);
        $this->assertNotSame('pass', (string)$dto->status);
        $this->assertContains('RULE_RESOLUTION_FAILED', $dto->issuecodes, json_encode($dto->to_array()));
    }

    /**
     * F83: no word list picks a template; a kind of template with several matches is the user's choice.
     */
    public function test_no_word_list_picks_a_template(): void {
        global $USER;
        $this->assertFalse(method_exists(create_rule_from_template_skill::class, 'try_autoselect_confirmation_template'));

        $dto = (new create_rule_from_template_skill())->preflight(
            ['templatequery' => 'booking confirmation', 'rulename' => 'Willkommen an Bord'],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertNotSame('pass', (string)$dto->status, json_encode($dto->to_array()));
        $codes = $dto->issuecodes;
        $this->assertTrue(
            in_array('TEMPLATE_RESOLUTION_AMBIGUOUS', $codes, true) || in_array('TEMPLATE_RESOLUTION_FAILED', $codes, true),
            json_encode($codes)
        );
    }
}

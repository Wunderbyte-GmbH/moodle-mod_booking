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
use context_system;
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
 * A rule query that finds nothing offers the existing rules as a choice instead of ending in an error.
 *
 * CBI-4, thread 11412 (baseline run 38): the constructor built rulequery "Erinnerung" while the only reminder rule is
 * named "Email reminder 2 days before course start". The service answered "no matching rule" and the turn ended
 * although the context held exactly one rule that fits. The code never translates or guesses (no word lists, no
 * cross-language search); the model may match, but only when it is shown the rules that exist. So a miss carries
 * the rules of the context as RULE_CANDIDATE lines: the current context first, site-wide rules always, other
 * contexts up to the cap (George, 2026-09-24).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\update_rule_from_template_skill
 * @covers     \mod_booking\local\wizard\booking\support\booking_rules_agent_service
 */
final class wizard_rule_not_found_offers_candidates_test extends booking_advanced_testcase {
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
            'course' => $course->id, 'name' => 'Rule Candidates', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * Creates one rule from a template in the given context and returns its id.
     *
     * @param booking_rules_agent_service $service
     * @param int $contextid
     * @param int $templateid
     * @param array $overrides
     * @return int
     */
    private function rule(booking_rules_agent_service $service, int $contextid, int $templateid, array $overrides = []): int {
        $result = $service->create_rule_from_template($contextid, -$templateid, $overrides);
        $this->assertSame('ok', (string)($result['status'] ?? ''), json_encode($result));
        return (int)$result['rule']['id'];
    }

    /**
     * The rule query misses in a foreign language: the preflight asks and lists every rule of the context.
     * (No days value here: with one, wave 30 narrows by days and the active flag - wizard_rule_single_days_rule_default_test.)
     */
    public function test_a_missed_rule_query_lists_the_rules_of_the_context(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $reminder = $this->rule($service, $this->contextid, ruletemplate_daysbeforestart::$templateid, ['days' => 2]);
        $booked = $this->rule($service, $this->contextid, ruletemplate_bookingoption_booked::$templateid);
        $system = $this->rule($service, (int)context_system::instance()->id, ruletemplate_bookingoption_booked::$templateid);

        $dto = (new update_rule_from_template_skill())->preflight(
            ['rulequery' => 'Erinnerung', 'isactive' => false],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertNotSame('pass', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertContains('RULE_RESOLUTION_FAILED', $dto->issuecodes);

        $iscandidate = static fn(array $i): bool => ($i['code'] ?? '') === 'RULE_CANDIDATE';
        $candidates = array_values(array_filter($dto->issues, $iscandidate));
        $this->assertCount(3, $candidates, json_encode($dto->issues));
        foreach ($dto->issues as $issue) {
            $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''), json_encode($issue));
        }
        // Every rule of the context is on offer, the current context before the site-wide rule.
        $idof = static fn(array $i): int => (int)preg_replace('/^id=(\d+).*$/s', '$1', (string)$i['message']);
        $ids = array_map($idof, $candidates);
        $this->assertSame([$system], array_values(array_diff($ids, [$reminder, $booked])));
        $this->assertSame($system, $ids[2]);
        // The days value is part of the offer: it is the attribute the request names.
        $reminderline = (string)$candidates[array_search($reminder, $ids, true)]['message'];
        $this->assertMatchesRegularExpression('/^id=' . $reminder . ' .*days=2/', $reminderline);
    }

    /**
     * No rule named at all: the same offer, so a days value alone never ends the turn.
     */
    public function test_no_rule_named_and_several_days_rules_lists_them(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $this->rule($service, $this->contextid, ruletemplate_daysbeforestart::$templateid, ['days' => 3]);
        $this->rule($service, $this->contextid, ruletemplate_daysbeforestart::$templateid, ['days' => 7]);

        $dto = (new update_rule_from_template_skill())->preflight(['days' => 5], $this->contextid, (int)$USER->id);
        $this->assertNotSame('pass', (string)$dto->status);
        $this->assertContains('RULE_RESOLUTION_FAILED', $dto->issuecodes, json_encode($dto->to_array()));
        $iscandidate = static fn(array $i): bool => ($i['code'] ?? '') === 'RULE_CANDIDATE';
        $this->assertCount(2, array_filter($dto->issues, $iscandidate));
    }

    /**
     * The offer keeps the current context and the site-wide rules whole, current first; the cap never drops them.
     * (Booking rules live in module or system contexts only, so today the cap has nothing else to drop.)
     */
    public function test_current_and_system_rules_are_never_dropped_by_the_cap(): void {
        $service = new booking_rules_agent_service();
        $current1 = $this->rule($service, $this->contextid, ruletemplate_daysbeforestart::$templateid, ['days' => 2]);
        $current2 = $this->rule($service, $this->contextid, ruletemplate_bookingoption_booked::$templateid);
        $system = $this->rule($service, (int)context_system::instance()->id, ruletemplate_bookingoption_booked::$templateid);

        $ids = static fn(array $rules): array => array_map(static fn(array $r): int => (int)$r['id'], $rules);

        $capped = $ids($service->rule_candidates_for_context($this->contextid, 1));
        $this->assertCount(3, $capped);
        $this->assertEqualsCanonicalizing([$current1, $current2], array_slice($capped, 0, 2));
        $this->assertSame($system, $capped[2]);
        $this->assertSame($capped, $ids($service->rule_candidates_for_context($this->contextid)));
    }

    /**
     * Wave 30: the rule choices also travel as a structured list for the engine (id, label, target field).
     */
    public function test_the_rule_choices_are_structured_for_the_engine(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $reminder = $this->rule($service, $this->contextid, ruletemplate_daysbeforestart::$templateid, ['days' => 2]);

        $dto = (new update_rule_from_template_skill())->preflight(
            ['rulequery' => 'Erinnerung'],
            $this->contextid,
            (int)$USER->id
        );

        $isfailure = static fn(array $i): bool => $i['code'] === 'RULE_RESOLUTION_FAILED';
        $failed = array_values(array_filter($dto->issues, $isfailure));
        $this->assertCount(1, $failed);
        $this->assertSame('ruleid', $failed[0]['field']);
        $this->assertContains($reminder, array_column($failed[0]['candidates'], 'id'));
        $this->assertNotEmpty($failed[0]['candidates'][0]['label']);
    }

    /**
     * Wave 30: a template query that matches nothing offers the templates instead of a bare error.
     */
    public function test_a_template_query_that_matches_nothing_offers_the_templates(): void {
        global $USER;
        $dto = (new create_rule_from_template_skill())->preflight(
            ['templatequery' => 'Zz nothing matches this'],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertNotSame('pass', (string)$dto->status);
        // Structural, not by code: the service answers a miss with every template (as an ambiguity).
        $offering = array_values(array_filter($dto->issues, static fn(array $i): bool => !empty($i['candidates'])));
        $this->assertCount(1, $offering, json_encode($dto->issues));
        $this->assertSame('needs_clarification', $offering[0]['severity']);
        $this->assertSame('templateid', $offering[0]['field']);
        $this->assertArrayHasKey('id', $offering[0]['candidates'][0]);
        $this->assertArrayHasKey('label', $offering[0]['candidates'][0]);
    }

    /**
     * A context without any rule stays a question without candidates, and the user text carries no code.
     */
    public function test_a_context_without_rules_stays_a_plain_question(): void {
        global $USER;
        $dto = (new update_rule_from_template_skill())->preflight(
            ['rulequery' => 'Erinnerung', 'days' => 5],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertNotSame('pass', (string)$dto->status);
        $this->assertContains('RULE_RESOLUTION_FAILED', $dto->issuecodes);
        $this->assertNotContains('RULE_CANDIDATE', $dto->issuecodes);
        foreach ($dto->issues as $issue) {
            $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
            $this->assertStringNotContainsString('RULE_RESOLUTION_FAILED', (string)($issue['message'] ?? ''));
        }
    }
}

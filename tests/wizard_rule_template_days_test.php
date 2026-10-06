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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\booking\support\booking_rules_agent_service;
use mod_booking\local\wizard\options\skills\create_rule_from_template_skill;
use mod_booking\local\wizard\options\skills\update_rule_from_template_skill;
use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoption_booked;
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * "Two days before the course starts" must reach the rule, not the question field.
 *
 * Write-path runs W1/W2 (findings W14/W15, #2403): the skills had no days property, the number
 * landed in the free-text question and the template default (3) was saved while the answer
 * claimed the requested value.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\support\booking_rules_agent_service
 * @covers     \mod_booking\local\wizard\options\skills\create_rule_from_template_skill
 * @covers     \mod_booking\local\wizard\options\skills\update_rule_from_template_skill
 */
final class wizard_rule_template_days_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingruletemplatesactive', 1, 'booking');

        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Rule Days', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * Days of a persisted rule.
     *
     * @param int $ruleid
     * @return int
     */
    private function days_of(int $ruleid): int {
        global $DB;
        $json = json_decode((string)$DB->get_field('booking_rules', 'rulejson', ['id' => $ruleid]));
        return (int)($json->ruledata->days ?? -1);
    }

    /**
     * The days override is persisted on creation and on update of a days-before rule.
     */
    public function test_days_override_is_persisted_on_create_and_update(): void {
        $service = new booking_rules_agent_service();

        $created = $service->create_rule_from_template($this->contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 2]);
        $this->assertSame('ok', (string)($created['status'] ?? ''), json_encode($created));
        $ruleid = (int)$created['rule']['id'];
        $this->assertSame(2, $this->days_of($ruleid));
        $this->assertSame(2, (int)($created['rule']['days'] ?? -1), 'the reported record carries the real value');

        $updated = $service->update_rule_from_template($this->contextid, $ruleid, 0, ['days' => 5]);
        $this->assertSame('ok', (string)($updated['status'] ?? ''), json_encode($updated));
        $this->assertSame(5, $this->days_of($ruleid));
        $this->assertSame(5, (int)($updated['rule']['days'] ?? -1));
    }

    /**
     * Without an override the template default survives (nothing changes for callers without days).
     */
    public function test_template_default_days_stay_without_override(): void {
        $created = (new booking_rules_agent_service())
            ->create_rule_from_template($this->contextid, -ruletemplate_daysbeforestart::$templateid, []);
        $this->assertSame('ok', (string)($created['status'] ?? ''));
        $this->assertSame(3, $this->days_of((int)$created['rule']['id']));
    }

    /**
     * days on a template without a days model is dropped visibly: not persisted, not in the
     * command, but named in the confirm preview (constructors copy the example's days onto event
     * templates, W3 rerun thread 1777).
     */
    public function test_days_on_event_template_is_dropped_visibly(): void {
        global $USER;
        $skill = new create_rule_from_template_skill();
        $dto = $skill->preflight(
            ['templateid' => -ruletemplate_bookingoption_booked::$templateid, 'days' => 2, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assertArrayNotHasKey('days', $prepared);
        $this->assertNotEmpty($prepared['days_not_applicable'] ?? null);

        $descriptor = (array)$skill->describe_proposed_action($prepared);
        $values = array_map(static fn(array $row): string => (string)($row['value'] ?? ''), (array)($descriptor['rows'] ?? []));
        $this->assertContains(
            get_string('agent_booking_rules_days_not_applicable', 'mod_booking'),
            $values,
            'the preview must say that days do not apply'
        );
    }

    /**
     * Both skills declare the property so the constructor can fill it.
     */
    public function test_days_is_a_declared_property_of_both_skills(): void {
        foreach ([new create_rule_from_template_skill(), new update_rule_from_template_skill()] as $skill) {
            $properties = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey('days', $properties, get_class($skill));
            $this->assertSame('integer', (string)($properties['days']['type'] ?? ''));
            // The constructor only sees the catalogue card: the example must show the property
            // (W2 rerun thread 1762 saved days=3 although the property existed but was invisible).
            $example = (array)($skill->get_prompt_contract()->to_array()['example_input'] ?? []);
            $this->assertArrayHasKey('days', $example, get_class($skill));
        }
    }
}

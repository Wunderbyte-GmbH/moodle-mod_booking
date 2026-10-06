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
use mod_booking\local\wizard\options\skills\configure_booking_instance_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Field-name contract of configure_booking_instance towards the constructor.
 *
 * Baseline run 9 / Nachlauf N3 (2026-09-16, Wunderbyte-GmbH#2411), threads 1973 and 2218: the
 * constructor sent changes[].field = "allowcancellations" three times, check_structure rejected it
 * as a structural error, the engine retried and the user got a clarification naming the field.
 * The constructor never sees the property schema — only the example values and guidance — and
 * the advertised example used "limitanswers", which is not a configurable field at all.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\configure_booking_instance_skill
 */
final class wizard_configure_instance_field_contract_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    /**
     * Setup: one booking instance.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Configure Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * The advertised example only uses configurable fields (the constructor copies it verbatim).
     */
    public function test_example_input_uses_configurable_fields_only(): void {
        $skill = new configure_booking_instance_skill();
        $fields = array_keys(configure_booking_instance_skill::get_configurable_fields());
        $example = $skill->get_example_input();
        $this->assertSame('update', (string)($example['action'] ?? ''));
        $this->assertNotEmpty($example['changes'] ?? []);
        foreach ((array)$example['changes'] as $change) {
            $this->assertContains((string)($change['field'] ?? ''), $fields, json_encode($example));
        }
    }

    /**
     * The construction hint lists every configurable identifier (the constructor's only field list).
     */
    public function test_construction_hint_lists_every_configurable_field(): void {
        global $USER;
        $skill = new configure_booking_instance_skill();
        $hints = $skill->get_dynamic_construction_hints($this->contextid, (int)$USER->id);
        $guidance = implode("\n", (array)($hints['guidance'] ?? []));
        foreach (array_keys(configure_booking_instance_skill::get_configurable_fields()) as $field) {
            $this->assertStringContainsString($field, $guidance, 'hint must name ' . $field);
        }
    }

    /**
     * An unknown field name is a recoverable input error: a clarification offering the identifiers as
     * structured remedies — never a structural error (which makes the engine retry the constructor).
     */
    public function test_unknown_field_ends_as_clarification_with_field_remedies(): void {
        global $USER;
        $skill = new configure_booking_instance_skill();
        $input = ['action' => 'update', 'changes' => [['field' => 'allowcancellations', 'value' => '0']], 'cmid' => $this->cmid];
        $structure = $skill->check_structure($input);
        $this->assertTrue((bool)($structure['valid'] ?? false), 'shape is valid: ' . json_encode($structure));

        $dto = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertNotContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $issues = json_decode(json_encode($dto->issues), true);
        $issue = $issues[0] ?? [];
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''), json_encode($issues));
        $this->assertSame('CONFIGURE_INSTANCE_UNKNOWN_FIELD', (string)($issue['code'] ?? ''));
        $this->assertStringContainsString('allowcancellations', (string)($issue['user_question'] ?? ''));
        $remedies = (array)($issue['remedy_options'] ?? []);
        $fields = configure_booking_instance_skill::get_configurable_fields();
        $this->assertCount(count($fields), $remedies, 'every configurable identifier is offered as a remedy');
        foreach ($remedies as $remedy) {
            $this->assertArrayHasKey((string)($remedy['id'] ?? ''), $fields, json_encode($remedy));
            $this->assertNotSame('', trim((string)($remedy['label'] ?? '')));
        }
        // The user text names the unknown field, but is not a raw dump of every identifier.
        $this->assertLessThan(3, substr_count((string)$issue['user_question'], 'cancancelbook')
            + substr_count((string)$issue['user_question'], 'pollurlteacherstext')
            + substr_count((string)$issue['user_question'], 'paginationnum'));
    }

    /**
     * A valid change still reaches the confirm card.
     */
    public function test_valid_change_reaches_the_card(): void {
        global $USER;
        $skill = new configure_booking_instance_skill();
        $dto = $skill->preflight(
            ['action' => 'update', 'changes' => [['field' => 'cancancelbook', 'value' => '0']], 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $descriptor = $skill->describe_proposed_action((array)$dto->preparedinput);
        $this->assertNotNull($descriptor);
        $this->assertNotEmpty($descriptor['rows'] ?? []);
    }
}

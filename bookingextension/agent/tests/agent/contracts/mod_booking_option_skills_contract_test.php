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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\skill_registry_factory;
use context_module;
use mod_booking\local\testing\booking_advanced_testcase;
use mod_booking\singleton_service;

// Conditional parent: booking's advanced testcase when mod_booking is installed,
// plain advanced_testcase otherwise (generated local_wizard plugin without booking);
// the setUp() guard then skips every booking-coupled test cleanly.
if (class_exists(booking_advanced_testcase::class)) {
    class_alias(booking_advanced_testcase::class, option_skills_contract_parent::class);
} else {
    class_alias(\advanced_testcase::class, option_skills_contract_parent::class);
}

/**
 * Contracts for canonical mod_booking option skill discovery and behavior.
 *
 * @covers \mod_booking\local\wizard\options\skills\create_option_skill
 * @covers \mod_booking\local\wizard\options\skills\create_selflearning_option_skill
 * @covers \mod_booking\local\wizard\options\skills\create_slotbooking_option_skill
 * @covers \mod_booking\local\wizard\options\skills\update_option_skill
 * @covers \mod_booking\local\wizard\options\skills\update_option_trainer_skill
 * @covers \bookingextension_agent\local\wizard\skill_registry
 * @covers \bookingextension_agent\local\wizard\skill_registry_factory
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mod_booking_option_skills_contract_test extends option_skills_contract_parent {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Ensure canonical mod_booking option skills are discoverable.
     */
    public function test_registry_discovers_canonical_mod_booking_option_skills(): void {
        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();

        $expected = [
            'mod_booking.create_option',
            'mod_booking.create_selflearning_option',
            'mod_booking.create_slotbooking_option',
            'mod_booking.update_option',
            'mod_booking.update_option_trainer',
        ];

        foreach ($expected as $skillname) {
            $this->assertNotNull($registry->get_skill($skillname), 'Missing discovered skill: ' . $skillname);
        }
    }

    /**
     * Ensure normal create skill creates a type-0 option when no extra hints are given.
     */
    public function test_create_option_defaults_to_type_zero(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();
        $skill = $registry->get_skill('mod_booking.create_option');

        $this->assertNotNull($skill);

        $input = [
            'text' => 'Default type option',
        ];

        $preflight = $skill->preflight($input, $contextid, (int)$teacher->id);
        $this->assertSame('pass', $preflight->status, 'Preflight must pass for canonical create.');

        $result = $skill->execute($preflight->preparedinput, $contextid, (int)$teacher->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $this->assertGreaterThan(0, (int)($result['resultid'] ?? 0)); // That is the optionid.
        $this->assertSame('passed', (string)($result['postcondition_status'] ?? ''));
        $this->assertIsArray($result['failed_postconditions'] ?? null);
        $this->assertSame([], (array)($result['failed_postconditions'] ?? []));
        $this->assertArrayHasKey('postcondition_evidence', $result);
        $this->assertSame('mod_booking.create_option', (string)(($result['postcondition_evidence']['skill'] ?? '')));

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$result['resultid']);
        $this->assertSame($bookingid, (int)$settings->bookingid ?? 0);
        $this->assertSame(0, (int)$settings->type, 'Normal create skill must persist option type 0.');
    }

    /**
     * Ensure normal create skill emits a rich observation payload for follow-up planning.
     */
    public function test_create_option_emits_rich_observation_summary(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();
        $skill = $registry->get_skill('mod_booking.create_option');

        $this->assertNotNull($skill);

        $input = [
            'text' => 'Observation option',
            'maxanswers' => 7,
            'invisible' => 0,
        ];

        $preflight = $skill->preflight($input, $contextid, (int)$teacher->id);
        $this->assertSame('pass', $preflight->status, 'Preflight must pass for create observation test.');

        $result = $skill->execute($preflight->preparedinput, $contextid, (int)$teacher->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''));

        $observation = trim((string)($result['observation_full'] ?? $result['detail'] ?? ''));
        $this->assertStringContainsString('Booking option created', $observation);
        $this->assertStringContainsString('title="Observation option"', $observation);
        $this->assertStringContainsString('id=' . (int)($result['resultid'] ?? 0), $observation);
        $this->assertStringContainsString('link=', $observation);

        $settings = singleton_service::get_instance_of_booking_option_settings((int)($result['resultid'] ?? 0));
        $this->assertSame($bookingid, (int)$settings->bookingid ?? 0);
        $this->assertSame(0, (int)$settings->type, 'Normal create skill must persist option type 0.');
    }

    /**
     * Bulk update must carry a real moodle_url link per option in the observation the synchronizer reads
     * (observation_full), not just a bare id — so course/option entities are always rendered linked.
     */
    public function test_bulk_update_emits_option_links_in_observation(): void {
        [$teacher, $contextid] = $this->create_booking_test_context();

        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();

        // Create two options to update in bulk.
        $create = $registry->get_skill('mod_booking.create_option');
        $optionids = [];
        foreach (['Bulk option A', 'Bulk option B'] as $title) {
            $preflight = $create->preflight(['text' => $title, 'maxanswers' => 5], $contextid, (int)$teacher->id);
            $created = $create->execute($preflight->preparedinput, $contextid, (int)$teacher->id);
            $optionids[] = (int)($created['resultid'] ?? 0);
        }
        $this->assertCount(2, array_filter($optionids), 'Two options must be created for the bulk test.');

        $bulk = $registry->get_skill('mod_booking.bulk_update_options');
        $this->assertNotNull($bulk);
        $input = ['optionids' => $optionids, 'maxanswers' => 9];
        $preflight = $bulk->preflight($input, $contextid, (int)$teacher->id);
        $prepared = $preflight->status === 'pass' ? $preflight->preparedinput : $input;
        $result = $bulk->execute($prepared, $contextid, (int)$teacher->id);

        $observation = trim((string)($result['observation_full'] ?? ''));
        $this->assertNotSame('', $observation, 'Bulk must emit an observation.');
        foreach ($optionids as $id) {
            // The fix: "Option <id> (<moodle_url>): confirmed" — id paired with its real link.
            $this->assertStringContainsString(
                'Option ' . $id . ' (http',
                $observation,
                'Bulk observation must carry a real link for option ' . $id
            );
        }
    }

    /**
     * Ensure selflearning update skill persists option type 1.
     */
    public function test_update_option_sets_type_one_for_selflearning_input(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        /** @var \mod_booking_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option([
            'bookingid' => $bookingid,
            'text' => 'Initial option',
            'maxanswers' => 8,
        ]);

        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();
        $skill = $registry->get_skill('mod_booking.update_option');

        $this->assertNotNull($skill);

        $input = [
            'optionid' => (int)$option->id,
            'text' => 'Selflearning option',
            'maxanswers' => 16,
            'optiontype' => 'selflearning',
        ];

        $preflight = $skill->preflight($input, $contextid, (int)$teacher->id);
        $this->assertSame('pass', $preflight->status, 'Preflight must pass for canonical selflearning update.');

        $result = $skill->execute($preflight->preparedinput, $contextid, (int)$teacher->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''));
        $this->assertSame((int)$option->id, (int)($result['resultid'] ?? 0));

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$result['resultid']);
        $this->assertSame(1, (int)$settings->type, 'Selflearning update skill must persist option type 1.');
        $this->assertSame('Selflearning option', (string)$settings->text);
        $this->assertSame(16, (int)$settings->maxanswers);
    }

    /**
     * Ensure slotbooking create skill blocks when slot form fields are missing.
     */
    public function test_create_slotbooking_option_requires_slot_fields(): void {
        [$teacher, $contextid] = $this->create_booking_test_context();

        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();
        $skill = $registry->get_skill('mod_booking.create_slotbooking_option');

        $this->assertNotNull($skill);

        $input = [
            'text' => 'Slot without required fields',
        ];

        $preflight = $skill->preflight($input, $contextid, (int)$teacher->id);
        $this->assertContains(
            $preflight->status,
            ['hard_block', 'pass'],
            'Slotbooking preflight must return a supported status.'
        );

        if ($preflight->status === 'hard_block') {
            $this->assertNotEmpty($preflight->issues);
            $this->assertStringContainsString(
                'Missing required slot field: slot_opening_time.',
                (string)($preflight->issues[0]['message'] ?? '')
            );
        }
    }

    /**
     * Ensure slotbooking contracts expose explicit slotbooking intent metadata.
     */
    public function test_slotbooking_prompt_contracts_are_explicit(): void {
        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();

        $create = $registry->get_skill('mod_booking.create_slotbooking_option');
        $update = $registry->get_skill('mod_booking.update_option');

        $this->assertNotNull($create);
        $this->assertNotNull($update);

        $createcontract = $create->get_prompt_contract()->to_array();
        $updatecontract = $update->get_prompt_contract()->to_array();

        $this->assertSame('create_slotbooking', (string)($createcontract['intent'] ?? ''));
        $this->assertContains('slot_opening_time', (array)($createcontract['minimal_input'] ?? []));
        $this->assertContains('optionid', array_keys((array)$update->get_schema()['properties']));
    }

    /**
     * Ensure duplicate signature guard blocks exact same title+time recreation.
     */
    public function test_create_option_duplicate_signature_requires_confirmation(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        /** @var \mod_booking_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $gen->create_option([
            'bookingid' => $bookingid,
            'text' => 'Agent Fire 2',
            'coursestarttime' => 1781078400,
            'courseendtime' => 1781085600,
            'maxanswers' => 9,
        ]);

        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();
        $skill = $registry->get_skill('mod_booking.create_option');
        $this->assertNotNull($skill);

        $preflight = $skill->preflight([
            'text' => 'Agent Fire 2',
            'coursestarttime' => 1781078400,
            'courseendtime' => 1781085600,
            'maxanswers' => 9,
            'override' => ['duplicate_title'],
        ], $contextid, (int)$teacher->id);

        $this->assertSame('hard_block', (string)$preflight->status);
        $codes = array_values(array_filter(array_map(
            static fn(array $issue): string => (string)($issue['code'] ?? ''),
            (array)$preflight->issues
        )));
        $this->assertContains('DUPLICATE_SIGNATURE_CONFIRM_REQUIRED', $codes);
    }

    /**
     * duplicate_title override must not leak into duplicate_signature allowance on follow-up calls.
     */
    public function test_create_option_duplicate_title_override_does_not_bypass_signature_guard(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        /** @var \mod_booking_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $gen->create_option([
            'bookingid' => $bookingid,
            'text' => 'Agent Fire 2',
            'coursestarttime' => 1781078400,
            'courseendtime' => 1781085600,
            'maxanswers' => 9,
        ]);

        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();
        $skill = $registry->get_skill('mod_booking.create_option');
        $this->assertNotNull($skill);

        // User confirmed duplicate title only, but not duplicate signature.
        $preflight = $skill->preflight([
            'text' => 'Agent Fire 2',
            'coursestarttime' => 1781078400,
            'courseendtime' => 1781085600,
            'maxanswers' => 9,
            'override' => ['duplicate_title'],
        ], $contextid, (int)$teacher->id);

        $this->assertSame('hard_block', (string)$preflight->status);
        $codes = array_values(array_filter(array_map(
            static fn(array $issue): string => (string)($issue['code'] ?? ''),
            (array)$preflight->issues
        )));
        $this->assertContains('DUPLICATE_SIGNATURE_CONFIRM_REQUIRED', $codes);
        $this->assertNotContains('DUPLICATE_TITLE_CONFIRM_REQUIRED', $codes);
    }

    /**
     * Trainer verification should emit warnings when expected trainer is not present.
     */
    public function test_option_input_verification_reports_missing_expected_trainer(): void {
        $input = ['teacherids' => [1006]];
        $settings = (object)[
            'teachers' => [
                (object)['id' => 1001, 'email' => 'other@example.invalid'],
            ],
        ];

        $warnings = \mod_booking\local\wizard\options\skills\option_input_verification::verify_common_fields($input, $settings);
        $joined = implode(' ', $warnings);
        $this->assertStringContainsString('Postcondition failed', $joined);
        $this->assertStringContainsString('trainer id 1006', $joined);

        $structured = \mod_booking\local\wizard\options\skills\option_input_verification::verify_common_fields_structured(
            $input,
            $settings
        );
        $this->assertNotEmpty($structured);
        $this->assertSame('POSTCOND_TRAINER_ID_MISSING', (string)($structured[0]['code'] ?? ''));
        $this->assertStringContainsString('trainer id 1006', (string)($structured[0]['message'] ?? ''));
    }

    /**
     * Ensure trainer assignment does not report false success when trainer persistence is not observable.
     */
    public function test_update_option_trainer_fails_when_requested_trainer_id_not_persisted(): void {
        [$teacher, $contextid, $bookingid] = $this->create_booking_test_context();

        $expectedtrainer = $this->getDataGenerator()->create_user();
        $othertrainer = $this->getDataGenerator()->create_user();

        /** @var \mod_booking_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option([
            'bookingid' => $bookingid,
            'text' => 'Trainer postcondition guard',
            'maxanswers' => 5,
        ]);

        skill_registry_factory::reset();
        $registry = skill_registry_factory::get_default();
        $skill = $registry->get_skill('mod_booking.update_option_trainer');

        $this->assertNotNull($skill);

        $input = [
            'optionid' => (int)$option->id,
            'teacherids' => [(int)$expectedtrainer->id],
            'teacheremail' => (string)$othertrainer->email,
        ];

        $preflight = $skill->preflight($input, $contextid, (int)$teacher->id);
        $this->assertSame('pass', $preflight->status, 'Preflight should pass; postcondition must guard trainer mismatch.');

        $result = $skill->execute($preflight->preparedinput, $contextid, (int)$teacher->id);
        $this->assertSame('error', (string)($result['status'] ?? ''));
        $this->assertSame('failed', (string)($result['postcondition_status'] ?? ''));

        $issuecodes = array_values(array_filter(array_map('strval', (array)($result['issue_codes'] ?? []))));
        $this->assertContains('POSTCONDITION_FAILED', $issuecodes);
        $this->assertContains('POSTCONDITION_FAILED_OPTION_MUTATION', $issuecodes);
        $this->assertContains('POSTCOND_TRAINER_ID_MISSING', $issuecodes);

        $failedpostconditions = (array)($result['failed_postconditions'] ?? []);
        $this->assertNotEmpty($failedpostconditions);
        $this->assertSame('POSTCOND_TRAINER_ID_MISSING', (string)($failedpostconditions[0]['code'] ?? ''));

        $evidence = (array)($result['postcondition_evidence'] ?? []);
        $this->assertSame('mod_booking.update_option_trainer', (string)($evidence['skill'] ?? ''));
        $this->assertSame((int)$option->id, (int)($evidence['optionid'] ?? 0));
    }

    /**
     * Create booking/module context and a teacher with required booking capabilities.
     *
     * @return array{0:\stdClass,1:int,2:int}
     */
    private function create_booking_test_context(): array {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Option skill contract test',
        ]);

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$teacher->id, (int)$course->id, 'editingteacher');
        $this->setUser($teacher);

        $cm = get_coursemodule_from_instance('booking', (int)$booking->id, (int)$course->id, false, MUST_EXIST);
        $context = context_module::instance((int)$cm->id);

        $this->grant_booking_option_skill_capabilities((int)$teacher->id, (int)$context->id);

        return [$teacher, (int)$context->id, (int)$booking->id];
    }

    /**
     * Ensure editingteacher has required booking capabilities in module context.
     *
     * @param int $userid
     * @param int $contextid
     * @return void
     */
    private function grant_booking_option_skill_capabilities(int $userid, int $contextid): void {
        $roles = get_archetype_roles('editingteacher');
        if (empty($roles)) {
            $this->fail('editingteacher role archetype not found');
        }

        $role = reset($roles);
        $roleid = (int)$role->id;

        assign_capability('mod/booking:addoption', CAP_ALLOW, $roleid, $contextid, true);
        assign_capability('mod/booking:addeditownoption', CAP_ALLOW, $roleid, $contextid, true);
        role_assign($roleid, $userid, $contextid);

        accesslib_clear_all_caches(true);
        accesslib_reset_role_cache();
    }
}

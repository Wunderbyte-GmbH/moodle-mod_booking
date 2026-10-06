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

declare(strict_types=1);

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\contracts\skill_family_contract;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\services\discovery\context_prior_builder;
use bookingextension_agent\local\wizard\services\discovery\core_family_set;
use bookingextension_agent\local\wizard\services\discovery\family_registry_service;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\skill_contract_validator;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for phase-1 family discovery foundation.
 *
 * @covers \bookingextension_agent\local\wizard\contracts\skill_family_contract
 * @covers \bookingextension_agent\local\wizard\dto\skill_prompt_contract
 * @covers \bookingextension_agent\local\wizard\skill_contract_validator
 * @covers \bookingextension_agent\local\wizard\services\discovery\family_registry_service
 * @covers \bookingextension_agent\local\wizard\services\discovery\core_family_set
 * @covers \bookingextension_agent\local\wizard\services\discovery\context_prior_builder
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class phase1_discovery_foundation_contract_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Family contract must derive deterministic fallback families from skill names.
     */
    public function test_skill_family_contract_derives_family_from_skill_name(): void {
        $this->assertSame('mod_booking.general', skill_family_contract::from_skill_name('mod_booking.create_option'));
        $this->assertSame('wizard.general', skill_family_contract::from_skill_name('wizard.recall_memory'));
        $this->assertSame('wizard.general', skill_family_contract::from_skill_name('not_namespaced'));
    }

    /**
     * Prompt contract must include normalized family metadata.
     */
    public function test_skill_prompt_contract_normalizes_family(): void {
        $contract = new skill_prompt_contract([
            'intent' => 'search',
            'namespace' => 'entities',
            'family' => 'Entities.Lookup',
        ]);

        $payload = $contract->to_array();
        $this->assertSame('entities.lookup', $payload['family']);

        $fallback = new skill_prompt_contract([
            'intent' => 'search',
            'namespace' => 'demo',
        ]);
        $fallbackpayload = $fallback->to_array();
        $this->assertSame('demo.general', $fallbackpayload['family']);
    }

    /**
     * Validator metadata must include valid family contract output.
     */
    public function test_skill_contract_validator_metadata_contains_family(): void {
        $skill = $this->createMock(skill_interface::class);
        $skill->method('get_name')->willReturn('demo.lookup');
        $skill->method('get_schema')->willReturn(['version' => 1, 'governance' => []]);
        $skill->method('is_read_only')->willReturn(true);
        $skill->method('get_risk_class')->willReturn(skill_risk_class::R0);
        $skill->method('get_example_input')->willReturn([]);
        $skill->method('get_prompt_contract')->willReturn(new skill_prompt_contract([
            'intent' => 'lookup',
            'anchors' => [],
            'minimal_input' => [],
            'example_input' => [],
            'namespace' => 'demo',
            'version' => 1,
            'capabilities' => [],
            'context_scopes' => ['module'],
            'risk_class' => skill_risk_class::R0,
        ]));

        $meta = skill_contract_validator::build_skill_metadata($skill, 'local_demo');
        $this->assertSame('demo.general', $meta['family']);
        $this->assertSame(skill_risk_class::R0, $meta['risk_class']);

        $validation = skill_contract_validator::validate_skill_metadata($meta);
        $this->assertTrue($validation['valid']);
    }

    /**
     * Family registry must produce deterministic context/core family candidates.
     */
    public function test_family_registry_discovers_context_and_core_families(): void {
        $contracts = [
            ['skill' => 'mod_booking.create_option', 'family' => 'mod_booking.options'],
            ['skill' => 'mod_booking.create_slotbooking_option', 'family' => 'mod_booking.options'],
            ['skill' => 'wizard.recall_memory', 'family' => 'wizard.general'],
            ['skill' => 'local_entities.lookup', 'family' => 'local_entities.general'],
        ];

        $contextprior = (new context_prior_builder())->build(42, ['namespace_hint' => 'mod_booking', 'userid' => 12]);
        $registry = new family_registry_service(new core_family_set());
        $result = $registry->discover($contracts, $contextprior)->to_array();

        $this->assertSame(['mod_booking.options'], $result['context_families']);
        $this->assertContains('wizard.general', $result['core_families']);
        $this->assertContains('mod_booking.options', $result['families']);
        $this->assertArrayHasKey('context_prior', $result);
    }

    /**
     * Context prior must be ranking-only and never mark hard-filter semantics.
     */
    public function test_context_prior_is_ranking_only(): void {
        $prior = (new context_prior_builder())->build(99, ['namespace_hint' => 'mod_booking', 'page_type' => 'mod-booking-view']);

        $this->assertSame(99, $prior['contextid']);
        $this->assertSame('mod_booking', $prior['namespace_hint']);
        $this->assertSame('mod-booking-view', $prior['page_type']);
        $this->assertFalse($prior['is_hard_filter']);
    }
}

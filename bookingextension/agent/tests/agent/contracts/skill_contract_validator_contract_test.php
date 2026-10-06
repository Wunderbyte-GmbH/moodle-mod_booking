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

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\interfaces\skill_provider_interface;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\skill_contract_validator;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for skill namespace/version governance rules.
 *
 * @covers \bookingextension_agent\local\wizard\skill_contract_validator
 * @covers \bookingextension_agent\local\wizard\skill_registry
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skill_contract_validator_contract_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Validate namespaced skill-name format helper.
     */
    public function test_namespaced_skill_name_format(): void {
        $this->assertTrue(skill_contract_validator::is_namespaced_skill_name('mod_booking.create_option'));
        $this->assertTrue(skill_contract_validator::is_namespaced_skill_name('entities.search'));
        $this->assertFalse(skill_contract_validator::is_namespaced_skill_name('create_option'));
        $this->assertFalse(skill_contract_validator::is_namespaced_skill_name('booking.create.option'));
    }

    /**
     * Validate reserved namespace ownership rules.
     */
    public function test_reserved_namespace_ownership(): void {
        $this->assertTrue(skill_contract_validator::component_may_register_namespace('bookingextension_agent', 'booking'));
        $this->assertTrue(skill_contract_validator::component_may_register_namespace('bookingextension_agent', 'core'));
        // The report.* family (Moodle Report Builder skills) is engine-owned like course.*, but reserved
        // explicitly so no third-party provider can squat it (Wunderbyte-GmbH/Wunderbyte-GmbH#2471).
        $this->assertTrue(skill_contract_validator::component_may_register_namespace('bookingextension_agent', 'report'));
        $this->assertFalse(skill_contract_validator::component_may_register_namespace('local_dummy', 'report'));
        $this->assertFalse(skill_contract_validator::component_may_register_namespace('local_dummy', 'booking'));
        $this->assertFalse(skill_contract_validator::component_may_register_namespace('local_dummy', 'core'));
        $this->assertTrue(skill_contract_validator::component_may_register_namespace('local_dummy', 'entities'));
    }

    /**
     * Validate alias version mismatch detection in registry-wide contracts.
     */
    public function test_validate_registry_contracts_rejects_alias_version_mismatch(): void {
        $contracts = [
            'entities.search' => [
                'skillname' => 'entities.search',
                'namespace' => 'entities',
                'family' => 'entities.general',
                'version' => 1,
                'component' => 'local_entities',
                'capabilities' => ['local/entities:skill_entities_search'],
                'active' => true,
                'alias_of' => '',
                'deprecated_since' => '',
                'readonly' => true,
                'risk_class' => skill_risk_class::R0,
                'context_scopes' => ['module'],
            ],
            'entities.lookup' => [
                'skillname' => 'entities.lookup',
                'namespace' => 'entities',
                'family' => 'entities.general',
                'version' => 2,
                'component' => 'local_entities',
                'capabilities' => ['local/entities:skill_entities_lookup'],
                'active' => true,
                'alias_of' => 'entities.search',
                'deprecated_since' => '',
                'readonly' => true,
                'risk_class' => skill_risk_class::R0,
                'context_scopes' => ['module'],
            ],
        ];

        $errors = skill_contract_validator::validate_registry_contracts($contracts);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Alias version mismatch', (string)$errors[0]);
    }

    /**
     * Validate registry rejects third-party skills in reserved booking/core namespaces.
     */
    public function test_registry_rejects_reserved_namespace_for_third_party_provider(): void {
        $skill = $this->createMock(skill_interface::class);
        $skill->method('get_name')->willReturn('booking.hijack');
        $skill->method('get_schema')->willReturn([
            'description' => 'Invalid skill in reserved namespace.',
            'version' => 1,
            'governance' => [],
            'properties' => [],
            'required' => [],
        ]);
        $skill->method('is_read_only')->willReturn(true);
        $skill->method('get_risk_class')->willReturn(skill_risk_class::R0);
        $skill->method('get_example_input')->willReturn([]);
        $skill->method('get_prompt_contract')->willReturn(new skill_prompt_contract([
            'intent' => 'invalid',
            'anchors' => [],
            'minimal_input' => [],
            'example_input' => [],
            'namespace' => 'booking',
            'version' => 1,
            'capabilities' => [],
            'context_scopes' => ['module'],
            'risk_class' => skill_risk_class::R0,
        ]));

        $provider = $this->createMock(skill_provider_interface::class);
        $provider->method('get_component')->willReturn('local_dummy');
        $provider->method('get_skills')->willReturn([$skill]);
        $provider->method('get_contextual_prompt_packs')->willReturn([]);
        $provider->method('get_issue_code_provider')->willReturn(null);
        $provider->method('get_prompt_guidance')->willReturn([]);

        $registry = new skill_registry();
        $registry->register($provider);

        $this->assertNull($registry->get_skill('booking.hijack'));
        $this->assertNotEmpty($registry->get_contract_diagnostics());
        $this->assertStringContainsString('namespace is reserved', $registry->get_contract_diagnostics()[0]);
    }

    /**
     * Validate that a demo skill can be onboarded via provider registration only.
     */
    public function test_demo_skill_onboards_via_provider_registration_only(): void {
        $skill = $this->createMock(skill_interface::class);
        $skill->method('get_name')->willReturn('demo.lookup');
        $skill->method('get_schema')->willReturn([
            'description' => 'Demo lookup skill.',
            'version' => 1,
            'governance' => [],
            'properties' => [
                'query' => ['type' => 'string'],
            ],
            'required' => ['query'],
        ]);
        $skill->method('is_read_only')->willReturn(true);
        $skill->method('get_risk_class')->willReturn(skill_risk_class::R0);
        $skill->method('get_example_input')->willReturn(['query' => 'demo']);
        $skill->method('get_prompt_contract')->willReturn(new skill_prompt_contract([
            'intent' => 'search',
            'anchors' => ['demo'],
            'minimal_input' => ['query'],
            'example_input' => ['query' => 'demo'],
            'namespace' => 'demo',
            'version' => 1,
            'capabilities' => ['local/demo:skill_demo_lookup'],
            'context_scopes' => ['module'],
            'risk_class' => skill_risk_class::R0,
        ]));

        $provider = $this->createMock(skill_provider_interface::class);
        $provider->method('get_component')->willReturn('local_demo');
        $provider->method('get_skills')->willReturn([$skill]);
        $provider->method('get_contextual_prompt_packs')->willReturn([]);
        $provider->method('get_issue_code_provider')->willReturn(null);
        $provider->method('get_prompt_guidance')->willReturn([]);

        $registry = new skill_registry();
        $registry->register($provider);

        $this->assertNotNull($registry->get_skill('demo.lookup'));
        $contracts = $registry->get_all_prompt_contracts();
        $this->assertCount(1, $contracts);
        $this->assertSame('demo.lookup', (string)$contracts[0]['skill']);
        $this->assertSame('demo', (string)$contracts[0]['namespace']);
        $this->assertSame('demo.general', (string)$contracts[0]['family']);
        $this->assertSame(1, (int)$contracts[0]['version']);
        $this->assertContains('local/demo:skill_demo_lookup', (array)$contracts[0]['capabilities']);
        $this->assertSame(skill_risk_class::R0, (string)$contracts[0]['risk_class']);
    }

    /**
     * Validate risk-class metadata is built and enforced for readonly skills.
     */
    public function test_validate_skill_metadata_requires_risk_class_and_readonly_alignment(): void {
        $metadata = [
            'skillname' => 'demo.lookup',
            'namespace' => 'demo',
            'family' => 'demo.general',
            'version' => 1,
            'component' => 'local_demo',
            'capabilities' => ['local/demo:skill_demo_lookup'],
            'active' => true,
            'alias_of' => '',
            'deprecated_since' => '',
            'readonly' => true,
            'risk_class' => skill_risk_class::R0,
            'context_scopes' => ['module'],
        ];

        $validation = skill_contract_validator::validate_skill_metadata($metadata);
        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);

        $metadata['risk_class'] = '';
        $validation = skill_contract_validator::validate_skill_metadata($metadata);
        $this->assertFalse($validation['valid']);
        $this->assertContains('Missing required field: risk_class.', $validation['errors']);
    }

    /**
     * Unknown risk classes must be rejected immediately.
     */
    public function test_validate_skill_metadata_rejects_unknown_risk_class_value(): void {
        $metadata = [
            'skillname' => 'demo.lookup',
            'namespace' => 'demo',
            'family' => 'demo.general',
            'version' => 1,
            'component' => 'local_demo',
            'capabilities' => ['local/demo:skill_demo_lookup'],
            'active' => true,
            'alias_of' => '',
            'deprecated_since' => '',
            'readonly' => true,
            'risk_class' => 'R9',
            'context_scopes' => ['module'],
        ];

        $validation = skill_contract_validator::validate_skill_metadata($metadata);
        $this->assertFalse($validation['valid']);
        $this->assertContains(
            'Invalid required field: risk_class must be one of read_only, scoped_write, broad_write, irreversible_or_external.',
            $validation['errors']
        );
    }

    /**
     * R0 must always be declared as readonly.
     */
    public function test_validate_skill_metadata_rejects_r0_when_not_readonly(): void {
        $metadata = [
            'skillname' => 'demo.lookup',
            'namespace' => 'demo',
            'family' => 'demo.general',
            'version' => 1,
            'component' => 'local_demo',
            'capabilities' => ['local/demo:skill_demo_lookup'],
            'active' => true,
            'alias_of' => '',
            'deprecated_since' => '',
            'readonly' => false,
            'risk_class' => skill_risk_class::R0,
            'context_scopes' => ['module'],
        ];

        $validation = skill_contract_validator::validate_skill_metadata($metadata);
        $this->assertFalse($validation['valid']);
        $this->assertContains(
            'Invalid risk_class declaration: R0 skills must be read-only.',
            $validation['errors']
        );
    }

    /**
     * Validate mutating skills require a non-readonly declaration and explicit scope.
     */
    public function test_validate_skill_metadata_rejects_mutating_readonly_or_scope_missing_skills(): void {
        $metadata = [
            'skillname' => 'demo.mutate',
            'namespace' => 'demo',
            'family' => 'demo.general',
            'version' => 1,
            'component' => 'local_demo',
            'capabilities' => ['local/demo:skill_demo_mutate'],
            'active' => true,
            'alias_of' => '',
            'deprecated_since' => '',
            'readonly' => true,
            'risk_class' => skill_risk_class::R2,
            'context_scopes' => [],
        ];

        $validation = skill_contract_validator::validate_skill_metadata($metadata);
        $this->assertFalse($validation['valid']);
        $this->assertContains(
            'Invalid risk_class declaration: mutating skills must not be marked read-only.',
            $validation['errors']
        );
        $this->assertContains(
            'Invalid risk_class declaration: broad or irreversible skills must declare explicit context scopes.',
            $validation['errors']
        );
    }

    /**
     * Validate that a failing provider does not block already registered providers.
     */
    public function test_failing_provider_does_not_block_other_registered_skills(): void {
        $goodskill = $this->createMock(skill_interface::class);
        $goodskill->method('get_name')->willReturn('demo.healthy_skill');
        $goodskill->method('get_schema')->willReturn([
            'description' => 'Healthy demo skill.',
            'version' => 1,
            'governance' => [],
            'properties' => [],
            'required' => [],
        ]);
        $goodskill->method('is_read_only')->willReturn(true);
        $goodskill->method('get_risk_class')->willReturn(skill_risk_class::R0);
        $goodskill->method('get_example_input')->willReturn([]);
        $goodskill->method('get_prompt_contract')->willReturn(new skill_prompt_contract([
            'intent' => 'healthy',
            'anchors' => [],
            'minimal_input' => [],
            'example_input' => [],
            'namespace' => 'demo',
            'version' => 1,
            'capabilities' => [],
            'context_scopes' => ['module'],
            'risk_class' => skill_risk_class::R0,
        ]));

        $goodprovider = $this->createMock(skill_provider_interface::class);
        $goodprovider->method('get_component')->willReturn('local_demo');
        $goodprovider->method('get_skills')->willReturn([$goodskill]);
        $goodprovider->method('get_contextual_prompt_packs')->willReturn([]);
        $goodprovider->method('get_issue_code_provider')->willReturn(null);
        $goodprovider->method('get_prompt_guidance')->willReturn([]);

        $badprovider = $this->createMock(skill_provider_interface::class);
        $badprovider->method('get_component')->willReturn('local_broken');
        $badprovider->method('get_contextual_prompt_packs')->willReturn([]);
        $badprovider->method('get_issue_code_provider')->willReturn(null);
        $badprovider->method('get_prompt_guidance')->willReturn([]);
        $badprovider->method('get_skills')->willThrowException(new \RuntimeException('broken provider'));

        $registry = new skill_registry();
        $registry->register($goodprovider);
        $registry->register($badprovider);

        $this->assertNotNull($registry->get_skill('demo.healthy_skill'));
        $this->assertNotEmpty($registry->get_contract_diagnostics());
    }
}

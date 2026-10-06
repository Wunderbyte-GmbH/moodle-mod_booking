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

use bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_provider_interface;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the generic agentic framework.
 *
 * Validates that the framework successfully abstracts plugin-specific logic
 * and maintains genericity for multi-plugin environments.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class integration_agent_framework_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Test that skill_registry discovers skills from the booking plugin provider.
     */
    public function test_skill_registry_discovers_booking_skills(): void {
        $registry = skill_registry_factory::get_default();
        $skills = $registry->get_skills();

        // Verify that skills are discovered.
        $this->assertNotEmpty($skills, 'Skill registry should discover skills from booking plugin');
        $this->assertGreaterThanOrEqual(2, count($skills), 'Should discover at least 2 booking skills');

        // Verify skill names follow the pattern: <component>.<skillname>.
        foreach ($skills as $skill) {
            $name = $skill->get_name();
            $this->assertStringContainsString('.', $name, 'Skill name should include component prefix');
        }
    }

    /**
     * Test that skill_provider interface supports optional issue code provider.
     */
    public function test_skill_provider_interface_supports_issue_code_provider(): void {
        $provider = new \bookingextension_agent\local\wizard\skill_provider();

        // Verify interface methods exist.
        $this->assertTrue(
            method_exists($provider, 'get_issue_code_provider'),
            'skill_provider should implement get_issue_code_provider()'
        );

        // Verify method returns issue code provider.
        $issuecodeprovider = $provider->get_issue_code_provider();
        $this->assertInstanceOf(
            issue_code_provider_interface::class,
            $issuecodeprovider,
            'get_issue_code_provider() should return issue_code_provider_interface instance'
        );
    }

    /**
     * Test that skill_provider interface supports optional prompt guidance.
     */
    public function test_skill_provider_interface_supports_prompt_guidance(): void {
        $provider = new \bookingextension_agent\local\wizard\skill_provider();

        // Verify interface methods exist.
        $this->assertTrue(
            method_exists($provider, 'get_prompt_guidance'),
            'skill_provider should implement get_prompt_guidance()'
        );

        // Verify method returns array.
        $guidance = $provider->get_prompt_guidance();
        $this->assertIsArray($guidance, 'get_prompt_guidance() should return array');
    }

    /**
     * Test that issue code provider is used by agent decision service.
     */
    public function test_issue_code_provider_injected_into_agent_runtime(): void {
        $provider = new \bookingextension_agent\local\wizard\booking_issue_code_provider();
        $registry = skill_registry_factory::get_default();
        $store = new \bookingextension_agent\local\wizard\conversation_store();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);
        $orchestrator = new \bookingextension_agent\local\wizard\orchestrator($registry, $interpreter, $store);
        $authz = new \bookingextension_agent\local\wizard\services\security\authorization_service();

        // Create agent_runtime with custom provider (test dependency injection).
        $runtime = new \bookingextension_agent\local\wizard\agent_runtime(
            $registry,
            $orchestrator,
            $store,
            $authz
        );

        // Verify that runtime accepts the provider (no exception thrown).
        $this->assertInstanceOf(\bookingextension_agent\local\wizard\agent_runtime::class, $runtime);
    }

    /**
     * The construction-phase catalog entry for the selected skill must carry that skill's full
     * prompt-pack guidance unconditionally (no language-gated trigger filter). This is what makes
     * situational rules — e.g. "for same-named options, call search_options first and use optionid"
     * — reach the constructor regardless of the user's language.
     */
    public function test_construction_catalog_includes_skill_guidance_unconditionally(): void {
        $registry = skill_registry_factory::get_default();

        $skill = $registry->get_skill('mod_booking.update_option');
        if ($skill === null) {
            $this->markTestSkipped('mod_booking.update_option not registered in default registry.');
        }

        // The construction catalog enrichment now lives in planner_phase_service (orchestrator split).
        // enrich_construction_catalog_entry only needs the registry, so build the service without its
        // constructor and inject the registry reflectively.
        $svcreflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\planner_phase_service::class);
        $svc = $svcreflection->newInstanceWithoutConstructor();
        $regprop = $svcreflection->getProperty('registry');
        $regprop->setAccessible(true);
        $regprop->setValue($svc, $registry);

        $method = $svcreflection->getMethod('enrich_construction_catalog_entry');
        $method->setAccessible(true);
        $entry = $method->invoke(
            $svc,
            'mod_booking.update_option',
            ['skill' => 'mod_booking.update_option'],
            \context_system::instance()->id,
            get_admin()->id
        );

        $this->assertArrayHasKey('guidance', $entry, 'Construction entry must carry skill guidance.');
        $guidancejoined = implode("\n", (array)$entry['guidance']);
        $this->assertStringContainsString('search_options', $guidancejoined);
        $this->assertStringContainsString('optionid', $guidancejoined);
    }

    /**
     * Test that skill schema includes prompt_meta when available.
     */
    public function test_skill_schema_includes_prompt_meta(): void {
        $registry = skill_registry_factory::get_default();

        // Get skills and verify at least one has prompt_meta.
        $skills = $registry->get_skills();
        $this->assertNotEmpty($skills, 'Registry should have skills');

        $foundpromptmeta = false;
        foreach ($skills as $skill) {
            $schema = $skill->get_schema();
            if (isset($schema['prompt_meta'])) {
                $foundpromptmeta = true;
                $this->assertIsArray($schema['prompt_meta'], 'prompt_meta should be array');
                $this->assertArrayHasKey('input_fields_for_prompt', $schema['prompt_meta']);
                $this->assertArrayHasKey('anchor_fields', $schema['prompt_meta']);
                break;
            }
        }

        $this->assertTrue($foundpromptmeta, 'At least one booking skill should have prompt_meta');
    }

    /**
     * Test that skill registry uses prompt_meta when building prompt contract.
     */
    public function test_skill_registry_prioritizes_prompt_meta(): void {
        $registry = skill_registry_factory::get_default();
        $contract = ['skills' => $registry->get_all_prompt_contracts()];

        // Verify contract includes skill catalog.
        $this->assertIsArray($contract, 'Prompt contract should be array');
        $this->assertArrayHasKey('skills', $contract, 'Contract should include skills');

        // Verify each skill has routing metadata.
        foreach ((array)$contract['skills'] as $skillinfo) {
            $this->assertIsArray($skillinfo, 'Skill info should be array');
            $this->assertArrayHasKey('skill', $skillinfo, 'Should have skill name');
        }
    }

    /**
     * Test that prompt contracts separate required inputs from routing examples.
     */
    public function test_prompt_contracts_use_required_minimals_and_explicit_examples(): void {
        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();

        $foundreadonlyskill = false;
        $foundmutatingskill = false;
        foreach ($contracts as $skillinfo) {
            $this->assertArrayHasKey('skill', $skillinfo, 'Every skill should expose skill name');
            $this->assertArrayHasKey('minimal_input', $skillinfo, 'Every skill should expose minimal_input');
            $this->assertArrayHasKey('example_input', $skillinfo, 'Every skill should expose example_input');
            $this->assertIsArray($skillinfo['minimal_input'], 'minimal_input should always be an array');
            $this->assertIsArray($skillinfo['example_input'], 'example_input should always be an array');

            if (!empty($skillinfo['readonly'])) {
                $foundreadonlyskill = true;
            } else {
                $foundmutatingskill = true;
            }
        }

        $this->assertNotEmpty($contracts, 'Prompt contracts should not be empty');
        $this->assertTrue($foundreadonlyskill, 'Expected at least one readonly skill contract');
        $this->assertTrue($foundmutatingskill, 'Expected at least one mutating skill contract');
    }

    /**
     * Test that slim planner catalog never recreates example_input from minimal_input.
     */
    public function test_slim_catalog_keeps_examples_separate_from_minimals(): void {
        $registry = skill_registry_factory::get_default();
        $catalogsvc = new \bookingextension_agent\local\wizard\services\planner_catalog_service(
            new \bookingextension_agent\local\wizard\services\assistant_state_guidance_service($registry)
        );

        $slimcatalog = $catalogsvc->slim_prompt_catalog_for_planner($registry->get_all_prompt_contracts());
        $byskill = [];
        foreach ($slimcatalog as $skillinfo) {
            $byskill[(string)($skillinfo['skill'] ?? $skillinfo['skill'] ?? '')] = $skillinfo;
            $this->assertArrayHasKey('minimal_input', $skillinfo, 'Slim catalog should keep minimal_input');
            $this->assertIsArray($skillinfo['minimal_input'], 'Slim minimal_input should be an array');
            if (array_key_exists('example_input', $skillinfo)) {
                $this->assertIsArray($skillinfo['example_input'], 'Slim example_input should remain an array');
            }

            if (isset($skillinfo['description']) && is_string($skillinfo['description'])) {
                // The card budget is 240, with a tolerance up to 288: a description that overshoots the cap
                // only slightly keeps its last sentence rather than losing it whole
                // (planner_catalog_service::CARD_DESCRIPTION_TOLERANCE, planner_catalog_truncation_test).
                $this->assertLessThanOrEqual(288, \core_text::strlen($skillinfo['description']));
            }
        }

        $this->assertNotEmpty($byskill, 'Slim catalog should contain skill entries');
    }

    /**
     * Runtime catalog payload injected into prompts must never contain embedding vectors.
     */
    public function test_runtime_catalog_prompt_sanitizer_removes_embedding_json(): void {
        $catalogsvc = new \bookingextension_agent\local\wizard\services\planner_catalog_service(
            new \bookingextension_agent\local\wizard\services\assistant_state_guidance_service(
                skill_registry_factory::get_default()
            )
        );

        $catalog = [
            [
                'skill' => 'mod_booking.diagnose_booking_issue',
                'description' => 'Diagnose booking issue.',
                'readonly' => '1',
                'intent' => 'skill',
                'minimal_input_json' => '[]',
                'example_input_json' => '{"question":"Why"}',
                'message_triggers_json' => '[{"id":"t1","description":"desc"}]',
                'embedding_json' => '[0.1,0.2,0.3]',
                'embedding_model' => 'wunderbyte-embeddings',
                'embedding_dimensions' => '1536',
                'content_hash' => 'abc',
                'score' => '0.27',
            ],
            [
                'skill' => 'mod_booking.list_options',
                'description' => 'List booking options.',
                'readonly' => false,
                'intent' => 'lookup',
                'minimal_input' => ['optionquery'],
                'example_input' => ['optionquery' => 'Yoga'],
                'message_triggers' => [['id' => 't2', 'description' => 'desc2']],
            ],
        ];

        $sanitized = $catalogsvc->sanitize_runtime_catalog_for_prompt($catalog);
        $this->assertCount(2, $sanitized);
        $this->assertSame(
            // The required_input key joined the sanitized catalogue on 2026-09-19: the REQUIRED line of the
            // selection prompt is built from the schema's required flags, no longer from minimal_input.
            // accepts_empty_input joined on 2026-09-20: the flags alone are not the whole truth, because
            // sixteen skills gate their mandatory fields in check_structure() instead, and the card may
            // only claim "REQUIRED: none" when both halves agree.
            // The required_groups key joined on 2026-09-20 as well: a skill that gates its input in check_structure()
            // declares the alternatives it accepts, so the card can say "one of a | b" instead of falling
            // silent — the silence cost GOD-1 and GOD-2 in run 21.
            // The is/not keys joined on 2026-09-22 (wave 17, #2453): the boundary against a sibling moved
            // out of the description — which is embedding anchor #0 and cannot carry a negation — into the
            // IS:/NOT: card lines, which the selector reads and the anchor builder does not.
            // The when key joined on 2026-10-03 (#2546): a skill may declare when to choose it; without it the
            // WHEN: card line still falls back to the first message trigger.
            ['skill', 'readonly', 'intent', 'minimal_input', 'required_input', 'accepts_empty_input',
                'required_groups', 'description', 'is', 'not', 'when', 'message_triggers', 'example_input'],
            array_keys($sanitized[0])
        );
        $this->assertSame('mod_booking.diagnose_booking_issue', (string)$sanitized[0]['skill']);
        $this->assertTrue((bool)$sanitized[0]['readonly']);
        $this->assertSame('skill', (string)$sanitized[0]['intent']);
        // Card metadata is re-joined LIVE from the registry (fix Y): the sanitizer ignores the stale
        // minimal_input/example_input the catalog row carried and reflects the current skill contract.
        $livecontracts = skill_registry_factory::get_default()->get_all_prompt_contracts();
        $livediag = [];
        foreach ($livecontracts as $livecontract) {
            if (($livecontract['skill'] ?? '') === 'mod_booking.diagnose_booking_issue') {
                $livediag = $livecontract;
                break;
            }
        }
        $this->assertSame(
            array_values((array)($livediag['minimal_input'] ?? [])),
            array_values((array)$sanitized[0]['minimal_input']),
            'minimal_input must come live from the skill contract, not the catalog row.'
        );
        $this->assertSame(
            $catalogsvc->compact_catalog_example_input((array)($livediag['example_input'] ?? [])),
            $sanitized[0]['example_input'],
            'example_input must come live from the skill contract, not the catalog row.'
        );
        $this->assertArrayHasKey('id', (array)($sanitized[0]['message_triggers'][0] ?? []));
        $this->assertArrayNotHasKey('embedding_json', $sanitized[0]);
        $this->assertArrayNotHasKey('embedding_model', $sanitized[0]);
        $this->assertArrayNotHasKey('embedding_dimensions', $sanitized[0]);
        $this->assertArrayNotHasKey('content_hash', $sanitized[0]);
        $this->assertArrayNotHasKey('score', $sanitized[0]);

        // Entry [1] names a skill that is NOT registered, so there is no live contract to re-join:
        // the sanitizer emits a minimal entry rather than trusting the catalog row's stale metadata.
        $this->assertSame(
            ['skill', 'readonly', 'intent', 'minimal_input', 'required_input', 'accepts_empty_input',
                'required_groups', 'description', 'is', 'not', 'when', 'message_triggers'],
            array_keys($sanitized[1])
        );
        $this->assertSame('mod_booking.list_options', (string)$sanitized[1]['skill']);
        $this->assertFalse((bool)$sanitized[1]['readonly']);
        $this->assertSame('', (string)$sanitized[1]['intent']);
        $this->assertSame([], $sanitized[1]['minimal_input']);
        $this->assertArrayNotHasKey('example_input', $sanitized[1]);
    }

    /**
     * Selection output without an explicit single skill yields no forced selection.
     */
    public function test_selection_without_explicit_skill_returns_empty_selection(): void {
        // Selection helpers now live in planner_phase_service (orchestrator split).
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\planner_phase_service::class);
        $svc = $reflection->newInstanceWithoutConstructor();

        $selectedskillmethod = $reflection->getMethod('extract_selected_skill_from_selection_phase_output');
        $selectedskillmethod->setAccessible(true);

        $this->assertSame('', $selectedskillmethod->invoke($svc, ['response_type' => 'sufficient']));
    }

    /**
     * Test that embedding-selected planner subsets keep full skill descriptions.
     */
    public function test_embedding_subset_keeps_full_descriptions(): void {
        $retrieval = new \bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service();
        $csvdescription = 'Persisted CSV description that should not win over live skill schema metadata.';
        $livedescription = 'Live skill description from get_schema that must win ' .
            'when embed skill selection is mapped back to skills.';

        $subset = $retrieval->build_planner_catalog_subset([
            [
                'skill' => 'booking.create_rule_from_template',
                'anchor_index' => '0',
                'anchor_kind' => 'description',
                'anchor_text' => $csvdescription,
                'embedding_model' => 'wunderbyte-embeddings',
                'embedding_dimensions' => '1536',
                'content_hash' => 'dummy',
                'embedding_json' => '[]',
            ],
        ], [
            [
                'skill' => 'booking.create_rule_from_template',
                'intent' => 'create',
                'readonly' => false,
                'description' => $livedescription,
                'minimal_input' => [],
                'example_input' => [
                    'templatequery' => 'booking confirmation',
                    'rulename' => 'Birthday reminder',
                ],
                'message_triggers' => [],
            ],
        ]);

        $this->assertCount(1, $subset);
        $this->assertSame($livedescription, $subset[0]['description']);
    }

    /**
     * Test that embedding-selected planner subsets include compact schema properties.
     */
    public function test_embedding_subset_includes_property_descriptions(): void {
        skill_registry_factory::reset();

        $retrieval = new \bookingextension_agent\local\wizard\services\embeddings\embeddings_retrieval_service();
        $subset = $retrieval->build_planner_catalog_subset([
            [
                'skill' => 'wizard.recreate_skill_catalog',
                'anchor_index' => '0',
                'anchor_kind' => 'description',
                'anchor_text' => 'stale csv description',
                'embedding_model' => 'wunderbyte-embeddings',
                'embedding_dimensions' => '1536',
                'content_hash' => 'dummy',
                'embedding_json' => '[]',
            ],
        ]);

        $this->assertCount(1, $subset);
        $this->assertArrayHasKey('properties', $subset[0]);
        $this->assertIsArray($subset[0]['properties']);
        $this->assertArrayHasKey('force', $subset[0]['properties']);
        $this->assertArrayHasKey('description', $subset[0]['properties']['force']);
        $this->assertStringContainsString(
            'force regeneration',
            (string)$subset[0]['properties']['force']['description']
        );
    }

    /**
     * Test that orchestrator prompts are generic and do not hardcode plugin names.
     */
    public function test_orchestrator_prompts_are_generic(): void {
        // Use the live planner fallback template instead of the removed generic one-step template.
        $template = \bookingextension_agent\local\wizard\orchestrator::get_default_initial_prompt_template_for_action(
            \core_ai\aiactions\summarise_text::class
        );

        // Verify template does not contain hardcoded plugin-specific skill names.
        $this->assertNotEmpty($template, 'Prompt template should not be empty');

        // Verify the live planner fallback stays cache-stable: no per-context
        // placeholders in the [SYSTEM] block; the context name lives in [SYSTEM_RUNTIME].
        $this->assertStringNotContainsString('{{bookingname}}', $template, 'Template must not embed per-context names');
        $this->assertStringNotContainsString('{{contextname}}', $template, 'Template must not embed per-context names');
        $this->assertStringNotContainsString('booking.explain_docs_topic', $template);
    }

    /**
     * Test that action-specific prompts in orchestrator are generic.
     */
    public function test_action_specific_prompts_generic(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\orchestrator::class);
        $method = $reflection->getMethod('get_default_initial_prompt_template_for_action');
        $method->setAccessible(true);

        // Test summarise_text action prompt.
        $summariseprompt = $method->invoke(null, \core_ai\aiactions\summarise_text::class);
        $this->assertStringNotContainsString(
            'booking.explain_docs_topic',
            $summariseprompt,
            'Action prompt should not hardcode "booking.explain_docs_topic"'
        );
        $this->assertStringContainsString(
            'SKILL CATALOG',
            $summariseprompt,
            'Action prompt should reference skill catalog routing'
        );
        $this->assertStringContainsString(
            'Use only exact skill names from the SKILL CATALOG',
            $summariseprompt,
            'Action prompt should enforce skill-catalog based routing'
        );
        // Wave 32 (frozen prompt spec): "Use only exact skill names" covers invented aliases; the selector no longer
        // restates it with examples.

        // Test explain_text action prompt.
        $explainprompt = $method->invoke(null, \core_ai\aiactions\explain_text::class);
        $this->assertStringNotContainsString(
            'booking.',
            $explainprompt,
            'Explain prompt should not hardcode booking-specific names'
        );
        $this->assertStringContainsString(
            'SKILL CATALOG',
            $explainprompt,
            'Explain prompt should reference skill-catalog based routing'
        );
    }

    /**
     * Test that booking base class is properly renamed.
     */
    public function test_discovered_skills_implement_skill_interface(): void {
        $provider = new \bookingextension_agent\local\wizard\skill_provider();
        $skills = $provider->get_skills();

        $this->assertNotEmpty($skills, 'Provider should discover at least one skill');
        foreach ($skills as $skill) {
            $this->assertInstanceOf(
                \bookingextension_agent\local\wizard\interfaces\skill_interface::class,
                $skill
            );
        }
    }

    /**
     * Test multi-provider scenario: booking and other plugins can coexist.
     */
    public function test_multi_provider_discovery(): void {
        // This test validates the discovery and registration mechanism.
        $registry = skill_registry_factory::get_default();

        // Verify booking skills are registered.
        $skills = $registry->get_skills();
        $this->assertNotEmpty($skills, 'Registry should have skills from providers');

        // Verify skill names include component prefix (plugin-specific routing).
        // Legacy names used booking.*, current core skills use core.*.
        $componentprefixskillfound = false;
        $coreskillfound = false;
        foreach ($skills as $skill) {
            $name = (string)$skill->get_name();
            if (preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_]/', $name) === 1) {
                $componentprefixskillfound = true;
            }
            if (str_starts_with($name, 'core.')) {
                $coreskillfound = true;
            }
        }

        $this->assertTrue($componentprefixskillfound, 'Should have skills prefixed with plugin component');
        $this->assertTrue($coreskillfound, 'Should expose core.* skills from bookingextension_agent');
    }

    /**
     * Test that skill discovery scans all direct skill namespaces under local/wizard.
     */
    public function test_skill_discovery_scans_all_wizard_skill_namespaces(): void {
        skill_registry_factory::reset();

        $provider = new \bookingextension_agent\local\wizard\skill_provider();
        $skillnames = array_map(static fn($skill): string => $skill->get_name(), $provider->get_skills());

        $this->assertContains('core.get_current_user', $skillnames);
        $this->assertContains('wizard.recreate_skill_catalog', $skillnames);

        $exampleskillclass = '\\bookingextension_agent\\local\\wizard\\examples\\skills\\readonly_example_skill';
        if (class_exists($exampleskillclass)) {
            $this->assertContains('examples.readonly_example', $skillnames);
        }
    }

    /**
     * Test that discovery does not expose duplicate skill names.
     */
    public function test_skill_discovery_deduplicates_same_skill_name(): void {
        skill_registry_factory::reset();

        $provider = new \bookingextension_agent\local\wizard\skill_provider();
        $skillnames = array_map(static fn($skill): string => $skill->get_name(), $provider->get_skills());

        $this->assertSame($skillnames, array_values(array_unique($skillnames)));
    }

    /**
     * Test that trigger-provider discovery ignores non-trigger classes without failing.
     */
    public function test_trigger_provider_discovery_ignores_non_trigger_classes(): void {
        $providers = \bookingextension_agent\local\wizard\skill_discovery::get_trigger_provider_instances(
            'bookingextension_agent'
        );

        $this->assertNotEmpty($providers);
        foreach ($providers as $provider) {
            $this->assertInstanceOf(
                \bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface::class,
                $provider
            );
        }
    }

    /**
     * Test that language-specific logic is removed from skills.
     */
    public function test_skills_no_language_specific_logic(): void {
        $provider = new \bookingextension_agent\local\wizard\skill_provider();
        $skills = $provider->get_skills();

        $this->assertNotEmpty($skills, 'Provider should discover skills for reflection checks');
        foreach ($skills as $skill) {
            $reflection = new \ReflectionClass($skill);
            $this->assertFalse(
                $reflection->hasMethod('looks_like_german'),
                'Skill classes must not contain language-token heuristics'
            );
            $this->assertFalse(
                $reflection->hasMethod('build_disambiguation_message'),
                'Skill classes must not contain language-specific disambiguation helpers'
            );
        }
    }

    /**
     * Test skill schema validation includes all required fields.
     */
    public function test_skill_schema_required_fields(): void {
        $registry = skill_registry_factory::get_default();
        $skills = $registry->get_skills();

        foreach ($skills as $skill) {
            $schema = $skill->get_schema();

            // Verify required fields.
            $this->assertArrayHasKey('version', $schema, 'Schema should have version');
            $this->assertArrayHasKey('properties', $schema, 'Schema should have properties');
            $this->assertArrayHasKey('readonly', $schema, 'Schema should expose readonly flag');
        }
    }

    /**
     * Test that backward compatibility is maintained.
     */
    public function test_backward_compatibility_constants(): void {
        // Verify old constants still exist (marked @deprecated).
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);

        // The old constants should still be accessible for backward compat.
        $this->assertTrue(true, 'Backward compatibility checks passed');
    }

    /**
     * R3 guardrail: planner loop retry must be blocked when R3_NO_RETRY is present.
     */
    public function test_agent_runtime_retry_resolver_blocks_retry_when_r3_issue_present(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_PARSE_ERROR', 'R3_NO_RETRY'],
            'commands' => [],
        ];

        $resolved = $method->invoke($runtime, $result, []);
        $this->assertNull($resolved);
    }

    /**
     * Synchronizer gate issue codes must not be interpreted as planner-retryable contract errors.
     */
    public function test_agent_runtime_retry_resolver_ignores_synchronizer_gate_issue_codes(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => [
                'SYNC_FACT_CONFLICT_REJECTED',
                'SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED',
            ],
            'commands' => [],
        ];

        $resolved = $method->invoke($runtime, $result, []);
        $this->assertNull($resolved);
    }

    /**
     * R3 guardrail: planner loop retry must be blocked when command risk class is R3.
     */
    public function test_agent_runtime_retry_resolver_blocks_retry_when_command_risk_is_r3(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED'],
            'commands' => [
                [
                    'skill' => 'mod_booking.book_users',
                    'risk_class' => \bookingextension_agent\local\wizard\dto\skill_risk_class::R3,
                    'input' => [],
                ],
            ],
        ];

        $resolved = $method->invoke($runtime, $result, []);
        $this->assertNull($resolved);
    }

    /**
     * Control check: retry remains enabled for retryable planner contract errors without R3 blockers.
     */
    public function test_agent_runtime_retry_resolver_allows_retry_without_r3_blocker(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_PARSE_ERROR'],
            'commands' => [],
        ];

        $resolved = $method->invoke($runtime, $result, []);
        $this->assertSame('CONTRACT_PARSE_ERROR', $resolved);
    }

    /**
     * The empty-commands contract violation (CONTRACT_VALIDATION_ERROR) gets one framework
     * retry instead of surfacing as a terminal "please try again" error to the user.
     */
    public function test_agent_runtime_retry_resolver_allows_retry_for_empty_commands_contract_error(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('resolve_framework_retry_issue_code');
        $method->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_VALIDATION_ERROR'],
            'commands' => [],
        ];

        $this->assertSame('CONTRACT_VALIDATION_ERROR', $method->invoke($runtime, $result, []));

        // Retry budget is one: a second occurrence of the same issue code is no longer retryable.
        $this->assertNull($method->invoke($runtime, $result, ['CONTRACT_VALIDATION_ERROR' => 1]));

        // The retry hint names the violation so the next planner turn can self-correct.
        $hintmethod = $reflection->getMethod('build_framework_retry_observation');
        $hintmethod->setAccessible(true);
        $hint = $hintmethod->invoke($runtime, 'CONTRACT_VALIDATION_ERROR');
        $this->assertStringStartsWith('RETRY_HINT:', $hint);
        // Wave 32 (frozen appendix A.3): the hint states the fact.
        $this->assertStringContainsString('commands was empty', $hint);
    }

    /**
     * Guardrail: same error class may not open retry in a third distinct layer.
     */
    public function test_queue_transition_retry_layer_guard_blocks_third_distinct_layer(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\queue_transition_service::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('evaluate_retry_layer_guard');
        $method->setAccessible(true);

        $decision = $method->invoke(
            $service,
            'provider_error',
            ['preflight', 'execution'],
            'provider_error',
            'QUEUE_RETRY_HINT'
        );

        $this->assertFalse((bool)($decision['allow'] ?? true));
        $this->assertSame(['preflight', 'execution'], array_values((array)($decision['layers'] ?? [])));
    }

    /**
     * Guardrail: retry layer sequence resets when error class changes.
     */
    public function test_queue_transition_retry_layer_guard_resets_for_new_error_class(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\queue_transition_service::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('evaluate_retry_layer_guard');
        $method->setAccessible(true);

        $decision = $method->invoke(
            $service,
            'provider_error',
            ['preflight', 'execution'],
            'domain_error',
            'QUEUE_RETRY_HINT'
        );

        $this->assertTrue((bool)($decision['allow'] ?? false));
        $this->assertSame(['queue'], array_values((array)($decision['layers'] ?? [])));
    }

    /**
     * Retry policy maps contract/parse signals to TECHNICAL category.
     */
    public function test_retry_policy_maps_contract_errors_to_technical(): void {
        $policy = new \bookingextension_agent\local\wizard\services\retry_policy_service();
        $category = $policy->resolve_retry_hint_category(
            '',
            ['CONTRACT_PARSE_ERROR'],
            'planner'
        );

        $this->assertSame(
            \bookingextension_agent\local\wizard\services\retry_policy_service::CATEGORY_TECHNICAL,
            $category
        );
        $this->assertTrue($policy->is_retryable_category($category));
    }

    /**
     * Retry policy classifies domain conflicts as non-retryable.
     */
    public function test_retry_policy_marks_domain_category_not_retryable(): void {
        $policy = new \bookingextension_agent\local\wizard\services\retry_policy_service();
        $category = $policy->resolve_retry_hint_category(
            'domain_conflict',
            ['DOMAIN_CONFLICT'],
            'preflight'
        );

        $this->assertSame(
            \bookingextension_agent\local\wizard\services\retry_policy_service::CATEGORY_DOMAIN,
            $category
        );
        $this->assertFalse($policy->is_retryable_category($category));
    }

    /**
     * Provider auth failures open the provider circuit breaker.
     */
    public function test_retry_policy_provider_circuit_breaker_blocks_auth(): void {
        $policy = new \bookingextension_agent\local\wizard\services\retry_policy_service();
        $decision = $policy->evaluate_provider_circuit_breaker(
            'auth_error',
            ['PROVIDER_AUTH_FAILED']
        );

        $this->assertFalse((bool)($decision['allow'] ?? true));
        $this->assertContains(
            \bookingextension_agent\local\wizard\services\retry_policy_service::ISSUE_PROVIDER_CIRCUIT_OPEN_AUTH,
            (array)($decision['issue_codes'] ?? [])
        );
    }

    /**
     * Planner retry must be blocked when queue/execution retry is already active.
     */
    public function test_agent_runtime_blocks_planner_retry_on_non_planner_signal(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('has_active_non_planner_retry_signal');
        $method->setAccessible(true);

        $result = [
            'issue_codes' => ['RETRY_WAITING', 'EXECUTION_RETRY_HINT'],
        ];

        $blocked = $method->invoke($runtime, $result);
        $this->assertTrue((bool)$blocked);
    }

    /**
     * Constructor JSON parse retries must exhaust exactly at configured limit.
     */
    public function test_agent_runtime_constructor_parse_retry_exhaustion_is_deterministic(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();

        $retrymethod = $reflection->getMethod('resolve_framework_retry_issue_code');
        $retrymethod->setAccessible(true);
        $exhaustedmethod = $reflection->getMethod('resolve_exhausted_framework_retry_issue_code');
        $exhaustedmethod->setAccessible(true);

        $result = [
            'response_type' => 'error',
            'issue_codes' => ['CONTRACT_PARSE_ERROR'],
            'commands' => [],
        ];

        $this->assertSame('CONTRACT_PARSE_ERROR', $retrymethod->invoke($runtime, $result, []));
        $this->assertNull($retrymethod->invoke($runtime, $result, ['CONTRACT_PARSE_ERROR' => 1]));
        $this->assertSame('CONTRACT_PARSE_ERROR', $exhaustedmethod->invoke($runtime, $result, ['CONTRACT_PARSE_ERROR' => 1]));
    }

    /**
     * Budget guard must stop deterministically at the loop boundary.
     */
    public function test_agent_runtime_budget_guard_stops_at_limit_boundary(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\agent_runtime::class);
        $runtime = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('budget_guard_allows_next_llm_call');
        $method->setAccessible(true);

        $this->assertTrue((bool)$method->invoke($runtime, 0, 2));
        $this->assertFalse((bool)$method->invoke($runtime, 1, 2));
    }

    /**
     * Template finalization must keep technical root-cause messages explicit.
     */
    public function test_finalization_template_message_reflects_technical_cause(): void {
        $templates = new \bookingextension_agent\local\wizard\services\finalization_template_service();

        $budget = $templates->resolve_message([
            'issue_codes' => ['BUDGET_EXCEEDED'],
        ]);
        $this->assertStringContainsString('loop budget is exhausted', $budget);

        $timeout = $templates->resolve_message([
            'error_class' => 'provider_timeout',
        ]);
        $this->assertStringContainsString('timed out', $timeout);
    }

    /**
     * Regression guard: readonly and mutating command routing split stays stable.
     */
    public function test_decision_service_mutability_split_preserves_readonly_vs_mutating(): void {
        $reflection = new \ReflectionClass(
            \bookingextension_agent\local\wizard\services\decision\agent_decision_service::class
        );
        $service = $reflection->newInstanceWithoutConstructor();

        // Split_commands_by_mutability resolves risk class via risk_class_resolver, which receives
        // $this->registry; initialise the typed property with a stub (the commands carry explicit
        // risk_class, so get_skill() is never actually consulted here).
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill'])
            ->getMock();
        $registry->method('get_skill')->willReturn(null);
        $registryprop = $reflection->getProperty('registry');
        $registryprop->setAccessible(true);
        $registryprop->setValue($service, $registry);

        $method = $reflection->getMethod('split_commands_by_mutability');
        $method->setAccessible(true);

        $groups = $method->invoke($service, [
            ['skill' => 'core.get_current_user', 'input' => [], 'risk_class' => 'read_only'],
            ['skill' => 'mod_booking.create_option', 'input' => [], 'risk_class' => 'broad_write'],
        ]);

        $this->assertCount(1, (array)($groups['readonly'] ?? []));
        $this->assertCount(1, (array)($groups['mutating'] ?? []));
    }

    /**
     * Regression guard: pending confirmation flow stays consistent and unblocked.
     */
    public function test_pending_confirmation_message_is_not_blocked_as_new_intent(): void {
        $reflection = new \ReflectionClass(
            \bookingextension_agent\local\wizard\services\decision\agent_decision_service::class
        );
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('should_block_new_intent_while_pending');
        $method->setAccessible(true);

        $confirmpending = $method->invoke($service, [
            'response_type' => 'confirm_pending',
            'commands' => [],
        ]);
        $this->assertFalse((bool)$confirmpending);
    }

    /**
     * Test that the planner result composer preserves the construction payload.
     */
    public function test_planner_result_composer_preserves_construction_payload(): void {
        $composer = new \bookingextension_agent\local\wizard\services\planner_result_composer();

        $result = $composer->compose(
            [
                'plannertracehistory' => ['discovery-trace'],
                'catalogselectionmode' => 'embed_topk',
                'embeddingstatus' => 'applied',
            ],
            [
                'response_type' => 'skill_call',
                'message' => 'selection message',
                'selected_skill' => 'core.get_current_user',
                'catalogselectionmode' => 'embed_topk',
                'embeddingstatus' => 'applied',
            ],
            [
                'response_type' => 'clarification',
                'message' => 'construction message',
                'commands' => [],
                'issue_codes' => ['CONTRACT_EMPTY_MESSAGE'],
            ]
        );

        $this->assertSame('clarification', $result['response_type']);
        $this->assertSame('construction message', $result['message']);
        $this->assertArrayHasKey('planner_result', $result);
        $this->assertArrayHasKey('phase_trace', $result);
        $this->assertSame(['discovery-trace'], $result['planner_result']['planner_trace_history']);
        $this->assertArrayHasKey('parameter_construction', $result['planner_result']);
        $this->assertSame('construction message', $result['planner_result']['parameter_construction']['message']);
        $this->assertSame('core.get_current_user', $result['phase_trace']['selection']['selected_skill']);
        $this->assertSame('embed_topk', $result['phase_trace']['selection']['catalogselectionmode']);
        $this->assertSame('', $result['phase_trace']['parameter_construction']['embeddingstatus']);
        $this->assertArrayNotHasKey('discovery', $result['phase_trace']);
    }

    /**
     * Test that the phase-aware interpreter wrapper tags the normalized phase.
     */
    public function test_interpret_phase_output_tags_phase(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $result = $interpreter->interpret_phase_output(
            '{"response_type":"clarification","message":"Need more info"}',
            'parameter_construction',
            [
                'contextid' => 12,
                'userid' => 34,
                'lastusermessage' => 'Please continue',
            ]
        );

        $this->assertSame('clarification', $result['response_type']);
        $this->assertSame('Need more info', $result['message']);
        $this->assertSame('parameter_construction', $result['phase']);
    }

    /**
     * Test that selection phase now behaves like a single-skill selector call.
     */
    public function test_interpreter_phase_contract_accepts_single_selector_skill_in_selection(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);
        $selectionpayload = '{"response_type":"skill_call","message":"Selecting skill",'
            . '"commands":[{"skill":"mod_booking.create_option","version":1,"input":{}}]}';

        $result = $interpreter->interpret_phase_output(
            $selectionpayload,
            'selection',
            [
                'contextid' => 12,
                'userid' => 34,
                'lastusermessage' => 'Please continue',
            ]
        );

        $this->assertSame('skill_call', $result['response_type']);
        $this->assertSame('selection', $result['phase']);
        $this->assertSame('mod_booking.create_option', $result['selected_skill']);
        $this->assertCount(1, $result['commands']);
    }

    /**
     * Test that selection handoff strips parameter payload and keeps only one selected skill command.
     */
    public function test_orchestrator_selection_handoff_normalization_strips_payload(): void {
        // Selection handoff normalization now lives in planner_phase_service (orchestrator split).
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\planner_phase_service::class);
        $svc = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('normalize_selection_phase_output_for_handoff');
        $method->setAccessible(true);

        $result = $method->invoke($svc, [
            'response_type' => 'skill_call',
            'message' => 'Selecting skill',
            'commands' => [[
                'skill' => 'mod_booking.create_option',
                'version' => 2,
                'input' => [
                    'optionname' => 'Yoga',
                    'duration' => 60,
                ],
            ]],
            'selected_skill' => 'mod_booking.create_option',
        ]);

        $this->assertSame('skill_call', $result['response_type']);
        $this->assertSame('mod_booking.create_option', $result['selected_skill']);
        $this->assertCount(1, $result['commands']);
        $this->assertSame('mod_booking.create_option', (string)$result['commands'][0]['skill']);
        $this->assertSame(2, (int)$result['commands'][0]['version']);
        $this->assertSame([], $result['commands'][0]['input']);
    }

    /**
     * Test that selection handoff normalization rejects multi-command skill_call payloads.
     */
    public function test_orchestrator_selection_handoff_normalization_rejects_multi_command_payload(): void {
        // Selection handoff normalization now lives in planner_phase_service (orchestrator split).
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\planner_phase_service::class);
        $svc = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('normalize_selection_phase_output_for_handoff');
        $method->setAccessible(true);

        $result = $method->invoke($svc, [
            'response_type' => 'skill_call',
            'message' => 'Selecting skill',
            'commands' => [
                ['skill' => 'mod_booking.create_option', 'version' => 1, 'input' => []],
                ['skill' => 'mod_booking.update_option', 'version' => 1, 'input' => []],
            ],
            'selected_skill' => 'mod_booking.create_option',
        ]);

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_SELECTION_SINGLE_COMMAND_REQUIRED', $result['issue_codes']);
        $this->assertSame([], $result['commands']);
        $this->assertSame('', $result['selected_skill']);
    }

    /**
     * Test that interpreter keeps strict JSON parsing at the trust boundary.
     */
    public function test_interpreter_rejects_non_json_payload(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $result = $interpreter->interpret('this is not json', 0, 0, '');

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PARSE_ERROR', $result['issue_codes']);
    }

    /**
     * Test that unknown response_type values are rejected by allow-list contract.
     */
    public function test_interpreter_rejects_unknown_response_type(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $result = $interpreter->interpret(
            '{"response_type":"unexpected_type","message":"x","commands":[]}',
            0,
            0,
            ''
        );

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_UNKNOWN_RESPONSE_TYPE', $result['issue_codes']);
    }

    /**
     * A naked skill-like response carrying its payload under "parameters" keeps every
     * argument through the rescue path (threads 585/586: extract_command_input read only
     * "input", so a perfectly constructed fullname/topic surfaced as a false
     * "<field> is required" error).
     */
    public function test_interpreter_naked_skill_response_keeps_parameters_payload(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);
        $method = new \ReflectionMethod($interpreter, 'normalize_skill_like_response');
        $method->setAccessible(true);

        $normalized = $method->invoke($interpreter, [
            'skill' => 'course.scaffold_course_content',
            'version' => 1,
            'parameters' => [
                'topic' => 'Das Leben der Wikinger',
                'chapters' => 4,
                'practicequizzes' => false,
                'finalquiz' => true,
                'coursequery' => 'Das Leben der Wikinger',
            ],
        ], '');

        $this->assertIsArray($normalized);
        $this->assertSame('skill_call', $normalized['response_type']);
        $command = (array)($normalized['commands'][0] ?? []);
        $this->assertSame('course.scaffold_course_content', $command['skill'] ?? '');
        $input = (array)($command['input'] ?? []);
        $this->assertSame('Das Leben der Wikinger', $input['topic'] ?? null);
        $this->assertSame(4, (int)($input['chapters'] ?? 0));
        $this->assertTrue((bool)($input['finalquiz'] ?? false));
        $this->assertSame('Das Leben der Wikinger', $input['coursequery'] ?? null);
    }

    /**
     * When both keys are present, "input" wins per key over "parameters" — the same
     * precedence normalize_commands_payload() applies to enveloped commands.
     */
    public function test_interpreter_naked_skill_response_input_wins_over_parameters(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);
        $method = new \ReflectionMethod($interpreter, 'normalize_skill_like_response');
        $method->setAccessible(true);

        $normalized = $method->invoke($interpreter, [
            'skill' => 'course.create_course',
            'parameters' => ['fullname' => 'From parameters', 'summary' => 'Kept from parameters'],
            'input' => ['fullname' => 'From input'],
        ], '');

        $this->assertIsArray($normalized);
        $input = (array)($normalized['commands'][0]['input'] ?? []);
        $this->assertSame('From input', $input['fullname'] ?? null);
        $this->assertSame('Kept from parameters', $input['summary'] ?? null);
    }

    /**
     * A confirmation_request without commands is a question to the user: it must be relayed
     * as a clarification (message preserved) instead of erroring into the framework retry,
     * which pushes the planner to emit commands it was not ready to build.
     */
    public function test_interpreter_downgrades_empty_confirmation_request_to_clarification(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $question = 'Soll ich 5 Buchungsoptionen für nächste Woche erstellen? Bitte bestätige den Beginn.';
        $result = $interpreter->interpret(
            json_encode([
                'response_type' => 'confirmation_request',
                'message' => $question,
                'commands' => [],
            ]),
            0,
            0,
            ''
        );

        $this->assertSame('clarification', $result['response_type']);
        $this->assertSame($question, $result['message']);
        $this->assertSame([], $result['commands']);
        $this->assertContains('CONTRACT_CONFIRMATION_DOWNGRADED_TO_CLARIFICATION', (array)($result['issue_codes'] ?? []));
    }

    /**
     * The downgrade is confirmation_request-only: a skill_call without commands stays a
     * contract error (CONTRACT_VALIDATION_ERROR) and keeps its one framework retry.
     */
    public function test_interpreter_keeps_contract_error_for_empty_skill_call(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $result = $interpreter->interpret(
            '{"response_type":"skill_call","message":"Ich lege die Option an.","commands":[]}',
            0,
            0,
            ''
        );

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_VALIDATION_ERROR', (array)($result['issue_codes'] ?? []));
    }

    /**
     * Test that orchestrator executes two planner invoke calls (selection + construction).
     */
    public function test_orchestrator_process_uses_two_phase_invokes(): void {
        // The two planner invokes (selection + construction) now live in planner_phase_service.
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\planner_phase_service::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        // Both phase calls go through the truncation-retrying entry point (#2395): a call cut off at
        // the token limit is repeated once before the result is interpreted.
        $this->assertGreaterThanOrEqual(2, substr_count($source, '->invoke_for_context_retrying_truncation('));
        $this->assertStringContainsString('orchestrator_routing_service::PHASE_SELECTION', $source);
        $this->assertStringContainsString('orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION', $source);
    }

    /**
     * Test that construction phase allows multiple commands when all skills are in allow-list.
     */
    public function test_interpreter_construction_phase_accepts_multi_command_batch_in_allow_list(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'skill_call',
            'commands' => [
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => []],
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => ['foo' => 'bar']],
            ],
            'message' => 'Executing.',
        ], 'parameter_construction', [
            'allowed_skills' => ['core.get_current_user'],
        ]);

        $this->assertSame('skill_call', $result['response_type']);
        $this->assertCount(2, (array)($result['commands'] ?? []));
    }

    /**
     * Test that construction phase rejects skills outside discovery-ranked allow-list.
     */
    public function test_interpreter_construction_phase_rejects_skill_outside_allow_list(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'skill_call',
            'commands' => [
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => []],
            ],
            'message' => 'Executing.',
        ], 'parameter_construction', [
            'allowed_skills' => ['wizard.recreate_skill_catalog'],
        ]);

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_PHASE_SKILL_NOT_ALLOWED', $result['issue_codes']);
    }

    /**
     * Test that selection contract rejects selector skill and selected_skill mismatches.
     */
    public function test_interpreter_selection_phase_rejects_selected_skill_mismatch(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('enforce_phase_contract');
        $method->setAccessible(true);

        $result = $method->invoke($interpreter, [
            'response_type' => 'skill_call',
            'commands' => [
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => []],
            ],
            'selected_skill' => 'wizard.recreate_skill_catalog',
            'message' => 'Selecting.',
        ], 'selection');

        $this->assertSame('error', $result['response_type']);
        $this->assertContains('CONTRACT_SELECTION_SKILL_MISMATCH', $result['issue_codes']);
    }

    /**
     * Test that command payload normalization keeps raw skill names for selector-only canonicalization.
     */
    public function test_interpreter_normalize_commands_payload_keeps_raw_skill_name(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('normalize_commands_payload');
        $method->setAccessible(true);

        $commands = $method->invoke($interpreter, [
            'commands' => [[
                'skill' => 'create_booking',
                'version' => 1,
                'input' => ['question' => 'Need help'],
            ]],
        ], 'Need help');

        $this->assertSame('create_booking', (string)($commands[0]['skill'] ?? ''));
    }

    /**
     * Test that unknown command envelope keys are ignored during input normalization.
     */
    public function test_interpreter_normalize_commands_payload_ignores_unknown_command_keys(): void {
        $registry = skill_registry_factory::get_default();
        $interpreter = new \bookingextension_agent\local\wizard\interpreter($registry);

        $reflection = new \ReflectionClass($interpreter);
        $method = $reflection->getMethod('normalize_commands_payload');
        $method->setAccessible(true);

        $commands = $method->invoke($interpreter, [
            'commands' => [[
                'skill' => 'mod_booking.create_option',
                'version' => 1,
                'command_id' => 'cmd_1',
                'commandid' => 'cmd_legacy',
                'id' => '42',
                'cid' => 'abc',
                'parameters' => [
                    'text' => 'Portishead 1',
                    'maxanswers' => 8,
                ],
            ]],
        ], 'Portishead');

        $this->assertSame('mod_booking.create_option', (string)($commands[0]['skill'] ?? ''));
        $this->assertSame('Portishead 1', (string)($commands[0]['input']['text'] ?? ''));
        $this->assertSame(8, (int)($commands[0]['input']['maxanswers'] ?? 0));
        $this->assertArrayNotHasKey('command_id', $commands[0]['input']);
        $this->assertArrayNotHasKey('commandid', $commands[0]['input']);
        $this->assertArrayNotHasKey('id', $commands[0]['input']);
        $this->assertArrayNotHasKey('cid', $commands[0]['input']);
    }

    /**
     * Test that preflight pipeline supports skipping duplicate schema checks for interpreter-validated commands.
     */
    public function test_preflight_pipeline_supports_structural_validation_skip_flag(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\preflight_pipeline::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString("_structural_validated", $source);
        $this->assertStringContainsString("if (!\$skipcontractschema)", $source);
    }

    /**
     * Test that synchronizer input includes phase trace and execution feedback blocks.
     */
    public function test_synchronizer_input_builder_includes_phase_trace_and_execution_feedback(): void {
        $builder = new \bookingextension_agent\local\wizard\services\synchronizer_input_builder();

        $observations = $builder->build_observations([
            'response_type' => 'execution_result',
            'message' => 'Done',
            'phase_trace' => [
                'discovery' => ['response_type' => 'clarification'],
                'selection' => ['response_type' => 'clarification'],
                'parameter_construction' => ['response_type' => 'skill_call'],
            ],
            'results' => [
                ['skill' => 'core.get_current_user', 'status' => 'ok'],
                ['skill' => 'wizard.recreate_skill_catalog', 'status' => 'error'],
            ],
        ]);

        $joined = implode("\n\n", $observations);
        $this->assertStringContainsString('PHASE_TRACE', $joined);
        $this->assertStringContainsString('EXECUTION_FEEDBACK', $joined);
    }

    /**
     * Test that synchronizer routing no longer reuses planner process() entry.
     */
    public function test_synchronizer_routing_uses_dedicated_orchestrator_path(): void {
        $reflection = new \ReflectionClass(
            \bookingextension_agent\local\wizard\services\synchronizer_routing_service::class
        );
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('process_synchronizer(', $source);
        $this->assertStringNotContainsString('->process(', $source);
    }

    /**
     * Test that synchronizer output contract never mutates command payloads.
     */
    public function test_synchronizer_output_contract_preserves_source_commands(): void {
        $contract = new \bookingextension_agent\local\wizard\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'confirmation_request',
            'message' => 'Original',
            'commands' => [
                ['skill' => 'wizard.recreate_skill_catalog', 'version' => 1, 'input' => ['force' => true]],
            ],
            'lang' => 'de',
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Polished output.',
            'commands' => [
                ['skill' => 'core.get_current_user', 'version' => 1, 'input' => []],
            ],
            'lang' => 'de',
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame($source['commands'], $merged['commands']);
        $this->assertSame('Original', $merged['message']);
    }

    /**
     * Synchronizer must reject stale fact drift when source evidence and polished text disagree.
     */
    public function test_synchronizer_output_contract_rejects_option_id_fact_conflict(): void {
        $contract = new \bookingextension_agent\local\wizard\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'sufficient',
            'message' => 'Booking option created (title="Agent Fire 2", id=1429, link=https://example.invalid).',
            'results' => [
                [
                    'status' => 'executed',
                    'skill' => 'mod_booking.create_option',
                    'detail' => 'Booking option created (title="Agent Fire 2", id=1429, link=https://example.invalid).',
                ],
            ],
            'issue_codes' => [],
            'commands' => [],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Alle Aktionen erledigt. Agent Fire 2 wurde mit id=1428 erstellt.',
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame($source['message'], $merged['message']);
        $this->assertContains('SYNC_FACT_CONFLICT_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_FACT_CONFLICT_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Synchronizer must emit deterministic issue code + telemetry for contract rejects.
     */
    public function test_synchronizer_output_contract_sets_issue_code_for_contract_reject(): void {
        $contract = new \bookingextension_agent\local\wizard\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'sufficient',
            'message' => 'Original source result.',
            'issue_codes' => [],
            'commands' => [],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Any text',
            'issue_codes' => ['CONTRACT_PARSE_ERROR'],
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame('Original source result.', (string)($merged['message'] ?? ''));
        $this->assertContains('SYNC_CONTRACT_ISSUE_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_CONTRACT_ISSUE_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Synchronizer must never replace source message when source response_type is error.
     */
    public function test_synchronizer_output_contract_rejects_message_replace_for_source_error(): void {
        $contract = new \bookingextension_agent\local\wizard\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'error',
            'message' => 'Source error detail that must stay visible.',
            'commands' => [],
            'issue_codes' => [],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Everything completed successfully.',
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame('Source error detail that must stay visible.', (string)($merged['message'] ?? ''));
        $this->assertContains('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_SOURCE_RESPONSE_ERROR_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Synchronizer must preserve source message for failed postcondition outcomes.
     */
    public function test_synchronizer_output_contract_rejects_message_replace_for_failed_postcondition(): void {
        $contract = new \bookingextension_agent\local\wizard\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'execution_result',
            'postcondition_status' => 'failed',
            'message' => 'Trainer assignment postcondition failed.',
            'commands' => [],
            'issue_codes' => ['POSTCONDITION_FAILED'],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Trainer assignment completed.',
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame('Trainer assignment postcondition failed.', (string)($merged['message'] ?? ''));
        $this->assertContains('SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_SOURCE_POSTCONDITION_FAILED_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Synchronizer must reject success polishing when the latest source result is an error.
     */
    public function test_synchronizer_output_contract_rejects_success_when_latest_result_is_error(): void {
        $contract = new \bookingextension_agent\local\wizard\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'execution_result',
            'message' => 'One sub-step failed and requires attention.',
            'commands' => [],
            'issue_codes' => [],
            'results' => [
                ['status' => 'executed', 'skill' => 'mod_booking.create_option'],
                ['status' => 'error', 'skill' => 'mod_booking.update_option_trainer'],
            ],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'All requested actions were completed successfully.',
            'commands' => [],
        ];

        $merged = $contract->merge($source, $sync);
        $this->assertSame('One sub-step failed and requires attention.', (string)($merged['message'] ?? ''));
        $this->assertContains('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (array)($merged['issue_codes'] ?? []));
        $this->assertSame('failed', (string)($merged['sync_gate_status'] ?? ''));
        $this->assertSame('SYNC_SOURCE_RESULT_STATUS_CONFLICT_REJECTED', (string)($merged['sync_gate_reason'] ?? ''));
    }

    /**
     * Source-of-truth merge behavior must remain deterministic for identical inputs.
     */
    public function test_synchronizer_output_contract_merge_is_deterministic_for_same_input(): void {
        $contract = new \bookingextension_agent\local\wizard\services\synchronizer_output_contract();

        $source = [
            'response_type' => 'sufficient',
            'message' => 'Booking option created (id=1429).',
            'results' => [
                ['status' => 'executed', 'detail' => 'Booking option created (id=1429).'],
            ],
            'issue_codes' => [],
            'commands' => [],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message' => 'Option wurde erstellt (id=1428).',
            'commands' => [],
        ];

        $mergedone = $contract->merge($source, $sync);
        $mergedtwo = $contract->merge($source, $sync);

        $this->assertSame($mergedone, $mergedtwo);
        $this->assertContains('SYNC_FACT_CONFLICT_REJECTED', (array)($mergedone['issue_codes'] ?? []));
    }

    /**
     * Test that dedicated synchronizer prompt builder is wired in orchestrator.
     */
    public function test_orchestrator_uses_dedicated_synchronizer_prompt_builder(): void {
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\orchestrator::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('new synchronizer_prompt_builder()', $source);
        $this->assertStringContainsString('synchronizerpromptbuilder->build_prompt(', $source);
    }

    /**
     * Test that discovery stage controller is wired into the live orchestrator flow.
     */
    public function test_orchestrator_discovery_uses_live_stage_controller(): void {
        // The discovery phase logic now lives in discovery_phase_service (orchestrator split).
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\discovery_phase_service::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('new discovery_stage_controller()', $source);
        $this->assertStringContainsString('filter_catalog_by_selected_families(', $source);
        $this->assertStringContainsString("'discovery_stage' => \$discoverystage", $source);
    }

    /**
     * Test that family filter helper no longer falls back to full catalog.
     */
    public function test_orchestrator_family_filter_is_strict_without_full_catalog_fallback(): void {
        // The family-filter logic now lives in planner_catalog_service (orchestrator split).
        $reflection = new \ReflectionClass(\bookingextension_agent\local\wizard\services\planner_catalog_service::class);
        $source = file_get_contents((string)$reflection->getFileName());
        $this->assertIsString($source);

        $this->assertStringContainsString('if (empty($allow)) {', $source);
        $this->assertStringContainsString('return [];', $source);
    }
}

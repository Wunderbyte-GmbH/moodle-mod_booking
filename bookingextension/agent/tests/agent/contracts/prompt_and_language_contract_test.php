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
use bookingextension_agent\local\wizard\services\language_policy_service;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for explicit prompt contracts and language policy.
 *
 * @covers \bookingextension_agent\local\wizard\skill_registry
 * @covers \bookingextension_agent\local\wizard\services\language_policy_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_and_language_contract_test extends TestCase {
    /**
     * Ensure prompt contracts are explicit and no longer inferred from skill naming conventions.
     */
    public function test_prompt_contracts_do_not_use_name_based_heuristics(): void {
        $registry = new skill_registry();

        $skill = $this->createMock(skill_interface::class);
        $skill->method('get_name')->willReturn('dummy.create_dummy');
        $skill->method('get_schema')->willReturn([
            'description' => 'Dummy skill for explicit prompt-contract tests.',
            'readonly' => false,
            'version' => 1,
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Query text.',
                ],
            ],
            'required' => [],
        ]);
        $skill->method('is_read_only')->willReturn(false);
        $skill->method('get_risk_class')->willReturn(skill_risk_class::R2);
        $skill->method('get_example_input')->willReturn([]);
        $skill->method('get_prompt_contract')->willReturn(new skill_prompt_contract([
            'intent' => '',
            'anchors' => [],
            'minimal_input' => [],
            'example_input' => [],
            'namespace' => 'dummy',
            'version' => 1,
            'capabilities' => [],
            'context_scopes' => ['module'],
            'risk_class' => skill_risk_class::R2,
        ]));

        $provider = $this->createMock(skill_provider_interface::class);
        $provider->method('get_component')->willReturn('local_dummy');
        $provider->method('get_skills')->willReturn([$skill]);
        $provider->method('get_contextual_prompt_packs')->willReturn([]);
        $provider->method('get_issue_code_provider')->willReturn(null);
        $provider->method('get_prompt_guidance')->willReturn([]);

        $registry->register($provider);
        $contracts = $registry->get_all_prompt_contracts();

        $this->assertCount(1, $contracts);
        $contract = $contracts[0];
        $this->assertSame('dummy.create_dummy', $contract['skill']);
        $this->assertSame('skill', $contract['intent']);
        $this->assertSame([], $contract['minimal_input']);
        $this->assertSame([], $contract['anchors']);
        $this->assertSame(skill_risk_class::R2, $contract['risk_class']);
    }

    /**
     * The selector-emitted user_lang is the top language authority and wins over the model `lang` hint.
     */
    public function test_language_policy_prefers_selector_user_lang(): void {
        $service = new language_policy_service();

        $resolved = $service->resolve_output_language([
            'user_lang' => 'de',
            'lang' => 'it',
        ]);

        $this->assertSame('de', $resolved);
    }

    /**
     * Ensure fallback string mapping remains deterministic.
     */
    public function test_language_policy_fallback_string_mapping(): void {
        $service = new language_policy_service();

        $this->assertSame('ai_fallback_error', $service->fallback_string_id_for_response_type('error'));
        $this->assertSame(
            'ai_fallback_confirmation_request',
            $service->fallback_string_id_for_response_type('confirmation_request')
        );
        $this->assertSame('ai_fallback_skill_call', $service->fallback_string_id_for_response_type('skill_call'));
        $this->assertSame('ai_fallback_summary', $service->fallback_string_id_for_response_type('clarification'));
        $this->assertSame('ai_preflight_retry_hint', $service->preflight_retry_hint_string_id());
    }

    /**
     * Policy order: selector user_lang -> model lang -> current UI language -> technical fallback (en),
     * with each candidate normalized to a 2-letter ISO code.
     */
    public function test_language_policy_order_and_normalization(): void {
        $service = new language_policy_service();

        // 1) user_lang present -> wins, even against a model `lang` hint.
        $this->assertSame('de', $service->resolve_output_language(['user_lang' => 'de', 'lang' => 'it']));
        // 2) user_lang blank -> falls through to the model `lang` hint.
        $this->assertSame('it', $service->resolve_output_language(['user_lang' => '', 'lang' => 'it']));
        // 3) neither present -> current UI language ('en' in the test runner).
        $this->assertSame(current_language(), $service->resolve_output_language([]));
        // Locale-ish values are reduced to their 2-letter code...
        $this->assertSame('zh', $service->resolve_output_language(['user_lang' => 'ZH-CN', 'lang' => 'it']));
        // ...and non-ISO junk is dropped so the next candidate wins.
        $this->assertSame('it', $service->resolve_output_language(['user_lang' => '!!', 'lang' => 'it']));
    }
}

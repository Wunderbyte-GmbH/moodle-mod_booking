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

/**
 * Error messaging v2 — deterministic contract tests (no LLM).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\finalization_classifier;
use bookingextension_agent\local\wizard\services\finalization_template_service;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;

/**
 * The provider catch-all must never masquerade as the message for other causes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\finalization_template_service
 * @covers     \bookingextension_agent\local\wizard\services\finalization_classifier
 * @covers     \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 */
final class ai_error_messaging_test extends \advanced_testcase {
    /**
     * Template fallback resolves a class-specific message per error class.
     */
    public function test_template_resolves_class_specific_messages(): void {
        $this->resetAfterTest();
        $svc = new finalization_template_service();

        $cases = [
            'quota_exceeded' => 'quota',
            'provider_timeout' => 'timed out',
            'transient_io' => 'connection problem',
            'internal_contract' => 'internal planning error',
            'internal_status' => 'checking the AI status',
            'skill_exception' => 'internal error',
            'provider_error' => 'AI provider returned an error',
        ];

        foreach ($cases as $class => $needle) {
            $message = $svc->resolve_message([
                'response_type' => 'error',
                'error_class' => $class,
                'errors' => ['raw technical detail'],
            ]);
            $this->assertStringContainsStringIgnoringCase($needle, $message, "class {$class}");
        }
    }

    /**
     * Raw error details are an admin-only diagnostic channel.
     */
    public function test_raw_details_only_for_admins(): void {
        $this->resetAfterTest();
        $svc = new finalization_template_service();
        $payload = [
            'response_type' => 'error',
            'error_class' => 'quota_exceeded',
            'errors' => ['raw-detail-marker-123'],
        ];

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertStringNotContainsString('raw-detail-marker-123', $svc->resolve_message($payload));

        $this->setAdminUser();
        $this->assertStringContainsString('raw-detail-marker-123', $svc->resolve_message($payload));
    }

    /**
     * Routing: provider-dead classes go to the deterministic template, internal
     * planning and skill exceptions go to the synchronizer (error presentation).
     */
    public function test_classifier_routes_error_classes(): void {
        $classifier = new finalization_classifier();

        $templateclasses = ['provider_error', 'provider_unreachable', 'quota_exceeded', 'provider_timeout', 'transient_io',
            'internal_status'];
        foreach ($templateclasses as $class) {
            $this->assertSame(
                finalization_classifier::STRATEGY_TEMPLATE_ONLY,
                $classifier->classify(['response_type' => 'error', 'error_class' => $class]),
                "class {$class} must be template-only"
            );
        }

        foreach (['internal_contract', 'skill_exception'] as $class) {
            $this->assertSame(
                finalization_classifier::STRATEGY_LLM_POLISH,
                $classifier->classify(['response_type' => 'error', 'error_class' => $class]),
                "class {$class} must be synchronizer-presented"
            );
        }
    }

    /**
     * The synchronizer input carries a structured [ERROR] observation with the
     * real causes and the anti-catchall presentation rules.
     */
    public function test_error_observation_carries_causes_and_rules(): void {
        $builder = new synchronizer_input_builder();
        $observations = $builder->build_observations([
            'response_type' => 'error',
            'error_class' => 'skill_exception',
            'issue_codes' => ['SOME_CODE'],
            'errors' => ['Invalid course module ID'],
            'results' => [[
                'status' => 'error',
                'detail' => 'Ungueltige Kursmodul-ID',
            ]],
        ]);

        $errorblock = '';
        foreach ($observations as $observation) {
            if (str_starts_with((string)$observation, '[ERROR]')) {
                $errorblock = (string)$observation;
            }
        }

        $this->assertNotSame('', $errorblock, 'error observation missing');
        $this->assertStringContainsString('error_class: skill_exception', $errorblock);
        $this->assertStringContainsString('Invalid course module ID', $errorblock);
        $this->assertStringContainsString('Ungueltige Kursmodul-ID', $errorblock);
        $this->assertStringContainsString('Do NOT blame the AI provider', $errorblock);
        $this->assertStringContainsString('Do NOT claim the request succeeded', $errorblock);
    }

    /**
     * CI guard: the provider catch-all may only be referenced by the template
     * fallback (its one honest use). Every new reference is a regression.
     */
    public function test_provider_catchall_only_in_template_fallback(): void {
        $root = dirname(__DIR__) . '/classes';
        $allowed = ['/local/wizard/services/finalization_template_service.php'];

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = (string)file_get_contents($file->getPathname());
            if (!preg_match("/['\"]ai_provider_error['\"]/", $content)) {
                continue;
            }
            $relative = str_replace($root, '', $file->getPathname());
            if (!in_array($relative, array_map(static fn($a) => $a, $allowed), true)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'ai_provider_error referenced outside the template fallback');
    }
}

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

use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\services\construction\parameter_contract_validator;
use bookingextension_agent\local\wizard\services\decision\agent_decision_service;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;
use bookingextension_agent\local\wizard\services\synchronizer_output_contract;
use PHPUnit\Framework\TestCase;

/**
 * F3 two-channel cause contract: user_cause vs. repair, origin-based guard rules,
 * envelope sanitization (design: Blueprints/f3_error_cause_channels_design_2026-07-11.md).
 *
 * @covers \bookingextension_agent\local\wizard\services\construction\parameter_contract_validator
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_output_contract
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class f3_error_cause_channels_test extends TestCase {
    /**
     * A migrated check_structure (repair key present) yields label-free user_cause errors
     * and labelled repair hints; a legacy one keeps the historical labelled errors.
     */
    public function test_validator_splits_channels_and_keeps_legacy_behaviour(): void {
        $validator = new parameter_contract_validator();

        $migrated = $validator->validate($this->skill_with_structure([
            'valid' => false,
            'errors' => ['Für welches Thema soll der Kursinhalt erstellt werden?'],
            'repair' => ['topic is required: pass the user\'s content topic verbatim.'],
            'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
        ]), [], 'Command #1');

        $this->assertFalse($migrated->valid);
        $this->assertSame(['Für welches Thema soll der Kursinhalt erstellt werden?'], $migrated->errors);
        $this->assertSame(['Command #1: topic is required: pass the user\'s content topic verbatim.'], $migrated->repair);
        $this->assertSame(['RECOVERABLE_INPUT_ERROR'], $migrated->issuecodes);

        $legacy = $validator->validate($this->skill_with_structure([
            'valid' => false,
            'errors' => ['fullname is required: the course needs a name.'],
        ]), [], 'Command #1');

        $this->assertSame(['Command #1: fullname is required: the course needs a name.'], $legacy->errors);
        $this->assertSame([], $legacy->repair);
    }

    /**
     * The [ERROR] causes block carries the user_cause channel only: "Command #N:" labels
     * are stripped, failed rows prefer usermessage over detail, repair hints never appear.
     */
    public function test_error_observation_causes_use_user_channel_only(): void {
        $builder = new synchronizer_input_builder();

        $observations = $builder->build_observations([
            'response_type' => 'error',
            'message' => 'x',
            'errors' => ['Command #1: Für welches Thema soll der Kursinhalt erstellt werden?'],
            'repair_hints' => ['Command #1: topic is required: pass the user\'s content topic verbatim.'],
            'results' => [[
                'status' => 'error',
                'detail' => 'Create option schema mismatch. Unknown properties: coursequery.',
                'usermessage' => 'Die Option konnte nicht erstellt werden.',
            ]],
        ], null);

        $errorblock = '';
        foreach ($observations as $observation) {
            if (str_contains((string)$observation, '[ERROR]') || str_contains((string)$observation, 'causes:')) {
                $errorblock = (string)$observation;
                break;
            }
        }

        $this->assertNotSame('', $errorblock, 'An [ERROR] observation must exist for an error result.');
        $this->assertStringContainsString('Für welches Thema soll der Kursinhalt erstellt werden?', $errorblock);
        $this->assertStringNotContainsString('Command #1:', $errorblock);
        $this->assertStringNotContainsString('pass the user\'s content topic verbatim', $errorblock);
        $this->assertStringContainsString('Die Option konnte nicht erstellt werden.', $errorblock);
        $this->assertStringNotContainsString('Unknown properties', $errorblock);
    }

    /**
     * 589 regression: on a clarification source, a sync reply with an error ENVELOPE keeps
     * its (better) message — the envelope is sanitized instead of the message discarded.
     */
    public function test_envelope_sanitization_keeps_sync_message_on_clarification_source(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'clarification',
            'message' => 'Command #1: Ich konnte keine passende Aktivität für diese Aktion finden.',
            'commands' => [],
            'issue_codes' => ['CONTEXT_TARGET_UNRESOLVED'],
            'results' => [],
        ];
        $sync = [
            'response_type' => 'error',
            'message' => 'Welchen Kurs soll ich mit Inhalten füllen? Bitte nenne den Kursnamen.',
            'commands' => [['skill' => 'x', 'input' => []]],
        ];

        $result = $contract->merge($source, $sync);

        $this->assertSame('clarification', $result['response_type'], 'Source semantics must never change.');
        $this->assertSame($sync['message'], $result['message']);
        $this->assertSame([], $result['commands']);
        $this->assertSame('SYNC_ENVELOPE_SANITIZED', $result['sync_gate_reason'] ?? '');
    }

    /**
     * The sanitization is scoped: on a SUFFICIENT source a sync error envelope stays a real
     * conflict and rejects exactly as before (Thread-58 family untouched).
     */
    public function test_error_envelope_on_sufficient_source_still_rejects(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'message' => 'Done.',
            'commands' => [],
            'issue_codes' => [],
            'results' => [['status' => 'executed', 'detail' => 'ok']],
        ];
        $sync = ['response_type' => 'error', 'message' => 'Something else entirely.'];

        $result = $contract->merge($source, $sync);

        $this->assertContains('SYNC_RESPONSE_TYPE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
        $this->assertSame('Done.', $result['message']);
    }

    /**
     * Review point 6a (thread 586): the preflight clarification wording prefers each
     * issue's user_question over its message and never carries the "Command #N:" label.
     */
    public function test_decision_user_cause_prefers_user_question(): void {
        $reflection = new \ReflectionClass(agent_decision_service::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('compose_user_cause_from_issues');
        $method->setAccessible(true);

        $message = $method->invoke($service, [
            [
                'code' => 'SCAFFOLD_COURSE_NOT_EMPTY_CONFIRM_REQUIRED',
                'severity' => 'needs_confirmation',
                'message' => 'Command #1: The course "X" already contains 1 activity(ies).',
                'user_question' => 'Dieser Kurs ist nicht leer. Sollen die Inhalte trotzdem dort erstellt werden?',
            ],
        ], ['Command #1: fallback error']);

        $this->assertSame(
            'Dieser Kurs ist nicht leer. Sollen die Inhalte trotzdem dort erstellt werden?',
            $message
        );

        $fallback = $method->invoke($service, [], ['Command #1: fallback error']);
        $this->assertSame('fallback error', $fallback);
    }

    /**
     * Build a minimal skill mock whose check_structure returns the given array.
     *
     * @param array $structure
     * @return skill_interface
     */
    private function skill_with_structure(array $structure): skill_interface {
        $skill = $this->createMock(skill_interface::class);
        $skill->method('check_structure')->willReturn($structure);
        return $skill;
    }
}

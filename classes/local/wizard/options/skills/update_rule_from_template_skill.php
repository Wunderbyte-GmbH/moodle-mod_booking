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

namespace mod_booking\local\wizard\options\skills;

use mod_booking\local\wizard\engine\skill_risk_class;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for booking.update_rule_from_template.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_rule_from_template_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.update_rule_from_template';

    /** @var object|null */
    private ?object $ruleservice = null;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2, ['mod/booking:editbookingrules']);
        $this->ruleservice = $this->resolve_rule_service();
    }

    /**
     * Resolve optional rules service without breaking task discovery.
     *
     * @return object|null
     */
    private function resolve_rule_service(): ?object {
        $candidates = [
            '\\mod_booking\\local\\wizard\\booking\\support\\booking_rules_agent_service',
        ];

        foreach ($candidates as $classname) {
            if (!class_exists($classname)) {
                continue;
            }
            try {
                return new $classname();
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Human-readable preview of the rule update (tier-3): target + changed fields.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return rule_preview_builder::update_descriptor($input);
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Update an existing booking rule in the current booking context, '
                . 'optionally by reapplying a template first. Use this for natural-language requests '
                . 'like "rename this reminder rule", "disable that confirmation rule", '
                . 'or "change the template for this rule".',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_update_option',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_update_option',
            'example_utterances' => [
                'Rename the existing reminder rule',
                'Turn off the confirmation rule on this booking',
                'Change the timing of the reminder that already exists',
                'Edit the message text of my cancellation rule',
                'Disable that notification rule for now',
            ],
            'properties' => [
                'ruleid' => [
                    'type' => 'integer',
                    'description' => 'Target booking rule id.',
                    'required' => false,
                ],
                'rulequery' => [
                    'type' => 'string',
                    'description' => 'Rule name fragment when ruleid is unknown.',
                    'required' => false,
                ],
                'templateid' => [
                    'type' => 'integer',
                    'description' => 'Optional template id to reapply before saving (negative id for built-ins).',
                    'required' => false,
                ],
                'templatequery' => [
                    'type' => 'string',
                    'description' => 'Optional template search text if templateid is unknown.',
                    'required' => false,
                ],
                'rulename' => [
                    'type' => 'string',
                    'description' => 'Optional new display name for the rule.',
                    'required' => false,
                ],
                'isactive' => [
                    'type' => 'boolean',
                    'description' => 'Optional active flag override.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for user-facing wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.update_rule_from_template',
                'description' => 'User asks to modify an existing booking rule by id/name, optionally based on a template.',
            ],
        ];
    }

    /**
     * Structural validation.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        $hastargetid = !empty($input['ruleid']);
        $hastargetquery = trim((string)($input['rulequery'] ?? '')) !== '';
        if (!$hastargetid && !$hastargetquery) {
            return [
                'valid' => false,
                'errors' => ['Please provide a ruleid or rulequery.'],
            ];
        }

        return ['valid' => true, 'errors' => []];
    }

    /**
     * Deep preflight validation.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        $capdenied = $this->require_native_capability('mod/booking:editbookingrules', $cmid, $userid);
        if ($capdenied !== null) {
            return $capdenied;
        }
        if ($this->ruleservice === null) {
            return $this->invalid([
                [
                    'code' => 'RULE_SERVICE_UNAVAILABLE',
                    'severity' => 'needs_clarification',
                    'message' => 'Booking rules service is currently unavailable in this installation.',
                ],
            ]);
        }

        $issues = [];
        $contextid = $this->ruleservice->get_module_contextid($cmid);

        $ruleresolution = $this->ruleservice->resolve_rule(
            $contextid,
            (int)($input['ruleid'] ?? 0),
            trim((string)($input['rulequery'] ?? ''))
        );

        if (($ruleresolution['status'] ?? '') === 'error') {
            $issues[] = [
                'code' => 'RULE_RESOLUTION_FAILED',
                'severity' => 'needs_clarification',
                'message' => (string)($ruleresolution['message'] ?? 'The rule could not be resolved.'),
            ];
            return $this->invalid($issues);
        }

        if (($ruleresolution['status'] ?? '') === 'ambiguity') {
            $issues[] = [
                'code' => 'RULE_RESOLUTION_AMBIGUOUS',
                'severity' => 'needs_clarification',
                'message' => (string)($ruleresolution['message'] ?? 'Multiple rules match.'),
            ];
            foreach ((array)($ruleresolution['candidates'] ?? []) as $candidate) {
                $issues[] = [
                    'code' => 'RULE_CANDIDATE',
                    'severity' => 'needs_clarification',
                    'message' => 'id=' . (int)($candidate['id'] ?? 0) . ' name=' . (string)($candidate['name'] ?? ''),
                ];
            }
            return $this->invalid($issues);
        }

        $prepared = $input;
        $rule = (array)($ruleresolution['rule'] ?? []);
        $prepared['ruleid'] = (int)($rule['id'] ?? 0);

        $hastemplateid = !empty($input['templateid']);
        $hastemplatequery = trim((string)($input['templatequery'] ?? '')) !== '';
        if ($hastemplateid || $hastemplatequery) {
            $templateresolution = $this->ruleservice->resolve_template(
                (int)($input['templateid'] ?? 0),
                trim((string)($input['templatequery'] ?? ''))
            );

            if (($templateresolution['status'] ?? '') === 'error') {
                $issues[] = [
                    'code' => 'TEMPLATE_RESOLUTION_FAILED',
                    'severity' => 'needs_clarification',
                    'message' => (string)($templateresolution['message'] ?? 'The template could not be resolved.'),
                ];
                return $this->invalid($issues);
            }

            if (($templateresolution['status'] ?? '') === 'ambiguity') {
                $issues[] = [
                    'code' => 'TEMPLATE_RESOLUTION_AMBIGUOUS',
                    'severity' => 'needs_clarification',
                    'message' => (string)($templateresolution['message'] ?? 'Multiple templates match.'),
                ];
                foreach ((array)($templateresolution['candidates'] ?? []) as $candidate) {
                    $issues[] = [
                        'code' => 'TEMPLATE_CANDIDATE',
                        'severity' => 'needs_clarification',
                        'message' => 'templateid=' . (int)($candidate['templateid'] ?? 0)
                            . ' name=' . (string)($candidate['name'] ?? ''),
                    ];
                }
                return $this->invalid($issues);
            }

            $template = (array)($templateresolution['template'] ?? []);
            $prepared['templateid'] = (int)($template['templateid'] ?? 0);
        }

        return $this->pass($prepared);
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($this->ruleservice === null) {
            $message = 'Booking rules service is currently unavailable in this installation.';
            return [
                'status' => 'failed',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Rules service: unavailable']),
            ];
        }

        $contextid = $this->ruleservice->get_module_contextid($cmid);
        $overrides = [];
        if (isset($input['rulename'])) {
            $overrides['rulename'] = trim((string)$input['rulename']);
        }
        if (array_key_exists('isactive', $input)) {
            $overrides['isactive'] = !empty($input['isactive']);
        }

        $result = $this->ruleservice->update_rule_from_template(
            $contextid,
            (int)($input['ruleid'] ?? 0),
            (int)($input['templateid'] ?? 0),
            $overrides
        );

        if (($result['status'] ?? '') !== 'ok') {
            $message = (string)($result['message'] ?? 'The rule could not be updated.');
            return [
                'status' => 'failed',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => (int)($input['ruleid'] ?? 0),
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Update status: failed']),
            ];
        }

        $rule = (array)($result['rule'] ?? []);
        $name = (string)($rule['name'] ?? '');
        $ruleid = (int)($rule['id'] ?? 0);
        $link = $this->ruleservice->build_rules_link($cmid);

        $message = 'Rule updated: ' . $name . ' (ID ' . $ruleid . ', ' . $link . ').';

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => $ruleid,
            'rule' => $rule,
            'link' => $link,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Update status: ok',
                'Rule id: ' . $ruleid,
                'Rule name: ' . $name,
            ]),
        ];
    }
}

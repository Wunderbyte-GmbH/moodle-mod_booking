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
use core_text;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for booking.analyze_rules.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyze_rules_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.analyze_rules';

    /** @var object|null */
    private ?object $ruleservice = null;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
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
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Read-only analysis of booking rules and notification behavior in the current booking context. '
                . 'Use this for natural-language questions like "what emails are sent", '
                . '"which rules are active", or "how are confirmations/reminders configured".',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_search_options',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_search_options',
            'example_utterances' => [
                'What emails does this booking send out?',
                'Which rules are currently set up here?',
                'Show me how the reminders are configured',
                'List the notification rules on this booking',
                'Explain what automations are active on this instance',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional keyword filter applied to rule name, rule type, condition or action. '
                        . 'Pass a short keyword (e.g. "cancellation", "reminder") NOT the full user question. '
                        . 'Omit or leave empty when the user asks a general listing question.',
                    'required' => false,
                ],
                'active_only' => [
                    'type' => 'boolean',
                    'description' => 'When true only active rules are returned. Default is false (show all rules). '
                        . 'Set to true when the user says "currently", "active", "aktuell", "gerade aktiv", '
                        . '"what is being sent", "which are active" '
                        . 'or otherwise implies they only want rules that are switched on right now.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of matching rules to return (default 25).',
                    'required' => false,
                ],
                'include_templates' => [
                    'type' => 'boolean',
                    'description' => 'Also include available rule templates in the output.',
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
                'id' => 'mod_booking.analyze_rules',
                'description' => 'User asks to inspect, understand, list or summarize booking rules, '
                    . 'automated notifications, e-mails or messages that are sent by the booking instance, '
                    . 'or wants to know which rules are active / configured. '
                    . 'This also covers read-only capability questions about booking confirmations, '
                    . 'reminders or mails triggered after a booking.',
                'examples' => [
                    'Which messages are currently being sent here?',
                    'What notifications does this booking send?',
                    'Show me all active booking rules.',
                    'What emails are triggered when someone books?',
                    'Can I send a booking confirmation in booking?',
                    'Which rule sends a booking confirmation when someone has booked?',
                    'Is there a booking confirmation when someone has booked?',
                    'Are there any rules configured for cancellations?',
                    'List all rules in this booking.',
                    'What automated actions are set up?',
                    'Which rule sends reminder emails?',
                ],
            ],
        ];
    }

    /**
     * Structural validation — no DB access.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Whether the user may analyze site-level (system context) booking rules.
     *
     * Drives the global-entry-point fallback: when the agent runs without a booking instance in
     * scope (cmid 0, e.g. the navbar wand on a non-course page), users who can edit booking rules
     * at the system context get the site-wide rules instead of an error. Mirrors the capability
     * gate of edit_rules.php (mod/booking:editbookingrules); site admins pass via doanything.
     *
     * @param int $userid
     * @return bool
     */
    private function user_can_analyze_system_rules(int $userid): bool {
        return has_capability('mod/booking:editbookingrules', \context_system::instance(), $userid);
    }

    /**
     * Explicit preflight for readonly task — validates structure and passes input unchanged.
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($this->ruleservice === null) {
            return $this->invalid([
                [
                    'code' => 'RULE_SERVICE_UNAVAILABLE',
                    'severity' => 'needs_clarification',
                    'message' => 'Booking rules service is currently unavailable in this installation.',
                ],
            ]);
        }

        // No booking instance in scope (global entry point). Privileged users fall back to the
        // system-context rules in execute(); everyone else is asked which booking activity to use.
        if (
            $cmid <= 0
            && !$this->user_can_analyze_system_rules($userid)
            && ($guard = $this->require_booking_instance_scope($cmid)) !== null
        ) {
            return $guard;
        }

        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }
        return $this->pass($input);
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

        $systemfallback = false;
        if ($cmid > 0) {
            $contextid = $this->ruleservice->get_module_contextid($cmid);
        } else if ($this->user_can_analyze_system_rules($userid)) {
            // Global entry point (e.g. the navbar wand on a non-course page): no booking instance in
            // scope. Users allowed to manage site-level booking rules get a graceful fallback to the
            // system-context (global) rules instead of a "record not found" error.
            $contextid = (int)\context_system::instance()->id;
            $systemfallback = true;
        } else if ($scoperesult = $this->build_no_instance_scope_result($cmid)) {
            // Non-privileged user, no booking instance in scope: ask which booking activity to use
            // rather than surfacing a raw error.
            return $scoperesult;
        } else {
            $contextid = (int)\context_system::instance()->id;
        }

        $query = trim((string)($input['query'] ?? ''));
        $needle = core_text::strtolower($query);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 25;

        // Rules in the context path of the current booking instance.
        $activeonly = !empty($input['active_only']);
        $allrules = $this->ruleservice->list_rules_for_context($contextid, $activeonly);
        $filtered = [];
        $usedfallback = false;

        foreach ($allrules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if ($needle === '') {
                $filtered[] = $rule;
                continue;
            }

            $haystack = core_text::strtolower(implode(' ', [
                (string)($rule['name'] ?? ''),
                (string)($rule['rulename'] ?? ''),
                (string)($rule['localizedrulename'] ?? ''),
                (string)($rule['conditionname'] ?? ''),
                (string)($rule['localizedconditionname'] ?? ''),
                (string)($rule['actionname'] ?? ''),
                (string)($rule['localizedactionname'] ?? ''),
                (string)($rule['eventname'] ?? ''),
            ]));

            if ($haystack !== '' && strpos($haystack, $needle) !== false) {
                $filtered[] = $rule;
            }
        }

        // Fallback: if a search term is present but nothing matches, return all rules.
        if ($needle !== '' && count($filtered) === 0) {
            $filtered = $allrules;
            $usedfallback = true;
        }

        $filtered = array_slice($filtered, 0, $limit);

        $templates = [];
        if (!empty($input['include_templates'])) {
            $templates = $this->ruleservice->list_templates();
            if ($needle !== '') {
                $templates = array_values(array_filter($templates, static function (array $item) use ($needle): bool {
                    $name = core_text::strtolower((string)($item['name'] ?? ''));
                    return $name !== '' && strpos($name, $needle) !== false;
                }));
            }
        }

        $suffix = $activeonly ? ' (active only)' : '';
        $scopelabel = $systemfallback ? 'site-wide booking rules (system context)' : 'the current context';
        $summary = count($filtered) . ' booking rule(s) in ' . $scopelabel . $suffix . '.';
        if ($usedfallback) {
            $summary .= ' (No rules matched the search term — showing all rules.)';
        }
        if (!empty($templates)) {
            $summary .= ' Templates: ' . count($templates) . '.';
        }

        $ruleslink = (string)$this->ruleservice->build_rules_link($cmid);

        // Serialize rules inline so the generic observation handler sees them.
        $rulelines = [];
        foreach ($filtered as $rule) {
            $status   = !empty($rule['isactive']) ? '[active]' : '[inactive]';
            $name     = (string)($rule['localizedrulename'] ?? $rule['name'] ?? $rule['rulename'] ?? '');
            $scope    = (string)($rule['context_scope'] ?? '');
            $event    = (string)($rule['eventname'] ?? '');
            $cond     = (string)($rule['localizedconditionname'] ?? $rule['conditionname'] ?? '');
            $action   = (string)($rule['localizedactionname'] ?? $rule['actionname'] ?? '');
            $editlink = (string)($rule['editlink'] ?? '');
            $line = "{$status} {$name}";
            if ($scope !== '' && $scope !== 'current') {
                $line .= " [context: {$scope}]";
            }
            if ($event !== '') {
                $line .= " | event: {$event}";
            }
            if ($cond !== '') {
                $line .= " | condition: {$cond}";
            }
            if ($action !== '') {
                $line .= " | action: {$action}";
            }
            if ($editlink !== '') {
                $line .= " | edit: {$editlink}";
            }
            $rulelines[] = $line;
        }
        if (!empty($rulelines)) {
            $summary .= "\n" . implode("\n", $rulelines);
        }

        // Mandatory guidance line for follow-up mutation flows in observation context.
        if ($ruleslink !== '') {
            $summary .= "\nYou can add or edit messages here: {$ruleslink}";
        } else {
            $summary .= "\nYou can add or edit messages here.";
        }

        return [
            'status' => 'executed',
            'detail' => $summary,
            'usermessage' => $summary,
            'observation_full' => $summary,
            'resultid' => null,
            'rules' => $filtered,
            'templates' => $templates,
            'link' => $ruleslink,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'cmid: ' . $cmid,
                'active_only: ' . ($activeonly ? 'yes' : 'no'),
                'Rules in context: ' . count($allrules),
                'Returned rules: ' . count($filtered),
                'Used fallback: ' . ($usedfallback ? 'yes' : 'no'),
                'Returned templates: ' . count($templates),
            ]),
        ];
    }
}

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

use mod_booking\local\wizard\engine\module_targeted_skill;
use mod_booking\local\wizard\engine\skill_risk_class;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for booking.create_rule_from_template.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_rule_from_template_skill extends booking_skill_base implements skill_trigger_provider_interface {
    use module_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.create_rule_from_template';

    /**
     * This skill targets a booking activity instance.
     *
     * @return string
     */
    public function get_target_modname(): string {
        return 'booking';
    }

    /** Maximum number of template candidates shown in clarification output. */
    private const MAX_TEMPLATE_CANDIDATES_IN_CLARIFICATION = 8;

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
     * Template attributes that travel with a choice (wave 32): what the rule does, never words of the request. Keys are
     * the service's attribute names, values the names in the choice: a template's own number of days is its DEFAULT and
     * is named apart from the input field "days" that takes the requested number (L44 CRT-2, thread 13616: "days=3" read
     * as the template's fixed value dropped the requested two days).
     */
    private const TEMPLATE_CHOICE_ATTRIBUTES = [
        'ruletype' => 'ruletype',
        'event' => 'event',
        'recipients' => 'recipients',
        'datefield' => 'datefield',
        'days' => 'defaultdays',
        'namematch' => 'namematch',
    ];

    /**
     * Templates in the engine's choice shape (wave 30): id = templateid, label = template name, plus the attributes
     * the rules service reports (wave 32: rule type, trigger event, recipients, date field, default days, name hit).
     *
     * @param array $templates Templates as the rules service lists them.
     * @return array<int,array<string,mixed>>
     */
    public static function template_choices(array $templates): array {
        return array_values(array_map(static function (array $t): array {
            $choice = [
                'id' => (int)($t['templateid'] ?? 0),
                'label' => (string)($t['name'] ?? ''),
            ];
            foreach (self::TEMPLATE_CHOICE_ATTRIBUTES as $attribute => $name) {
                if (isset($t[$attribute]) && is_scalar($t[$attribute]) && (string)$t[$attribute] !== '') {
                    $choice[$name] = $t[$attribute];
                }
            }
            return $choice;
        }, $templates));
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
     * Human-readable preview of the rule to be created from a template (tier-3).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return rule_preview_builder::create_descriptor($input);
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Create a booking rule from a booking-rule template '
                . 'via the existing server-side rules form pipeline. '
                . 'Use this for natural-language requests like adding a booking confirmation, reminder, '
                . 'waitlist, or cancellation notification rule. '
                . 'If the user says "with the name ...", map that value to rulename (not to optionquery).',
            'is' => 'Creating a booking rule.',
            'not' => 'Changing an existing rule (update_rule_from_template); taskflow rules (local_taskflow.create_rule).',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'Set up a confirmation email when someone books',
                'Add a reminder rule one day before the course starts',
                'Create a notification rule for the waiting list',
                'I want a new rule that emails participants on cancellation',
                'Add an automatic booking confirmation message',
            ],
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course-module id of the booking activity, when it is known — e.g. from a '
                        . 'candidate list that names "cmid <id>" or from a link. Takes precedence over '
                        . 'activityquery; use it to pick one of several activities that share a name.',
                    'required' => false,
                ],
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Optional: the name of the target booking activity, when it is not the '
                        . 'current one (e.g. over MCP, which runs at the system context). If omitted and the '
                        . 'site has a single booking activity in scope it is used automatically.',
                    'required' => false,
                ],
                'templateid' => [
                    'type' => 'integer',
                    'description' => 'Rule template id (negative id for built-in templates).',
                    'required' => false,
                ],
                // Wave 32 (CRT-1/-4): the English examples here and "booking confirmation" in the example input were
                // copied verbatim in 17 of 20 constructions of CRT-1/CRT-4 (L30-L41) and matched no German template
                // name; every such turn needed a second round. A request that describes what the rule does leaves
                // this field out: the skill then lists every template with its attributes and the model picks by id.
                'templatequery' => [
                    'type' => 'string',
                    // L43: <= 160 characters (skill_input_schema_projection::MAX_DESCRIPTION_CHARS cuts the rest).
                    // L44 CRT-4 (thread 13620): "only when the user NAMES a template" left a request that DESCRIBES the
                    // rule without any value to call the skill with; the model asked instead. A description is a query
                    // like a name: only an exact name resolves, anything else lists every template (no guess, ebaeae3d57).
                    'description' => 'The template the user names or describes, in their words. Only an exact template '
                        . 'name resolves; otherwise every template is listed, closest names first.',
                    'required' => false,
                ],
                'question' => [
                    'type' => 'string',
                    'description' => 'Optional original user request text (context only; a template is never '
                        . 'inferred from it).',
                    'required' => false,
                    'from_user_message' => true,
                ],
                'rulename' => [
                    'type' => 'string',
                    'description' => 'Optional custom display name for the created rule.',
                    'required' => false,
                ],
                'isactive' => [
                    'type' => 'boolean',
                    'description' => 'Optional active flag for the new rule (default true).',
                    'required' => false,
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'Number of days for a "days before/after a date" reminder template, e.g. 2 for '
                        . '"two days before the course starts". Only for templates with a days model.',
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
                'id' => 'mod_booking.create_rule_from_template',
                'description' => 'User asks to create/add a new booking rule, especially notification rules '
                    . 'such as booking confirmation, reminder, cancellation or waitlist email.',
                'examples' => [
                    'Add a simple booking confirmation.',
                    'Create a confirmation email rule for bookings.',
                    'Add a reminder rule for this booking.',
                    'Create a cancellation notification rule.',
                    'Can you create a booking confirmation for me?',
                    'Can you create a booking confirmation for me? With the name "Confirmation 8".',
                    'I want you to create a booking confirmation with the name "my new booking confirmation".',
                    'Please create a booking confirmation with the name "Confirmation 8".',
                    'Add a simple booking confirmation.',
                ],
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
        // Keep structure validation permissive here.
        // Missing template selection is handled in preflight as a clarification
        // with concrete candidate templates.
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

        // Stash the resolved target activity so the confirm preview can name it
        // (option_preview_builder::target_rows). Execute ignores this key.
        if ($cmid > 0) {
            $input['targetcmid'] = $cmid;
        }

        $issues = [];

        $templateid = (int)($input['templateid'] ?? 0);
        $templatequery = trim((string)($input['templatequery'] ?? ''));
        // Wave 32: the request sentence and the new rule's own name are no template names. Using them as a lookup
        // ran a substring and a fuzzy similarity pick over the whole sentence - a silent guess at best, a miss that
        // only then listed the templates at worst (threads 12935, 12937, 12939). No template named -> every
        // template is offered with its attributes at once.
        if ($templateid === 0 && $templatequery === '') {
            $alltemplates = $this->ruleservice->template_candidates();
            $issues[] = [
                'code' => 'TEMPLATE_SELECTION_REQUIRED',
                'severity' => 'needs_clarification',
                'message' => get_string('agent_booking_rules_no_template_named', 'mod_booking') . ' '
                    . get_string('agent_booking_rules_choose_template', 'mod_booking'),
                'field' => 'templateid',
                'candidates' => self::template_choices($alltemplates),
            ];

            $candidates = array_slice(
                $alltemplates,
                0,
                self::MAX_TEMPLATE_CANDIDATES_IN_CLARIFICATION
            );
            foreach ($candidates as $candidate) {
                $issues[] = [
                    'code' => 'TEMPLATE_CANDIDATE',
                    'severity' => 'needs_clarification',
                    'message' => 'templateid=' . (int)($candidate['templateid'] ?? 0)
                        . ' name=' . (string)($candidate['name'] ?? ''),
                ];
            }

            return $this->invalid($issues);
        }

        $resolved = $this->ruleservice->resolve_template(
            $templateid,
            $templatequery
        );

        if (($resolved['status'] ?? '') === 'error') {
            // Choices, not an error (wave 30): a template query that matches nothing offers the templates.
            $issues[] = [
                'code' => 'TEMPLATE_RESOLUTION_FAILED',
                'severity' => 'needs_clarification',
                'message' => (string)($resolved['message'] ?? 'The template could not be resolved.'),
                'field' => 'templateid',
                'candidates' => self::template_choices($this->ruleservice->template_candidates()),
            ];
            return $this->invalid($issues);
        }

        if (($resolved['status'] ?? '') === 'ambiguity') {
            // F83 (wave 28): the English needle list that auto-picked a "confirmation" template here was a
            // language-bound detection and never matched the German template names anyway. Several matching
            // templates are the user's choice; one matching template is resolved by the service itself.
            $issues[] = [
                'code' => 'TEMPLATE_RESOLUTION_AMBIGUOUS',
                'severity' => 'needs_clarification',
                'message' => (string)($resolved['message'] ?? 'Multiple templates match.'),
                'field' => 'templateid',
                'candidates' => self::template_choices((array)($resolved['candidates'] ?? [])),
            ];
            $candidates = array_slice(
                (array)($resolved['candidates'] ?? []),
                0,
                self::MAX_TEMPLATE_CANDIDATES_IN_CLARIFICATION
            );
            foreach ($candidates as $candidate) {
                $issues[] = [
                    'code' => 'TEMPLATE_CANDIDATE',
                    'severity' => 'needs_clarification',
                    'message' => 'templateid=' . (int)($candidate['templateid'] ?? 0)
                        . ' name=' . (string)($candidate['name'] ?? ''),
                ];
            }
            return $this->invalid($issues);
        }

        $template = (array)($resolved['template'] ?? []);
        if (empty($template['templateid'])) {
            $issues[] = [
                'code' => 'TEMPLATE_MISSING',
                'severity' => 'needs_clarification',
                'message' => 'The template could not be resolved.',
            ];
            return $this->invalid($issues);
        }

        $prepared = $input;
        $prepared['templateid'] = (int)$template['templateid'];
        $prepared['template_name_resolved'] = (string)($template['name'] ?? '');

        // A number of days only applies to templates with a days model. On any other template the
        // value is dropped VISIBLY: the confirm card shows that it does not apply (W14, #2403), so
        // neither the execution nor the answer can claim it.
        $days = $input['days'] ?? null;
        $templatetype = $this->ruleservice->rule_type_of((int)$template['templateid']);
        if ($days !== null && $days !== '' && !$this->ruleservice->rule_type_has_days($templatetype)) {
            unset($prepared['days']);
            $prepared['days_not_applicable'] = 1;
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
        if (isset($input['days']) && $input['days'] !== '') {
            $overrides['days'] = (int)$input['days'];
        }

        $result = $this->ruleservice->create_rule_from_template(
            $contextid,
            (int)($input['templateid'] ?? 0),
            $overrides
        );

        if (($result['status'] ?? '') !== 'ok') {
            $message = (string)($result['message'] ?? 'The rule could not be created.');
            return [
                'status' => 'failed',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Create status: failed']),
            ];
        }

        $rule = (array)($result['rule'] ?? []);
        $name = (string)($rule['name'] ?? '');
        $ruleid = (int)($rule['id'] ?? 0);
        $link = $this->ruleservice->build_rules_link($cmid);

        $message = 'Rule created: ' . $name . ' (ID ' . $ruleid . ', ' . $link . ').';

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => $ruleid,
            'rule' => $rule,
            'link' => $link,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                'Create status: ok',
                'Rule id: ' . $ruleid,
                'Rule name: ' . $name,
            ]),
        ];
    }
}

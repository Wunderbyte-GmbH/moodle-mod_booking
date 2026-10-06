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
    // Rule-derived targeting (thread 584): the named rule pins its own context — a rule in a
    // booking activity resolves that activity, a system rule stays at the ambient context.
    // The previous module_targeted contract asked "which booking activity?" for rule requests,
    // which a system-scoped rule cannot answer by construction.
    use rule_targeted_skill;

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
     * The context whose rule set this call operates on: the ambient/resolved booking activity
     * when there is one, otherwise the SYSTEM context (system rules are reachable from every
     * context via the path-scoped rule listing; a rule request never asks for an activity).
     *
     * @param int $cmid Resolved cmid, 0 outside a booking activity.
     * @return int Context id.
     */
    private function resolve_rule_scope_contextid(int $cmid): int {
        if ($cmid > 0 && $this->ruleservice !== null) {
            return (int)$this->ruleservice->get_module_contextid($cmid);
        }
        return (int)\context_system::instance()->id;
    }

    /**
     * Rule candidates in the engine's choice shape: id, label and the attributes a request can name.
     *
     * @param array $candidates Candidates as the rules service returns them.
     * @return array<int,array<string,mixed>>
     */
    private function rule_choices(array $candidates): array {
        $choices = [];
        foreach ($candidates as $candidate) {
            $choice = [
                'id' => (int)($candidate['id'] ?? 0),
                'label' => (string)($candidate['name'] ?? ''),
            ];
            foreach (['scope', 'isactive', 'days'] as $attribute) {
                if (isset($candidate[$attribute])) {
                    $choice[$attribute] = $candidate[$attribute];
                }
            }
            $choices[] = $choice;
        }
        return $choices;
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
     * Representative FLAT example input for the construction-phase catalog.
     *
     * @return array
     */
    public function get_example_input(): array {
        // The constructor only sees this card: without "days" here it never fills the property (#2403).
        return ['rulequery' => 'confirmation rule', 'isactive' => false, 'days' => 5];
    }

    /**
     * Strictly parse an isactive override: quoted booleans keep their meaning, junk is null.
     *
     * A bare !empty() would read the string 'false' as TRUE and invert the user's intent.
     *
     * @param mixed $value Raw isactive value from the command input.
     * @return bool|null Null when the value carries no parseable boolean.
     */
    public static function parse_isactive_override($value): ?bool {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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
            // Wave 32: first 240 characters = selector window. The card named no mail text although an example
            // utterance promised it (R5); URT-3 went to local_taskflow.update_message_template in 4 of 11 runs and
            // URT-1 (L39, thread 11682) to local_taskflow.update_rule, whose IS line was word for word this one.
            'description' => 'Change an EXISTING booking rule - an automatic mail of a booking activity: its days '
                . 'before/after the date, on/off, name, mail subject and mail text, or reapply a template. The rule is '
                . 'found by ruleid or rulequery.',
            'is' => 'An existing booking rule and the mail it sends.',
            // Review w32s-b1: <= 160 characters (skill_catalog_discrimination_test card budget). The taskflow skills are
            // fenced in words, not by id: an id here needs the counter-fence on their cards (mutual-fence test), and
            // local_taskflow.update_message_template has no NOT line at all.
            'not' => 'Creating a rule (create_rule_from_template); analysing rules (analyze_rules); rules and message '
                . 'templates of taskflow.',
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
                    'description' => 'Numeric id of the target rule. A number the user mentions '
                        . '("Regel 3", "rule with id 7") belongs HERE, never into rulequery.',
                    'required' => false,
                ],
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Optional: name of the target booking activity whose rules are meant, when it'
                        . ' is not the current one (e.g. over MCP, which runs at the system context).',
                    'required' => false,
                ],
                'rulequery' => [
                    'type' => 'string',
                    // Wave 32, L43: <= 160 characters (skill_input_schema_projection::MAX_DESCRIPTION_CHARS cuts the rest).
                    'description' => 'The user\'s words for the rule if no id is given: its name or its kind, as said, '
                        . 'in the user\'s language. Left out, the skill finds the rule or offers the rules.',
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
                    'description' => 'Set false to DISABLE/deactivate the rule, true to enable/activate it. '
                        . 'Use this whenever the user asks to switch a rule on or off.',
                    'required' => false,
                ],
                'days' => [
                    'type' => 'integer',
                    'description' => 'New number of days for a "days before/after a date" reminder rule, e.g. 5 for '
                        . '"remind five days before". Only for rules with a days model.',
                    'required' => false,
                ],
                'mailsubject' => [
                    'type' => 'string',
                    'description' => 'New subject of the rule\'s mail - only as the user gave it; never write one yourself.',
                    'required' => false,
                ],
                'mailbody' => [
                    'type' => 'string',
                    'description' => 'New text of the rule\'s mail - only as the user gave it, placeholders kept; never '
                        . 'write one. Without it the skill shows the current text and asks.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for user-facing wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                // The prompt_meta block keeps its established shape even where only the group is declared: the
                // contract test asserts both keys on every skill that carries prompt_meta at all, and an
                // empty list is what the readers saw before this block existed.
                'input_fields_for_prompt' => [],
                'anchor_fields' => [],
                // Wave 32, L43: no required group. The frozen constructor reminder prints the declared group as
                // "Its required values: one of ruleid | rulequery", and the constructor asked for the rule in 2 of 2
                // requests that named it by its kind (13330 cons 83955, 13333 cons 83968) although the preflight
                // resolves such a request itself (see check_structure()).
                'required_groups' => [],
            ],
        ];
    }

    /**
     * Contextual guidance for the construction phase (surfaced unconditionally there).
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'mod_booking.rule_target_reference',
                'triggers' => ['rule', 'regel'],
                'guidance' => [
                    '- Numeric rule reference -> {"ruleid": 3}; name reference -> {"rulequery": "reminder rule"}.',
                    '- Never encode ids inside rulequery (no "3", no "id:3") — rulequery is for name text only.',
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
                'description' => 'User wants an existing booking rule or its mail changed: timing, on/off, name, '
                    . 'mail subject or text, or a template reapplied.',
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
        // Wave 32, L43 (threads 13330 CBI-4, 13333 URT-3): a rule reference is no structural precondition. The preflight
        // resolves a request that names no rule from DB facts - the one active days-before rule for a days value
        // (0c7cb317a6), otherwise every rule as a choice (8367ed11c) - so this gate and the required group behind it
        // only made the constructor ask "which rule?" where the user named the rule by its kind.
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
        if ($this->ruleservice === null) {
            return $this->invalid([
                [
                    'code' => 'RULE_SERVICE_UNAVAILABLE',
                    'severity' => 'needs_clarification',
                    'message' => 'Booking rules service is currently unavailable in this installation.',
                ],
            ]);
        }

        // The rule's own context is the operating scope: a module context when the rule (or the
        // ambient activity) lives there, the SYSTEM context otherwise — system rules are managed
        // from anywhere, so no "which booking activity?" question is asked for a rule (thread 584).
        $contextid = $this->resolve_rule_scope_contextid($cmid);
        $rulecontext = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$rulecontext || !has_capability('mod/booking:editbookingrules', $rulecontext, $userid)) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('nopermissions', 'error', 'mod/booking:editbookingrules'),
                'code' => 'NO_NATIVE_CAPABILITY',
            ]]);
        }

        $issues = [];

        $ruleid = (int)($input['ruleid'] ?? 0);
        $rulequery = trim((string)($input['rulequery'] ?? ''));
        if ($ruleid <= 0 && $rulequery === '' && array_key_exists('days', $input)) {
            // A request such as "Die Erinnerung soll künftig fünf Tage vor Kursbeginn rausgehen" names no rule (baseline runs
            // 31-37, CBI-4): a days value can only belong to a days-before rule, and when this context has
            // exactly ONE active rule of that kind it is the one meant - a DB fact, no wording. Several such
            // rules stay a question.
            $daysrules = array_values(array_filter(
                $this->ruleservice->list_rules_for_context($contextid, true),
                static fn(array $rule): bool => array_key_exists('days', $rule) && $rule['days'] !== null
            ));
            if (count($daysrules) === 1) {
                $ruleid = (int)$daysrules[0]['id'];
            }
        }

        $ruleresolution = $this->ruleservice->resolve_rule($contextid, $ruleid, $rulequery);

        if (($ruleresolution['status'] ?? '') === 'notfound' && array_key_exists('days', $input) && $ruleid <= 0) {
            // A name that matches no rule gives no information (wave 30, CBI-4: the model's own word "Erinnerung"
            // for a rule named "Email reminder 2 days before course start"). The days value and the active flag
            // still do - DB facts, no wording: one active days-before rule is the rule meant; several narrow the
            // choices to them.
            $daysrules = array_values(array_filter(
                $this->ruleservice->list_rules_for_context($contextid),
                static fn(array $rule): bool => array_key_exists('days', $rule) && $rule['days'] !== null
            ));
            $activedays = array_values(array_filter($daysrules, static fn(array $rule): bool => (int)$rule['isactive'] === 1));
            if (count($activedays) === 1) {
                $ruleresolution = ['status' => 'ok', 'rule' => $activedays[0]];
            } else if (!empty($activedays) || !empty($daysrules)) {
                $narrowed = !empty($activedays) ? $activedays : $daysrules;
                $ruleresolution['candidates'] = array_values(array_map(static fn(array $item): array => [
                    'id' => (int)($item['id'] ?? 0),
                    'name' => (string)($item['name'] ?? ''),
                    'scope' => (string)($item['context_scope'] ?? ''),
                    'isactive' => (int)($item['isactive'] ?? 0),
                    'days' => $item['days'] ?? null,
                ], $narrowed));
            }
        }

        if ($ruleid <= 0 && $rulequery === '' && (string)($ruleresolution['status'] ?? '') === 'notfound') {
            // Wave 32, L43: without a required group a request may name no rule at all. The service's text for that
            // case names the schema fields ("ruleid or rulequery"), which must never reach the user (HARD RULE
            // 2026-09-14, point 3); the choice itself stays as the service built it. Review w32s-b1: also without
            // any rule to offer - that path was unreachable behind the old structure gate.
            $ruleresolution['message'] = !empty($ruleresolution['candidates'])
                ? get_string('agent_booking_rules_no_rule_named', 'mod_booking') . ' '
                    . get_string('agent_booking_rules_choose_from_candidates', 'mod_booking')
                : get_string('agent_booking_rules_no_rules_in_context', 'mod_booking');
        }

        if (in_array((string)($ruleresolution['status'] ?? ''), ['error', 'notfound'], true)) {
            $issues[] = [
                'code' => 'RULE_RESOLUTION_FAILED',
                'severity' => 'needs_clarification',
                'message' => (string)($ruleresolution['message'] ?? 'The rule could not be resolved.'),
                'field' => 'ruleid',
                // Engine contract (wave 30): an issue carrying `candidates` offers a choice; the engine hands it
                // to the selector for one re-plan, which picks the rule by its id.
                'candidates' => $this->rule_choices((array)($ruleresolution['candidates'] ?? [])),
            ];
            // A miss offers the rules that exist (current context first, site-wide rules always) so the
            // model can pick the one meant by its id instead of ending the turn.
            foreach ((array)($ruleresolution['candidates'] ?? []) as $candidate) {
                $issues[] = [
                    'code' => 'RULE_CANDIDATE',
                    'severity' => 'needs_clarification',
                    'message' => 'id=' . (int)($candidate['id'] ?? 0)
                        . ' name=' . (string)($candidate['name'] ?? '')
                        . ' scope=' . (string)($candidate['scope'] ?? '')
                        . ' active=' . (int)($candidate['isactive'] ?? 0)
                        . (isset($candidate['days']) ? ' days=' . (int)$candidate['days'] : ''),
                ];
            }
            return $this->invalid($issues);
        }

        if (($ruleresolution['status'] ?? '') === 'ambiguity') {
            $issues[] = [
                'code' => 'RULE_RESOLUTION_AMBIGUOUS',
                'severity' => 'needs_clarification',
                'message' => (string)($ruleresolution['message'] ?? 'Multiple rules match.'),
                'field' => 'ruleid',
                'candidates' => $this->rule_choices((array)($ruleresolution['candidates'] ?? [])),
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

        // The rule may live ABOVE the ambient scope (system/course rule reached via the context
        // path). Editing it requires the capability where the rule lives, not just where the
        // user happens to stand.
        $rulectxid = (int)($rule['contextid'] ?? 0);
        if ($rulectxid > 0 && $rulectxid !== $contextid) {
            $ownctx = \context::instance_by_id($rulectxid, IGNORE_MISSING);
            if (!$ownctx || !has_capability('mod/booking:editbookingrules', $ownctx, $userid)) {
                return $this->invalid([[
                    'severity' => 'needs_clarification',
                    'message' => get_string('nopermissions', 'error', 'mod/booking:editbookingrules'),
                    'code' => 'NO_NATIVE_CAPABILITY',
                ]]);
            }
        }
        // Stash resolution results for the confirm preview (rule_preview_builder): the resolved
        // rule NAME for the title, and the target activity row. Execute ignores these keys.
        $rulename = trim((string)($rule['name'] ?? ''));
        if ($rulename !== '') {
            $prepared['rule_name_resolved'] = $rulename;
        }
        if ($cmid > 0) {
            $prepared['targetcmid'] = $cmid;
        }

        // Wave 32 (URT-3, R5): the mail subject and text are fields of the rule's action. A rule whose action sends
        // no mail has none - asked back, never dropped silently.
        $mailtext = $this->ruleservice->rule_mail_text((int)$prepared['ruleid']);
        $wantsmailtext = trim((string)($input['mailsubject'] ?? '')) !== '' || trim((string)($input['mailbody'] ?? '')) !== '';
        if ($wantsmailtext && $mailtext === null) {
            return $this->invalid([[
                'code' => 'RULE_MAIL_TEXT_NOT_APPLICABLE',
                'severity' => 'needs_clarification',
                'message' => get_string('agent_booking_rules_mail_text_not_applicable', 'mod_booking', $rulename),
                'field' => 'ruleid',
            ]]);
        }
        // A resolved rule and nothing to change would stage an empty update: ask what should change and show what
        // the rule sends today, so the answer can name a new subject or text (URT-3: "the text is too dry").
        // Review w32s-b1: templateid 0 is "no template" (the skill's own default), not a change.
        $haschange = (int)($input['templateid'] ?? 0) !== 0;
        foreach (['rulename', 'isactive', 'days', 'templatequery', 'mailsubject', 'mailbody'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $haschange = true;
                break;
            }
        }
        if (!$haschange) {
            // Review w32s-b1: the current subject and text are only shown for a rule that sends a mail; any other
            // rule gets the question alone (the earlier single string printed an empty subject for it).
            $message = get_string('agent_booking_rules_change_required', 'mod_booking', $rulename);
            if ($mailtext !== null) {
                $message .= ' ' . get_string('agent_booking_rules_current_mail_text', 'mod_booking', (object)[
                    'subject' => (string)$mailtext['subject'],
                    'body' => shorten_text(strip_tags((string)$mailtext['body']), 600),
                ]);
            }
            $issue = [
                'code' => 'RULE_CHANGE_REQUIRED',
                'severity' => 'needs_clarification',
                'message' => $message,
            ];
            if ($mailtext !== null) {
                $issue['field'] = 'mailbody';
            }
            return $this->invalid([$issue]);
        }

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
                    'field' => 'templateid',
                    'candidates' => create_rule_from_template_skill::template_choices($this->ruleservice->list_templates()),
                ];
                return $this->invalid($issues);
            }

            if (($templateresolution['status'] ?? '') === 'ambiguity') {
                $issues[] = [
                    'code' => 'TEMPLATE_RESOLUTION_AMBIGUOUS',
                    'severity' => 'needs_clarification',
                    'message' => (string)($templateresolution['message'] ?? 'Multiple templates match.'),
                    'field' => 'templateid',
                    'candidates' => create_rule_from_template_skill::template_choices(
                        (array)($templateresolution['candidates'] ?? [])
                    ),
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
            $templatename = trim((string)($template['name'] ?? ''));
            if ($templatename !== '') {
                // Show the RESOLVED template name (not the raw query) in the confirm preview.
                $prepared['template_name_resolved'] = $templatename;
            }
        }

        // A number of days only applies to a days-model rule (or a days-model template being
        // reapplied); anything else is asked back, never silently dropped (W15, #2403).
        $days = $input['days'] ?? null;
        if ($days !== null && $days !== '') {
            $typesource = (int)($prepared['templateid'] ?? 0) !== 0 ? (int)$prepared['templateid'] : (int)$prepared['ruleid'];
            if (!$this->ruleservice->rule_type_has_days($this->ruleservice->rule_type_of($typesource))) {
                // Dropped visibly (preview row), never silently and never as a claim in the answer.
                unset($prepared['days']);
                $prepared['days_not_applicable'] = 1;
            }
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

        // The service only accepts the rule's OWN contextid (a system rule stays a system rule
        // even when edited from inside a booking activity). The ambient scope is still enforced:
        // the rule's context must lie on the scope's context path — exactly the visibility rule
        // the listing/resolution uses.
        global $DB;
        $scopecontextid = $this->resolve_rule_scope_contextid($cmid);
        $ruleid = (int)($input['ruleid'] ?? 0);
        $contextid = (int)($DB->get_field('booking_rules', 'contextid', ['id' => $ruleid]) ?: 0);
        $scopecontext = \context::instance_by_id($scopecontextid, IGNORE_MISSING);
        $pathids = $scopecontext
            ? array_map('intval', explode('/', trim((string)$scopecontext->path, '/')))
            : [];
        if ($contextid <= 0 || !in_array($contextid, $pathids, true)) {
            $message = 'The rule is not reachable from the current booking context.';
            return [
                'status' => 'failed',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => $ruleid,
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, [
                    'Rule context ' . $contextid . ' outside scope path of context ' . $scopecontextid,
                ]),
            ];
        }

        $overrides = [];
        if (isset($input['rulename'])) {
            $overrides['rulename'] = trim((string)$input['rulename']);
        }
        if (isset($input['days']) && $input['days'] !== '') {
            $overrides['days'] = (int)$input['days'];
        }
        if (array_key_exists('isactive', $input)) {
            $parsed = self::parse_isactive_override($input['isactive']);
            if ($parsed !== null) {
                $overrides['isactive'] = $parsed;
            }
        }
        foreach (['mailsubject', 'mailbody'] as $key) {
            if (isset($input[$key]) && trim((string)$input[$key]) !== '') {
                $overrides[$key] = (string)$input[$key];
            }
        }

        $result = $this->ruleservice->update_rule_from_template(
            $contextid,
            $ruleid,
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

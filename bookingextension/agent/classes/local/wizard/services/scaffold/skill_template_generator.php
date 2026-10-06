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

namespace bookingextension_agent\local\wizard\services\scaffold;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\skill_contract_validator;
use core_text;

/**
 * Deterministic generator for third-party skill templates.
 *
 * Produces a ready-to-drop, heavily-commented skill class plus the wiring snippets a developer
 * needs, packaged as a downloadable ZIP. It NEVER emits business logic: run_preflight()/execute()
 * bodies are guided TODO comments and execute() is an inert "not implemented yet" placeholder, so
 * the agent (and Wunderbyte) never authors the third party's actual behaviour.
 *
 * The output is contract-valid by construction (the spec is validated against the same rules the
 * runtime applies via {@see skill_contract_validator}).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_template_generator {
    /** @var string Placeholder message rendered by an unfinished skill. */
    public const NOT_IMPLEMENTED_MESSAGE =
        'This skill has not implemented its actual task yet.';

    /**
     * Generate the template bundle for a structured skill spec.
     *
     * @param array $spec see {@see self::normalize_spec()} for accepted keys
     * @return array{
     *     files: array<string,string>,
     *     zip_filename: string,
     *     zip_base64: string,
     *     skillname: string,
     *     capability: string,
     *     relativepath: string,
     *     warnings: array<int,string>
     * }
     * @throws \invalid_parameter_exception when the spec cannot produce a contract-valid skill
     */
    public static function generate(array $spec): array {
        $spec = self::normalize_spec($spec);
        self::validate_spec($spec);

        $skillfile = self::build_skill_php($spec);
        $relativepath = 'classes/local/wizard/' . $spec['domain'] . '/skills/' . $spec['classfile'];

        $files = [
            $relativepath => $skillfile,
            'README.md' => self::build_readme($spec, $relativepath),
            'snippets/db_access.php.txt' => self::build_access_snippet($spec),
            'snippets/lang_strings.php.txt' => self::build_lang_snippet($spec),
        ];

        [$zipbase64, $zipfilename] = self::build_zip($spec, $files);

        return [
            'files' => $files,
            'zip_filename' => $zipfilename,
            'zip_base64' => $zipbase64,
            'skillname' => $spec['skillname'],
            'capability' => $spec['capability'],
            'relativepath' => $relativepath,
            'warnings' => $spec['warnings'],
        ];
    }

    /**
     * Normalize a raw spec into the canonical shape used by the builders.
     *
     * Accepted raw keys: component (required), skillname, description (required), intent,
     * risk_class, properties[], anchor_fields[], context_scopes[], capabilities[], triggers[],
     * is, not, example_utterances[].
     *
     * @param array $spec
     * @return array
     */
    private static function normalize_spec(array $spec): array {
        $warnings = [];

        $component = trim(core_text::strtolower((string)($spec['component'] ?? '')));
        $component = str_replace('\\', '/', $component);
        $namespaceroot = str_replace('/', '_', $component);

        $description = trim((string)($spec['description'] ?? ''));

        // Risk class + read-only are coupled: R0 == read-only, everything else mutates.
        $risk = trim((string)($spec['risk_class'] ?? skill_risk_class::R0));
        if (!skill_risk_class::is_valid($risk)) {
            $warnings[] = "Unknown risk_class '{$risk}', defaulting to read_only.";
            $risk = skill_risk_class::R0;
        }
        $readonly = ($risk === skill_risk_class::R0);

        // Skill name: keep a valid namespaced name or derive one from component + description.
        $skillname = trim(core_text::strtolower((string)($spec['skillname'] ?? '')));
        // A provided plain name is the caller's choice: namespace it, never replace it
        // with the description slug (#2338).
        if ($skillname !== '' && !skill_contract_validator::is_namespaced_skill_name($skillname)) {
            $candidate = self::derive_namespace($component) . '.' . self::slugify($skillname);
            if (skill_contract_validator::is_namespaced_skill_name($candidate)) {
                $skillname = $candidate;
            }
        }
        if (!skill_contract_validator::is_namespaced_skill_name($skillname)) {
            $namespace = self::derive_namespace($component);
            $action = self::slugify($description !== '' ? $description : 'do_something');
            $skillname = $namespace . '.' . $action;
            $warnings[] = "Derived skill name '{$skillname}' - rename it to something meaningful.";
        }
        $namespace = skill_contract_validator::extract_skill_namespace($skillname);
        $action = substr($skillname, strpos($skillname, '.') + 1);

        $properties = self::normalize_properties((array)($spec['properties'] ?? []));
        $anchors = self::normalize_string_list((array)($spec['anchor_fields'] ?? []));
        $scopes = self::normalize_string_list((array)($spec['context_scopes'] ?? []));
        $capabilities = self::normalize_string_list((array)($spec['capabilities'] ?? []));
        $triggers = self::normalize_triggers((array)($spec['triggers'] ?? []), $description);

        return [
            'component' => $component,
            'namespaceroot' => $namespaceroot,
            'namespace' => $namespace,
            'action' => $action,
            'skillname' => $skillname,
            'classname' => $action . '_skill',
            'classfile' => $action . '_skill.php',
            'domain' => trim((string)($spec['domain'] ?? $namespace)) ?: $namespace,
            'description' => $description !== '' ? $description : 'TODO: describe what this skill does.',
            // Sibling discrimination (#2453): it belongs on the selector CARD, never in the embedded
            // description. See skill_template_get_schema() for the reasoning the template carries.
            'is' => trim((string)($spec['is'] ?? '')) ?: 'TODO: what this skill covers, in one short clause.',
            'not' => trim((string)($spec['not'] ?? ''))
                ?: 'TODO: what a sibling skill covers instead, with that sibling in brackets.',
            'example_utterances' => self::normalize_string_list((array)($spec['example_utterances'] ?? [])),
            'intent' => trim((string)($spec['intent'] ?? '')) ?: $description,
            'risk_class' => $risk,
            'readonly' => $readonly,
            'risk_const' => self::risk_constant_name($risk),
            'properties' => $properties,
            'anchor_fields' => $anchors !== [] ? $anchors : array_slice(array_column($properties, 'name'), 0, 1),
            'context_scopes' => $scopes,
            'capabilities' => $capabilities,
            'triggers' => $triggers,
            'capability' => skill_contract_validator::build_skill_capability_name($component, $skillname),
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate the normalized spec against the runtime contract rules so the output is usable.
     *
     * @param array $spec
     * @return void
     * @throws \invalid_parameter_exception
     */
    private static function validate_spec(array $spec): void {
        if ($spec['component'] === '') {
            throw new \invalid_parameter_exception('A target component (e.g. "mod/myplugin") is required.');
        }
        if (!skill_contract_validator::is_namespaced_skill_name($spec['skillname'])) {
            throw new \invalid_parameter_exception('Could not build a valid namespaced skill name.');
        }
        if (!skill_contract_validator::component_may_register_namespace($spec['component'], $spec['namespace'])) {
            throw new \invalid_parameter_exception(
                "Component '{$spec['component']}' may not register the reserved namespace '{$spec['namespace']}'. "
                . 'Choose a namespace specific to your plugin.'
            );
        }
        // Broad/irreversible skills must declare explicit context scopes (mirrors the validator).
        $mutatingbroad = in_array($spec['risk_class'], [skill_risk_class::R2, skill_risk_class::R3], true);
        if ($mutatingbroad && empty($spec['context_scopes'])) {
            throw new \invalid_parameter_exception(
                'Broad or irreversible skills (R2/R3) must declare at least one context scope '
                . '(e.g. "module", "course", "user").'
            );
        }
        if ($spec['capability'] === '') {
            throw new \invalid_parameter_exception('Could not derive a capability name for the skill.');
        }
    }

    /**
     * Build the fully-commented skill class file.
     *
     * @param array $spec
     * @return string
     */
    private static function build_skill_php(array $spec): string {
        $namespace = $spec['namespaceroot'] . '\\local\\wizard\\' . $spec['domain'] . '\\skills';
        // Engine types are referenced ONLY through component-local alias names
        // (\<component>\local\wizard\engine\*), which the active engine registers
        // via class_alias() before it loads the skill (engine_alias_registrar),
        // so the same skill runs unchanged under either engine plugin.
        $engineprefix = $spec['namespaceroot'] . '\\local\\wizard\\engine';
        $mutating = empty($spec['readonly']);

        // Risk-dependent use list + implemented interfaces. Mutating skills additionally provide a
        // confirm-queue identity (queue_identity_provider_interface).
        $uses = [
            "use {$engineprefix}\\base_skill;",
            "use {$engineprefix}\\skill_risk_class;",
            "use {$engineprefix}\\skill_trigger_provider_interface;",
        ];
        $interfaces = ['skill_trigger_provider_interface'];
        if ($mutating) {
            $uses[] = "use {$engineprefix}\\queue_identity_provider_interface;";
            $interfaces[] = 'queue_identity_provider_interface';
        }

        $template = self::skill_template_header()
            . self::skill_template_constructor()
            . self::skill_template_targeting_block()
            . self::skill_template_get_schema()
            . self::skill_template_native_caps()
            . self::skill_template_check_structure()
            . ($mutating ? self::skill_template_mutating_methods() : self::skill_template_readonly_note())
            . self::skill_template_execute()
            . self::skill_template_result_preview()
            . self::skill_template_message_triggers()
            . self::skill_template_footer();

        return strtr($template, [
            '{{NAMESPACE}}' => $namespace,
            '{{USES}}' => implode("\n", $uses),
            '{{INTERFACES}}' => implode(', ', $interfaces),
            '{{NAMESPACEROOT}}' => $spec['namespaceroot'],
            '{{CLASSNAME}}' => $spec['classname'],
            '{{SKILLNAME}}' => $spec['skillname'],
            '{{RELATIVEPATH}}' => 'classes/local/wizard/' . $spec['domain'] . '/skills/' . $spec['classfile'],
            '{{READONLY}}' => $spec['readonly'] ? 'true' : 'false',
            '{{RISKCONST}}' => $spec['risk_const'],
            '{{DESCRIPTION}}' => self::php_single_quote($spec['description']),
            '{{IS}}' => self::php_single_quote($spec['is']),
            '{{NOT}}' => self::php_single_quote($spec['not']),
            '{{UTTERANCES}}' => self::render_utterances(
                $spec['example_utterances'] !== []
                    ? $spec['example_utterances']
                    : ['TODO: a sentence a user would really say to trigger this skill.']
            ),
            '{{INTENT}}' => self::php_single_quote($spec['intent']),
            '{{PROPERTIES}}' => self::render_properties($spec['properties']),
            '{{ANCHORS}}' => self::render_quoted_list($spec['anchor_fields']),
            '{{SCOPES}}' => self::render_quoted_list($spec['context_scopes']),
            '{{CAPABILITIES}}' => self::render_quoted_list($spec['capabilities']),
            '{{NOTIMPLEMENTED}}' => self::php_single_quote(self::NOT_IMPLEMENTED_MESSAGE),
            '{{TRIGGERID}}' => self::php_single_quote($spec['skillname'] . '_request'),
            '{{TRIGGERDESC}}' => self::php_single_quote($spec['triggers'][0]['description']),
            '{{TRIGGEREXAMPLES}}' => self::render_quoted_list($spec['triggers'][0]['examples']),
        ]);
    }

    /**
     * File header + class declaration (discovery contract documented).
     *
     * @return string
     */
    private static function skill_template_header(): string {
        return <<<'PHP'
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify it under the terms of the GNU
// General Public License as published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version. See <http://www.gnu.org/licenses/>.

namespace {{NAMESPACE}};

{{USES}}

/**
 * AI agent skill: {{SKILLNAME}}.
 *
 * GENERATED SCAFFOLD - the contract is filled in; the behaviour is NOT.
 * Implement the methods marked "TODO (you)". Until then this skill is inert and only reports that it
 * is unfinished.
 *
 * DISCOVERY (no skill_provider class needed): the agent scans every plugin's
 * classes/local/wizard/&#42;&#42;/skills/ tree and registers each class that (a) extends base_skill /
 * implements skill_interface, (b) has a no-argument constructor, and (c) returns a unique get_name().
 * Drop this file at: {{RELATIVEPATH}} and it is picked up automatically. (A skill_provider class is
 * only needed for advanced cases: contextual prompt packs, issue-code providers, input normalizers.)
 *
 * NOTE: engine contract types are referenced through component-local alias names
 * (\{{NAMESPACEROOT}}\local\wizard\engine\*). The active engine registers these aliases
 * automatically before it loads this class - no engine files are shipped with this plugin.
 * Never import an engine component's classes directly - the aliases bind to whichever
 * engine plugin is active on the site. (Loading this class OUTSIDE the engine, e.g. in
 * your own unit tests, needs the one-line bootstrap:
 * engine_alias_registrar::ensure_component_aliases('{{NAMESPACEROOT}}') on the installed engine.)
 *
 * @package {{NAMESPACEROOT}}
 */
class {{CLASSNAME}} extends base_skill implements {{INTERFACES}} {

PHP;
    }

    /**
     * Constructor block (documents the four risk classes).
     *
     * @return string
     */
    private static function skill_template_constructor(): string {
        return <<<'PHP'
    /**
     * Declare the skill's read-only flag and risk class. These drive the whole confirmation policy:
     *   R0 read_only             - auto-executes, NO confirmation, NO pre-confirmation preview.
     *   R1 scoped_write          - mutating, narrow scope; may be session-auto-confirmed.
     *   R2 broad_write           - mutating, broad scope; always explicit confirmation.
     *   R3 irreversible_or_external - irreversible/external; always manual confirmation.
     * The boolean MUST be true only for R0 (read-only), false for R1/R2/R3.
     */
    public function __construct() {
        parent::__construct({{READONLY}}, skill_risk_class::{{RISKCONST}});
    }

    /**
     * Unique, namespaced skill name: "<namespace>.<action>", lowercase, matching
     * ^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$ (e.g. "course.add_widget").
     *
     * @return string
     */
    public function get_name(): string {
        return '{{SKILLNAME}}';
    }

    /**
     * Representative input for the construction-phase skill catalog (helps the planner fill params).
     *
     * @return array<string,mixed>
     */
    public function get_example_input(): array {
        // TODO (you): a realistic example, e.g. ['yourfield' => 'example value'].
        return [];
    }

PHP;
    }

    /**
     * Optional cross-context / context-level block (commented; applies to any risk class).
     *
     * @return string
     */
    private static function skill_template_targeting_block(): string {
        return <<<'PHP'
    // ---------------------------------------------------------------------------------------------
    // TARGET CONTEXT — read this if your skill MUTATES an activity instance or an object inside one
    // (an option, a rule, a price, ...). For those skills it is REQUIRED, not optional.
    //
    // WHY: a skill runs at an "operating context" (a Moodle context id). In chat that defaults to
    // the activity the user has open, but over MCP it is ALWAYS the system context, and from the
    // dashboard it is the site context. A skill that resolves its target activity from the ambient
    // context alone works inside an open activity and silently fails everywhere else ("this action
    // needs a target activity"). So NEVER derive the cmid from the ambient context as the only
    // source. Resolve the target from your OWN input parameters instead and let the engine set the
    // operating context; your native capability (Gate 2) is then re-checked there automatically.
    //
    // HOW — pick one:
    //
    // (a) Activity-scoped skill (the user names an activity, or there is a single one in scope):
    //     use the generic engine trait and only declare which module type you target:
    //
    //         use \{{NAMESPACEROOT}}\local\wizard\engine\module_targeted_skill;
    //         public function get_target_modname(): string { return 'yourmodname'; }
    //         // ...and add 'cmid' / 'activityquery' to your get_schema() properties.
    //
    // (b) Object-scoped skill (the user names an object whose id already implies its activity, e.g.
    //     an option id): write a small plugin-side trait that turns the object reference into a
    //     module target_selector. See mod_booking's option_targeted_skill for a worked example
    //     (optionid -> owning cmid). Such a trait implements:
    //
    //         public function supports_target_context(): bool { return true; }
    //         public function get_target_context_level(): int { return CONTEXT_MODULE; }
    //         public function get_target_selector(array $input): ?target_selector {
    //             // resolve $input's object to its cmid, then:
    //             // return target_selector::for_module($cmid, null, 'yourmodname');
    //         }
    //
    // SAFETY: only opt in if Gate 2 binds to the PASSED operating context — declare your native
    // capabilities via get_required_native_capabilities() (the engine enforces them at the resolved
    // context). Never check a hardwired ambient cmid or $USER.
    //
    // A genuinely site-wide skill instead overrides get_required_context_level(): CONTEXT_SYSTEM and
    // needs no activity target — it already works over MCP.
    // ---------------------------------------------------------------------------------------------

PHP;
    }

    /**
     * get_schema() block.
     *
     * @return string
     */
    private static function skill_template_get_schema(): string {
        return <<<'PHP'
    /**
     * The schema the planner reads to route to and call this skill.
     *
     * Rules:
     * - 'description': ONE clear sentence - what this does and when to use it.
     * - every entry in 'properties' needs: type, a one-sentence 'description' (the LLM reads it), and required(bool).
     * - 'prompt_meta.intent': one line; 'anchor_fields': the main lookup field(s).
     * - For R2/R3 skills 'context_scopes' is MANDATORY (e.g. ['module','course']) or the skill is rejected.
     *
     * The skill is reached in TWO steps, and they read different fields:
     *
     * 1. DISCOVERY (semantic, no text budget). 'description' and EACH 'example_utterances' entry become
     *    their OWN embedding vector, so several phrasings can each find this skill. Add utterances
     *    generously - they cost only embedding tokens and never take anything away from another skill.
     * 2. SELECTION (a language model reads cards). Only about 240 characters of the description reach the
     *    card, cut at a sentence boundary. 'is' and 'not' are printed there as IS: / NOT: lines.
     *
     * Therefore: write 'description' as what this skill DOES, in the words a user would use, and put every
     * boundary against a neighbouring skill into 'not'. Do not write the boundary into the description. A
     * vector carries no negation - "not the built-in fields" lands NEXT TO "built-in fields" and on top of
     * that names the competitor, so a boundary sentence inside the description attracts exactly the
     * requests it was written to keep away (#2453). The selector, unlike a vector, does read negation.
     *
     * Both clauses are English, one short clause each, at most 120 characters (they are printed for every
     * skill when the full catalogue goes into the prompt). Name the sibling in brackets. If this skill has
     * no sibling it can be confused with, DELETE both keys - an absent line is better than an empty one.
     *
     *   'is'  => 'Custom fields an administrator defined for booking options.',
     *   'not' => 'The built-in properties of a booking option (list_option_properties).',
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => {{DESCRIPTION}},
            'is' => {{IS}},
            'not' => {{NOT}},
            'example_utterances' => [
{{UTTERANCES}}
            ],
            'readonly' => $this->is_read_only(),
            'properties' => [
{{PROPERTIES}}
            ],
            'prompt_meta' => [
                'intent' => {{INTENT}},
                'anchor_fields' => [{{ANCHORS}}],
                'context_scopes' => [{{SCOPES}}],
            ],
        ];
    }

PHP;
    }

    /**
     * get_required_native_capabilities() block.
     *
     * @return string
     */
    private static function skill_template_native_caps(): string {
        return <<<'PHP'
    /**
     * Native Moodle capabilities the underlying action requires (Gate 2). The engine enforces these
     * at the OPERATING context, so the agent never grants a right the user does not natively have.
     * Return [] only for a read-only skill with no extra gate.
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return [{{CAPABILITIES}}];
    }

PHP;
    }

    /**
     * check_structure() block.
     *
     * @return string
     */
    private static function skill_template_check_structure(): string {
        return <<<'PHP'
    /**
     * Cheap, pure structural validation (NO database access).
     *
     * @param array $input raw input from the planner
     * @return array{valid: bool, errors: string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        // TODO (you): check required fields are present / well-formed, e.g.:
        // if (trim((string)($input['yourfield'] ?? '')) === '') { $errors[] = 'yourfield is required.'; }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

PHP;
    }

    /**
     * Mutating-only methods: preflight, the pre-confirmation preview and the confirm-queue identity.
     *
     * @return string
     */
    private static function skill_template_mutating_methods(): string {
        return <<<'PHP'
    /**
     * Deep validation + preparation. Resolve entities (DB lookups), authorise the user, and return
     * the data execute() will act on. DO NOT mutate anything here.
     *
     * Return one of (DTO-free - the base class wraps these into the engine result):
     *   $this->pass($preparedinput)              - ready to execute
     *   $this->confirmable($prepared, $issues)   - needs user confirmation
     *   $this->invalid($issues)                  - cannot proceed
     *
     * @param array $input raw input from the planner
     * @param int $contextid operating context id
     * @param int $userid acting user id
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        // TODO (you): resolve the target entity from $input, authorise via
        // $this->require_native_capabilities(\context::instance_by_id($contextid), $userid),
        // and collect everything execute() needs into prepared_input.
        return $this->pass($input);
    }

    /**
     * Human-readable preview of the PROPOSED action, shown before the user confirms (tier-3).
     *
     * Return ['title' => ..., 'summary' => ..., 'rows' => [['label' => ..., 'value' => ...], ...]]
     * with ALL text via get_string() in the conversation language (read $input['outputlang']):
     *   get_string_manager()->get_string($id, $component, $a, (string)($input['outputlang'] ?? '')).
     * Collapse/relabel raw params into meaning (the user should not read a JSON dump). Returning the
     * parent call keeps the generic, schema-derived field preview as a floor until you tailor it.
     *
     * @param array $input prepared (or proposed) input
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        // TODO (you): build a tailored {title, summary, rows} descriptor (localized via get_string).
        return parent::describe_proposed_action($input);
    }

    /**
     * Identity used to de-duplicate confirm-queue items: two requests that should count as "the same"
     * action must hash to the same identity. Keep it to the user-facing essentials (target + key
     * params), normalized; omit volatile/formatting-only fields.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        // TODO (you): return the essentials, e.g. ['task' => $this->get_name(), 'target' => ...].
        return ['task' => $this->get_name()];
    }

PHP;
    }

    /**
     * Read-only note (no preflight/confirmation/proposal-preview needed).
     *
     * @return string
     */
    private static function skill_template_readonly_note(): string {
        return <<<'PHP'
    // Read-only (R0): no run_preflight()/confirmation is required - the skill resolves its target and
    // reads directly in execute(). There is no pre-confirmation preview (nothing is changed); use
    // get_result_preview() below to render the result. If this skill needs admin-provisioned
    // infrastructure before it is useful, override requires_explicit_activation() to return true.

PHP;
    }

    /**
     * execute() block.
     *
     * @return string
     */
    private static function skill_template_execute(): string {
        return <<<'PHP'
    /**
     * Perform the action. For a mutating skill use ONLY $preparedinput from run_preflight() - do not
     * re-resolve. For a read-only skill resolve + read here directly.
     *
     * @param array $preparedinput prepared input (mutating) or raw input (read-only)
     * @param int $contextid operating context id
     * @param int $userid acting user id
     * @return array result, e.g. ['status' => 'executed', 'detail' => '...', 'observation_full' => '...']
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        // TODO (you): implement the real behaviour here, then remove this placeholder return.
        // Until implemented this skill intentionally does nothing and reports it is unfinished.
        return [
            'status' => 'executed',
            'detail' => self::NOT_IMPLEMENTED_MESSAGE,
            'observation_full' => 'Scaffold stub "{{SKILLNAME}}" is not implemented yet.',
        ];
    }

    /** Placeholder message shown until the real behaviour is implemented. */
    private const NOT_IMPLEMENTED_MESSAGE = {{NOTIMPLEMENTED}};

PHP;
    }

    /**
     * get_result_preview() block.
     *
     * @return string
     */
    private static function skill_template_result_preview(): string {
        return <<<'PHP'
    /**
     * Optional rich preview of the RESULT (rendered in the agent's side panel after execution). While
     * unimplemented this shows an "under construction" card; replace it with a real preview, or return
     * null for no preview. The block is self-contained data {type, html?, js_module?, payload?}; the
     * engine forwards it verbatim, so it never needs to know your domain.
     *
     * @param array $resultentry the stored result entry from execute()
     * @param int $contextid operating context id
     * @param int $userid acting user id
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        return [
            'type' => 'under_construction',
            'html' => '<div class="text-center p-4">'
                . '<i class="fa-solid fa-person-digging fa-bounce fa-3x text-warning" aria-hidden="true"></i>'
                . '<p class="mt-3 mb-0">' . s(self::NOT_IMPLEMENTED_MESSAGE) . '</p>'
                . '</div>',
        ];
    }

PHP;
    }

    /**
     * get_message_triggers() block.
     *
     * @return string
     */
    private static function skill_template_message_triggers(): string {
        return <<<'PHP'
    /**
     * Example phrases that should route the planner to this skill. Keep them natural and varied.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => {{TRIGGERID}},
                'description' => {{TRIGGERDESC}},
                'examples' => [{{TRIGGEREXAMPLES}}],
            ],
        ];
    }

PHP;
    }

    /**
     * Class closing brace.
     *
     * @return string
     */
    private static function skill_template_footer(): string {
        return "}\n";
    }

    /**
     * Render the properties block of get_schema().
     *
     * @param array[] $properties
     * @return string
     */
    private static function render_properties(array $properties): string {
        if (empty($properties)) {
            // Provide one illustrative property so the developer sees the required shape.
            $properties = [[
                'name' => 'yourfield',
                'type' => 'string',
                'description' => 'TODO: describe this input for the planner.',
                'required' => false,
            ]];
        }

        $lines = [];
        foreach ($properties as $prop) {
            $name = self::php_single_quote((string)$prop['name']);
            $type = self::php_single_quote((string)$prop['type']);
            $desc = self::php_single_quote((string)$prop['description']);
            $required = !empty($prop['required']) ? 'true' : 'false';
            $lines[] = "                {$name} => ["
                . "\n                    'type' => {$type},"
                . "\n                    'description' => {$desc},"
                . "\n                    'required' => {$required},"
                . "\n                ],";
        }
        return implode("\n", $lines);
    }

    /**
     * Render a PHP array body of single-quoted strings (without the surrounding brackets).
     *
     * @param string[] $items
     * @return string
     */
    private static function render_quoted_list(array $items): string {
        if (empty($items)) {
            return '';
        }
        return implode(', ', array_map([self::class, 'php_single_quote'], $items));
    }

    /**
     * Render the example_utterances block: one discovery anchor per line.
     *
     * Each entry becomes its own embedding vector, so they are written one per line to invite the author
     * to add more; a single inline list reads like a closed set.
     *
     * @param string[] $items
     * @return string
     */
    private static function render_utterances(array $items): string {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = '                ' . self::php_single_quote((string)$item) . ',';
        }
        return implode("\n", $lines);
    }

    /**
     * Build the db/access.php capability snippet.
     *
     * @param array $spec
     * @return string
     */
    private static function build_access_snippet(array $spec): string {
        $contextlevel = in_array('course', $spec['context_scopes'], true) ? 'CONTEXT_COURSE' : 'CONTEXT_MODULE';
        $captype = $spec['readonly'] ? 'read' : 'write';
        return "<?php\n"
            . "// Add this entry to your plugin's db/access.php \$capabilities array.\n"
            . "// The agent governs each skill behind a per-skill capability of the form\n"
            . "// '<component>:skill_<normalized_skill_name>'.\n\n"
            . "'{$spec['capability']}' => [\n"
            . "    'captype' => '{$captype}',\n"
            . "    'contextlevel' => {$contextlevel},\n"
            . "    'archetypes' => [\n"
            . "        'editingteacher' => CAP_ALLOW,\n"
            . "        'manager' => CAP_ALLOW,\n"
            . "    ],\n"
            . "],\n";
    }

    /**
     * Build the lang strings snippet (capability label).
     *
     * @param array $spec
     * @return string
     */
    private static function build_lang_snippet(array $spec): string {
        // Capability lang key: '<frankenstyle>:<permission>' renders as '<component>/<permission>'.
        $key = str_replace('/', ':', $spec['capability']);
        return "<?php\n"
            . "// Add to your plugin's lang/en/{component}.php for the capability label.\n\n"
            . "\$string['{$key}'] = 'Use the {$spec['skillname']} AI skill';\n";
    }

    /**
     * Build the README with the exact wiring steps.
     *
     * @param array $spec
     * @param string $relativepath
     * @return string
     */
    private static function build_readme(array $spec, string $relativepath): string {
        $warnings = '';
        if (!empty($spec['warnings'])) {
            $warnings = "\n> NOTE:\n> - " . implode("\n> - ", $spec['warnings']) . "\n";
        }
        // phpcs:disable moodle.Strings.ForbiddenStrings.Found -- Literal backticks in scaffold README/Markdown template content, not shell execution.
        return "# Skill template: {$spec['skillname']}\n"
            . $warnings
            . "\nThis bundle scaffolds an AI agent skill for component `{$spec['component']}`.\n"
            . "It fills the **contract** (name, schema, risk class, capability, triggers) and leaves the\n"
            . "**behaviour** to you - `run_preflight()` and `execute()` contain `TODO (you)` markers and the\n"
            . "skill is inert until you implement them.\n\n"
            . "## 1. Drop the skill file\n"
            . "Copy the generated file to:\n\n    {$relativepath}\n\n"
            . "The agent auto-discovers any class under `classes/local/wizard/<domain>/skills/` that\n"
            . "extends `base_skill`, has a no-argument constructor and returns a unique `get_name()` -\n"
            . "**no** `skill_provider` class is required. (A provider is only needed for advanced cases\n"
            . "such as custom issue codes, input normalizers or contextual prompt packs.)\n\n"
            . "The engine types the skill imports (`\\...\\local\\wizard\\engine\\*`) are aliases the\n"
            . "active engine registers automatically before loading your class - this bundle ships no\n"
            . "engine files and you must not create any.\n\n"
            . "## 2. Declare the capability\n"
            . "Add the entry from `snippets/db_access.php.txt` to your plugin's `db/access.php`, and the\n"
            . "label from `snippets/lang_strings.php.txt` to your language file. The skill capability is:\n\n"
            . "    {$spec['capability']}\n\n"
            . "## 3. Bump version & upgrade\n"
            . "Increase your plugin's `version.php` and run the Moodle upgrade so the new capability and\n"
            . "skill are registered.\n\n"
            . "## 4. Enable the skill\n"
            . "Skills are **default-off**. Enable `{$spec['skillname']}` on the agent governance page\n"
            . "(Site administration > Plugins > Booking AI agent > Skill governance).\n\n"
            . "## 5. Rebuild skill embeddings\n"
            . "So the planner can route to your skill, rebuild the skill-catalog embeddings from the\n"
            . "governance page (or via the agent's embeddings CLI).\n\n"
            . "## 6. Implement the behaviour\n"
            . "Read-only (R0) skills: implement `execute()` (resolve + read) and an optional\n"
            . "`get_result_preview()`. Mutating (R1/R2/R3) skills: fill `run_preflight()` (resolve +\n"
            . "authorise, no mutation), `execute()` (act using only the prepared input), and tailor\n"
            . "`describe_proposed_action()` so the confirmation shows a readable summary (all text via\n"
            . "`get_string()` in the conversation language) instead of a raw parameter dump.\n";
        // phpcs:enable moodle.Strings.ForbiddenStrings.Found
    }

    /**
     * Package the files into a base64-encoded ZIP.
     *
     * @param array $spec
     * @param array $files
     * @return array{0:string,1:string} [base64, filename]
     */
    private static function build_zip(array $spec, array $files): array {
        $tmpdir = make_request_directory();
        $zippath = $tmpdir . '/skill_template.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zippath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('cannotcreatetempdir', 'error');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = (string)file_get_contents($zippath);
        @unlink($zippath);

        // Name the archive after the actual skill, e.g. "myplugin.archive_item.zip".
        $filename = $spec['skillname'] . '.zip';
        return [base64_encode($bytes), $filename];
    }

    // Small helpers.

    /**
     * Normalize the properties list.
     *
     * @param mixed[] $properties
     * @return array[]
     */
    private static function normalize_properties(array $properties): array {
        $result = [];
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $name = self::slugify((string)($prop['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $type = trim((string)($prop['type'] ?? 'string'));
            if (!in_array($type, ['string', 'integer', 'number', 'boolean', 'object', 'array'], true)) {
                $type = 'string';
            }
            $result[] = [
                'name' => $name,
                'type' => $type,
                'description' => trim((string)($prop['description'] ?? '')) ?: 'TODO: describe this input.',
                'required' => !empty($prop['required']),
            ];
        }
        return $result;
    }

    /**
     * Normalize triggers, falling back to a single derived trigger.
     *
     * @param mixed[] $triggers
     * @param string $description
     * @return array[]
     */
    private static function normalize_triggers(array $triggers, string $description): array {
        $result = [];
        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }
            $examples = self::normalize_string_list((array)($trigger['examples'] ?? []));
            if (empty($examples)) {
                continue;
            }
            $result[] = [
                'description' => trim((string)($trigger['description'] ?? '')) ?: 'User asks for this action.',
                'examples' => $examples,
            ];
        }
        if (empty($result)) {
            $result[] = [
                'description' => 'User asks for this action.',
                'examples' => array_values(array_filter([
                    $description !== '' ? $description : 'TODO: add a natural example phrase.',
                ])),
            ];
        }
        return [$result[0]];
    }

    /**
     * Trim, lowercase, and string-cast a flat list.
     *
     * @param mixed[] $items
     * @return string[]
     */
    private static function normalize_string_list(array $items): array {
        $result = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * Derive a plausible skill namespace from a component (e.g. "mod/booking" -> "booking").
     *
     * @param string $component
     * @return string
     */
    private static function derive_namespace(string $component): string {
        $parts = preg_split('#[/_]#', $component) ?: [];
        $candidate = self::slugify((string)end($parts));
        if ($candidate === '') {
            $candidate = 'myplugin';
        }
        // Reserved namespaces (booking/core/wizard) belong to the agent engine. If the derived
        // namespace is reserved, fall back to the full component name (e.g. local/wizard ->
        // local_wizard), which is unique to the plugin and never reserved.
        if (!skill_contract_validator::component_may_register_namespace($component, $candidate)) {
            $fallback = self::slugify(str_replace('/', '_', $component));
            if ($fallback !== '') {
                $candidate = $fallback;
            }
        }
        return $candidate;
    }

    /**
     * Slugify a string into a lowercase [a-z][a-z0-9_]* identifier.
     *
     * @param string $value
     * @return string
     */
    private static function slugify(string $value): string {
        $value = core_text::strtolower(trim($value));
        // Transliterate instead of mangling umlauts to underscores (#2338).
        $value = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'é', 'è', 'ê', 'à', 'ç'],
            ['ae', 'oe', 'ue', 'ss', 'e', 'e', 'e', 'a', 'c'],
            $value
        );
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        // Limit to a sane length and ensure it starts with a letter.
        $value = (string)preg_replace('/^[^a-z]+/', '', $value);
        if (core_text::strlen($value) > 40) {
            $value = core_text::substr($value, 0, 40);
            // Cut at the last word boundary so no fragment like "_actio" survives (#2338).
            $cut = strrpos($value, '_');
            if ($cut !== false && $cut >= 20) {
                $value = core_text::substr($value, 0, $cut);
            }
            $value = trim($value, '_');
        }
        return $value;
    }

    /**
     * Map a risk class value to its skill_risk_class constant name.
     *
     * @param string $risk
     * @return string
     */
    private static function risk_constant_name(string $risk): string {
        switch ($risk) {
            case skill_risk_class::R1:
                return 'R1';
            case skill_risk_class::R2:
                return 'R2';
            case skill_risk_class::R3:
                return 'R3';
            default:
                return 'R0';
        }
    }

    /**
     * Escape a value for embedding inside single-quoted generated PHP.
     *
     * @param string $value
     * @return string
     */
    private static function php_single_quote(string $value): string {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}

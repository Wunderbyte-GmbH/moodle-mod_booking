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

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\interfaces\attachment_resolver;
use bookingextension_agent\local\wizard\interfaces\thread_memory;
use bookingextension_agent\local\wizard\interfaces\skill_catalog;
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\services\attachment\attachment_token_service;
use bookingextension_agent\local\wizard\services\conversation_thread_memory;
use bookingextension_agent\local\wizard\services\skill_catalog_discovery;

/**
 * Base class for AI skills.
 *
 * Provides default pass-through implementations for structural and preflight
 * validation without legacy validate() shims.
 *
 * Migration path for subclasses:
 *  1. Override check_structure() for pure structural checks.
 *  2. Override preflight()       for DB-dependent deep validation.
 *  3. Override execute()         to use $preparedinput from preflight().
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_skill implements skill_interface {
    /** @var bool */
    protected bool $readonly;

    /** @var string */
    protected string $riskclass;

    /**
     * Constructor.
     *
     * @param bool $readonly
     * @param string $riskclass
     */
    public function __construct(bool $readonly, string $riskclass) {
        if (!skill_risk_class::is_valid($riskclass)) {
            throw new \coding_exception('Invalid risk class declared for skill base: ' . trim($riskclass));
        }

        $this->readonly = $readonly;
        $this->riskclass = trim($riskclass);
    }

    /**
     * Return whether the skill is read-only.
     *
     * @return bool
     */
    public function is_read_only(): bool {
        return $this->readonly;
    }

    /**
     * Return the declared risk class.
     *
     * @return string
     */
    public function get_risk_class(): string {
        return $this->riskclass;
    }

    /**
     * CRUD letter recorded for this skill in the audit log (r|c|u|d).
     *
     * Drives the precise `other['crud']` on the {@see \bookingextension_agent\event\skill_executed}
     * audit event (the log-report CRUD column is fixed per event class; see that class). Default:
     * read-only skills report 'r', writers report 'u'. Skills that create ('c') or delete/cancel
     * ('d') override this so reports and SIEM rules can classify the operation precisely.
     *
     * @return string
     */
    public function get_log_crud(): string {
        return $this->is_read_only() ? 'r' : 'u';
    }

    /**
     * User ids this skill's action concerns, beyond the acting user.
     *
     * When a skill reads or changes data about *other* users (e.g. booking a participant,
     * diagnosing another user's enrolment), it returns those ids here so the audit event carries
     * a `relateduserid` — surfacing the action in the target user's privacy export and letting
     * admins query "everything the agent did affecting user X". Default: none.
     *
     * @param array $input the skill input
     * @return int[]
     */
    public function get_related_userids(array $input): array {
        return [];
    }

    /**
     * Whether this skill must be enabled explicitly by an admin, even though it is read-only.
     *
     * Read-only skills are active out of the box (see skill_registry::is_skill_active). A skill
     * overrides this to true when it depends on supporting infrastructure an admin has to set up
     * first (e.g. a documentation corpus / embeddings index) and would otherwise run in a degraded,
     * not-useful state until then. Default: false (auto-enabled when read-only).
     *
     * @return bool
     */
    public function requires_explicit_activation(): bool {
        return false;
    }

    /**
     * Whether this skill may be exposed to external MCP clients (e.g. Claude).
     *
     * Default true: a skill reachable in chat is reachable over MCP too, and the same
     * governance/licence/capability gates apply. A skill overrides this to false to stay
     * chat-only — e.g. one whose value depends on the interactive conversation, or that a
     * third-party author deliberately wants to keep off the programmatic surface. This only
     * governs visibility/callability over MCP; it never widens any permission. Admins can
     * still narrow exposure further per skill via the mcpexposedskills allowlist.
     *
     * @return bool
     */
    public function is_mcp_exposable(): bool {
        return true;
    }

    /**
     * Minimum Moodle context level this skill needs to operate (runtime context switch).
     *
     * Default CONTEXT_MODULE = behaves exactly as today. A skill that must act on a broader
     * scope (e.g. a course question bank) overrides this with CONTEXT_COURSE / CONTEXT_SYSTEM;
     * the executor then resolves the operating context via context_resolver before execution.
     *
     * @return int A Moodle CONTEXT_* level constant.
     */
    public function get_required_context_level(): int {
        return CONTEXT_MODULE;
    }

    /**
     * Whether this skill can run against an explicitly named operating context (cross-context).
     *
     * Default false = the skill always runs in the ambient context (today's behaviour). A skill
     * opts in by returning true AND implementing {@see self::get_target_selector()}; the operating
     * context is then resolved by skill_operating_context_resolver and the skill's native
     * capability (Gate 2) is re-checked there.
     *
     * OPT-IN SAFETY RULE (cross-context blueprint §8): only return true if this skill's capability
     * check binds to the OPERATING context — either by declaring
     * {@see self::get_required_native_capabilities()} (preferred; the engine enforces it at the
     * operating context), or by an inline require_capability/has_capability that uses the PASSED
     * $contextid (the operating context), never a hardwired ambient cmid/$USER. A skill relying
     * only on the ambient-checked governance capability (Gate 1) must NOT opt in.
     *
     * @return bool
     */
    public function supports_target_context(): bool {
        return false;
    }

    /**
     * The Moodle context level a cross-context target names (defaults to the required level).
     *
     * @return int A Moodle CONTEXT_* level constant.
     */
    public function get_target_context_level(): int {
        return $this->get_required_context_level();
    }

    /**
     * Build the operating-context target selector from this command's input, or null for none.
     *
     * Only consulted when {@see self::supports_target_context()} is true. Returning null or an
     * empty selector keeps the skill in the ambient context.
     *
     * @param array $input
     * @return target_selector|null
     */
    public function get_target_selector(array $input): ?target_selector {
        return null;
    }

    /**
     * Native Moodle capabilities required for this skill's underlying core action (Gate 2).
     *
     * The agent must never grant a right the user does not natively have. Every mutating
     * skill declares the native capability(ies) of the core action it performs (e.g.
     * 'mod/booking:updatebooking', 'moodle/question:add'); preflight enforces them at the
     * operating context via require_native_capabilities(). Default empty = read-only/none.
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return [];
    }

    /**
     * Enforce the skill's native Moodle capabilities at the given (operating) context.
     *
     * Call this from preflight()/execute() with the operating context so the user's own
     * Moodle rights gate the action — independent of the agent skill capability.
     *
     * @param \context $operatingcontext
     * @param int $userid
     * @return void
     */
    protected function require_native_capabilities(\context $operatingcontext, int $userid): void {
        foreach ($this->get_required_native_capabilities() as $capability) {
            require_capability($capability, $operatingcontext, $userid);
        }
    }

    /**
     * The active engine's attachment resolver (chat-upload token -> temp file).
     *
     * Skills/their services use $this->attachments()->resolve(...) instead of naming the engine's
     * concrete attachment service, so they stay engine-agnostic (the conditional-extends base
     * binds to whichever engine is installed).
     *
     * @return attachment_resolver
     */
    protected function attachments(): attachment_resolver {
        return new attachment_token_service();
    }

    /**
     * The active engine's per-user/per-context conversation-thread key/value memory.
     *
     * @return thread_memory
     */
    protected function thread_memory(): thread_memory {
        return new conversation_thread_memory();
    }

    /**
     * The active engine's skill catalog (enumeration of a component's skills).
     *
     * @return skill_catalog
     */
    protected function skill_catalog(): skill_catalog {
        return new skill_catalog_discovery();
    }

    /**
     * Default example input.
     *
     * Concrete skill families can override this to provide centralized example
     * metadata close to their skill implementations.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [];
    }

    /**
     * Live construction grounding for THIS operating context (constructor phase only).
     *
     * Unlike get_example_input()/get_contextual_prompt_packs() — both static — this hook runs
     * after the skill is chosen and receives the resolved context, so a skill can inject the
     * actual site/context facts the constructor otherwise has to GUESS (e.g. the real price
     * category identifiers, the bookable activities). Hard rule: this produces INSTRUCTIONS and
     * DB-derived data for the model, never lexical detection of the user's text.
     *
     * Return shape (all optional): [
     *   'guidance' => string[],            // appended to the constructor guidance block
     *   'example_parameters' => array,     // merged over the static example_parameters
     * ]
     *
     * @param int $contextid Resolved operating context id.
     * @param int $userid Acting user id.
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        return [];
    }

    /** @var string[] Framework-level control params that carry no user meaning in a preview. */
    private const PROPOSED_ACTION_HIDDEN_KEYS = ['outputlang'];

    /**
     * Describe the proposed input of a (mutating) skill call for the confirmation preview.
     *
     * This is the pre-execution counterpart of the optional get_result_preview(): instead of a raw
     * JSON dump of the parameter object, it returns a human-readable, data-only descriptor that the
     * engine forwards verbatim (see proposed_action_preview + the client 'proposed_action' renderer).
     * The engine stays skill-agnostic; only the skill knows what its fields mean.
     *
     * The default implementation is generic and works for every skill without any per-skill code
     * (tiers 1+2): it surfaces ONLY the fields the skill declares in its prompt-facing schema, maps
     * each to a label (schema 'label' if present, otherwise a humanized key) and formats the value by
     * its declared type, dropping framework-internal, empty and falsy entries. Skills that want a
     * richer preview (tier 3) override this method to collapse/relabel fields and add a one-line
     * summary; they may reuse the protected helpers below.
     *
     * @param array $input Proposed (prepared) input for the skill call.
     * @return array{title:string,summary:string,rows:array[]}|null
     *         Null when there is nothing worth showing.
     */
    public function describe_proposed_action(array $input): ?array {
        $schema = (array)$this->get_schema();
        $properties = is_array($schema['properties'] ?? null) ? (array)$schema['properties'] : [];

        $rows = [];
        foreach ($input as $key => $value) {
            $key = (string)$key;
            if (in_array($key, self::PROPOSED_ACTION_HIDDEN_KEYS, true)) {
                continue;
            }
            // Only surface fields the skill actually declares in its prompt-facing schema; this drops
            // internal/derived params (e.g. optiontype) that the skill sets behind the scenes.
            if (!isset($properties[$key]) || !is_array($properties[$key])) {
                continue;
            }
            $formatted = $this->format_proposed_action_value($value, (string)($properties[$key]['type'] ?? 'string'));
            if ($formatted === null) {
                continue;
            }
            $label = trim((string)($properties[$key]['label'] ?? ''));
            if ($label === '') {
                $label = $this->humanize_identifier($key);
            }
            $rows[] = ['label' => $label, 'value' => $formatted];
        }

        if (empty($rows)) {
            return null;
        }

        return [
            'title' => $this->humanize_identifier($this->short_skill_name()),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Return the skill name without its namespace prefix (the part after the last dot).
     *
     * @return string
     */
    protected function short_skill_name(): string {
        $name = trim($this->get_name());
        $pos = strrpos($name, '.');
        return $pos === false ? $name : (string)substr($name, $pos + 1);
    }

    /**
     * Turn a snake_case / dotted identifier into a human-readable label (first letter capitalized).
     *
     * @param string $identifier
     * @return string
     */
    protected function humanize_identifier(string $identifier): string {
        $text = str_replace(['_', '.'], ' ', trim($identifier));
        $text = trim((string)preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }
        return \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }

    /**
     * Format a single proposed-input value for display, honoring its declared schema type.
     *
     * Returns null when the value carries nothing worth showing (empty, or a falsy boolean), so the
     * caller can omit the row entirely.
     *
     * @param mixed $value
     * @param string $type Declared schema type (string|integer|boolean|array|object).
     * @return string|null
     */
    protected function format_proposed_action_value($value, string $type): ?string {
        if ($value === null) {
            return null;
        }

        if ($type === 'boolean' || is_bool($value)) {
            return $this->is_truthy_preview_flag($value) ? get_string('yes') : null;
        }

        if (is_array($value)) {
            return $this->format_proposed_action_array($value);
        }

        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    /**
     * Interpret a (possibly string) value as a boolean flag for preview purposes.
     *
     * @param mixed $value
     * @return bool
     */
    private function is_truthy_preview_flag($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Format an array value (list or map) into a compact human-readable string, or null when empty.
     *
     * @param mixed[] $value
     * @return string|null
     */
    private function format_proposed_action_array(array $value): ?string {
        if (empty($value)) {
            return null;
        }

        $islist = array_keys($value) === range(0, count($value) - 1);
        $parts = [];
        foreach ($value as $itemkey => $itemvalue) {
            if (is_array($itemvalue) || is_object($itemvalue)) {
                continue;
            }
            $text = trim((string)$itemvalue);
            if ($text === '') {
                continue;
            }
            $parts[] = $islist ? $text : ($this->humanize_identifier((string)$itemkey) . ': ' . $text);
        }

        return empty($parts) ? null : implode(', ', $parts);
    }

    /**
     * DTO-free prompt-contract payload. Concrete skills override THIS (returning a plain array)
     * instead of get_prompt_contract(), so they never name the skill_prompt_contract DTO. The
     * default derives the payload from the skill schema.
     *
     * @return array
     */
    /**
     * Field names the schema marks as required.
     *
     * @param array $schema
     * @return string[]
     */
    protected static function required_fields_of(array $schema): array {
        $properties = (array)($schema['properties'] ?? ($schema['input']['properties'] ?? []));
        $required = [];
        foreach ($properties as $field => $definition) {
            if (is_array($definition) && !empty($definition['required'])) {
                $required[] = (string)$field;
            }
        }

        return array_values($required);
    }

    /**
     * Build the prompt contract payload the registry and the planner catalogue read.
     *
     * @return array
     */
    protected function prompt_contract_payload(): array {
        $schema = (array)$this->get_schema();
        $promptmeta = (array)($schema['prompt_meta'] ?? []);
        $skillname = trim($this->get_name());
        $namespace = '';
        if ($skillname !== '' && strpos($skillname, '.') !== false) {
            $namespace = (string)substr($skillname, 0, (int)strpos($skillname, '.'));
        }

        return [
            'intent' => trim((string)($promptmeta['intent'] ?? '')),
            'anchors' => array_values(array_filter((array)($promptmeta['anchor_fields'] ?? []), 'is_string')),
            'minimal_input' => array_values(array_filter((array)($promptmeta['input_fields_for_prompt'] ?? []), 'is_string')),
            // What the SCHEMA requires, which is not the same as what is worth showing. The selection catalogue
            // used to print minimal_input behind "REQUIRED:", so optional fields looked mandatory and the selector
            // asked the user for them instead of routing (baseline run 15: DP-1, CC-3, CC-4, UA-4).
            'required_input' => self::required_fields_of($schema),
            // Whether the skill accepts an EMPTY input at all. The schema flag is not the whole truth: 16 of the
            // registered skills declare no required field and still reject {} in check_structure() — among them
            // update_option_trainer, get_option_details and generate_questions. Printing "REQUIRED: none" for
            // those told the selector they were free of charge, which is how EU-2 lost course.enrol_user to a
            // booking skill in run 19. check_structure() is pure and does no DB work, so asking it is cheap.
            'accepts_empty_input' => $this->accepts_empty_input(),
            // Alternatives the skill's own structure gate accepts: [['optionid', 'optionquery']] means it
            // needs ONE of the two. Many skills express their mandatory input this way instead of through the
            // schema's `required` flag, and until 2026-09-20 the catalogue had no way to say so: it either
            // lied ("REQUIRED: none") or said nothing at all, and saying nothing cost GOD-1 and GOD-2 in run 21
            // because the model reads silence as ignorance rather than freedom.
            'required_groups' => self::required_groups_of($schema),
            'example_input' => $this->get_example_input(),
            'namespace' => $namespace,
            'version' => max(1, (int)($schema['version'] ?? 1)),
            'capabilities' => array_values(array_filter((array)($promptmeta['capabilities'] ?? []), 'is_string')),
            'context_scopes' => array_values(array_filter((array)($promptmeta['context_scopes'] ?? ['module']), 'is_string')),
            'risk_class' => skill_risk_class::is_valid($this->riskclass) ? $this->riskclass : '',
        ];
    }

    /**
     * DTO wrapper around prompt_contract_payload(). FINAL: concrete skills override the DTO-free
     * prompt_contract_payload() instead, so no skill names the skill_prompt_contract type.
     *
     * @return skill_prompt_contract
     */
    final public function get_prompt_contract(): skill_prompt_contract {
        return new skill_prompt_contract($this->prompt_contract_payload());
    }

    /**
     * Default structural validation — always passes.
     *
     * Override in concrete skills to check required fields without DB access.
     *
     * @param  array $input
     * @return array{valid:bool,errors:string[]}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => []];
    }

    /**
     * Primitive preflight result: input is valid and ready for execution.
     *
     * Engine-type-free helper for run_preflight(); base_skill::preflight() wraps it
     * into a preflight_result_v2. Concrete skills should return these instead of
     * constructing the DTO directly (keeps skills free of any engine type).
     *
     * @param  array $preparedinput
     * @return array{status:string,prepared_input:array,issues:array}
     */
    final protected function pass(array $preparedinput): array {
        return ['status' => 'pass', 'prepared_input' => $preparedinput, 'issues' => []];
    }

    /**
     * Primitive preflight result: fatal issues block execution.
     *
     * @param  array $issues structured issue objects (arrays)
     * @return array{status:string,prepared_input:array,issues:array}
     */
    final protected function invalid(array $issues): array {
        return ['status' => 'invalid', 'prepared_input' => [], 'issues' => $issues];
    }

    /**
     * Primitive preflight result: recoverable issues need user confirmation.
     *
     * @param  array $preparedinput
     * @param  array $issues structured issue objects (arrays)
     * @return array{status:string,prepared_input:array,issues:array}
     */
    final protected function confirmable(array $preparedinput, array $issues): array {
        return ['status' => 'confirmable', 'prepared_input' => $preparedinput, 'issues' => $issues];
    }

    /**
     * DTO-free deep preflight. Concrete skills override THIS (returning a primitive
     * array via pass()/invalid()/confirmable()) instead of preflight(), so they never
     * name an engine DTO type. The default reproduces the structure-check behaviour.
     *
     * @param  array $input
     * @param  int   $contextid
     * @param  int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? true)) {
            $issues = [];
            foreach (array_merge((array)($structure['errors'] ?? []), (array)($structure['ambiguities'] ?? [])) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }

        $confirmableissues = array_values(array_filter(
            (array)($structure['issues'] ?? []),
            static fn($issue): bool => is_array($issue) && (string)($issue['severity'] ?? '') === 'needs_confirmation'
        ));
        if (!empty($confirmableissues)) {
            return $this->confirmable($input, $confirmableissues);
        }

        return $this->pass($input);
    }

    /**
     * DTO wrapper around run_preflight(): the single place a skill's primitive
     * preflight result is mapped onto the engine's preflight_result_v2.
     *
     * FINAL: concrete skills override the DTO-free run_preflight() instead, so no skill
     * ever names an engine DTO type in its signatures (enables the engine-agnostic
     * conditional-extends pattern).
     *
     * @param  array $input
     * @param  int   $contextid
     * @param  int   $userid
     * @return preflight_result_v2
     */
    final public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
        $result = $this->run_preflight($input, $contextid, $userid);
        switch ($result['status'] ?? 'pass') {
            case 'invalid':
                return preflight_result_v2::invalid($result['issues'] ?? []);
            case 'confirmable':
                return preflight_result_v2::confirmable($result['prepared_input'] ?? $input, $result['issues'] ?? []);
            default:
                return preflight_result_v2::ok($result['prepared_input'] ?? $input);
        }
    }

    /**
     * Whether this skill would accept an entirely empty input.
     *
     * Used to keep the catalogue honest: a skill that gates its mandatory fields in check_structure() instead
     * of the schema's `required` flag must not be advertised as requiring nothing.
     *
     * @return bool
     */
    protected function accepts_empty_input(): bool {
        if (!method_exists($this, 'check_structure')) {
            return true;
        }

        try {
            $result = (array)$this->check_structure([]);
        } catch (\Throwable $e) {
            // A gate that cannot even be asked is not evidence of freedom.
            return false;
        }

        if (!empty($result['valid'])) {
            return true;
        }

        // A rejection the gate itself flags RECOVERABLE_INPUT_ERROR is the SKILL asking the user, not a
        // precondition for routing to it: the selector may send the request here and the skill takes it from
        // there. question.generate_questions is the case that made this explicit — its `count` field says
        // "leave it out so the system asks (never invent a number)" (#B4), so declaring the field required
        // would invert its own contract and make the selector ask before the skill is ever reached.
        $codes = array_map('strval', (array)($result['issue_codes'] ?? []));

        return in_array('RECOVERABLE_INPUT_ERROR', $codes, true);
    }

    /**
     * Groups of alternative fields the skill requires one of.
     *
     * @param array $schema
     * @return array[] List of groups, each a list of field names.
     */
    protected static function required_groups_of(array $schema): array {
        $meta = (array)($schema['prompt_meta'] ?? []);
        $groups = [];
        foreach ((array)($meta['required_groups'] ?? []) as $group) {
            $fields = array_values(array_filter(array_map('strval', (array)$group)));
            if (count($fields) > 1) {
                $groups[] = $fields;
            }
        }

        return $groups;
    }
}

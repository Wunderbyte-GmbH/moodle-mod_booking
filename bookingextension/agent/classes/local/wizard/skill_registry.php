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
 * Skill schema registry.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard;

use core_component;
use core_text;
use bookingextension_agent\local\wizard\contracts\skill_family_contract;
use bookingextension_agent\local\wizard\interfaces\result_summary_provider_interface;
use bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface;
use bookingextension_agent\local\wizard\interfaces\summarizer\result_summary_contributor_interface;
use bookingextension_agent\local\wizard\interfaces\skill_input_normalizer_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\interfaces\skill_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;

/**
 * Central registry that maps skill names to their provider instances.
 *
 * Providers register themselves here. The orchestrator uses the registry
 * to embed skill schemas in the system prompt and the executor uses it to
 * dispatch commands to the correct provider.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_registry {
    /**
     * @var int Characters an IS:/NOT: discrimination clause may carry on a selector card.
     *
     * 120 until wave 20; a skill with four or five siblings (course.add_activity) cannot name them all in
     * that, and a fence that is not mutual is what the selector follows (LRP-4, nine of ten runs).
     */
    public const DISCRIMINATION_CAP = 160;

    /** @var int Cap of a declared when-text, equal to the WHEN: card line cap. */
    public const WHEN_CAP = 180;

    /** @var array component => provider instance */
    private array $providers = [];

    /** @var array skill name => skill instance */
    private array $skills = [];

    /** @var array skill name => provider instance */
    private array $skillproviders = [];

    /** @var array skill name => normalized governance metadata */
    private array $skillcontracts = [];

    /** @var string[] contract diagnostics collected during registration */
    private array $contractdiagnostics = [];

    /** @var result_summary_contributor_interface[] */
    private array $resultsummarycontributors = [];


    /**
     * Constructor.
     */
    public function __construct() {
    }

    /**
     * Register a skill provider.  All skills it declares are mapped to it.
     *
     * @param skill_provider_interface $provider
     * @return void
     */
    public function register(skill_provider_interface $provider): void {
        $this->providers[$provider->get_component()] = $provider;

        if ($provider instanceof result_summary_provider_interface) {
            try {
                $contributors = $provider->get_result_summary_contributors();
            } catch (\Throwable $e) {
                $this->add_contract_diagnostic(
                    'Ignoring result summary contributors from component ' . $provider->get_component()
                    . ' due to provider error: ' . get_class($e) . ': ' . $e->getMessage()
                );
                $contributors = [];
            }

            foreach ($contributors as $contributor) {
                if (!$contributor instanceof result_summary_contributor_interface) {
                    continue;
                }

                $class = get_class($contributor);
                $alreadyregistered = false;
                foreach ($this->resultsummarycontributors as $existing) {
                    if (get_class($existing) === $class) {
                        $alreadyregistered = true;
                        break;
                    }
                }

                if (!$alreadyregistered) {
                    $this->resultsummarycontributors[] = $contributor;
                }
            }
        }

        try {
            $providerskills = $provider->get_skills();
        } catch (\Throwable $e) {
            $this->add_contract_diagnostic(
                'Ignoring AI skills from component ' . $provider->get_component()
                . ' due to provider error: ' . get_class($e) . ': ' . $e->getMessage()
            );
            $this->append_provider_discovery_diagnostics($provider);
            $this->fail_on_contract_diagnostics_when_strict();
            return;
        }

        $this->append_provider_discovery_diagnostics($provider);

        foreach ($providerskills as $skill) {
            if (!$skill instanceof skill_interface) {
                $this->add_contract_diagnostic(
                    'Ignoring non-skill contribution from component ' . $provider->get_component()
                );
                continue;
            }

            try {
                $skillname = trim($skill->get_name());
            } catch (\Throwable $e) {
                $this->add_contract_diagnostic(
                    'Ignoring AI skill from component ' . $provider->get_component()
                    . ' because get_name() failed: ' . get_class($e) . ': ' . $e->getMessage()
                );
                continue;
            }

            if ($skillname === '') {
                $this->add_contract_diagnostic(
                    'Ignoring AI skill with empty name from component ' . $provider->get_component()
                );
                continue;
            }

            $skillnamespace = skill_contract_validator::extract_skill_namespace($skillname);
            if (!skill_contract_validator::component_may_register_namespace($provider->get_component(), $skillnamespace)) {
                $this->add_contract_diagnostic(
                    'Ignoring AI skill because namespace is reserved: ' . $skillname
                    . ' (component: ' . $provider->get_component() . ')'
                );
                continue;
            }

            if (isset($this->skills[$skillname])) {
                $this->add_contract_diagnostic(
                    'Duplicate AI skill name detected: ' . $skillname
                    . ' (component: ' . $provider->get_component() . '). Keeping first registered skill.'
                );
                continue;
            }

            try {
                $metadata = skill_contract_validator::build_skill_metadata($skill, $provider->get_component());
            } catch (\Throwable $e) {
                $this->add_contract_diagnostic(
                    'Ignoring skill due to metadata build error: ' . $skillname
                    . ' [' . get_class($e) . ': ' . $e->getMessage() . ']'
                );
                continue;
            }

            $validation = skill_contract_validator::validate_skill_metadata($metadata);
            if (!$validation['valid']) {
                $this->add_contract_diagnostic(
                    'Ignoring skill due to contract validation errors: ' . $skillname
                    . ' [' . implode(' | ', (array)$validation['errors']) . ']'
                );
                continue;
            }

            // Dev feedback: the governance gate ALWAYS enforces the name-derived capability
            // (<component>:skill_<normalized_name>). If the plugin forgot to define it in its
            // db/access.php, the skill registers but is always-denied — flag it loudly here rather
            // than letting it fail silently at runtime.
            $namecapability = skill_contract_validator::build_skill_capability_name(
                $provider->get_component(),
                $skillname
            );
            if ($namecapability === '' || get_capability_info($namecapability) === null) {
                $this->add_contract_diagnostic(
                    'Skill ' . $skillname . ' (component ' . $provider->get_component()
                    . ') has no defined access capability "' . $namecapability . '". Define it in the '
                    . "plugin's db/access.php, otherwise the skill is always denied."
                );
            }

            $this->skills[$skillname] = $skill;
            $this->skillcontracts[$skillname] = $metadata;
            $this->skillproviders[$skillname] = $provider;
        }

        $registryerrors = skill_contract_validator::validate_registry_contracts($this->skillcontracts);
        foreach ($registryerrors as $error) {
            $this->add_contract_diagnostic($error);
        }

        $this->fail_on_contract_diagnostics_when_strict();
    }

    /**
     * Return the skill for a given skill name, or null if not found.
     *
     * @param string $skillname
     * @return skill_interface|null
     */
    public function get_skill(string $skillname): ?skill_interface {
        return $this->skills[$skillname] ?? null;
    }

    /**
     * Return provider owning a given skill, or null when unavailable.
     *
     * @param string $skillname
     * @return skill_provider_interface|null
     */
    public function get_provider_for_skill(string $skillname): ?skill_provider_interface {
        return $this->skillproviders[$skillname] ?? null;
    }

    /**
     * Normalize skill input via optional provider-owned normalizer.
     *
     * @param string $skillname
     * @param array $input
     * @return array
     */
    public function normalize_skill_input(string $skillname, array $input): array {
        $provider = $this->get_provider_for_skill($skillname);
        if (!($provider instanceof skill_input_normalizer_provider_interface)) {
            return $input;
        }

        $normalizer = $provider->get_skill_input_normalizer();
        if ($normalizer === null) {
            return $input;
        }

        return $normalizer->normalize($skillname, $input);
    }

    /**
     * Return all registered skill names (the allow-list).
     *
     * @return string[]
     */
    public function get_skill_names(): array {
        return array_keys($this->skills);
    }

    /**
     * Return skill names for the given user/context filtered by executability.
     *
     * @param skill_executability_evaluator $evaluator
     * @param int $userid
     * @param int $contextid
     * @param bool $includeunavailable
     * @return string[]
     */
    public function get_skill_names_for_context(
        skill_executability_evaluator $evaluator,
        int $userid,
        int $contextid,
        bool $includeunavailable = false
    ): array {
        if ($includeunavailable) {
            return $this->get_skill_names();
        }

        return $evaluator->get_executable_skill_names($userid, $contextid);
    }

    /**
     * Return all registered skill instances.
     *
     * @return array
     */
    public function get_skills(): array {
        return $this->skills;
    }

    /**
     * Return normalized governance metadata for a single skill.
     *
     * @param string $skillname
     * @return array|null
     */
    public function get_skill_contract(string $skillname): ?array {
        return $this->skillcontracts[$skillname] ?? null;
    }

    /**
     * Return normalized governance metadata for all registered skills.
     *
     * @return array
     */
    public function get_skill_contracts(): array {
        return $this->skillcontracts;
    }

    /**
     * Return contract diagnostics collected during provider/skill registration.
     *
     * @return string[]
     */
    public function get_contract_diagnostics(): array {
        return $this->contractdiagnostics;
    }

    /**
     * Return all registered result summary contributors.
     *
     * @return result_summary_contributor_interface[]
     */
    public function get_result_summary_contributors(): array {
        return $this->resultsummarycontributors;
    }

    /**
     * Whether a skill is read-only.
     *
     * @param string $skillname
     * @return bool
     */
    public function is_read_only_skill(string $skillname): bool {
        $skill = $this->get_skill($skillname);
        return $skill ? $skill->is_read_only() : false;
    }

    /**
     * Return whether a skill is active according to governance metadata.
     *
     * @param string $skillname
     * @return bool
     */
    public function is_skill_active(string $skillname): bool {
        $meta = $this->get_skill_contract($skillname);
        if ($meta === null) {
            return false;
        }

        if ((bool)get_config('bookingextension_agent', 'aiskillenableall')) {
            return true;
        }

        $settingname = self::get_skill_toggle_setting_name($skillname);
        $configured = get_config('bookingextension_agent', $settingname);
        if ($configured !== false) {
            return (bool)$configured;
        }

        // Default: read-only skills are enabled out of the box so the agent is useful on a fresh
        // install; mutating skills stay off until an admin explicitly enables them in skill
        // governance. Read-only skills that depend on admin-provisioned infrastructure (e.g.
        // explain_docs needs a docs corpus / embeddings index) opt out via
        // requires_explicit_activation() so they are not auto-enabled into a degraded state.
        // This is only a discovery/visibility default — every execution is still gated by the
        // skill's specific capability (fail-closed in skill_executability_evaluator), so an enabled
        // read-only skill is usable only by users who actually hold that capability.
        $skill = $this->get_skill($skillname);
        if ($skill === null || !$skill->is_read_only()) {
            return false;
        }
        return !$skill->requires_explicit_activation();
    }

    /**
     * Return config key used for system-wide skill enabled/disabled flag.
     *
     * @param string $skillname
     * @return string
     */
    public static function get_skill_toggle_setting_name(string $skillname): string {
        $normalized = preg_replace('/[^a-z0-9]+/', '_', core_text::strtolower(trim($skillname)));
        $normalized = trim((string)$normalized, '_');
        if ($normalized === '') {
            $normalized = 'unknown_skill';
        }

        return 'aiskillenabled_' . $normalized;
    }

    /**
     * Return skill capability requirements from governance metadata.
     *
     * @param string $skillname
     * @return string[]
     */
    public function get_skill_capabilities(string $skillname): array {
        $meta = $this->get_skill_contract($skillname);
        if ($meta === null) {
            return [];
        }

        return array_values((array)($meta['capabilities'] ?? []));
    }

    /**
     * Return compact skill metadata for system-prompt routing.
     *
     * This intentionally excludes full field descriptions so the initial prompt
     * stays small. Runtime validation continues to use the full skill schemas.
     *
     * @return array[]
     */
    public function get_all_prompt_contracts(): array {
        $contracts = [];
        foreach ($this->skills as $name => $skill) {
            $contracts[] = $this->build_prompt_contract($name, $skill);
        }
        return $contracts;
    }

    /**
     * Return prompt contracts filtered for the given user/context executability.
     *
     * @param skill_executability_evaluator $evaluator
     * @param int $userid
     * @param int $contextid
     * @param bool $includeunavailable
     * @return array[]
     */
    public function get_prompt_contracts_for_context(
        skill_executability_evaluator $evaluator,
        int $userid,
        int $contextid,
        bool $includeunavailable = false
    ): array {
        $allowed = array_fill_keys(
            $this->get_skill_names_for_context($evaluator, $userid, $contextid, $includeunavailable),
            true
        );
        $contracts = [];

        foreach ($this->skills as $name => $skill) {
            if (!isset($allowed[$name])) {
                continue;
            }

            $contracts[] = $this->build_prompt_contract($name, $skill);
        }

        return $contracts;
    }

    /**
     * Build compact prompt metadata for one skill.
     *
     * Attempts to extract input_fields and anchor_fields from schema['prompt_meta'].
     * Falls back to legacy detection logic for skills that don't declare prompt_meta.
     *
     * @param string $skillname
     * @param skill_interface $skill
     * @return array
     */
    private function build_prompt_contract(string $skillname, skill_interface $skill): array {
        $schema = (array)$skill->get_schema();
        $promptcontract = $skill->get_prompt_contract()->to_array();
        $skillmeta = (array)($this->get_skill_contract($skillname) ?? []);
        $minimalinput = array_values(array_filter(array_map('strval', (array)($promptcontract['minimal_input'] ?? []))));
        // The schema's own required flags travel separately: the catalogue prints these behind "REQUIRED:",
        // never minimal_input (baseline run 15 forensics).
        $requiredinput = array_values(array_filter(array_map('strval', (array)($promptcontract['required_input'] ?? []))));
        $acceptsemptyinput = (bool)($promptcontract['accepts_empty_input'] ?? true);
        $requiredgroups = array_values((array)($promptcontract['required_groups'] ?? []));
        $anchorfields = array_values(array_filter(array_map('strval', (array)($promptcontract['anchors'] ?? []))));

        $exampleinput = is_array($promptcontract['example_input'] ?? null)
            ? (array)$promptcontract['example_input']
            : $skill->get_example_input();

        $namespace = trim((string)($promptcontract['namespace'] ?? ''));
        if ($namespace === '' && strpos($skillname, '.') !== false) {
            $namespace = (string)substr($skillname, 0, (int)strpos($skillname, '.'));
        }
        if ($namespace === '') {
            $namespace = 'wizard';
        }

        $contractversion = max(1, (int)($promptcontract['version'] ?? 1));
        $metaversion = max(1, (int)($skillmeta['version'] ?? 1));
        $version = max($contractversion, $metaversion);
        $family = skill_family_contract::resolve_from_prompt_contract($promptcontract, $skillname);
        if ($family === skill_family_contract::DEFAULT_FAMILY && !empty($skillmeta['family'])) {
            $family = skill_family_contract::normalize_family((string)$skillmeta['family']);
        }

        $capabilities = array_values(array_unique(array_filter(array_map(
            'strval',
            !empty($promptcontract['capabilities'])
                ? (array)$promptcontract['capabilities']
                : (array)($skillmeta['capabilities'] ?? [])
        ))));

        $contextscopes = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($promptcontract['context_scopes'] ?? ['module'])
        ))));

        $messagetriggers = [];
        if ($skill instanceof skill_trigger_provider_interface) {
            try {
                $messagetriggers = array_values(array_filter((array)$skill->get_message_triggers()));
            } catch (\Throwable $e) {
                $messagetriggers = [];
            }
        }

        return [
            'skill' => $skillname,
            'description' => trim((string)($schema['description'] ?? '')),
            // Sibling discrimination (#2453). Until wave 17 a skill separated itself from its sibling
            // inside the DESCRIPTION — which is embedding anchor #0. Vectors carry no negation: "not the
            // built-in option properties" sits NEXT TO "built-in option properties" and additionally names
            // the competitor, so the boundary sentence pulled the wrong skill towards exactly the queries
            // it was meant to keep it away from. These two keys carry the boundary instead: they are
            // rendered as IS:/NOT: card lines for the selector and are deliberately NOT embedded
            // (see embeddings_catalog_builder_service::build_anchor_list, which reads description +
            // example_utterances only). English, one short clause each; skill_catalog_discrimination_test
            // enforces the length so the slim catalogue stays affordable.
            'is' => $this->compact_discrimination((string)($schema['is'] ?? '')),
            'not' => $this->compact_discrimination((string)($schema['not'] ?? '')),
            // When to choose the skill, rendered as the WHEN: card line. Optional: without it the card falls back
            // to the first message trigger, which for some skills describes an edge case (#2546). Selector only.
            'when' => $this->compact_discrimination((string)($schema['when'] ?? ''), self::WHEN_CAP),
            'readonly' => (bool)($schema['readonly'] ?? $skill->is_read_only()),
            // Owning component (path form, e.g. 'mod/booking'). Carried so the full-access gate can
            // restrict the PRO lock to Wunderbyte's own write skills; see agent_access_service.
            'component' => trim((string)($skillmeta['component'] ?? '')),
            'risk_class' => trim((string)($promptcontract['risk_class'] ?? ($skillmeta['risk_class'] ?? $skill->get_risk_class()))),
            'intent' => trim((string)($promptcontract['intent'] ?? '')) !== ''
                ? trim((string)$promptcontract['intent'])
                : 'skill',
            'anchors' => $anchorfields,
            'always_available' => (bool)($skillmeta['always_available'] ?? false),
            // Multi-vector semantic discovery anchors (English); each is embedded separately next to
            // the description. See docs/Blueprints/SKILL_REWORK.md §5.
            'example_utterances' => array_values(array_filter(array_map(
                'strval',
                (array)($schema['example_utterances'] ?? ($skillmeta['example_utterances'] ?? []))
            ))),
            'minimal_input' => $minimalinput,
            'required_input' => $requiredinput,
            'accepts_empty_input' => $acceptsemptyinput,
            'required_groups' => $requiredgroups,
            'example_input' => $exampleinput,
            'namespace' => $namespace,
            'family' => $family,
            'version' => $version,
            'capabilities' => $capabilities,
            'context_scopes' => $contextscopes,
            'message_triggers' => $messagetriggers,
        ];
    }

    /**
     * Normalise one IS:/NOT: discrimination clause.
     *
     * Collapses whitespace and caps the clause. The cap is a guard, not a feature: a skill that needs
     * more than {@see self::DISCRIMINATION_CAP} characters to say what it is not has an unclear scope,
     * and every clause is paid for in the slim catalogue, where all cards enter the prompt at once.
     *
     * @param string $clause
     * @param int $cap maximum length, {@see self::DISCRIMINATION_CAP} by default
     * @return string
     */
    private function compact_discrimination(string $clause, int $cap = self::DISCRIMINATION_CAP): string {
        $clause = trim((string)preg_replace('/\s+/', ' ', $clause));
        if ($clause === '') {
            return '';
        }
        return core_text::substr($clause, 0, $cap);
    }

    /**
     * Return all context-specific prompt packs from registered providers.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->providers as $provider) {
            $providerpacks = $provider->get_contextual_prompt_packs();
            foreach ($providerpacks as $pack) {
                if (!is_array($pack)) {
                    continue;
                }
                $id = (string)($pack['id'] ?? '');
                if ($id === '' || isset($seenids[$id])) {
                    continue;
                }
                $seenids[$id] = true;
                $packs[] = $pack;
            }
        }

        return $packs;
    }

    /**
     * Build and return the default registry loaded with all discovered skill providers.
     *
     * @return self
     */
    public static function make_default(): self {
        $registry = new self();
        $registeredcomponents = [];

        foreach (core_component::get_component_names() as $component) {
            // A component's skill_provider and skill classes reference engine
            // types through component-local aliases; define them before the
            // first class of that component is (auto)loaded. Cheap no-op for
            // components without a local/wizard tree.
            services\engine_alias_registrar::ensure_component_aliases($component);

            $classname = '\\' . $component . '\\local\\wizard\\skill_provider';
            if (!class_exists($classname)) {
                self::register_discovered_skills_without_provider($registry, $component, $registeredcomponents);
                continue;
            }

            try {
                $provider = new $classname();
            } catch (\Throwable $e) {
                $registry->add_contract_diagnostic(
                    'Ignoring AI skill provider ' . $classname . ' because construction failed: '
                    . get_class($e) . ': ' . $e->getMessage()
                );
                continue;
            }

            if (!$provider instanceof skill_provider_interface) {
                continue;
            }

            try {
                $registry->register($provider);
            } catch (\Throwable $e) {
                $registry->add_contract_diagnostic(
                    'Ignoring AI skill provider ' . $classname . ' because registration failed: '
                    . get_class($e) . ': ' . $e->getMessage()
                );
                continue;
            }
            $registeredcomponents[$provider->get_component()] = true;
        }

        if (!isset($registeredcomponents['bookingextension/agent'])) {
            $provider = new skill_provider();
            try {
                $registry->register($provider);
            } catch (\Throwable $e) {
                $registry->add_contract_diagnostic(
                    'Ignoring default bookingextension_agent provider because registration failed: '
                    . get_class($e) . ': ' . $e->getMessage()
                );
            }
        }

        $registry->fail_on_contract_diagnostics_when_strict();
        return $registry;
    }

    /**
     * Register discovered skills directly when a component has no skill_provider class.
     *
     * Provider-first rule: this fallback runs only when no provider class exists.
     *
     * @param self $registry
     * @param string $component
     * @param array $registeredcomponents
     * @return void
     */
    private static function register_discovered_skills_without_provider(
        self $registry,
        string $component,
        array &$registeredcomponents
    ): void {
        $skills = array_values(skill_discovery::get_skill_instances($component));
        if (empty($skills)) {
            return;
        }

        usort($skills, static fn(skill_interface $a, skill_interface $b): int => strcmp($a->get_name(), $b->get_name()));

        $providercomponent = self::normalize_provider_component_name($component);
        if ($providercomponent === null) {
            return;
        }

        $provider = new class ($providercomponent, $skills) implements skill_provider_interface {
            /** @var string */
            private string $component;

            /** @var skill_interface[] */
            private array $skills;

            /** @var string[] */
            private array $diagnostics;

            /**
             * Create a provider wrapper for discovered fallback skills.
             *
             * @param string $component
             * @param skill_interface[] $skills
             */
            public function __construct(string $component, array $skills) {
                $this->component = $component;
                $this->skills = $skills;
                $this->diagnostics = skill_discovery::get_last_diagnostics();
            }

            /**
             * Return provider component name.
             *
             * @return string
             */
            public function get_component(): string {
                return $this->component;
            }

            /**
             * Return discovered skill instances.
             *
             * @return array
             */
            public function get_skills(): array {
                return $this->skills;
            }

            /**
             * Return contextual prompt packs.
             *
             * @return array[]
             */
            public function get_contextual_prompt_packs(): array {
                return [];
            }

            /**
             * Return optional issue code provider.
             *
             * @return issue_code_provider_interface|null
             */
            public function get_issue_code_provider(): ?issue_code_provider_interface {
                return null;
            }

            /**
             * Return prompt guidance payload.
             *
             * @return array
             */
            public function get_prompt_guidance(): array {
                return [];
            }

            /**
             * Return discovery diagnostics captured during wrapper creation.
             *
             * @return string[]
             */
            public function get_discovery_diagnostics(): array {
                return $this->diagnostics;
            }
        };

        try {
            $registry->register($provider);
        } catch (\Throwable $e) {
            $registry->add_contract_diagnostic(
                'Ignoring direct skill discovery for component ' . $providercomponent . ' because registration failed: '
                . get_class($e) . ': ' . $e->getMessage()
            );
            return;
        }

        $registeredcomponents[$providercomponent] = true;
    }

    /**
     * Convert plugin component notation (mod_booking) to provider notation (mod/booking).
     *
     * @param string $component
     * @return string|null
     */
    private static function normalize_provider_component_name(string $component): ?string {
        [$plugintype, $pluginname] = core_component::normalize_component($component);
        if ($plugintype === 'core' || $pluginname === '') {
            return null;
        }

        return $plugintype . '/' . $pluginname;
    }

    /**
     * Append optional discovery diagnostics exposed by a provider.
     *
     * @param skill_provider_interface $provider
     * @return void
     */
    private function append_provider_discovery_diagnostics(skill_provider_interface $provider): void {
        if (!method_exists($provider, 'get_discovery_diagnostics')) {
            return;
        }

        try {
            $diagnostics = (array)$provider->get_discovery_diagnostics();
        } catch (\Throwable $e) {
            $this->add_contract_diagnostic(
                'Could not read discovery diagnostics for component ' . $provider->get_component()
                . ': ' . get_class($e) . ': ' . $e->getMessage()
            );
            return;
        }

        foreach ($diagnostics as $diagnostic) {
            $this->add_contract_diagnostic((string)$diagnostic);
        }
    }

    /**
     * Append a contract diagnostic and forward to developer debugging output.
     *
     * @param string $message
     * @return void
     */
    private function add_contract_diagnostic(string $message): void {
        $message = trim($message);
        if ($message === '') {
            return;
        }

        $this->contractdiagnostics[] = $message;
    }

    /**
     * Throw when strict governance mode is enabled and diagnostics exist.
     *
     * @return void
     */
    private function fail_on_contract_diagnostics_when_strict(): void {
        if (!$this->is_governance_strict_mode_enabled()) {
            return;
        }

        if (empty($this->contractdiagnostics)) {
            return;
        }

        throw new \coding_exception(
            'AI skill governance strict mode blocked registry initialization: '
            . implode(' || ', $this->contractdiagnostics)
        );
    }

    /**
     * Whether governance strict mode is enabled via plugin config.
     *
     * @return bool
     */
    private function is_governance_strict_mode_enabled(): bool {
        return (bool)get_config('bookingextension_agent', 'aigovernancestrictmode');
    }
}

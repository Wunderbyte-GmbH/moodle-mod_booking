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
 * Skill governance contract validator.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

use core_text;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\contracts\skill_family_contract;
use bookingextension_agent\local\wizard\interfaces\skill_interface;

/**
 * Validates and normalizes governance metadata for skill registration.
 */
class skill_contract_validator {
    /** Reserved namespaces owned by bookingextension_agent. */
    public const RESERVED_NAMESPACES = ['booking', 'core', 'report', 'wizard'];

    /** Deny reason: skill was not registered. */
    public const DENY_NOT_REGISTERED = 'not_registered';

    /** Deny reason: skill is inactive. */
    public const DENY_INACTIVE = 'inactive';

    /** Deny reason: user misses required capability. */
    public const DENY_MISSING_CAPABILITY = 'missing_capability';

    /** Deny reason: context is invalid. */
    public const DENY_CONTEXT_INVALID = 'context_invalid';

    /** Deny reason: runtime is globally disabled. */
    public const DENY_RUNTIME_DISABLED = 'runtime_disabled';

    /** Deny reason: mutating skill needs a PRO license or the Wunderbyte subscription. */
    public const DENY_REQUIRES_PRO = 'requires_pro';

    /** Deny reason: requested skill version is unsupported. */
    public const DENY_SKILL_VERSION_UNSUPPORTED = 'skill_version_unsupported';

    /**
     * Build normalized governance metadata for one skill.
     *
     * @param skill_interface $skill
     * @param string $component
     * @return array
     */
    public static function build_skill_metadata(skill_interface $skill, string $component): array {
        $schema = (array)$skill->get_schema();
        $governance = (array)($schema['governance'] ?? []);
        $promptcontract = (array)$skill->get_prompt_contract()->to_array();
        $skillname = trim($skill->get_name());
        $capabilities = [];
        $defaultcapability = self::build_skill_capability_name($component, $skillname);
        if ($defaultcapability !== '') {
            $capabilities[] = $defaultcapability;
        }

        return [
            'skillname' => $skillname,
            'namespace' => self::extract_skill_namespace($skillname),
            'family' => skill_family_contract::from_skill_name($skillname),
            'version' => (int)($schema['version'] ?? 1),
            'component' => trim($component),
            'capabilities' => $capabilities,
            'active' => array_key_exists('active', $governance) ? (bool)$governance['active'] : true,
            'alias_of' => trim((string)($governance['alias_of'] ?? '')),
            'always_available' => (bool)($governance['always_available'] ?? false),
            // Multi-vector discovery anchors: short English utterances a user might say to invoke this
            // skill. Each becomes a SEPARATE embedding anchor alongside the description (anchor #0).
            // Discovery is purely semantic — these are NOT lexical/substring triggers.
            // See docs/Blueprints/SKILL_REWORK.md §5.
            'example_utterances' => array_values(array_filter(array_map(
                static fn ($u): string => trim((string)$u),
                (array)($schema['example_utterances'] ?? [])
            ))),
            'deprecated_since' => trim((string)($governance['deprecated_since'] ?? '')),
            'readonly' => (bool)$skill->is_read_only(),
            'risk_class' => trim((string)$skill->get_risk_class()),
            'context_scopes' => array_values(array_filter(array_map('strval', (array)($promptcontract['context_scopes'] ?? [])))),
        ];
    }

    /**
     * Build a deterministic skill capability name for component/skill combination.
     *
     * @param string $component
     * @param string $skillname
     * @return string
     */
    public static function build_skill_capability_name(string $component, string $skillname): string {
        $component = trim(core_text::strtolower($component));
        $skillname = trim(core_text::strtolower($skillname));
        if ($component === '' || $skillname === '') {
            return '';
        }

        $normalizedskillname = preg_replace('/[^a-z0-9]+/', '_', $skillname);
        $normalizedskillname = trim((string)$normalizedskillname, '_');
        if ($normalizedskillname === '') {
            return '';
        }

        return $component . ':skill_' . $normalizedskillname;
    }

    /**
     * Validate one normalized metadata record.
     *
     * @param array $skillmeta
     * @return array{valid:bool,errors:string[]}
     */
    public static function validate_skill_metadata(array $skillmeta): array {
        $errors = [];

        if (trim((string)($skillmeta['skillname'] ?? '')) === '') {
            $errors[] = 'Missing required field: skillname.';
        }

        $skillname = trim((string)($skillmeta['skillname'] ?? ''));
        if ($skillname !== '' && !self::is_namespaced_skill_name($skillname)) {
            $errors[] = 'Invalid required field: skillname must be namespaced as <namespace>.<skill>.';
        }

        $namespace = trim((string)($skillmeta['namespace'] ?? ''));
        if ($namespace === '') {
            $errors[] = 'Missing required field: namespace.';
        } else if ($skillname !== '' && self::extract_skill_namespace($skillname) !== $namespace) {
            $errors[] = 'Invalid namespace: must match the skillname prefix.';
        }

        $family = trim((string)($skillmeta['family'] ?? ''));
        if ($family === '') {
            $errors[] = 'Missing required field: family.';
        } else if (!skill_family_contract::is_valid_family($family)) {
            $errors[] = 'Invalid required field: family must be namespaced as <namespace>.<family>.';
        }

        $version = $skillmeta['version'] ?? null;
        if (!is_int($version) || $version <= 0) {
            $errors[] = 'Invalid required field: version must be an integer > 0.';
        }

        if (!array_key_exists('active', $skillmeta) || !is_bool($skillmeta['active'])) {
            $errors[] = 'Invalid required field: active must be a boolean.';
        }

        if (!array_key_exists('capabilities', $skillmeta) || !is_array($skillmeta['capabilities'])) {
            $errors[] = 'Invalid required field: capabilities must be a string array.';
        } else {
            foreach ($skillmeta['capabilities'] as $capability) {
                if (!is_string($capability) || trim($capability) === '') {
                    $errors[] = 'Invalid capability entry: expected non-empty string.';
                    break;
                }
            }
        }

        $riskclass = trim((string)($skillmeta['risk_class'] ?? ''));
        if ($riskclass === '') {
            $errors[] = 'Missing required field: risk_class.';
        } else if (!skill_risk_class::is_valid($riskclass)) {
            $errors[] = 'Invalid required field: risk_class must be one of '
                . 'read_only, scoped_write, broad_write, irreversible_or_external.';
        }

        $readonly = array_key_exists('readonly', $skillmeta) ? (bool)$skillmeta['readonly'] : null;
        if ($riskclass === skill_risk_class::R0 && $readonly !== true) {
            $errors[] = 'Invalid risk_class declaration: R0 skills must be read-only.';
        }
        if ($readonly === true && $riskclass !== '' && $riskclass !== skill_risk_class::R0) {
            $errors[] = 'Invalid risk_class declaration: mutating skills must not be marked read-only.';
        }

        $contextscopes = array_values(array_filter(array_map('strval', (array)($skillmeta['context_scopes'] ?? []))));
        if (in_array($riskclass, [skill_risk_class::R2, skill_risk_class::R3], true) && empty($contextscopes)) {
            $errors[] = 'Invalid risk_class declaration: broad or irreversible skills must declare explicit context scopes.';
        }

        $aliasof = trim((string)($skillmeta['alias_of'] ?? ''));
        if ($aliasof !== '' && $aliasof === trim((string)($skillmeta['skillname'] ?? ''))) {
            $errors[] = 'Invalid alias_of: alias cannot target itself.';
        }
        if ($aliasof !== '' && !self::is_namespaced_skill_name($aliasof)) {
            $errors[] = 'Invalid alias_of: alias target must be namespaced as <namespace>.<skill>.';
        }
        if (
            $aliasof !== ''
            && $namespace !== ''
            && self::extract_skill_namespace($aliasof) !== $namespace
        ) {
            $errors[] = 'Invalid alias_of: alias target must stay in the same namespace.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate registry-wide metadata conflicts (duplicates, broken aliases).
     *
     * @param array $skillcontracts
     * @return string[]
     */
    public static function validate_registry_contracts(array $skillcontracts): array {
        $errors = [];
        $seenidentities = [];

        foreach ($skillcontracts as $skillname => $meta) {
            if (isset($seenidentities[$skillname])) {
                $errors[] = 'Duplicate skill identity detected: ' . $skillname;
                continue;
            }
            $seenidentities[$skillname] = true;

            $aliasof = trim((string)($meta['alias_of'] ?? ''));
            if ($aliasof !== '' && !isset($skillcontracts[$aliasof])) {
                $errors[] = 'Alias target not found for skill ' . $skillname . ': ' . $aliasof;
                continue;
            }

            if ($aliasof !== '') {
                $aliasmeta = (array)($skillcontracts[$aliasof] ?? []);
                $version = (int)($meta['version'] ?? 0);
                $aliasversion = (int)($aliasmeta['version'] ?? 0);
                if ($version > 0 && $aliasversion > 0 && $version !== $aliasversion) {
                    $errors[] = 'Alias version mismatch for skill ' . $skillname
                        . ': v' . $version . ' cannot target ' . $aliasof . ' v' . $aliasversion;
                }
            }
        }

        return $errors;
    }

    /**
     * Return a localized user-facing message for a deny reason, when one exists.
     *
     * The message flows through the normal result/observation pipeline, so the
     * synchronizer relays (and translates) it like any other skill outcome.
     * Reasons without a dedicated message return null — callers keep their
     * technical diagnostics phrasing for those.
     *
     * @param string $reason one of the DENY_* constants
     * @param string $skillname
     * @return string|null
     */
    public static function get_user_facing_deny_message(string $reason, string $skillname): ?string {
        switch ($reason) {
            case self::DENY_REQUIRES_PRO:
                return get_string('agent_skill_denied_requires_pro', 'bookingextension_agent', (object)[
                    'skill' => $skillname,
                    'upgradeurl' => get_string('aitrial_pro_license_url', 'bookingextension_agent'),
                ]);
            case self::DENY_MISSING_CAPABILITY:
                // Not an error: the user simply lacks the right to use this capability.
                return get_string('agent_skill_denied_missing_capability', 'bookingextension_agent');
            case self::DENY_INACTIVE:
            case self::DENY_RUNTIME_DISABLED:
            case self::DENY_NOT_REGISTERED:
                // Not an error: the capability is simply not available/enabled on this system.
                return get_string('agent_skill_denied_unavailable', 'bookingextension_agent');
            default:
                return null;
        }
    }

    /**
     * Return skill namespace prefix from a namespaced skill name.
     *
     * @param string $skillname
     * @return string
     */
    public static function extract_skill_namespace(string $skillname): string {
        $skillname = trim(core_text::strtolower($skillname));
        $dotpos = strpos($skillname, '.');
        if ($dotpos === false || $dotpos === 0) {
            return '';
        }

        return trim((string)substr($skillname, 0, $dotpos));
    }

    /**
     * Check whether a skill name follows the required namespaced format.
     *
     * @param string $skillname
     * @return bool
     */
    public static function is_namespaced_skill_name(string $skillname): bool {
        $skillname = trim(core_text::strtolower($skillname));
        return preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $skillname) === 1;
    }

    /**
     * Determine whether a component may register skills inside a namespace.
     *
     * @param string $component
     * @param string $namespace
     * @return bool
     */
    public static function component_may_register_namespace(string $component, string $namespace): bool {
        $component = trim(core_text::strtolower($component));
        $normalizedcomponent = str_replace('/', '_', $component);
        $namespace = trim(core_text::strtolower($namespace));
        if ($component === '' || $namespace === '') {
            return false;
        }

        if (!in_array($namespace, self::RESERVED_NAMESPACES, true)) {
            return true;
        }

        return $normalizedcomponent === 'bookingextension_agent';
    }
}

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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Full-access gate for the AI agent.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wb_license;
use bookingextension_agent\local\wizard\wb_action_names;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_ai\manager as ai_manager;
use core_text;

/**
 * Decides whether the agent runs with full access or readonly-only skills.
 *
 * Full access is granted when either
 *  - the agent's LLM calls actually hit the Wunderbyte LLM gateway (trial or
 *    subscription — enforcement of the subscription itself happens server-side
 *    at that gateway), or
 *  - a valid PRO license is set (product 'wizard' or combined 'bookingagent',
 *    see wb_license).
 *
 * Without full access only readonly skills are executable; mutating skills are
 * surfaced as UNAVAILABLE so replies can point at the upgrade path.
 */
class agent_access_service {
    /** @var string Host suffix of the Wunderbyte LLM subscription gateway. */
    private const WUNDERBYTE_LLM_HOST_SUFFIX = 'wunderbyte.at';

    /** Wunderbyte planner action class name (optional plugin, referenced by name). */
    private const WB_ACTION_PLANNER_DECIDE = wb_action_names::PLANNER_DECIDE;

    /** Wunderbyte final reply action class name (optional plugin, referenced by name). */
    private const WB_ACTION_GENERATE_AGENT_REPLY = wb_action_names::GENERATE_AGENT_REPLY;

    /**
     * Frankenstyle components whose WRITE skills sit behind the full-access (PRO / Wunderbyte LLM)
     * gate. This allow-list is the ONLY thing that turns a mutating skill into a paid feature: it
     * names exactly Wunderbyte's own commercial write skills. Every other component's write skills
     * — i.e. all third-party skills — stay ungated, and read-only skills are never gated regardless
     * of component. Compared in normalised frankenstyle form, so path form ('mod/booking') and
     * frankenstyle ('mod_booking') are equivalent. See {@see self::skill_requires_full_access()}.
     */
    private const PRO_GATED_COMPONENTS = [
        'mod_booking',
        'local_shopping_cart',
        'local_entities',
    ];

    /** @var bool|null Request-scoped memoization (evaluator runs once per skill). */
    private static ?bool $fullaccess = null;

    /**
     * Whether the agent currently runs with full access (all skills).
     *
     * @return bool
     */
    public static function has_full_access(): bool {
        if (self::$fullaccess !== null) {
            return self::$fullaccess;
        }

        // The license check is local and cheap and also carries the
        // PHPUnit/Behat override, so it goes first.
        self::$fullaccess = wb_license::agent_license_is_activated() || self::runs_on_wunderbyte_llm();

        return self::$fullaccess;
    }

    /**
     * Whether a single skill needs full access (PRO license or Wunderbyte LLM subscription) to run.
     *
     * The PRO gate is deliberately NARROW: it applies ONLY to the WRITE skills of Wunderbyte's own
     * commercial components ({@see self::PRO_GATED_COMPONENTS}). It never applies to
     *  - read-only skills of any component, nor
     *  - third-party write skills (any component outside the allow-list).
     * A third-party plugin that ships a mutating skill therefore stays fully usable without any
     * Wunderbyte license. This is a STRUCTURAL component-identity check (an exact frankenstyle
     * match against a fixed allow-list), never a lexical/language match on names or descriptions.
     *
     * @param bool $readonly Whether the skill is read-only (from its contract/metadata).
     * @param string $component The skill's owning component, path form or frankenstyle.
     * @return bool True iff the skill is gated behind full access.
     */
    public static function skill_requires_full_access(bool $readonly, string $component): bool {
        if ($readonly) {
            return false;
        }

        $normalized = str_replace('/', '_', trim(core_text::strtolower($component)));

        return in_array($normalized, self::PRO_GATED_COMPONENTS, true);
    }

    /**
     * Whether the agent's LLM calls actually go to the Wunderbyte LLM gateway.
     *
     * The provider plugin is freely configurable, so the gate must check the
     * endpoint URL that would actually be called: the primary enabled provider
     * for the agent's planner action (in routing preference order) has to point
     * at a wunderbyte.at host. A configured non-Wunderbyte endpoint decides
     * negatively — later fallback actions are only consulted when an action has
     * no provider at all.
     *
     * @return bool
     */
    public static function runs_on_wunderbyte_llm(): bool {
        try {
            $manager = di::get(ai_manager::class);
        } catch (\Throwable $e) {
            return false;
        }

        $actions = [
            self::WB_ACTION_PLANNER_DECIDE,
            wb_action_names::PLANNER_CONSTRUCT,
            self::WB_ACTION_GENERATE_AGENT_REPLY,
            summarise_text::class,
            generate_text::class,
        ];

        foreach ($actions as $actionclass) {
            if (!class_exists($actionclass)) {
                continue;
            }

            $endpoint = self::resolve_primary_endpoint($manager, $actionclass);
            if ($endpoint === null) {
                // No provider serves this action — consult the next fallback action.
                continue;
            }

            return self::is_wunderbyte_host($endpoint);
        }

        return false;
    }

    /**
     * Find provider instances whose action endpoints point at the Wunderbyte LLM gateway.
     *
     * Endpoint-based detection that replaces any provider-name/-class heuristic: an instance
     * counts only when it actually targets a wunderbyte.at host, so a Wunderbyte provider that
     * is pointed at a different endpoint is deliberately NOT matched. Scans every instance
     * (including disabled ones) so the trial "activate" path can find a configured-but-off trial.
     *
     * @param bool $enabledonly Only consider enabled instances.
     * @return object[] Matching provider instances.
     */
    public static function find_wunderbyte_llm_instances(bool $enabledonly = false): array {
        // Provider_compat::get_provider_views() returns real instances on Moodle 5.x and
        // synthesised, instance-shaped views on Moodle 4.5 (no get_provider_instances() there).
        $matches = [];
        foreach (provider_compat::get_provider_views() as $instance) {
            if ($enabledonly && empty($instance->enabled)) {
                continue;
            }
            if (self::instance_targets_wunderbyte_llm($instance)) {
                $matches[] = $instance;
            }
        }

        return array_values($matches);
    }

    /**
     * Whether a provider instance's configured action endpoints point at the Wunderbyte LLM gateway.
     *
     * Reads the instance's own actionconfig (no manager routing), so it works for disabled
     * instances too. True as soon as any configured action endpoint is a wunderbyte.at host.
     *
     * @param object $instance A core_ai provider instance.
     * @return bool
     */
    public static function instance_targets_wunderbyte_llm(object $instance): bool {
        $actionconfig = (array)($instance->actionconfig ?? []);
        foreach ($actionconfig as $cfg) {
            $settings = (array)(($cfg ?? [])['settings'] ?? []);
            $endpoint = trim((string)($settings['endpoint'] ?? $settings['apiendpoint'] ?? ''));
            if ($endpoint !== '' && self::is_wunderbyte_host($endpoint)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the configured endpoint of the primary enabled provider for an action.
     *
     * @param ai_manager $manager
     * @param string $actionclass
     * @return string|null null when no provider serves the action; '' when the
     *                     provider has no endpoint setting.
     */
    private static function resolve_primary_endpoint(ai_manager $manager, string $actionclass): ?string {
        try {
            $providers = $manager->get_providers_for_actions([$actionclass], true);
            $list = (array)($providers[$actionclass] ?? []);
            if (empty($list)) {
                return null;
            }

            $primary = reset($list);
            $actionconfig = (array)($primary->actionconfig ?? []);
            $settings = (array)(($actionconfig[$actionclass] ?? [])['settings'] ?? []);
            $endpoint = trim((string)($settings['endpoint'] ?? $settings['apiendpoint'] ?? ''));

            if ($endpoint === '' && !property_exists($primary, 'actionconfig')) {
                // Moodle 4.5: there are no provider instances — get_providers_for_actions()
                // returns bare provider plugin objects, and the per-action endpoint lives in
                // flat plugin config (action_<basename>_endpoint, the same keys the
                // provider_compat legacy views read). Without this fallback a 4.5 site routing
                // e.g. aiprovider_openai through llm.wunderbyte.at is not recognised as running
                // on the Wunderbyte LLM: no subscription-based full access, no settings notice.
                $endpoint = self::legacy_flat_endpoint(get_class($primary), $actionclass);
            }

            return $endpoint;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read a provider's per-action endpoint from Moodle 4.5 flat plugin config.
     *
     * @param string $providerclass Provider class name, e.g. 'aiprovider_openai\provider'.
     * @param string $actionclass Action class name.
     * @return string The configured endpoint, or '' when unset.
     */
    private static function legacy_flat_endpoint(string $providerclass, string $actionclass): string {
        $component = substr($providerclass, 0, (int)strpos($providerclass, '\\'));
        if ($component === '' || !is_callable([$actionclass, 'get_basename'])) {
            return '';
        }

        $basename = (string)$actionclass::get_basename();

        return trim((string)get_config($component, "action_{$basename}_endpoint"));
    }

    /**
     * Whether an endpoint URL points at the Wunderbyte LLM gateway.
     *
     * @param string $endpoint
     * @return bool
     */
    private static function is_wunderbyte_host(string $endpoint): bool {
        if ($endpoint === '') {
            return false;
        }

        $host = core_text::strtolower(trim((string)parse_url($endpoint, PHP_URL_HOST)));
        if ($host === '') {
            return false;
        }

        return $host === self::WUNDERBYTE_LLM_HOST_SUFFIX
            || str_ends_with($host, '.' . self::WUNDERBYTE_LLM_HOST_SUFFIX);
    }
}

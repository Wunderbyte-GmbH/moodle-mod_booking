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

namespace bookingextension_agent\local\wizard\benchmark;

use bookingextension_agent\local\wizard\wb_action_names;
use core_ai\aiactions\generate_text;

/**
 * AI manager subclass that applies env var overrides when
 * BOOKING_TEST_AI_KEY is supplied, for benchmark runs.
 *
 * Two concerns are handled here:
 *
 * 1. is_provider_configured() bypass (get_providers_for_actions):
 *    The standard manager filters out providers whose is_provider_configured()
 *    returns false (DB apikey empty).  When BOOKING_TEST_AI_KEY is set we
 *    patch the apikey into the provider's config via get_sorted_providers(),
 *    so the check passes naturally.  The fallback retry in
 *    get_providers_for_actions() is kept as a belt-and-suspenders guard.
 *
 * 2. Model / key patching (get_sorted_providers):
 *    abstract_processor::get_model() and add_authentication_headers() read
 *    only from the provider's decoded config/actionconfig arrays; there is
 *    no env var logic in those files (they are outside bookingextension_agent).
 *    We intercept get_sorted_providers() and rewrite each wunderbyte provider
 *    instance in-memory using Cloneable::with() so that:
 *      - config['apikey']                     ← BOOKING_TEST_AI_KEY
 *      - planner_decide settings.model        ← BOOKING_TEST_AI_MODEL_MINI
 *                                               (falls back to BOOKING_TEST_AI_MODEL)
 *      - planner_construct settings.model     ← BOOKING_TEST_AI_MODEL_CONSTRUCT
 *                                               (falls back to BOOKING_TEST_AI_MODEL)
 *      - generate_agent_reply settings.model  ← BOOKING_TEST_AI_MODEL
 *      - generate_text settings.model         ← BOOKING_TEST_AI_MODEL (skill-internal calls; the
 *                                               normalizer uses it only without planner_decide)
 *      - generate_embeddings settings.model   ← BOOKING_TEST_AI_EMBEDDING_MODEL
 *
 *    Cloneable::with() uses reflection to set readonly properties directly on
 *    a constructor-bypassed clone, so no JSON round-trip is needed.
 *
 * Registered into the DI container by benchmark_runner.php at process start.
 * No DB writes are performed — this is a pure runtime override.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_envkey_manager extends \core_ai\manager {
    /** Action class: planner/selector. */
    private const WB_ACTION_PLANNER_DECIDE = \bookingextension_agent\local\wizard\wb_action_names::PLANNER_DECIDE;

    /** Action class: final agent reply. */
    private const WB_ACTION_GENERATE_AGENT_REPLY = \bookingextension_agent\local\wizard\wb_action_names::GENERATE_AGENT_REPLY;

    /** Action class: embeddings. */
    private const WB_ACTION_GENERATE_EMBEDDINGS = \bookingextension_agent\local\wizard\wb_action_names::GENERATE_EMBEDDINGS;

    /** Wunderbyte provider class. */
    private const WB_PROVIDER_CLASS = 'aiprovider_wunderbyte\\provider';

    /**
     * Return sorted providers, patching each wunderbyte instance with env var
     * model and key overrides when BOOKING_TEST_AI_KEY is set.
     *
     * @return array  Same shape as parent.
     */
    public function get_sorted_providers(): array {
        $providers = parent::get_sorted_providers();
        if (trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: '')) === '') {
            return $providers;
        }
        return array_map([$this, 'patch_provider_for_env'], $providers);
    }

    /**
     * Apply BOOKING_TEST_AI_* env var overrides to a wunderbyte provider.
     *
     * Non-wunderbyte providers are returned unchanged.
     *
     * @param \core_ai\provider $provider
     * @return \core_ai\provider
     */
    private function patch_provider_for_env(\core_ai\provider $provider): \core_ai\provider {
        $wbproviderclass = self::WB_PROVIDER_CLASS;
        if (!($provider instanceof $wbproviderclass)) {
            return $provider;
        }

        $envkey          = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
        $envmodel        = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
        $envmodelmini    = trim((string)(getenv('BOOKING_TEST_AI_MODEL_MINI') ?: ''));
        $envmodelcons    = trim((string)(getenv('BOOKING_TEST_AI_MODEL_CONSTRUCT') ?: ''));
        $envembedmodel   = trim((string)(getenv('BOOKING_TEST_AI_EMBEDDING_MODEL') ?: ''));
        $envendpoint     = trim((string)(getenv('BOOKING_TEST_AI_ENDPOINT') ?: ''));

        // Clone config array and patch apikey so is_provider_configured() passes; patch the endpoint
        // too when given, so a benchmarked provider instance with a different endpoint is honoured.
        $newconfig = $provider->config;
        if ($envkey !== '') {
            $newconfig['apikey'] = $envkey;
        }
        if ($envendpoint !== '') {
            $newconfig['endpoint'] = $envendpoint;
        }

        // Clone actionconfig and patch model for each supported action.
        $newac = $provider->actionconfig;

        $plannermodel = $envmodelmini !== '' ? $envmodelmini : $envmodel;
        if ($plannermodel !== '' && isset($newac[self::WB_ACTION_PLANNER_DECIDE])) {
            $newac[self::WB_ACTION_PLANNER_DECIDE]['settings']['model'] = $plannermodel;
        }
        $constructmodel = $envmodelcons !== '' ? $envmodelcons : $envmodel;
        if ($constructmodel !== '' && isset($newac[wb_action_names::PLANNER_CONSTRUCT])) {
            $newac[wb_action_names::PLANNER_CONSTRUCT]['settings']['model'] = $constructmodel;
        }
        if ($envmodel !== '' && isset($newac[self::WB_ACTION_GENERATE_AGENT_REPLY])) {
            $newac[self::WB_ACTION_GENERATE_AGENT_REPLY]['settings']['model'] = $envmodel;
        }
        // Without this, generate_text kept the working instance's model while the key came from the benchmarked
        // instance, which the gateway rejects (HTTP 403, benchmark runs 71-73).
        if ($envmodel !== '' && isset($newac[generate_text::class])) {
            $newac[generate_text::class]['settings']['model'] = $envmodel;
        }
        if ($envembedmodel !== '' && isset($newac[self::WB_ACTION_GENERATE_EMBEDDINGS])) {
            $newac[self::WB_ACTION_GENERATE_EMBEDDINGS]['settings']['model'] = $envembedmodel;
        }

        // Cloneable::with() sets readonly properties via reflection without
        // going through the constructor — values are already decoded arrays.
        return $provider->with(
            config: $newconfig,
            actionconfig: $newac,
        );
    }

    /**
     * Return providers for the given actions, bypassing is_provider_configured()
     * when the BOOKING_TEST_AI_KEY env var is set and would otherwise block them.
     *
     * With get_sorted_providers() already patching the apikey, this guard is
     * only needed for edge cases where the provider record has no key at all
     * and patch_provider_for_env could not be applied (e.g. wrong provider class).
     *
     * @param array $actions  Fully-qualified action class names.
     * @param bool  $enabledonly  Whether to restrict to enabled + action-enabled providers.
     * @return array  Same shape as parent: array keyed by action class name.
     */
    public function get_providers_for_actions(array $actions, bool $enabledonly = false): array {
        // No env override — full parent behaviour.
        if (trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: '')) === '') {
            return parent::get_providers_for_actions($actions, $enabledonly);
        }

        // Try the normal path first.  get_sorted_providers() has already patched
        // the apikey, so is_provider_configured() should now pass for wunderbyte
        // providers.  Return early if at least one provider was found.
        $normal = parent::get_providers_for_actions($actions, $enabledonly);
        if (array_sum(array_map('count', $normal)) > 0) {
            return $normal;
        }

        // Fallback: retry without is_provider_configured gate (catches providers
        // whose class is not aiprovider_wunderbyte and therefore not patched).
        $all = parent::get_providers_for_actions($actions, false);

        if (!$enabledonly) {
            return $all;
        }

        // Re-apply enabled + action-enabled filters only.
        $filtered = [];
        foreach ($actions as $action) {
            $filtered[$action] = [];
            foreach ($all[$action] ?? [] as $instance) {
                if (
                    !empty($instance->enabled)
                    && $this->is_action_enabled(
                        (string)($instance->provider ?? ''),
                        $action,
                        (int)($instance->id ?? 0)
                    )
                ) {
                    $filtered[$action][] = $instance;
                }
            }
        }
        return $filtered;
    }
}

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

use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\wb_action_names;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Describes which provider/model/key values a benchmark run will actually use, so the
 * interface can show them next to the "run benchmark" button and the provider-instance picker.
 *
 * A run targets a chosen core_ai provider INSTANCE — any provider type, enabled or not (a key use is
 * to benchmark a not-yet-live instance). Its key/model/endpoint are extracted and applied as the
 * BOOKING_TEST_AI_* override onto the working provider, so the agent's wunderbyte actions run with
 * the chosen instance's credentials. The BOOKING_TEST_AI_* env vars themselves still override for CLI
 * runs; the web/cron path never sees them, hence explicit instance selection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_provider_preview {
    /**
     * Per-role candidate action classes, tried in order against an instance's actionconfig. Covers
     * both the wunderbyte provider (agent actions) and generic providers (core_ai text/embeddings).
     *
     * @var array<string, string[]>
     */
    private const ROLE_ACTIONS = [
        'planner' => [
            wb_action_names::PLANNER_DECIDE,
            wb_action_names::GENERATE_AGENT_REPLY,
            'core_ai\\aiactions\\generate_text',
        ],
        'reply' => [
            wb_action_names::GENERATE_AGENT_REPLY,
            'core_ai\\aiactions\\generate_text',
        ],
        'construct' => [
            wb_action_names::PLANNER_CONSTRUCT,
        ],
        'embed' => [
            wb_action_names::GENERATE_EMBEDDINGS,
            'core_ai\\aiactions\\generate_embeddings',
        ],
    ];

    /**
     * All configured provider instances (any type, enabled or not), id => provider object.
     *
     * @return array
     */
    private function provider_instances(): array {
        global $DB;
        $out = [];
        try {
            $manager = new \core_ai\manager($DB);
            foreach ($manager->get_sorted_providers() as $provider) {
                $out[(int)$provider->id] = $provider;
            }
        } catch (\Throwable $e) {
            $out = [];
        }
        return $out;
    }

    /**
     * Extract the override values (key / per-role model / endpoint) from a provider instance,
     * provider-type agnostically: for each role pick the first candidate action present that carries
     * a model, and read that action's model + endpoint from its settings.
     *
     * @param \core_ai\provider $provider
     * @return array{key: string, planner: string, construct: string, reply: string, embed: string, embeddims: int,
     *     endpoint: string}
     */
    public static function extract_overrides(\core_ai\provider $provider): array {
        $config = (array)($provider->config ?? []);
        $ac     = (array)($provider->actionconfig ?? []);

        $modelof = static fn(string $action): string => (string)($ac[$action]['settings']['model'] ?? '');
        $endpointof = static fn(string $action): string => (string)($ac[$action]['settings']['endpoint'] ?? '');

        $pick = static function (array $actions) use ($ac, $modelof): array {
            foreach ($actions as $action) {
                if (isset($ac[$action]) && $modelof($action) !== '') {
                    return [$action, $modelof($action)];
                }
            }
            return ['', ''];
        };

        [$replyaction, $replymodel] = $pick(self::ROLE_ACTIONS['reply']);
        [, $plannermodel]           = $pick(self::ROLE_ACTIONS['planner']);
        [, $constructmodel]         = $pick(self::ROLE_ACTIONS['construct']);
        [$embedaction, $embedmodel] = $pick(self::ROLE_ACTIONS['embed']);
        $embeddims = $embedaction !== '' ? (int)($ac[$embedaction]['settings']['dimensions'] ?? 0) : 0;

        // Endpoint lives in the action settings (not config); take it from the reply action, else the
        // first action that carries one, else any config-level endpoint.
        $endpoint = $replyaction !== '' ? $endpointof($replyaction) : '';
        if ($endpoint === '') {
            foreach ($ac as $entry) {
                if (!empty($entry['settings']['endpoint'])) {
                    $endpoint = (string)$entry['settings']['endpoint'];
                    break;
                }
            }
        }
        if ($endpoint === '') {
            $endpoint = (string)($config['endpoint'] ?? ($config['apiendpoint'] ?? ''));
        }

        return [
            'key'      => (string)($config['apikey'] ?? ''),
            'planner'  => $plannermodel,
            // An instance without the constructor action serves construction with its planner model.
            'construct' => $constructmodel !== '' ? $constructmodel : $plannermodel,
            'reply'    => $replymodel,
            'embed'    => $embedmodel,
            'embeddims' => $embeddims,
            'endpoint' => $endpoint,
        ];
    }

    /**
     * Selectable provider instances for the run form: id => display name (disabled ones flagged).
     *
     * @return array
     */
    public function list_instances(): array {
        $out = [];
        foreach ($this->provider_instances() as $id => $provider) {
            $name = (string)$provider->name;
            // Disabled instances are listed too — a key use of interface benchmarks is to score a
            // not-yet-live instance before enabling it — but flagged so it is obvious.
            if (empty($provider->enabled)) {
                $name .= ' ' . get_string('benchmark_run_instance_disabled', 'bookingextension_agent');
            }
            $out[$id] = $name;
        }
        return $out;
    }

    /**
     * Compute the effective benchmark provider values for the chosen instance.
     *
     * @param int|null $instanceid The provider instance to describe; null = the default (first sorted).
     * @return array {
     *   env_override_active: bool, embeddings_active: bool, provider_found: bool, instance_enabled: bool,
     *   instance_id: int, instance_name: string,
     *   key: array{source: string, detail: string}, endpoint: array{source: string, value: string},
     *   actions: array<int, array{label: string, model: string, source: string, envvar: string}>
     * }
     */
    public function describe(?int $instanceid = null): array {
        $envkey        = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
        $envmodel      = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
        $envmodelmini  = trim((string)(getenv('BOOKING_TEST_AI_MODEL_MINI') ?: ''));
        $envembedmodel = trim((string)(getenv('BOOKING_TEST_AI_EMBEDDING_MODEL') ?: ''));
        $envendpoint   = trim((string)(getenv('BOOKING_TEST_AI_ENDPOINT') ?: ''));
        $envactive     = $envkey !== '';

        // Resolve the target instance: the one requested, else the default (first sorted).
        $instances = $this->provider_instances();
        $target = null;
        if ($instanceid !== null && isset($instances[$instanceid])) {
            $target = $instances[$instanceid];
        } else if (!empty($instances)) {
            $target = reset($instances);
        }
        $providerfound = $target !== null;
        $instname      = $providerfound ? (string)$target->name : '';
        $instid        = $providerfound ? (int)$target->id : 0;
        $instenabled   = $providerfound ? !empty($target->enabled) : false;
        $ov            = $providerfound
            ? self::extract_overrides($target)
            : ['key' => '', 'planner' => '', 'reply' => '', 'embed' => '', 'endpoint' => ''];

        $resolve = static function (string $role, string $envvalue, string $envvarname) use ($envactive, $ov): array {
            if ($envactive && $envvalue !== '') {
                return ['model' => $envvalue, 'source' => 'env', 'envvar' => $envvarname];
            }
            return ['model' => $ov[$role], 'source' => 'provider', 'envvar' => $envvarname];
        };

        $plannerenv = $envmodelmini !== '' ? $envmodelmini : $envmodel;
        $plannervar = $envmodelmini !== '' ? 'BOOKING_TEST_AI_MODEL_MINI' : 'BOOKING_TEST_AI_MODEL';
        $planner = $resolve('planner', $plannerenv, $plannervar);
        $reply   = $resolve('reply', $envmodel, 'BOOKING_TEST_AI_MODEL');
        $embed   = $resolve('embed', $envembedmodel, 'BOOKING_TEST_AI_EMBEDDING_MODEL');

        // Embeddings are live for routing iff a CURRENT skill catalog exists for the embedding variant
        // this run will actually use — the SELECTED instance's embedding model + dimensions (the same
        // freshness check skill_governance surfaces). Show that model only when live.
        $embeddims = (int)($ov['embeddims'] ?? 0);
        if ($embeddims < 1) {
            $embeddims = orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS;
        }
        $embeddingsmodel = self::catalog_model_if_ready((string)$embed['model'], $embeddims);
        $embeddingsactive = $embeddingsmodel !== '';

        // Key source: env override (CLI only), else the instance's own key, else none.
        if ($envactive) {
            $key = ['source' => 'env', 'detail' => 'BOOKING_TEST_AI_KEY'];
        } else if ($ov['key'] !== '') {
            $key = ['source' => 'provider', 'detail' => $instname];
        } else {
            $key = ['source' => 'none', 'detail' => get_string('benchmark_run_key_none', 'bookingextension_agent')];
        }

        if ($envactive && $envendpoint !== '') {
            $endpoint = ['source' => 'env', 'value' => $envendpoint];
        } else {
            $endpoint = ['source' => 'provider', 'value' => $ov['endpoint']];
        }

        return [
            'env_override_active' => $envactive,
            'embeddings_active'   => $embeddingsactive,
            'embeddings_model'    => $embeddingsmodel,
            'provider_found'      => $providerfound,
            'instance_enabled'    => $instenabled,
            'instance_id'         => $instid,
            'instance_name'       => $instname,
            'key'                 => $key,
            'endpoint'            => $endpoint,
            'actions'             => [
                ['label' => get_string('benchmark_run_action_planner', 'bookingextension_agent')] + $planner,
                ['label' => get_string('benchmark_run_action_reply', 'bookingextension_agent')] + $reply,
                ['label' => get_string('benchmark_run_action_embed', 'bookingextension_agent')] + $embed,
            ],
        ];
    }

    /**
     * The embedding model/dimensions a run will use for the given provider instance: its configured
     * embeddings model (or the BOOKING_TEST_AI_EMBEDDING_MODEL env override) and dimensions (or the
     * orchestrator default when the instance does not declare them).
     *
     * @param int|null $instanceid The provider instance, or null for the default (first sorted).
     * @return array{model: string, dimensions: int}
     */
    public function embedding_variant_for_instance(?int $instanceid): array {
        $envembedmodel = trim((string)(getenv('BOOKING_TEST_AI_EMBEDDING_MODEL') ?: ''));
        $instances = $this->provider_instances();
        $target = ($instanceid !== null && isset($instances[$instanceid]))
            ? $instances[$instanceid]
            : (!empty($instances) ? reset($instances) : null);
        $ov = $target !== null ? self::extract_overrides($target) : ['embed' => '', 'embeddims' => 0];

        $model = $envembedmodel !== '' ? $envembedmodel : trim((string)($ov['embed'] ?? ''));
        $dims = (int)($ov['embeddims'] ?? 0);
        if ($dims < 1) {
            $dims = orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS;
        }
        return ['model' => $model, 'dimensions' => $dims];
    }

    /**
     * The embedding model when a CURRENT skill catalog exists for the given variant (model + dims) —
     * the same freshness check skill_governance surfaces — or '' when the catalog is stale/missing/empty
     * or no embedding model is configured. This is what decides "embeddings live" for a run.
     *
     * @param string $model
     * @param int $dimensions
     * @return string
     */
    public static function catalog_model_if_ready(string $model, int $dimensions): string {
        $model = trim($model);
        if ($model === '' || $dimensions < 1) {
            return '';
        }
        $status = (new embeddings_readiness_service())->get_catalog_status(
            skill_registry_factory::get_default(),
            $model,
            $dimensions
        );
        return ((string)($status['status'] ?? '')) === 'ready' ? $model : '';
    }
}

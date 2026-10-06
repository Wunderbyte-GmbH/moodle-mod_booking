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

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\skill_registry_factory;
use core\di;
use core_ai\manager as ai_manager;

/**
 * Runs a benchmark scenario set and persists the result, independent of the
 * caller (CLI runner or the "run from interface" adhoc task).
 *
 * This is the wiring + scenario loop, factored out so the interface "run" path
 * (run_benchmark_adhoc) can reuse it. It MIRRORS cli/benchmark_runner.php, which
 * remains the canonical reference; the two are intentionally kept behaviourally
 * identical (the CLI was left untouched to avoid any risk to the CI runner — a
 * follow-up can make the CLI delegate here to remove the duplication). Output is
 * surfaced via the optional progress callback (cli_writeln for CLI, mtrace for the task).
 *
 * Provider resolution is the SAME as production: when BOOKING_TEST_AI_KEY is set
 * the benchmark_envkey_manager is injected to apply BOOKING_TEST_AI_* overrides,
 * otherwise the configured provider is used exactly as everywhere else. See
 * {@see benchmark_provider_preview} for the human-readable "what will be used".
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_run_service {
    /**
     * Run a benchmark and persist it.
     *
     * @param array $options Keys (all optional, CLI-compatible): scenario-set, model, label, env,
     *                       git-ref, stub, pin-baseline, baseline-label, cmid, userid, tier.
     * @param callable|null $progress Optional callback invoked with a single string per progress line.
     * @return array Summary: runid, total, passed, failed, success_rate, duration_ms, regression, label.
     */
    public function run(array $options = [], ?callable $progress = null): array {
        global $CFG, $DB;

        $emit = static function (string $line) use ($progress): void {
            if ($progress !== null) {
                $progress($line);
            }
        };

        $setname   = (string)($options['scenario-set'] ?? 'agent_core_v1');
        $label     = (string)(($options['label'] ?? '') ?: date('Y-m-d H:i') . ' ' . $setname);
        $env       = (string)($options['env'] ?? 'local');
        $gitref    = (string)($options['git-ref'] ?? '');
        $usestub   = (bool)($options['stub'] ?? false);
        $pinbase   = (bool)($options['pin-baseline'] ?? false);
        $baselabel = (string)(($options['baseline-label'] ?? '') ?: $label);
        $benchcmid   = (int)($options['cmid'] ?? 25);
        $benchuserid = (int)($options['userid'] ?? 2);
        $tier        = (string)($options['tier'] ?? 'probabilistic');
        $providerinstanceid = (int)($options['provider_instance_id'] ?? 0);

        // Embeddings are live for this run iff a CURRENT skill catalog exists for the embedding variant
        // the SELECTED provider instance uses (the same freshness check skill_governance surfaces).
        // When live, record the embedding model used; the run's score is then attributable to its mode.
        $embvariant = (new benchmark_provider_preview())->embedding_variant_for_instance($providerinstanceid ?: null);
        $embeddingsmodel = benchmark_provider_preview::catalog_model_if_ready(
            $embvariant['model'],
            $embvariant['dimensions']
        );
        $embeddingsused = $embeddingsmodel !== '' ? 1 : 0;

        $registry  = new benchmark_scenario_registry();
        $collector = new benchmark_result_collector();
        $metrics   = new benchmark_metrics_calculator();
        $dbwriter  = new benchmark_db_writer();

        // Build the orchestrator stack BEFORE installing the provider override, so the only code
        // running while the override is active is the (per-scenario try/catch'd) loop below — that
        // keeps the restore further down reliable.
        $store = null;
        $orc   = null;
        $prevforceddebug = null;
        $haddebugforced  = false;
        if (!$usestub) {
            $skillregistry = skill_registry_factory::get_default();
            $store         = new conversation_store();
            $orc           = new orchestrator($skillregistry, new interpreter($skillregistry), $store);

            // The harness scores a scenario by reading the selection response back from
            // {bx_agent_ai_llm_debug} — and that table is only written while aidebugmode is on
            // (llm_debug_logger::is_enabled()). With debug off (the production default) every
            // scenario would "fail" with an empty response. Force it ON process-locally via
            // forced_plugin_settings: no DB write, restored below, so a disabled production
            // debug mode stays disabled outside this run.
            $haddebugforced  = isset($CFG->forced_plugin_settings['bookingextension_agent']['aidebugmode']);
            $prevforceddebug = $CFG->forced_plugin_settings['bookingextension_agent']['aidebugmode'] ?? null;
            $CFG->forced_plugin_settings['bookingextension_agent']['aidebugmode'] = '1';
        }

        // Provider override for this run (process-local, no DB writes): a chosen provider INSTANCE has
        // its key/models applied as if they were the BOOKING_TEST_AI_* env vars (putenv) and then
        // patched onto the working provider by benchmark_envkey_manager — reusing the existing override
        // so even a DISABLED (not-yet-live) instance can be benchmarked. Otherwise the env vars apply
        // (CLI/CI), else the default configured provider. Both the env vars and the manager are restored
        // right after the run loop, so nothing leaks to other code or a later task in the same process.
        $envunset = ($providerinstanceid > 0 && !$usestub) ? $this->apply_instance_as_env($providerinstanceid) : [];
        $envkey = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
        $previousmanager = null;
        if (!$usestub && $envkey !== '') {
            $previousmanager = di::get(ai_manager::class);
            di::set(ai_manager::class, new benchmark_envkey_manager($DB));
        }

        // Record the model actually used — resolved AFTER the override above, so a chosen instance's
        // model (injected into BOOKING_TEST_AI_MODEL by apply_instance_as_env) is captured for the run.
        $envmodel = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
        $defaultmodel = (string)(get_config('bookingextension_agent', 'default_model') ?: 'unknown');
        $modelid = (string)(($options['model'] ?? '') ?: ($envmodel ?: $defaultmodel));

        $allscenarios = $registry->get_scenarios($setname);

        // Tier filter (BENCHMARK_REDESIGN.md): the live benchmark measures only model-dependent routing
        // (probabilistic). Deterministic contract behaviour belongs in PHPUnit unless explicitly asked.
        $scenarios = [];
        $excluded  = [];
        foreach ($allscenarios as $sc) {
            $sctier = method_exists($sc, 'get_tier') ? (string)$sc->get_tier() : 'probabilistic';
            if ($tier === 'all' || $sctier === $tier) {
                $scenarios[] = $sc;
            } else {
                $excluded[] = $sc->get_key() . ' (' . $sctier . ')';
            }
        }
        $scenarios = array_values($scenarios);
        $total     = count($scenarios);
        $emit('Tier: ' . $tier . ' | scenarios: ' . $total
            . (empty($excluded) ? '' : ' | excluded ' . count($excluded) . ': ' . implode(', ', $excluded)));

        $runstart        = microtime(true);
        $scenarioresults = [];
        $totaltokens     = 0;

        foreach ($scenarios as $i => $scenario) {
            $idx = $i + 1;
            $key = $scenario->get_key();
            $t0  = microtime(true);

            if ($usestub) {
                $stub = $scenario->get_stub_selector_response();
                if ($stub === '') {
                    $stub = json_encode([
                        'response_type'    => $scenario->get_expected_response_type() ?: 'clarification',
                        'commands'         => [],
                        'planned_steps'    => [],
                        'next_step_intent' => '',
                        'lang'             => 'de',
                        'user_lang'        => 'de',
                        'message'          => 'stub',
                    ]);
                }
                $rawresponse      = $stub;
                $tokensprompt     = 0;
                $tokenscompletion = 0;
            } else {
                try {
                    $contextid = (int)\context_module::instance($benchcmid)->id;
                    $thread    = $store->create_fresh_thread($benchuserid, $contextid, 0);
                    $threadid  = (int)$thread->id;

                    foreach ($scenario->get_prior_messages() as $msg) {
                        $store->add_message($threadid, (string)($msg['role'] ?? 'user'), (string)($msg['content'] ?? ''));
                    }
                    if (method_exists($scenario, 'setup_state')) {
                        $scenario->setup_state($store, $threadid, $contextid, $benchuserid);
                    }
                    $store->add_message($threadid, 'user', $scenario->get_user_message());

                    // The orchestrator's 2nd param is a CONTEXT id (it does context::instance_by_id on
                    // it), not a cmid — pass the module context id we resolved above, so scenarios run
                    // with real course grounding (else the agent lands in an unrelated context id=cmid).
                    $orc->process($threadid, $contextid, $benchuserid);

                    $logrow = $DB->get_record_sql(
                        "SELECT requesttext, responsetext FROM {bx_agent_ai_llm_debug}
                          WHERE threadid = :tid AND source LIKE 'orc|p=sel%'
                          ORDER BY id DESC LIMIT 1",
                        ['tid' => $threadid]
                    );
                    if (!$logrow) {
                        // Without a captured selection response the scenario cannot be scored; a '{}'
                        // fallback would masquerade as a model contract failure (all fields "missing").
                        // Surface it as the harness error it is (run 34: aidebugmode off => 0/19).
                        throw new \RuntimeException(
                            'harness: no selection response captured in bx_agent_ai_llm_debug for thread '
                            . $threadid . ' (is LLM debug logging active?)'
                        );
                    }
                    $rawresponse      = trim((string)$logrow->responsetext);
                    $tokensprompt     = (int)round(strlen($logrow->requesttext ?? '') / 4);
                    $tokenscompletion = (int)round(strlen($logrow->responsetext ?? '') / 4);

                    $DB->set_field('bx_agent_ai_threads', 'status', 'archived', ['id' => $threadid]);
                } catch (\Throwable $ex) {
                    $durationms = (int)round((microtime(true) - $t0) * 1000);
                    $emit("[{$idx}/{$total}] {$key} ... ERROR — " . $ex->getMessage());
                    $scenarioresults[] = [
                        'scenario_key'           => $key,
                        'scenario_class'         => $scenario->get_class(),
                        'passed'                 => 0,
                        'response_type_expected' => $scenario->get_expected_response_type(),
                        'response_type_actual'   => '',
                        'skill_expected'         => $scenario->get_expected_skill(),
                        'skill_selected'         => '',
                        'json_valid'             => 0,
                        'contract_compliant'     => 0,
                        'planned_steps_present'  => 0,
                        'tokens_prompt'          => 0,
                        'tokens_completion'      => 0,
                        'duration_ms'            => $durationms,
                        'step_count'             => 0,
                        'error_message'          => 'exception: ' . $ex->getMessage(),
                        'result_json'            => null,
                    ];
                    continue;
                }
            }

            $durationms   = (int)round((microtime(true) - $t0) * 1000);
            $result       = $collector->evaluate($scenario, $rawresponse, $durationms, $tokensprompt, $tokenscompletion);
            $totaltokens += $tokensprompt + $tokenscompletion;
            $scenarioresults[] = $result;

            $status = $result['passed'] ? 'PASS' : 'FAIL';
            $detail = $result['passed'] ? '' : ' — ' . ($result['error_message'] ?? '');
            $emit("[{$idx}/{$total}] {$key} ... {$status}{$detail}");
        }

        // Restore the AI manager + clear the test env now the LLM calls are done, so a later task in
        // the same cron run keeps the default provider.
        if ($previousmanager !== null) {
            di::set(ai_manager::class, $previousmanager);
        }
        foreach ($envunset as $var) {
            putenv($var);
        }
        // Drop the process-local aidebugmode force again (a later task in the same cron process
        // must see the real admin setting).
        if (!$usestub) {
            if ($haddebugforced) {
                $CFG->forced_plugin_settings['bookingextension_agent']['aidebugmode'] = $prevforceddebug;
            } else {
                unset($CFG->forced_plugin_settings['bookingextension_agent']['aidebugmode']);
            }
        }

        $rundurationms = (int)round((microtime(true) - $runstart) * 1000);
        $passed        = array_sum(array_column($scenarioresults, 'passed'));
        $failed        = $total - $passed;
        $rate          = $total > 0 ? round($passed / $total * 100, 2) : 0.0;
        $metricrecords = $metrics->calculate($scenarioresults);
        $metricsmap    = array_column($metricrecords, 'metric_value', 'metric_key');
        $regression    = $metrics->has_critical_regression($metricsmap);

        // Sub-metrics (BENCHMARK_REDESIGN.md §4): keep skill-routing, JSON validity and contract distinct
        // so a dip is attributable. Single-run % stays noisy — judge changes over N runs (benchmark_matrix).
        $skillscoped = 0;
        $skillhit    = 0;
        $jsonok      = 0;
        $contractok  = 0;
        foreach ($scenarioresults as $r) {
            if (!empty($r['json_valid'])) {
                $jsonok++;
            }
            if (!empty($r['contract_compliant'])) {
                $contractok++;
            }
            if ((string)($r['skill_expected'] ?? '') !== '') {
                $skillscoped++;
                if ((string)($r['skill_selected'] ?? '') === (string)$r['skill_expected']) {
                    $skillhit++;
                }
            }
        }
        $emit(str_repeat('-', 60));
        $emit("RESULTS: {$passed}/{$total} passed ({$rate}%) in {$rundurationms}ms");
        $emit("  skill-hit (scoped): {$skillhit}/{$skillscoped} | json-valid: {$jsonok}/{$total}"
            . " | contract: {$contractok}/{$total}");
        // Rate "regression" is meaningless for the binary deterministic tier (must be 4/4); skip it there.
        if ($regression && $tier !== 'deterministic') {
            $emit('WARNING: Critical metric regression detected!');
        }

        $rundata = [
            'label'               => $label,
            'model_id'            => $modelid,
            'prompt_profile'      => 'default',
            'skill_set'           => $setname,
            'total_scenarios'     => $total,
            'passed'              => $passed,
            'failed'              => $failed,
            'skipped'             => 0,
            'success_rate'        => $rate,
            'total_tokens'        => $totaltokens,
            'duration_ms'         => $rundurationms,
            'environment'         => $env,
            'git_ref'             => $gitref,
            'embeddings_used'     => $embeddingsused,
            'embeddings_model'    => $embeddingsmodel,
            'regression_detected' => $regression ? 1 : 0,
        ];

        $runid = $dbwriter->write_run($rundata, $scenarioresults, $metricrecords);
        $emit("Run saved: ID={$runid}");

        if ($pinbase) {
            $dbwriter->pin_baseline($runid, $baselabel, "Pinned from run {$runid}");
            $emit("Pinned as baseline: {$baselabel}");
        }

        return [
            'runid'        => $runid,
            'total'        => $total,
            'passed'       => $passed,
            'failed'       => $failed,
            'success_rate' => $rate,
            'duration_ms'  => $rundurationms,
            'regression'   => $regression,
            'label'        => $label,
            'tier'         => $tier,
            'scenario_set' => $setname,
            'embeddings_used' => (bool)$embeddingsused,
        ];
    }

    /**
     * Apply a chosen provider instance's key/models/endpoint to the process env as the
     * BOOKING_TEST_AI_* vars, so benchmark_envkey_manager patches them onto the working provider for
     * the run (the existing env-override path). This lets a disabled / not-yet-live instance be
     * benchmarked through a functional provider's plumbing.
     *
     * @param int $instanceid The m_ai_providers id.
     * @return string[] The env var names that were set, to unset again after the run.
     */
    private function apply_instance_as_env(int $instanceid): array {
        global $DB;
        try {
            $providers = (new ai_manager($DB))->get_sorted_providers();
        } catch (\Throwable $e) {
            return [];
        }
        if (!isset($providers[$instanceid])) {
            return [];
        }
        // Provider-type agnostic extraction (works for any provider, incl. a disabled one).
        $ov = benchmark_provider_preview::extract_overrides($providers[$instanceid]);
        $vars = [
            'BOOKING_TEST_AI_KEY'             => $ov['key'],
            'BOOKING_TEST_AI_MODEL'           => $ov['reply'],
            'BOOKING_TEST_AI_MODEL_MINI'      => $ov['planner'],
            'BOOKING_TEST_AI_MODEL_CONSTRUCT' => $ov['construct'],
            'BOOKING_TEST_AI_EMBEDDING_MODEL' => $ov['embed'],
            'BOOKING_TEST_AI_ENDPOINT'        => $ov['endpoint'],
        ];
        $set = [];
        foreach ($vars as $name => $value) {
            if ($value !== '') {
                putenv($name . '=' . $value);
                $set[] = $name;
            }
        }
        return $set;
    }
}

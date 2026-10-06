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
 * CLI benchmark runner for bookingextension_agent.
 *
 * Usage:
 *   php benchmark_runner.php [options]
 *
 * Options:
 *   --scenario-set=agent_core_v1     Scenario set to run (default: agent_core_v1)
 *   --model=claude-sonnet-4-6        Model ID to record (default: from config)
 *   --label=release-x.y.z           Human-readable run label
 *   --env=local                      Environment tag (local, ci, staging)
 *   --git-ref=HEAD                   Git ref (auto-detected if omitted)
 *   --stub                           Use stub responses (no live LLM calls)
 *   --pin-baseline                   Pin this run as the new baseline after completion
 *   --baseline-label=stable-x.y.z   Label for the baseline (used with --pin-baseline)
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use bookingextension_agent\local\wizard\benchmark\benchmark_envkey_manager;
use bookingextension_agent\local\wizard\benchmark\benchmark_scenario_registry;
use bookingextension_agent\local\wizard\benchmark\benchmark_result_collector;
use bookingextension_agent\local\wizard\benchmark\benchmark_metrics_calculator;
use bookingextension_agent\local\wizard\benchmark\benchmark_db_writer;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\interpreter;
use core\di;
use core_ai\manager as ai_manager;

[$options, $unrecognized] = cli_get_params(
    [
        'scenario-set'   => 'agent_core_v1',
        'model'          => '',
        'label'          => '',
        'env'            => 'local',
        'git-ref'        => '',
        'stub'           => false,
        'pin-baseline'   => false,
        'baseline-label' => '',
        'cmid'           => 25,
        'userid'         => 2,
        'tier'           => 'probabilistic',
        'help'           => false,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln(
        "Benchmark runner for bookingextension_agent.\n\n"
        . "A lean, decision-oriented routing benchmark (target ~1 min live). Exhaustive per-skill\n"
        . "coverage lives in the PHPUnit suite; this set is the curated 'is the routing healthy /\n"
        . "did my change help' instrument.\n\n"
        . "Options:\n"
        . "  --scenario-set=agent_core_v1  (default; booking-free, agent-native scenarios)\n"
        . "                Use --scenario-set=decision_core for the curated ~1-min set,\n"
        . "                or --scenario-set=core_booking_v1 for the broad confusable-cluster set.\n"
        . "  --model=claude-sonnet-4-6\n"
        . "  --label=release-x.y.z\n"
        . "  --env=local|ci|staging\n"
        . "  --git-ref=abc1234\n"
        . "  --stub  (use stub responses, no live LLM calls)\n"
        . "  --cmid=25     booking context module id for live runs (default: 25)\n"
        . "  --userid=2    Moodle user id to run as (default: 2 = admin)\n"
        . "  --tier=probabilistic|deterministic|all  (default: probabilistic)\n"
        . "                probabilistic = LLM routing/selection quality (the live benchmark).\n"
        . "                deterministic = contract behaviour — excluded here, covered by PHPUnit\n"
        . "                (see docs/Blueprints/BENCHMARK_REDESIGN.md).\n"
        . "  --pin-baseline  (pin run as baseline after completion)\n"
        . "  --baseline-label=stable-x.y.z\n"
    );
    exit(0);
}

$setname   = (string)$options['scenario-set'];
$envmodel  = trim((string)(getenv('BOOKING_TEST_AI_MODEL') ?: ''));
$modelid   = (string)($options['model'] ?: ($envmodel ?: (get_config('bookingextension_agent', 'default_model') ?: 'unknown')));
$label     = (string)($options['label'] ?: date('Y-m-d H:i') . ' ' . $setname);
$env       = (string)$options['env'];
$gitref    = (string)($options['git-ref'] ?: trim(shell_exec('git rev-parse --short HEAD 2>/dev/null') ?: ''));
$usestub   = (bool)$options['stub'];
$pinbase   = (bool)$options['pin-baseline'];
$baselabel = (string)($options['baseline-label'] ?: $label);

// Live run context — use the 'ai' booking instance (cmid=25, contextid=6168) and admin user.
// Override with --cmid and --userid if needed (add to cli_get_params if required).
$benchcmid   = (int)($options['cmid'] ?? 25);
$benchuserid = (int)($options['userid'] ?? 2);

$registry  = new benchmark_scenario_registry();
$collector = new benchmark_result_collector();
$metrics   = new benchmark_metrics_calculator();
$dbwriter  = new benchmark_db_writer();

// When BOOKING_TEST_AI_KEY is set, inject a custom AI manager that bypasses
// is_provider_configured() so a DB provider with an empty apikey is still used.
// This is a process-local DI override — no DB writes.
$envkey = trim((string)(getenv('BOOKING_TEST_AI_KEY') ?: ''));
if (!$usestub && $envkey !== '') {
    di::set(ai_manager::class, new benchmark_envkey_manager($DB));
}

// Build orchestrator stack for live runs (reused across scenarios).
$store      = null;
$orc        = null;
if (!$usestub) {
    $skillregistry = skill_registry_factory::get_default();
    $store        = new conversation_store();
    $orc          = new orchestrator($skillregistry, new interpreter($skillregistry), $store);

    // Scoring reads the selection response back from {bx_agent_ai_llm_debug}, which is only
    // written while aidebugmode is on. Force it ON process-locally (no DB write; this CLI
    // process ends after the run), so a production "debug off" cannot zero out the benchmark.
    $CFG->forced_plugin_settings['bookingextension_agent']['aidebugmode'] = '1';
}

$tier      = (string)$options['tier'];
$allscenarios = $registry->get_scenarios($setname);

// Tier filter (BENCHMARK_REDESIGN.md): the live benchmark measures only model-dependent routing
// (probabilistic). Deterministic contract behaviour belongs in PHPUnit, not this noisy run, so it is
// excluded here unless explicitly requested.
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
cli_writeln('Tier: ' . $tier . ' | scenarios: ' . $total
    . (empty($excluded) ? '' : ' | excluded ' . count($excluded) . ': ' . implode(', ', $excluded)));

$hasenvvars = $envkey !== ''
    && trim((string)(getenv('BOOKING_TEST_AI_ENDPOINT') ?: '')) !== ''
    && $envmodel !== '';

cli_writeln("=== bookingextension_agent Benchmark Runner ===");
cli_writeln("Set: {$setname} | Model: {$modelid} | Env: {$env} | Stub: " . ($usestub ? 'yes' : 'no'));
if (!$usestub && $envkey !== '') {
    $envinfo = 'BOOKING_TEST_AI_KEY set';
    if ($hasenvvars) {
        $envinfo .= ' (+ ENDPOINT + MODEL)';
    }
    cli_writeln("Credentials: {$envinfo} — env vars override provider config; is_provider_configured() gate bypassed.");
}
cli_writeln("Scenarios: {$total}");
cli_writeln(str_repeat('-', 60));

$runstart     = microtime(true);
$scenarioresults = [];
$totaltokens  = 0;

foreach ($scenarios as $i => $scenario) {
    $idx = $i + 1;
    $key = $scenario->get_key();
    cli_write("[{$idx}/{$total}] {$key} ... ");

    $t0 = microtime(true);

    // Determine response: stub or live.
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
        // Live LLM call via orchestrator::process().
        try {
            // Build a fresh isolated thread for this scenario.
            $contextid = (int)\context_module::instance($benchcmid)->id;
            $thread    = $store->create_fresh_thread($benchuserid, $contextid, 0);
            $threadid  = (int)$thread->id;

            // Inject prior conversation messages (for follow-up scenarios).
            foreach ($scenario->get_prior_messages() as $msg) {
                $store->add_message($threadid, (string)($msg['role'] ?? 'user'), (string)($msg['content'] ?? ''));
            }
            // Seed REAL thread state via production setters when the scenario needs it (state-driven
            // behaviour that prior message text alone cannot reproduce). Default is a no-op.
            if (method_exists($scenario, 'setup_state')) {
                $scenario->setup_state($store, $threadid, $contextid, $benchuserid);
            }
            // Add the scenario's user message.
            $store->add_message($threadid, 'user', $scenario->get_user_message());

            // Run the full planner pipeline (discovery → selection → construction). The 2nd param
            // is a CONTEXT id (context::instance_by_id), not a cmid — pass the resolved module
            // context so scenarios run with real course grounding (same fix as run_service).
            $plannerresult = $orc->process($threadid, $contextid, $benchuserid);

            // Get the raw selector LLM response directly from the debug log —
            // this is the actual JSON the model emitted, before any parsing.
            $logrow = $DB->get_record_sql(
                "SELECT requesttext, responsetext FROM {bx_agent_ai_llm_debug}
                  WHERE threadid = :tid AND source LIKE 'orc|p=sel%'
                  ORDER BY id DESC LIMIT 1",
                ['tid' => $threadid]
            );
            if (!$logrow) {
                // A '{}' fallback would score as a model contract failure; report the harness
                // problem (no captured selection response) honestly instead.
                throw new \RuntimeException(
                    'harness: no selection response captured in bx_agent_ai_llm_debug for thread '
                    . $threadid . ' (is LLM debug logging active?)'
                );
            }
            $rawresponse      = trim((string)$logrow->responsetext);
            $tokensprompt     = (int)round(strlen($logrow->requesttext ?? '') / 4);
            $tokenscompletion = (int)round(strlen($logrow->responsetext ?? '') / 4);

            // Archive the temporary thread to avoid polluting the user's history.
            $DB->set_field('bx_agent_ai_threads', 'status', 'archived', ['id' => $threadid]);
        } catch (\Throwable $ex) {
            $durationms = (int)round((microtime(true) - $t0) * 1000);
            cli_writeln('ERROR — ' . $ex->getMessage());
            $scenarioresults[] = [
                'scenario_key'           => $key,
                'scenario_class'         => $scenario->get_class(),
                'passed'                 => 0,
                'response_type_expected' => $scenario->get_expected_response_type(),
                'response_type_actual'   => '',
                'skill_expected'          => $scenario->get_expected_skill(),
                'skill_selected'          => '',
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

    $durationms = (int) round((microtime(true) - $t0) * 1000);
    $result     = $collector->evaluate($scenario, $rawresponse, $durationms, $tokensprompt, $tokenscompletion);
    $totaltokens += $tokensprompt + $tokenscompletion;
    $scenarioresults[] = $result;

    $status = $result['passed'] ? 'PASS' : 'FAIL';
    $detail = $result['passed'] ? '' : ' — ' . ($result['error_message'] ?? '');
    cli_writeln("{$status}{$detail}");
}

$runend     = microtime(true);
$rundurationms = (int) round(($runend - $runstart) * 1000);

$passed  = array_sum(array_column($scenarioresults, 'passed'));
$failed  = $total - $passed;
$rate    = $total > 0 ? round($passed / $total * 100, 2) : 0.0;
$metricrecords = $metrics->calculate($scenarioresults);
$metricsmap = array_column($metricrecords, 'metric_value', 'metric_key');
$regression = $metrics->has_critical_regression($metricsmap);

cli_writeln(str_repeat('-', 60));
cli_writeln("RESULTS: {$passed}/{$total} passed ({$rate}%) in {$rundurationms}ms");

// Separated sub-metrics (BENCHMARK_REDESIGN.md §4): keep skill-routing, JSON validity and contract
// distinct so a dip is attributable. Single-run % stays noisy — use cli/benchmark_matrix.php over N runs.
$skillscoped = 0;
$skillhit = 0;
$jsonok = 0;
$contractok = 0;
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
cli_writeln("  skill-hit (scoped): {$skillhit}/{$skillscoped} | json-valid: {$jsonok}/{$total}"
    . " | contract: {$contractok}/{$total}");
cli_writeln('  NOTE: judge a change by the stable-fail set over N runs (cli/benchmark_matrix.php),'
    . ' not a single percentage.');
// Rate-based thresholds (skill-hit, planned-steps coverage, …) are a Tier-2 quality concept. The
// deterministic tier is binary pass/fail (it must be 4/4), so a rate "regression" there is meaningless.
if ($regression && $tier !== 'deterministic') {
    cli_writeln("WARNING: Critical metric regression detected!");
}

$embvariant = (new \bookingextension_agent\local\wizard\benchmark\benchmark_provider_preview())
    ->embedding_variant_for_instance(null);
$embeddingsmodel = \bookingextension_agent\local\wizard\benchmark\benchmark_provider_preview::catalog_model_if_ready(
    $embvariant['model'],
    $embvariant['dimensions']
);
$rundata = [
    'label'              => $label,
    'model_id'           => $modelid,
    'prompt_profile'     => 'default',
    'skill_set'           => $setname,
    'total_scenarios'    => $total,
    'passed'             => $passed,
    'failed'             => $failed,
    'skipped'            => 0,
    'success_rate'       => $rate,
    'total_tokens'       => $totaltokens,
    'duration_ms'        => $rundurationms,
    'environment'        => $env,
    'git_ref'            => $gitref,
    'embeddings_used'    => $embeddingsmodel !== '' ? 1 : 0,
    'embeddings_model'   => $embeddingsmodel,
    'regression_detected' => $regression ? 1 : 0,
];

$runid = $dbwriter->write_run($rundata, $scenarioresults, $metricrecords);
cli_writeln("Run saved: ID={$runid}");

if ($pinbase) {
    $dbwriter->pin_baseline($runid, $baselabel, "Pinned from CLI run {$runid}");
    cli_writeln("Pinned as baseline: {$baselabel}");
}

cli_writeln("Done.");
exit($regression ? 1 : 0);

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
 * W2 measurement probe: natural product-language prompts against the booking create family.
 *
 * NOT a pass/fail regression test — a DATA COLLECTOR (F3-W2 baseline, blueprint §8 A1/A2).
 * The skill-matrix scenarios speak engine vocabulary ("maxanswers 6", "duration of 14400
 * seconds") and therefore never exercise the product-language → schema translation where
 * the live failures happen ("kostet 30 Euro", "Studierende zahlen 20", "mit Beschreibung").
 * This probe drives the FULL chat loop (construction → repair loop → preflight → sync)
 * with user-style wording, N iterations per scenario, and reports per run:
 *  - which non-schema keys the constructor guessed (diffed against the live skill schema),
 *  - whether the repair loop healed them or the turn surfaced to the user,
 *  - the final response type, issue codes and user-visible message.
 *
 * The JSON report is written to STDERR (grep for W2_PROBE_REPORT). Iterations via
 * BOOKING_TEST_PROBE_ITERATIONS (default 3). Only harness sanity is asserted.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Natural-language construction probe for the booking create family.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class booking_create_language_probe_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    /**
     * Run every scenario N times through the full chat loop and emit the measurement report.
     */
    public function test_probe_natural_language_construction(): void {
        set_config('selflearningcourseactive', 1, 'booking');
        foreach ([['default', 'Standardpreis', 25], ['student', 'Studierende', 15]] as $i => $cat) {
            $this->gen->create_pricecategory((object)[
                'ordernum' => $i + 1,
                'identifier' => $cat[0],
                'name' => $cat[1],
                'defaultvalue' => $cat[2],
            ]);
        }
        $this->setUser($this->teacher);

        $iterations = max(1, (int)(getenv('BOOKING_TEST_PROBE_ITERATIONS') ?: 3));
        $report = [
            'campaign' => 'w2-natural-language-baseline',
            'iterations' => $iterations,
            'runs' => [],
        ];

        foreach ($this->scenarios() as $scenariokey => $template) {
            for ($i = 1; $i <= $iterations; $i++) {
                $report['runs'][] = $this->run_probe_iteration($scenariokey, $template, $i);
            }
        }

        $report['summary'] = $this->summarize($report['runs']);
        fwrite(STDERR, "\nW2_PROBE_REPORT " . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n");

        $this->assertNotEmpty($report['runs'], 'The probe must produce measurement runs.');
    }

    /**
     * Natural product-language scenarios; {{i}} keeps titles unique per iteration.
     *
     * Wording mirrors the live threads (585/586/590): prices in Euro prose, student
     * discounts, descriptions, day-based access, hour-based durations, natural dates.
     *
     * @return array<string,string>
     */
    private function scenarios(): array {
        return [
            'price_singular' => 'Lege eine Buchungsoption "Yoga Abend {{i}}" an, kostet 25 Euro, '
                . 'maximal 12 Teilnehmer.',
            'student_price' => 'Erstelle die Buchungsoption "Rücken fit {{i}}": 30 Euro, '
                . 'Studierende zahlen nur 20 Euro, maximal 10 Plätze.',
            'description' => 'Erstelle eine Buchungsoption "Pilates {{i}}" mit der Beschreibung '
                . '"Sanftes Training für Anfänger", maximal 8 Teilnehmer.',
            'selflearning_days' => 'Mach eine Selbstlern-Buchungsoption "Lernkurs {{i}}", '
                . 'Zugang für 30 Tage, Preis 20 Euro.',
            'duration_hours' => 'Erstelle eine Selbstlern-Option "Kompaktwissen {{i}}" mit '
                . '4 Stunden Lernzeit, 15 Euro.',
            'natural_dates' => 'Lege die Veranstaltung "Infoabend {{i}}" an, morgen von 10 bis '
                . '12 Uhr, maximal 20 Leute.',
            'compound_option' => 'Erstelle "Workshop kompakt {{i}}" für 15 Euro, Trainer bin ich, '
                . 'maximal 10 Leute, mit einer kurzen Beschreibung zum Inhalt.',
        ];
    }

    /**
     * One full-loop iteration: chat → (confirm if offered) → collect construction telemetry.
     *
     * @param string $scenariokey
     * @param string $template
     * @param int $iteration
     * @return array<string,mixed>
     */
    private function run_probe_iteration(string $scenariokey, string $template, int $iteration): array {
        [$store, $runtime, $threadid] = $this->build_runtime();
        $prompt = str_replace('{{i}}', $scenariokey . '-' . $iteration . '-' . substr(sha1(uniqid('', true)), 0, 4), $template);

        $result = $this->chat($prompt, $threadid, $store, $runtime);
        $responsetype = (string)($result['response_type'] ?? '');

        $executed = null;
        if ($responsetype === 'confirmation_request') {
            $confirm = $this->confirm_pending_result($result, (int)$threadid, $store, false);
            $executed = (bool)($confirm['success'] ?? false);
        }

        $construction = $this->collect_construction_telemetry((int)$threadid);

        return [
            'scenario' => $scenariokey,
            'iteration' => $iteration,
            'response_type' => $responsetype,
            'issue_codes' => array_values((array)($result['issue_codes'] ?? [])),
            'executed' => $executed,
            'cons_attempts' => $construction['attempts'],
            'guessed_keys' => $construction['guessed_keys'],
            'retry_hints' => $construction['retry_hints'],
            // Healed means: wrong keys occurred but the turn still ended confirmable/executed.
            'healed' => !empty($construction['guessed_keys'])
                && $responsetype === 'confirmation_request' && $executed === true,
            'surfaced' => in_array($responsetype, ['clarification', 'error'], true),
            'final_message' => \core_text::substr((string)($result['message'] ?? ''), 0, 500),
        ];
    }

    /**
     * Mine this thread's construction rows: attempts, guessed non-schema keys, retry hints.
     *
     * Key diffing runs against the LIVE skill schema (registry), so the probe stays correct
     * when schemas evolve — no hardcoded whitelists.
     *
     * @param int $threadid
     * @return array{attempts:int,guessed_keys:array,retry_hints:int}
     */
    private function collect_construction_telemetry(int $threadid): array {
        global $DB;
        $registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();

        $rows = $DB->get_records_select(
            'bx_agent_ai_llm_debug',
            "threadid = :tid AND " . $DB->sql_like('source', ':src'),
            ['tid' => $threadid, 'src' => 'orc|p=cons%'],
            'id ASC'
        );

        $attempts = 0;
        $retryhints = 0;
        $guessed = [];
        foreach ($rows as $row) {
            $attempts++;
            if (strpos((string)$row->requesttext, 'RETRY_HINT') !== false) {
                $retryhints++;
            }
            [$skillname, $keys] = $this->extract_command_keys((string)$row->responsetext);
            if ($skillname === '' || empty($keys)) {
                continue;
            }
            $skill = $registry->get_skill($skillname);
            if ($skill === null) {
                continue;
            }
            $schemakeys = array_keys((array)(($skill->get_schema()['properties'] ?? [])));
            foreach (array_diff($keys, $schemakeys) as $unknown) {
                $guessed[] = $skillname . ':' . $unknown;
            }
        }

        return [
            'attempts' => $attempts,
            'guessed_keys' => array_values(array_unique($guessed)),
            'retry_hints' => $retryhints,
        ];
    }

    /**
     * Tolerant extraction of (skill, parameter keys) from a raw construction response —
     * naked command objects and enveloped commands[] both occur in the wild (F1 history).
     *
     * @param string $raw
     * @return array{0:string,1:array}
     */
    private function extract_command_keys(string $raw): array {
        $raw = trim(preg_replace('/^\x60{3}(json)?|\x60{3}$/m', '', trim($raw)) ?? $raw);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['', []];
        }

        $command = null;
        if (!empty($data['commands']) && is_array($data['commands'])) {
            $command = is_array($data['commands'][0] ?? null) ? $data['commands'][0] : null;
        } else if (!empty($data['skill'])) {
            $command = $data;
        }
        if ($command === null) {
            return ['', []];
        }

        $input = [];
        foreach (['parameters', 'input'] as $key) {
            if (is_array($command[$key] ?? null)) {
                $input += $command[$key];
            }
        }
        return [(string)($command['skill'] ?? ''), array_keys($input)];
    }

    /**
     * Aggregate the runs into the campaign summary.
     *
     * @param array $runs
     * @return array<string,mixed>
     */
    private function summarize(array $runs): array {
        $keycounts = [];
        $healed = 0;
        $surfaced = 0;
        $executed = 0;
        foreach ($runs as $run) {
            foreach ((array)$run['guessed_keys'] as $key) {
                $keycounts[$key] = ($keycounts[$key] ?? 0) + 1;
            }
            $healed += (int)$run['healed'];
            $surfaced += (int)$run['surfaced'];
            $executed += (int)($run['executed'] === true);
        }
        arsort($keycounts);

        return [
            'total_runs' => count($runs),
            'executed' => $executed,
            'healed_after_wrong_keys' => $healed,
            'surfaced_to_user' => $surfaced,
            'guessed_key_distribution' => $keycounts,
        ];
    }
}

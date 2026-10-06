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

/**
 * Persists benchmark run data to the four benchmark DB tables.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class benchmark_db_writer {
    /**
     * Persist a complete benchmark run atomically.
     *
     * @param array $rundata    Fields for bx_agent_bm_runs.
     * @param array $scenarios  Array of scenario result arrays.
     * @param array $metrics    Array of ['metric_key', 'metric_value', 'metric_unit', 'scenario_class'].
     * @return int  The new run ID.
     */
    public function write_run(array $rundata, array $scenarios, array $metrics): int {
        global $DB;

        $now = time();
        $runrecord = (object) array_merge([
            'run_uuid'           => $rundata['run_uuid'] ?? $this->generate_uuid(),
            'label'              => $rundata['label'] ?? '',
            'model_id'           => $rundata['model_id'] ?? '',
            'model_version'      => $rundata['model_version'] ?? '',
            'prompt_profile'     => $rundata['prompt_profile'] ?? '',
            'skill_set'           => $rundata['skill_set'] ?? '',
            'total_scenarios'    => $rundata['total_scenarios'] ?? 0,
            'passed'             => $rundata['passed'] ?? 0,
            'failed'             => $rundata['failed'] ?? 0,
            'skipped'            => $rundata['skipped'] ?? 0,
            'success_rate'       => $rundata['success_rate'] ?? 0.0,
            'baseline_run_id'    => $rundata['baseline_run_id'] ?? null,
            'is_baseline'        => $rundata['is_baseline'] ?? 0,
            'regression_detected' => $rundata['regression_detected'] ?? 0,
            'total_tokens'       => $rundata['total_tokens'] ?? 0,
            'total_cost_estimate' => $rundata['total_cost_estimate'] ?? 0.0,
            'duration_ms'        => $rundata['duration_ms'] ?? 0,
            'environment'        => $rundata['environment'] ?? 'local',
            'git_ref'            => $rundata['git_ref'] ?? '',
            'embeddings_used'    => $rundata['embeddings_used'] ?? 0,
            'embeddings_model'   => $rundata['embeddings_model'] ?? '',
            'timecreated'        => $now,
        ]);

        $runid = $DB->insert_record('bx_agent_bm_runs', $runrecord);

        foreach ($scenarios as $scenario) {
            $DB->insert_record('bx_agent_bm_scenarios', (object) array_merge([
                'run_id'                  => $runid,
                'scenario_key'            => '',
                'scenario_class'          => '',
                'passed'                  => 0,
                'response_type_expected'  => '',
                'response_type_actual'    => '',
                'skill_expected'           => '',
                'skill_selected'           => '',
                'json_valid'              => 0,
                'contract_compliant'      => 0,
                'planned_steps_present'   => 0,
                'tokens_prompt'           => 0,
                'tokens_completion'       => 0,
                'duration_ms'             => 0,
                'step_count'              => 0,
                'error_message'           => null,
                'result_json'             => null,
                'timecreated'             => $now,
            ], $scenario, ['run_id' => $runid]));
        }

        foreach ($metrics as $metric) {
            $DB->insert_record('bx_agent_bm_metrics', (object) [
                'run_id'         => $runid,
                'metric_key'     => $metric['metric_key'] ?? '',
                'metric_value'   => $metric['metric_value'] ?? 0.0,
                'metric_unit'    => $metric['metric_unit'] ?? 'percent',
                'scenario_class' => $metric['scenario_class'] ?? null,
                'timecreated'    => $now,
            ]);
        }

        return $runid;
    }

    /**
     * Pin a run as a named baseline.
     *
     * @param int $runid
     * @param string $label
     * @param string $description
     * @param int $createdby  Moodle user ID.
     * @return int Baseline record ID.
     */
    public function pin_baseline(int $runid, string $label, string $description = '', int $createdby = 0): int {
        global $DB;
        $DB->set_field('bx_agent_bm_runs', 'is_baseline', 1, ['id' => $runid]);
        return $DB->insert_record('bx_agent_bm_baselines', (object) [
            'run_id'      => $runid,
            'label'       => $label,
            'locked'      => 0,
            'description' => $description,
            'createdby'   => $createdby,
            'timecreated' => time(),
        ]);
    }

    /**
     * Retrieve the most recent pinned baseline run.
     *
     * @return \stdClass|null
     */
    public function get_latest_baseline(): ?\stdClass {
        global $DB;
        $sql = 'SELECT r.* FROM {bx_agent_bm_runs} r
                  JOIN {bx_agent_bm_baselines} b ON b.run_id = r.id
                 ORDER BY b.timecreated DESC';
        $records = $DB->get_records_sql($sql, [], 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Delete runs older than $days days, keeping all baselines.
     *
     * @param int $days
     * @return int Number of runs deleted.
     */
    public function purge_old_runs(int $days = 365): int {
        global $DB;
        $cutoff = time() - ($days * 86400);
        $sql = 'SELECT id FROM {bx_agent_bm_runs}
                 WHERE timecreated < :cutoff AND is_baseline = 0';
        $ids = $DB->get_fieldset_sql($sql, ['cutoff' => $cutoff]);
        if (empty($ids)) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $DB->delete_records_select('bx_agent_bm_scenarios', "run_id {$insql}", $params);
        $DB->delete_records_select('bx_agent_bm_metrics', "run_id {$insql}", $params);
        $DB->delete_records_select('bx_agent_bm_runs', "id {$insql}", $params);
        return count($ids);
    }

    /**
     * Generate a UUID v4.
     */
    private function generate_uuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

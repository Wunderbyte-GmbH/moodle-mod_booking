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
 * Service collecting the booking cache report metrics.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\cachereport;

use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\sqlfilter_relevance;
use stdClass;

/**
 * Service collecting the booking cache report metrics.
 *
 * Design rules: strictly read-only towards the caches (cache keys are computed,
 * never populated or purged), aggregates only (no user lists), bounded cost
 * (sample size capped). The methods return plain arrays so both the admin
 * report page and the snapshot task can consume them.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cachereport_service {
    /** @var int default number of sampled users */
    public const DEFAULTSAMPLESIZE = 100;

    /** @var int hard cap for the number of sampled users */
    public const MAXSAMPLESIZE = 500;

    /** @var int number of snapshot rows kept by the retention */
    public const SNAPSHOTRETENTION = 400;

    /** @var float key sharing ratio below which everything is fine */
    public const KEYSHARINGWARNRATIO = 0.2;

    /** @var float key sharing ratio above which the state is critical */
    public const KEYSHARINGCRITICALRATIO = 0.7;

    /** @var int minimum sample size for a meaningful key sharing verdict */
    public const KEYSHARINGMINSAMPLE = 20;

    /** @var int mean cache entry size in bytes above which we warn */
    public const ENTRYSIZEWARNBYTES = 512 * 1024;

    /** @var int mean cache entry size in bytes above which the state is critical */
    public const ENTRYSIZECRITICALBYTES = 2 * 1024 * 1024;

    /** @var int total cache size in bytes above which we warn */
    public const TOTALSIZEWARNBYTES = 256 * 1024 * 1024;

    /** @var int total cache size in bytes above which the state is critical */
    public const TOTALSIZECRITICALBYTES = 1024 * 1024 * 1024;

    /** @var int filtered query time in ms above which we warn */
    public const QUERYTIMEWARNMS = 200;

    /** @var int filtered query time in ms above which the state is critical */
    public const QUERYTIMECRITICALMS = 1000;

    /** @var int pending purge tasks above which we warn */
    public const PENDINGTASKSWARN = 3;

    /** @var array the cache definitions relevant for booking option tables */
    public const DEFINITIONS = [
        ['mod_booking', 'bookingoptionstable'],
        ['mod_booking', 'sqlfilterrelevance'],
        ['local_wunderbyte_table', 'encodedtables'],
        ['local_wunderbyte_table', 'cachedrawdata'],
        ['local_wunderbyte_table', 'cachedfilters'],
    ];

    /**
     * Configuration overview: setting state, option counts per marker value,
     * relevance data and rollover/task health.
     *
     * @return array
     */
    public function configuration(): array {
        global $DB;

        $active = (bool) get_config('booking', 'usesqlfilteravailability');

        $markercounts = [];
        $records = $DB->get_records_sql(
            "SELECT sqlfilter, COUNT(*) AS cnt
               FROM {booking_options}
              WHERE sqlfilter > 0
           GROUP BY sqlfilter"
        );
        foreach ($records as $record) {
            $markercounts[(int) $record->sqlfilter] = (int) $record->cnt;
        }

        $overview = sqlfilter_relevance::report_overview();
        $conditionnames = [];
        foreach (bo_info::get_available_conditions(MOD_BOOKING_CONDPARAM_MFORM_ONLY) as $class) {
            $condition = method_exists($class, 'instance') ? $class::instance() : new $class();
            if (in_array((int) $condition->id, $overview['usedids'], true)) {
                $name = method_exists($condition, 'get_name')
                    ? $condition->get_name()
                    : get_class($condition);
                $conditionnames[(int) $condition->id] = [
                    'name' => $name,
                    'referencedvalues' => count($overview['byconditionid'][(int) $condition->id] ?? []),
                ];
            }
        }

        return [
            'active' => $active,
            'markercounts' => $markercounts,
            'sqlfilteroptions' => array_sum($markercounts),
            'usedconditions' => $conditionnames,
            'markervalues' => $overview['markervalues'],
            'daybucket' => (int) get_config('booking', 'sqlfilterdaybucket'),
            'pendingpurgetasks' => count(
                \core\task\manager::get_adhoc_tasks('\mod_booking\task\purge_dated_table_caches')
            ),
        ];
    }

    /**
     * Compute the general-view stem cache key material for a sample of recently
     * active users and report how well the keys are shared: distinct stems close
     * to the sample size means one cache entry set per user (the problem state),
     * a small number means users share entries per visibility class.
     *
     * @param int $samplesize
     * @return array
     */
    public function stem_sample(int $samplesize = self::DEFAULTSAMPLESIZE): array {
        global $DB, $CFG;

        $samplesize = max(1, min($samplesize, self::MAXSAMPLESIZE));

        if (!get_config('booking', 'usesqlfilteravailability')) {
            return ['active' => false];
        }

        $users = $DB->get_records_select(
            'user',
            'deleted = 0 AND suspended = 0 AND lastaccess > 0 AND id <> :guestid',
            ['guestid' => $CFG->siteguest],
            'lastaccess DESC',
            'id',
            0,
            $samplesize
        );

        $stems = [];
        foreach ($users as $user) {
            $stems[] = crc32($this->stem_for_user((int) $user->id));
        }

        $classes = array_count_values($stems);
        rsort($classes);

        return [
            'active' => true,
            'samplesize' => count($stems),
            'distinctstems' => count($classes),
            'topclasses' => array_slice($classes, 0, 5),
        ];
    }

    /**
     * The general-view stem WHERE (plus encoded params) for one user - exactly
     * the user-varying material of the table cache keys. Read-only: nothing is
     * queried against booking_options and no cache entry is written.
     *
     * @param int $userid
     * @return string
     */
    public function stem_for_user(int $userid): string {
        if (!sqlfilter_relevance::any_sqlfilter_in_use()) {
            return '';
        }
        [, , , $params, $wherearray] = bo_info::conditions_sql_parts($userid);
        if (empty($wherearray)) {
            return '';
        }
        $where = implode(" AND ", $wherearray);
        // The exact wrapper of the general view (userid null - no bypass).
        $where = " (
                        sqlfilter < 1 OR  $where
                        )
                        ";
        return $where . json_encode($params);
    }

    /**
     * Time the options COUNT query of the largest booking instance once with and
     * once without the availability filter WHERE. Two queries, no cache writes.
     *
     * @return array
     */
    public function query_timing(): array {
        global $DB, $USER;

        $largest = $DB->get_records_sql(
            "SELECT bookingid, COUNT(*) AS cnt
               FROM {booking_options}
           GROUP BY bookingid
           ORDER BY COUNT(*) DESC",
            [],
            0,
            1
        );
        $largest = reset($largest);
        if (empty($largest)) {
            return ['available' => false];
        }

        $start = microtime(true);
        $plaincount = $DB->count_records('booking_options', ['bookingid' => $largest->bookingid]);
        $plainms = (int) round((microtime(true) - $start) * 1000);

        $filteredms = null;
        $filteredcount = null;
        if (get_config('booking', 'usesqlfilteravailability')) {
            [, , , $params, $wherearray] = bo_info::conditions_sql_parts((int) $USER->id);
            if (!empty($wherearray)) {
                $where = implode(" AND ", $wherearray);
                $params['cachereportbookingid'] = $largest->bookingid;
                $start = microtime(true);
                $filteredcount = $DB->count_records_sql(
                    "SELECT COUNT(*)
                       FROM {booking_options}
                      WHERE bookingid = :cachereportbookingid
                            AND (sqlfilter < 1 OR $where)",
                    $params
                );
                $filteredms = (int) round((microtime(true) - $start) * 1000);
            }
        }

        return [
            'available' => true,
            'bookingid' => (int) $largest->bookingid,
            'optioncount' => (int) $largest->cnt,
            'plainms' => $plainms,
            'plaincount' => (int) $plaincount,
            'filteredms' => $filteredms,
            'filteredcount' => $filteredcount,
        ];
    }

    /**
     * Store usage (entry counts and sizes) for the booking relevant cache
     * definitions, through the core cache usage API. Best effort: stores that
     * cannot report usage show up without numbers.
     *
     * @return array
     */
    public function store_usage(): array {
        $result = [];
        try {
            $helper = \core_cache\factory::instance()->get_administration_display_helper();
            $usage = $helper->get_usage(3);
        } catch (\Throwable $e) {
            return $result;
        }

        foreach (self::DEFINITIONS as [$component, $area]) {
            $definitionid = $component . '/' . $area;
            if (!isset($usage[$definitionid])) {
                continue;
            }
            foreach ($usage[$definitionid]->stores as $store) {
                $supported = !empty($store->supported);
                $items = $supported ? (int) ($store->items ?? 0) : null;
                $mean = $supported ? (float) ($store->mean ?? 0) : null;
                $result[] = [
                    'definition' => $definitionid,
                    'store' => $store->name ?? '',
                    'supported' => $supported,
                    'items' => $items,
                    'meansize' => $mean,
                    'totalsize' => ($items !== null && $mean !== null) ? (int) round($items * $mean) : null,
                ];
            }
        }
        return $result;
    }

    /**
     * Aggregation of the wunderbyte table filter cache log, when that feature is
     * enabled on the site.
     *
     * @return array|null null when the log is not enabled or not available
     */
    public function logfilter_stats(): ?array {
        global $DB;

        if (!get_config('local_wunderbyte_table', 'logfiltercaches')) {
            return null;
        }
        $manager = $DB->get_manager();
        if (!$manager->table_exists('local_wunderbyte_table')) {
            return null;
        }
        $record = $DB->get_record_sql(
            "SELECT COUNT(*) AS keycount,
                    COALESCE(SUM(" . $DB->sql_compare_text('count') . "), 0) AS hitsum,
                    MIN(timemodified) AS oldest,
                    MAX(timemodified) AS newest
               FROM {local_wunderbyte_table}"
        );
        return [
            'keycount' => (int) $record->keycount,
            'hitsum' => (int) $record->hitsum,
            'oldest' => (int) $record->oldest,
            'newest' => (int) $record->newest,
        ];
    }

    /**
     * Traffic light evaluation of the collected metrics with action
     * recommendations. Pure function over the section results, so the
     * thresholds are unit testable.
     *
     * Each finding: ['code' => string, 'status' => 'ok'|'warning'|'critical',
     * 'message' => string, 'action' => string|null].
     *
     * @param array $configuration result of configuration()
     * @param array $sample result of stem_sample()
     * @param array $timing result of query_timing()
     * @param array $storeusage result of store_usage()
     * @return array
     */
    public function evaluate(array $configuration, array $sample, array $timing, array $storeusage): array {
        $findings = [];

        // Key sharing across users.
        if (!empty($sample['active']) && ($sample['samplesize'] ?? 0) >= self::KEYSHARINGMINSAMPLE) {
            $ratio = $sample['distinctstems'] / $sample['samplesize'];
            $a = $sample['distinctstems'] . '/' . $sample['samplesize'];
            if ($ratio > self::KEYSHARINGCRITICALRATIO) {
                $findings[] = [
                    'code' => 'keysharing',
                    'status' => 'critical',
                    'message' => get_string('cachereportfindingkeysharingcritical', 'mod_booking', $a),
                    'action' => get_string('cachereportactioncontactwb', 'mod_booking'),
                ];
            } else if ($ratio > self::KEYSHARINGWARNRATIO) {
                $findings[] = [
                    'code' => 'keysharing',
                    'status' => 'warning',
                    'message' => get_string('cachereportfindingkeysharingwarning', 'mod_booking', $a),
                    'action' => get_string('cachereportactioncontactwb', 'mod_booking'),
                ];
            } else {
                $findings[] = [
                    'code' => 'keysharing',
                    'status' => 'ok',
                    'message' => get_string('cachereportfindingkeysharingok', 'mod_booking', $a),
                    'action' => null,
                ];
            }
        }

        // Entry sizes and total size of the affected cache definitions.
        $maxmean = 0;
        $totalsize = 0;
        foreach ($storeusage as $row) {
            if (empty($row['supported'])) {
                continue;
            }
            $maxmean = max($maxmean, (int) round($row['meansize'] ?? 0));
            $totalsize += (int) ($row['totalsize'] ?? 0);
        }
        if ($maxmean > 0) {
            if ($maxmean >= self::ENTRYSIZECRITICALBYTES) {
                $status = 'critical';
            } else if ($maxmean >= self::ENTRYSIZEWARNBYTES) {
                $status = 'warning';
            } else {
                $status = 'ok';
            }
            $findings[] = [
                'code' => 'entrysize',
                'status' => $status,
                'message' => get_string(
                    'cachereportfindingentrysize' . $status,
                    'mod_booking',
                    display_size($maxmean)
                ),
                'action' => $status === 'ok' ? null : get_string('cachereportactioncontactwb', 'mod_booking'),
            ];
        }
        if ($totalsize > 0) {
            if ($totalsize >= self::TOTALSIZECRITICALBYTES) {
                $status = 'critical';
            } else if ($totalsize >= self::TOTALSIZEWARNBYTES) {
                $status = 'warning';
            } else {
                $status = 'ok';
            }
            $findings[] = [
                'code' => 'totalsize',
                'status' => $status,
                'message' => get_string(
                    'cachereportfindingtotalsize' . $status,
                    'mod_booking',
                    display_size($totalsize)
                ),
                'action' => $status === 'ok' ? null : get_string('cachereportactionredis', 'mod_booking'),
            ];
        }

        // Query time of the filtered options query.
        if (!empty($timing['available']) && $timing['filteredms'] !== null) {
            if ($timing['filteredms'] >= self::QUERYTIMECRITICALMS) {
                $status = 'critical';
            } else if ($timing['filteredms'] >= self::QUERYTIMEWARNMS) {
                $status = 'warning';
            } else {
                $status = 'ok';
            }
            $findings[] = [
                'code' => 'querytime',
                'status' => $status,
                'message' => get_string(
                    'cachereportfindingquerytime' . $status,
                    'mod_booking',
                    $timing['filteredms']
                ),
                'action' => $status === 'ok' ? null : get_string('cachereportactioncontactwb', 'mod_booking'),
            ];
        }

        // Health of the dated cache rollover (only relevant when the booking
        // time filter marker is in use).
        if (!empty($configuration['markercounts'][MOD_BOOKING_SQL_FILTER_ACTIVE_BO_TIME])) {
            $stalebucket = !empty($configuration['daybucket'])
                && $configuration['daybucket'] < strtotime('yesterday 00:00');
            $taskpileup = $configuration['pendingpurgetasks'] > self::PENDINGTASKSWARN;
            if ($stalebucket || $taskpileup) {
                $a = (object) [
                    'bucket' => $configuration['daybucket'] ? userdate($configuration['daybucket']) : '-',
                    'tasks' => $configuration['pendingpurgetasks'],
                ];
                $findings[] = [
                    'code' => 'rollover',
                    'status' => 'warning',
                    'message' => get_string('cachereportfindingrolloverwarning', 'mod_booking', $a),
                    'action' => get_string('cachereportactioncheckcron', 'mod_booking'),
                ];
            } else {
                $findings[] = [
                    'code' => 'rollover',
                    'status' => 'ok',
                    'message' => get_string('cachereportfindingrolloverok', 'mod_booking'),
                    'action' => null,
                ];
            }
        }

        return $findings;
    }

    /**
     * Build one snapshot record combining all sections (used by the scheduled
     * task and available to the report page).
     *
     * @param int $samplesize
     * @return stdClass the snapshot record, not yet persisted
     */
    public function build_snapshot(int $samplesize = self::DEFAULTSAMPLESIZE): stdClass {
        $configuration = $this->configuration();
        $sample = $this->stem_sample($samplesize);
        $timing = $this->query_timing();
        $usage = $this->store_usage();

        $snapshot = new stdClass();
        $snapshot->timecreated = time();
        $snapshot->samplesize = (int) ($sample['samplesize'] ?? 0);
        $snapshot->distinctstems = (int) ($sample['distinctstems'] ?? 0);
        $snapshot->sqlfilteroptions = (int) $configuration['sqlfilteroptions'];
        $snapshot->querytimefiltered = $timing['filteredms'] ?? null;
        $snapshot->querytimeplain = $timing['plainms'] ?? null;
        $snapshot->extrasjson = json_encode([
            'active' => $configuration['active'],
            'markercounts' => $configuration['markercounts'],
            'topclasses' => $sample['topclasses'] ?? [],
            'storeusage' => $usage,
            'logfilter' => $this->logfilter_stats(),
        ]);

        return $snapshot;
    }

    /**
     * Persist a snapshot and apply the retention.
     *
     * @param int $samplesize
     * @return int id of the inserted snapshot
     */
    public function create_snapshot(int $samplesize = self::DEFAULTSAMPLESIZE): int {
        global $DB;

        $snapshot = $this->build_snapshot($samplesize);
        $id = $DB->insert_record('booking_cachereport_snapshots', $snapshot);

        // Retention: keep only the newest rows.
        $keep = $DB->get_records(
            'booking_cachereport_snapshots',
            null,
            'timecreated DESC, id DESC',
            'id',
            0,
            self::SNAPSHOTRETENTION
        );
        if (count($keep) >= self::SNAPSHOTRETENTION) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($keep), SQL_PARAMS_NAMED, 'keep', false);
            $DB->delete_records_select('booking_cachereport_snapshots', "id $insql", $inparams);
        }

        return $id;
    }
}

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

namespace bookingextension_agent\local\wizard\services\reportbuilder;

use context_system;
use core\di;
use core\clock;
use core_component;
use core_reportbuilder\local\helpers\audience as audience_helper;
use core_reportbuilder\local\helpers\report as report_helper;
use core_reportbuilder\local\helpers\schedule as schedule_helper;
use core_reportbuilder\local\models\audience as audience_model;
use core_reportbuilder\local\models\report;
use core_reportbuilder\local\models\schedule as schedule_model;
use core_reportbuilder\local\schedules\base as schedule_base;
use core_reportbuilder\task\send_schedule;
use core_text;

/**
 * Report schedules: types, option enums, building a schedule record from structured input, and a
 * deterministic delivery diagnosis.
 *
 * Types come from core's component discovery (`<component>\reportbuilder\schedule\*`, pluggable
 * since 5.1); formats from the enabled dataformat plugins; recurrence, view-as and empty-report
 * policies from the constants of core's classes, exposed under stable keys. Dates are ISO 8601 or
 * Unix time only. The diagnosis answers "why does this person (not) get the report" with facts.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_service {
    /** Problem kinds. */
    public const PROBLEM_TYPE = 'type';
    /** Problem kinds. */
    public const PROBLEM_AUDIENCE = 'audience';
    /** Problem kinds. */
    public const PROBLEM_NO_AUDIENCE = 'no_audience';
    /** Problem kinds. */
    public const PROBLEM_FORMAT = 'format';
    /** Problem kinds. */
    public const PROBLEM_RECURRENCE = 'recurrence';
    /** Problem kinds. */
    public const PROBLEM_START = 'start';
    /** Problem kinds. */
    public const PROBLEM_VIEWAS = 'viewas';
    /** Problem kinds. */
    public const PROBLEM_IF_EMPTY = 'if_empty';
    /** Problem kinds. */
    public const PROBLEM_SCHEDULE = 'schedule';

    /** View-as keys. */
    public const VIEWAS = ['creator' => schedule_model::REPORT_VIEWAS_CREATOR,
        'recipient' => schedule_model::REPORT_VIEWAS_RECIPIENT, 'user' => schedule_model::REPORT_VIEWAS_USER];

    /**
     * Registered schedule types the acting user may add.
     *
     * @return array[] {classname, type, name, requires_audience}
     */
    public function types(): array {
        $types = [];
        foreach (core_component::get_component_classes_in_namespace(null, 'reportbuilder\\schedule') as $class => $path) {
            $class = ltrim((string)$class, '\\');
            if (!schedule_helper::valid($class)) {
                continue;
            }
            try {
                $instance = $class::instance();
                if ($instance === null || !$instance->user_can_add()) {
                    continue;
                }
                $types[] = [
                    'classname' => $class,
                    'type' => $this->short_class($class),
                    'name' => (string)$instance->get_name(),
                    'requires_audience' => (bool)$instance->requires_audience(),
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }
        usort($types, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $types;
    }

    /**
     * Enabled export formats: plugin name => label.
     *
     * @return array<string, string>
     */
    public function formats(): array {
        return schedule_helper::get_format_options();
    }

    /**
     * Resolve a format reference: the dataformat plugin name, its label, or its file extension
     * (each taken from the plugin itself, e.g. "excel" / "Microsoft Excel (.xlsx)" / "xlsx").
     *
     * @param string $reference
     * @return string|null plugin name
     */
    public function resolve_format(string $reference): ?string {
        $formats = $this->formats();
        $key = $this->match_key($reference, $formats);
        if ($key !== null) {
            return $key;
        }
        $lower = core_text::strtolower(ltrim(trim($reference), '.'));
        foreach (array_keys($formats) as $name) {
            try {
                $extension = ltrim((string)\core\dataformat::get_format_instance((string)$name)->get_extension(), '.');
            } catch (\Throwable $e) {
                continue;
            }
            if ($extension !== '' && core_text::strtolower($extension) === $lower) {
                return (string)$name;
            }
        }
        return null;
    }

    /**
     * Recurrence keys => constant values, from the model's constants (RECURRENCE_*).
     *
     * @return array<string, int>
     */
    public function recurrences(): array {
        $map = [];
        foreach ((new \ReflectionClass(schedule_model::class))->getConstants() as $name => $value) {
            if (is_int($value) && str_starts_with((string)$name, 'RECURRENCE_')) {
                $map[strtolower((string)substr((string)$name, strlen('RECURRENCE_')))] = $value;
            }
        }
        return $map;
    }

    /**
     * Empty-report policies of a schedule type: key => value, from its REPORT_EMPTY_* constants.
     *
     * @param string $classname
     * @return array<string, int>
     */
    public function empty_policies(string $classname): array {
        $map = [];
        if (!class_exists($classname)) {
            return $map;
        }
        foreach ((new \ReflectionClass($classname))->getConstants() as $name => $value) {
            if (is_int($value) && str_starts_with((string)$name, 'REPORT_EMPTY_')) {
                $map[strtolower((string)substr((string)$name, strlen('REPORT_EMPTY_')))] = $value;
            }
        }
        return $map;
    }

    /**
     * Build the record for a new schedule (or the changes for an existing one) from structured input.
     *
     * @param report $report
     * @param array $input {schedule_type?, name?, audiences?, format?, recurrence?, starttime?, viewas?,
     *                     viewas_userid?, subject?, message?, if_empty?, enabled?}
     * @param int $userid acting user
     * @param schedule_model|null $existing when updating
     * @return array{record: array, problem: array|null, summary: array}
     */
    public function build(report $report, array $input, int $userid, ?schedule_model $existing = null): array {
        $reportid = (int)$report->get('id');
        $record = [];
        $summary = [];

        // Type: the named one, the existing one, or the only one registered.
        $types = $this->types();
        $classname = $existing !== null ? (string)$existing->get('classname') : '';
        $typeref = trim((string)($input['schedule_type'] ?? ''));
        if ($typeref !== '') {
            $classname = '';
            foreach ($types as $type) {
                if (
                    $type['classname'] === ltrim($typeref, '\\')
                    || core_text::strtolower($type['type']) === core_text::strtolower($typeref)
                    || core_text::strtolower($type['name']) === core_text::strtolower($typeref)
                ) {
                    $classname = $type['classname'];
                }
            }
            if ($classname === '') {
                return $this->problem(
                    self::PROBLEM_TYPE,
                    $typeref,
                    array_map(static fn(array $t): array => ['id' => $t['type'], 'label' => $t['name']], $types)
                );
            }
        }
        if ($classname === '' && count($types) === 1) {
            $classname = $types[0]['classname'];
        }
        if ($classname === '') {
            return $this->problem(
                self::PROBLEM_TYPE,
                '',
                array_map(static fn(array $t): array => ['id' => $t['type'], 'label' => $t['name']], $types)
            );
        }
        $record['classname'] = $classname;
        $instance = $classname::instance();
        $summary['type'] = (string)$instance->get_name();

        // Audiences: ids of the report's audiences, or all of them.
        $reportaudiences = audience_model::get_records(['reportid' => $reportid], 'id');
        $available = [];
        foreach ($reportaudiences as $model) {
            $available[(int)$model->get('id')] = $model;
        }
        $requested = $input['audiences'] ?? null;
        if ($existing === null || $requested !== null) {
            if ($instance->requires_audience() && empty($available)) {
                return $this->problem(self::PROBLEM_NO_AUDIENCE, '', []);
            }
            $ids = [];
            if ($requested === null || $requested === 'all' || $requested === ['all'] || $requested === []) {
                $ids = array_keys($available);
            } else {
                foreach ((array)$requested as $ref) {
                    if (is_numeric($ref) && isset($available[(int)$ref])) {
                        $ids[] = (int)$ref;
                        continue;
                    }
                    return $this->problem(
                        self::PROBLEM_AUDIENCE,
                        (string)(is_scalar($ref) ? $ref : ''),
                        $this->audience_options($available)
                    );
                }
            }
            if ($instance->requires_audience() && empty($ids)) {
                return $this->problem(self::PROBLEM_NO_AUDIENCE, '', []);
            }
            $record['audiences'] = json_encode(array_values(array_unique($ids)));
            $summary['audiences'] = count($ids);
        }

        // Name.
        $name = trim((string)($input['name'] ?? ''));
        if ($name !== '') {
            $record['name'] = $name;
        } else if ($existing === null) {
            $record['name'] = (string)$report->get('name');
        }

        // Format.
        $formats = $this->formats();
        $format = trim((string)($input['format'] ?? ''));
        if ($format !== '') {
            $key = $this->resolve_format($format);
            if ($key === null) {
                return $this->problem(self::PROBLEM_FORMAT, $format, $this->options_from_map($formats));
            }
            $record['format'] = $key;
        } else if ($existing === null) {
            $record['format'] = array_key_exists('csv', $formats) ? 'csv' : (string)array_key_first($formats);
        }
        $summary['format'] = $formats[$record['format'] ?? ($existing ? $existing->get('format') : '')] ?? '';

        // Recurrence.
        $recurrences = $this->recurrences();
        $recurrence = trim((string)($input['recurrence'] ?? ''));
        if ($recurrence !== '') {
            $lower = strtolower($recurrence);
            if (array_key_exists($lower, $recurrences)) {
                $record['recurrence'] = $recurrences[$lower];
            } else if (is_numeric($recurrence) && in_array((int)$recurrence, $recurrences, true)) {
                $record['recurrence'] = (int)$recurrence;
            } else {
                return $this->problem(
                    self::PROBLEM_RECURRENCE,
                    $recurrence,
                    array_map(static fn(string $k): array => ['id' => $k, 'label' => $k], array_keys($recurrences))
                );
            }
        } else if ($existing === null) {
            $record['recurrence'] = schedule_model::RECURRENCE_NONE;
        }
        $recurrencevalue = $record['recurrence'] ?? ($existing ? (int)$existing->get('recurrence') : 0);
        $summary['recurrence'] = (string)(schedule_helper::get_recurrence_options()[$recurrencevalue] ?? '');

        // Start time.
        $now = di::get(clock::class)->time();
        $start = $input['starttime'] ?? null;
        if ($start !== null && $start !== '') {
            $timestamp = filter_value_codec::timestamp($start, $userid);
            if ($timestamp === null) {
                return $this->problem(self::PROBLEM_START, (string)(is_scalar($start) ? $start : ''), []);
            }
            if ($timestamp < $now - 60) {
                return $this->problem(self::PROBLEM_START, (string)(is_scalar($start) ? $start : ''), [], 'past');
            }
            $record['timescheduled'] = $timestamp;
        } else if ($existing === null) {
            $record['timescheduled'] = usergetmidnight($now + DAYSECS);
        }
        $summary['starttime'] = userdate($record['timescheduled'] ?? ($existing ? (int)$existing->get('timescheduled') : $now));

        // View as.
        $viewas = strtolower(trim((string)($input['viewas'] ?? '')));
        if ($viewas !== '') {
            if (!array_key_exists($viewas, self::VIEWAS)) {
                return $this->problem(
                    self::PROBLEM_VIEWAS,
                    $viewas,
                    array_map(static fn(string $k): array => ['id' => $k, 'label' => $k], array_keys(self::VIEWAS))
                );
            }
            if ($viewas === 'user') {
                $viewasuser = (int)($input['viewas_userid'] ?? 0);
                if ($viewasuser <= 0) {
                    return $this->problem(self::PROBLEM_VIEWAS, $viewas, [], 'user_required');
                }
                if (!has_capability('moodle/reportbuilder:scheduleviewas', context_system::instance(), $userid)) {
                    return $this->problem(self::PROBLEM_VIEWAS, $viewas, [], 'capability');
                }
                $record['userviewas'] = $viewasuser;
            } else {
                if (
                    $viewas === 'recipient'
                    && !has_capability('moodle/reportbuilder:scheduleviewas', context_system::instance(), $userid)
                ) {
                    return $this->problem(self::PROBLEM_VIEWAS, $viewas, [], 'capability');
                }
                $record['userviewas'] = self::VIEWAS[$viewas];
            }
        } else if ($existing === null) {
            $record['userviewas'] = schedule_model::REPORT_VIEWAS_CREATOR;
        }
        $summary['viewas'] = $viewas !== ''
            ? $viewas
            : ($existing ? $this->viewas_key((int)$existing->get('userviewas')) : 'creator');

        // Type configuration: subject, message, empty-report policy (keys of the message type; other
        // types take what they declare through their REPORT_EMPTY_* constants, if any).
        $config = $existing !== null ? (array)json_decode((string)$existing->get('configdata'), true) : [];
        $subject = trim((string)($input['subject'] ?? ''));
        if ($subject !== '') {
            $config['subject'] = core_text::substr($subject, 0, 255);
        } else if ($existing === null) {
            $config['subject'] = (string)$report->get('name');
        }
        $message = trim((string)($input['message'] ?? ''));
        if ($message !== '') {
            $config['message'] = ['text' => $message, 'format' => FORMAT_PLAIN];
        } else if ($existing === null) {
            $config['message'] = ['text' => (string)$report->get('name'), 'format' => FORMAT_PLAIN];
        }
        $policies = $this->empty_policies($classname);
        $ifempty = strtolower(trim((string)($input['if_empty'] ?? '')));
        if ($ifempty !== '' && !empty($policies)) {
            if (!array_key_exists($ifempty, $policies)) {
                return $this->problem(
                    self::PROBLEM_IF_EMPTY,
                    $ifempty,
                    array_map(static fn(string $k): array => ['id' => $k, 'label' => $k], array_keys($policies))
                );
            }
            $config['reportempty'] = $policies[$ifempty];
        }
        $record['configdata'] = json_encode($config);
        $summary['if_empty'] = array_search((int)($config['reportempty'] ?? 0), $policies, true) ?: '';

        if (array_key_exists('enabled', $input) && $input['enabled'] !== null) {
            $record['enabled'] = (int)!empty($input['enabled']);
        }

        return ['record' => $record, 'problem' => null, 'summary' => $summary];
    }

    /**
     * Create the schedule.
     *
     * @param int $reportid
     * @param array $record from build()
     * @return schedule_model
     */
    public function create(int $reportid, array $record): schedule_model {
        $record['reportid'] = $reportid;
        $record['enabled'] = array_key_exists('enabled', $record) ? (int)$record['enabled'] : 1;
        $classname = (string)$record['classname'];
        return $classname::create((object)$record)->get_persistent();
    }

    /**
     * Update an existing schedule.
     *
     * @param int $reportid
     * @param int $scheduleid
     * @param array $record from build()
     * @return schedule_model
     */
    public function update(int $reportid, int $scheduleid, array $record): schedule_model {
        unset($record['classname']);
        $record['id'] = $scheduleid;
        $record['reportid'] = $reportid;
        return schedule_helper::update_schedule((object)$record);
    }

    /**
     * Enable or disable.
     *
     * @param int $reportid
     * @param int $scheduleid
     * @param bool $enabled
     * @return bool
     */
    public function toggle(int $reportid, int $scheduleid, bool $enabled): bool {
        return schedule_helper::toggle_schedule($reportid, $scheduleid, $enabled);
    }

    /**
     * Queue the schedule for immediate sending (same adhoc task as core's "send now").
     *
     * @param int $reportid
     * @param int $scheduleid
     * @return bool
     */
    public function send_now(int $reportid, int $scheduleid): bool {
        $task = new send_schedule();
        $task->set_custom_data(['reportid' => $reportid, 'scheduleid' => $scheduleid]);
        return (bool)\core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Deterministic delivery facts for one person and one schedule.
     *
     * @param schedule_model $schedule
     * @param int $userid the person asked about
     * @return array
     */
    public function diagnose(schedule_model $schedule, int $userid): array {
        global $DB;
        $audienceservice = new audience_service();
        $reportid = (int)$schedule->get('reportid');
        $scheduleaudienceids = array_map('intval', (array)json_decode((string)$schedule->get('audiences'), true));
        $scheduleaudiences = [];
        $reportaudiences = [];
        foreach (audience_model::get_records(['reportid' => $reportid]) as $model) {
            $reportaudiences[] = $model;
            if (in_array((int)$model->get('id'), $scheduleaudienceids, true)) {
                $scheduleaudiences[] = $model;
            }
        }
        $user = $DB->get_record('user', ['id' => $userid], 'id, deleted, suspended, email, emailstop, confirmed');
        $classname = (string)$schedule->get('classname');
        $policies = $this->empty_policies($classname);
        $config = (array)json_decode((string)$schedule->get('configdata'), true);
        $rowcount = null;
        try {
            $rowcount = report_helper::get_report_row_count($reportid);
        } catch (\Throwable $e) {
            $rowcount = null;
        }
        return [
            'scheduleid' => (int)$schedule->get('id'),
            'schedule_enabled' => (bool)$schedule->get('enabled'),
            'schedule_audiences' => $scheduleaudienceids,
            'schedule_audiences_missing' => array_values(array_diff(
                $scheduleaudienceids,
                array_map(static fn(audience_model $m): int => (int)$m->get('id'), $scheduleaudiences)
            )),
            'timescheduled' => (int)$schedule->get('timescheduled'),
            'timenextsend' => (int)$schedule->get('timenextsend'),
            'timelastsent' => (int)$schedule->get('timelastsent'),
            'recurrence' => $this->recurrence_key((int)$schedule->get('recurrence')),
            'should_send_now' => schedule_helper::should_send_schedule($schedule),
            'user_exists' => (bool)$user && empty($user->deleted),
            'user_suspended' => (bool)($user->suspended ?? false),
            'user_confirmed' => (bool)($user->confirmed ?? false),
            'user_has_email' => trim((string)($user->email ?? '')) !== '',
            'user_emailstop' => (bool)($user->emailstop ?? false),
            'user_in_schedule_audiences' => $audienceservice->covers_user($scheduleaudiences, $userid),
            'user_in_report_audiences' => $audienceservice->covers_user($reportaudiences, $userid),
            'viewas' => $this->viewas_key((int)$schedule->get('userviewas')),
            'if_empty' => array_search((int)($config['reportempty'] ?? 0), $policies, true) ?: '',
            'report_rowcount' => $rowcount,
            'would_skip_empty' => $rowcount === 0 && isset($config['reportempty'])
                && (int)$config['reportempty'] === ($policies['dont_send'] ?? -1),
        ];
    }

    /**
     * The key of a stored view-as value.
     *
     * @param int $value
     * @return string
     */
    public function viewas_key(int $value): string {
        foreach (self::VIEWAS as $key => $constant) {
            if ($constant === $value) {
                return $key;
            }
        }
        return $value > 0 ? 'user' : 'creator';
    }

    /**
     * The key of a stored recurrence value.
     *
     * @param int $value
     * @return string
     */
    public function recurrence_key(int $value): string {
        $key = array_search($value, $this->recurrences(), true);
        return $key === false ? (string)$value : (string)$key;
    }

    /**
     * Clarification options for the report's audiences.
     *
     * @param audience_model[] $available keyed by id
     * @return array[]
     */
    public function audience_options(array $available): array {
        $options = [];
        foreach ($available as $id => $model) {
            $instance = \core_reportbuilder\local\audiences\base::instance((int)$id);
            $label = $instance !== null ? (string)$instance->get_name() : (string)$model->get('classname');
            $heading = (string)$model->get('heading');
            $options[] = ['id' => (string)(int)$id, 'label' => $label . ($heading !== '' ? ' (' . $heading . ')' : '')];
        }
        return $options;
    }

    /**
     * Match a reference against a key => label map (key exact, or label case-insensitive).
     *
     * @param string $reference
     * @param array $map
     * @return string|null
     */
    private function match_key(string $reference, array $map): ?string {
        $lower = core_text::strtolower($reference);
        foreach ($map as $key => $label) {
            if (core_text::strtolower((string)$key) === $lower || core_text::strtolower((string)$label) === $lower) {
                return (string)$key;
            }
        }
        return null;
    }

    /**
     * Options from a key => label map.
     *
     * @param array $map
     * @return array[]
     */
    private function options_from_map(array $map): array {
        $options = [];
        foreach ($map as $key => $label) {
            $options[] = ['id' => (string)$key, 'label' => (string)$label];
        }
        return $options;
    }

    /**
     * Problem result.
     *
     * @param string $kind
     * @param string $value
     * @param array $options
     * @param string $detail
     * @return array
     */
    private function problem(string $kind, string $value, array $options, string $detail = ''): array {
        return ['record' => [], 'problem' => ['kind' => $kind, 'value' => $value, 'options' => $options, 'detail' => $detail],
            'summary' => []];
    }

    /**
     * Short class name.
     *
     * @param string $class
     * @return string
     */
    private function short_class(string $class): string {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : (string)substr($class, $pos + 1);
    }
}

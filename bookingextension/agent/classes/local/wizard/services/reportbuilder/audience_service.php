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

use bookingextension_agent\local\wizard\services\requester_reference;
use bookingextension_agent\local\wizard\services\target_query_normalizer;
use context_system;
use core_component;
use core_reportbuilder\local\audiences\base as audience_base;
use core_reportbuilder\local\helpers\audience as audience_helper;
use core_reportbuilder\local\models\audience as audience_model;
use core_text;


/**
 * Report audiences: the registered audience types and how to build their configuration.
 *
 * Types come from core's component discovery (`<component>\reportbuilder\audience\*`), so a
 * plugin's audience appears when the plugin is installed. Configuration is built per type from
 * structured input: role short names or ids, cohort id numbers or ids, person references
 * resolved like the engine's user resolvers (id, address, single name match) — with candidates,
 * never a silent pick. Types this service does not know take a raw `config` object validated by
 * the audience class' own form validation.
 *
 * Person data stays out of the observation: the service reports how many users an audience
 * covers, never who.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class audience_service {
    /** Problem kinds. */
    public const PROBLEM_TYPE = 'type';
    /** Problem kinds. */
    public const PROBLEM_TYPE_NOT_ADDABLE = 'type_not_addable';
    /** Problem kinds. */
    public const PROBLEM_ROLE = 'role';
    /** Problem kinds. */
    public const PROBLEM_COHORT = 'cohort';
    /** Problem kinds. */
    public const PROBLEM_USER_NOT_FOUND = 'user_not_found';
    /** Problem kinds. */
    public const PROBLEM_USER_AMBIGUOUS = 'user_ambiguous';
    /** Problem kinds. */
    public const PROBLEM_CONFIG = 'config';

    /** Configuration key the manual audience stores its users under. */
    private const KEY_USERS = 'users';
    /** Configuration key the system-role audience stores its roles under. */
    private const KEY_ROLES = 'roles';
    /** Configuration key the cohort audience stores its cohorts under. */
    private const KEY_COHORTS = 'cohorts';

    /**
     * Registered and available audience types.
     *
     * @return array[] {classname, type (short class name), name, component, can_add}
     */
    public function types(): array {
        $types = [];
        foreach (core_component::get_component_classes_in_namespace(null, 'reportbuilder\\audience') as $class => $path) {
            $class = ltrim((string)$class, '\\');
            if (!class_exists($class) || !is_subclass_of($class, audience_base::class)) {
                continue;
            }
            try {
                $instance = $class::instance();
                if ($instance === null || !$instance->is_available()) {
                    continue;
                }
                $types[] = [
                    'classname' => $class,
                    'type' => $this->short_class($class),
                    'name' => (string)$instance->get_name(),
                    'component' => (string)(explode('\\', $class)[0] ?? ''),
                    'can_add' => (bool)$instance->user_can_add(),
                ];
            } catch (\Throwable $e) {
                continue;
            }
        }
        usort($types, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $types;
    }

    /**
     * Resolve a type reference: FQCN, short class name or exact localised name (case-insensitive).
     *
     * @param string $reference
     * @return array{classname: string|null, candidates: array[]}
     */
    public function resolve_type(string $reference): array {
        $types = $this->types();
        $needle = ltrim(trim($reference), '\\');
        if ($needle === '') {
            return ['classname' => null, 'candidates' => $types];
        }
        $lowered = core_text::strtolower($needle);
        foreach ($types as $type) {
            if ($type['classname'] === $needle) {
                return ['classname' => $type['classname'], 'candidates' => []];
            }
        }
        foreach ($types as $type) {
            if (core_text::strtolower($type['type']) === $lowered || core_text::strtolower($type['name']) === $lowered) {
                return ['classname' => $type['classname'], 'candidates' => []];
            }
        }
        return ['classname' => null, 'candidates' => $types];
    }

    /**
     * Clarification options for the type list.
     *
     * @param array[] $types
     * @return array[]
     */
    public function type_options(array $types): array {
        return array_map(static fn(array $t): array => ['id' => $t['type'], 'label' => $t['name']], $types);
    }

    /**
     * Build the configuration of one audience type from structured input.
     *
     * @param string $classname audience class
     * @param array $input {roles?: array, cohorts?: array, userqueries?: array, config?: array}
     * @param int $userid acting user (visibility of persons and cohorts)
     * @return array{configdata: array, problem: array|null, summary: array}
     *         summary: {count?: int, labels?: string[]} for the card (no person names)
     */
    public function build_config(string $classname, array $input, int $userid): array {
        $classname = ltrim($classname, '\\');
        $instance = class_exists($classname) ? $classname::instance() : null;
        if ($instance === null) {
            return ['configdata' => [], 'problem' => ['kind' => self::PROBLEM_TYPE, 'value' => $classname,
                'options' => $this->type_options($this->types())], 'summary' => []];
        }
        if (!$instance->user_can_add()) {
            return ['configdata' => [], 'problem' => ['kind' => self::PROBLEM_TYPE_NOT_ADDABLE,
                'value' => (string)$instance->get_name(), 'options' => []], 'summary' => []];
        }

        $config = is_array($input['config'] ?? null) ? $input['config'] : [];
        $keys = $this->config_keys($instance);

        if (in_array(self::KEY_ROLES, $keys, true)) {
            $roles = $this->resolve_roles((array)($input['roles'] ?? $config[self::KEY_ROLES] ?? []));
            if ($roles['problem'] !== null) {
                return ['configdata' => [], 'problem' => $roles['problem'], 'summary' => []];
            }
            $config[self::KEY_ROLES] = $roles['ids'];
            return ['configdata' => $config, 'problem' => null, 'summary' => ['labels' => $roles['names']]];
        }
        if (in_array(self::KEY_COHORTS, $keys, true)) {
            $cohorts = $this->resolve_cohorts((array)($input['cohorts'] ?? $config[self::KEY_COHORTS] ?? []), $userid);
            if ($cohorts['problem'] !== null) {
                return ['configdata' => [], 'problem' => $cohorts['problem'], 'summary' => []];
            }
            $config[self::KEY_COHORTS] = $cohorts['ids'];
            return ['configdata' => $config, 'problem' => null, 'summary' => ['labels' => $cohorts['names']]];
        }
        if (in_array(self::KEY_USERS, $keys, true)) {
            $users = $this->resolve_users((array)($input['userqueries'] ?? $config[self::KEY_USERS] ?? []), $userid);
            if ($users['problem'] !== null) {
                return ['configdata' => [], 'problem' => $users['problem'], 'summary' => []];
            }
            $config[self::KEY_USERS] = $users['ids'];
            return ['configdata' => $config, 'problem' => null, 'summary' => ['count' => count($users['ids'])]];
        }

        // A type without a known configuration key (all users, admins, a plugin type): take the raw
        // config and let the audience class validate it the way its form does.
        $errors = [];
        try {
            $errors = (array)$instance->validate_config_form($config);
        } catch (\Throwable $e) {
            $errors = [];
        }
        if (!empty($errors)) {
            return ['configdata' => [], 'problem' => ['kind' => self::PROBLEM_CONFIG,
                'value' => implode(' ', array_map('strval', $errors)), 'options' => []], 'summary' => []];
        }
        return ['configdata' => $config, 'problem' => null, 'summary' => []];
    }

    /**
     * Add an audience to a report.
     *
     * @param int $reportid
     * @param string $classname
     * @param array $configdata
     * @param string $heading
     * @return audience_base
     */
    public function add(int $reportid, string $classname, array $configdata, string $heading = ''): audience_base {
        $classname = ltrim($classname, '\\');
        $audience = $classname::create($reportid, $configdata);
        if ($heading !== '') {
            $audience->get_persistent()->set('heading', $heading)->update();
        }
        audience_helper::purge_caches();
        return $audience;
    }

    /**
     * Remove an audience from a report.
     *
     * @param int $reportid
     * @param int $audienceid
     * @return bool
     */
    public function remove(int $reportid, int $audienceid): bool {
        $result = audience_helper::delete_report_audience($reportid, $audienceid);
        audience_helper::purge_caches();
        return $result;
    }

    /**
     * Whether a user is covered by the given audiences (any of them).
     *
     * @param audience_model[] $models
     * @param int $userid
     * @return bool
     */
    public function covers_user(array $models, int $userid): bool {
        global $DB;
        if (empty($models)) {
            return false;
        }
        try {
            [$wheres, $params] = audience_helper::user_audience_sql($models);
            if (empty($wheres)) {
                return false;
            }
            $params['bxagentuid'] = $userid;
            $sql = 'SELECT u.id FROM {user} u WHERE (' . implode(' OR ', $wheres) . ') AND u.deleted = 0 AND u.id = :bxagentuid';
            return $DB->record_exists_sql($sql, $params);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Number of (non-deleted) users an audience currently covers.
     *
     * @param audience_model $model
     * @return int
     */
    public function count_users(audience_model $model): int {
        global $DB;
        try {
            [$wheres, $params] = audience_helper::user_audience_sql([$model]);
            if (empty($wheres)) {
                return 0;
            }
            $sql = 'SELECT COUNT(DISTINCT u.id) FROM {user} u WHERE (' . implode(' OR ', $wheres) . ') AND u.deleted = 0';
            return (int)$DB->count_records_sql($sql, $params);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The configuration keys an audience type reads, taken from its own form definition.
     *
     * @param audience_base $instance
     * @return string[]
     */
    private function config_keys(audience_base $instance): array {
        $mform = new \MoodleQuickForm('bx_agent_audience_probe', 'post', '');
        try {
            $instance->get_config_form($mform);
        } catch (\Throwable $e) {
            return [];
        }
        $keys = [];
        foreach ($mform->_elements as $element) {
            $name = method_exists($element, 'getName') ? (string)$element->getName() : '';
            if ($name !== '') {
                $keys[] = $name;
            }
        }
        return $keys;
    }

    /**
     * Roles by short name or id.
     *
     * @param array $references
     * @return array{ids: int[], names: string[], problem: array|null}
     */
    private function resolve_roles(array $references): array {
        $all = get_all_roles();
        $options = [];
        foreach ($all as $role) {
            $options[] = ['id' => (string)$role->shortname, 'label' => role_get_name($role, context_system::instance())];
        }
        $ids = [];
        $names = [];
        foreach ($references as $reference) {
            $reference = trim((string)$reference);
            $found = null;
            foreach ($all as $role) {
                if (
                    (ctype_digit($reference) && (int)$role->id === (int)$reference)
                    || core_text::strtolower((string)$role->shortname) === core_text::strtolower($reference)
                ) {
                    $found = $role;
                    break;
                }
            }
            if ($found === null) {
                return ['ids' => [], 'names' => [], 'problem' => ['kind' => self::PROBLEM_ROLE, 'value' => $reference,
                    'options' => $options]];
            }
            $ids[] = (int)$found->id;
            $names[] = role_get_name($found, context_system::instance());
        }
        if (empty($ids)) {
            return ['ids' => [], 'names' => [], 'problem' => ['kind' => self::PROBLEM_ROLE, 'value' => '',
                'options' => $options]];
        }
        return ['ids' => array_values(array_unique($ids)), 'names' => $names, 'problem' => null];
    }

    /**
     * Cohorts by id number, exact name or id, restricted to cohorts the user may view.
     *
     * @param array $references
     * @param int $userid
     * @return array{ids: int[], names: string[], problem: array|null}
     */
    private function resolve_cohorts(array $references, int $userid): array {
        global $DB;

        // Core's cohort_can_view_cohort() is written for a course context (it looks at the PARENT
        // contexts); an audience is configured site-wide, so the rule is applied per cohort context:
        // a visible cohort, or moodle/cohort:view where the cohort lives.
        $visible = [];
        foreach ($DB->get_records('cohort', [], 'name ASC') as $cohort) {
            $context = \context::instance_by_id((int)$cohort->contextid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            if (!empty($cohort->visible) || has_capability('moodle/cohort:view', $context, $userid)) {
                $visible[(int)$cohort->id] = $cohort;
            }
        }
        $options = [];
        foreach ($visible as $cohort) {
            $options[] = ['id' => $cohort->idnumber !== '' ? (string)$cohort->idnumber : (string)$cohort->id,
                'label' => format_string($cohort->name)];
        }
        $ids = [];
        $names = [];
        foreach ($references as $reference) {
            $reference = trim((string)$reference);
            $found = null;
            foreach ($visible as $cohort) {
                if (
                    (ctype_digit($reference) && (int)$cohort->id === (int)$reference)
                    || ($cohort->idnumber !== ''
                        && core_text::strtolower((string)$cohort->idnumber) === core_text::strtolower($reference))
                    || core_text::strtolower((string)$cohort->name) === core_text::strtolower($reference)
                ) {
                    $found = $cohort;
                    break;
                }
            }
            if ($found === null) {
                return ['ids' => [], 'names' => [], 'problem' => ['kind' => self::PROBLEM_COHORT, 'value' => $reference,
                    'options' => $options]];
            }
            $ids[] = (int)$found->id;
            $names[] = format_string($found->name);
        }
        if (empty($ids)) {
            return ['ids' => [], 'names' => [], 'problem' => ['kind' => self::PROBLEM_COHORT, 'value' => '',
                'options' => $options]];
        }
        return ['ids' => array_values(array_unique($ids)), 'names' => $names, 'problem' => null];
    }

    /**
     * Persons by id, address or a single name match among the users the actor may see.
     *
     * @param array $references
     * @param int $actorid
     * @return array{ids: int[], problem: array|null}
     */
    public function resolve_users(array $references, int $actorid): array {
        global $CFG;
        require_once($CFG->libdir . '/datalib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $canviewall = has_capability('moodle/user:viewalldetails', context_system::instance(), $actorid)
            || has_capability('moodle/user:viewdetails', context_system::instance(), $actorid);
        $ids = [];
        foreach ($references as $reference) {
            $reference = trim((string)$reference);
            if ($reference === '') {
                continue;
            }
            // The requester marker (#2569) names the actor.
            if (requester_reference::is_marker($reference)) {
                $reference = (string)$actorid;
            }
            $candidates = [];
            if (ctype_digit($reference)) {
                $user = \core_user::get_user((int)$reference, 'id, deleted', IGNORE_MISSING);
                if ($user && empty($user->deleted)) {
                    $candidates[] = $user;
                }
            } else {
                $address = target_query_normalizer::address_token($reference);
                if ($address !== '') {
                    $user = \core_user::get_user_by_email($address, 'id, deleted', null, IGNORE_MISSING);
                    if ($user && empty($user->deleted)) {
                        $candidates[] = $user;
                    }
                } else {
                    foreach ((array)search_users(0, 0, $reference, 'lastname ASC, firstname ASC, id ASC') as $user) {
                        $candidates[] = $user;
                    }
                }
            }
            $candidates = array_values(array_filter($candidates, function (\stdClass $user) use ($actorid, $canviewall): bool {
                if ((int)$user->id === $actorid || $canviewall) {
                    return true;
                }
                return !empty(enrol_get_shared_courses($actorid, (int)$user->id, false, false));
            }));
            if (count($candidates) === 0) {
                return ['ids' => [], 'problem' => ['kind' => self::PROBLEM_USER_NOT_FOUND, 'value' => $reference, 'options' => []]];
            }
            if (count($candidates) > 1) {
                $options = [];
                foreach (array_slice($candidates, 0, 10) as $user) {
                    // Candidates from search_users() carry partial records: load the name fields properly.
                    $full = \core_user::get_user((int)$user->id, '*', IGNORE_MISSING);
                    $options[] = ['id' => (string)(int)$user->id, 'label' => $full ? fullname($full) : (string)(int)$user->id];
                }
                return ['ids' => [], 'problem' => ['kind' => self::PROBLEM_USER_AMBIGUOUS, 'value' => $reference,
                    'options' => $options]];
            }
            $ids[] = (int)$candidates[0]->id;
        }
        if (empty($ids)) {
            return ['ids' => [], 'problem' => ['kind' => self::PROBLEM_USER_NOT_FOUND, 'value' => '', 'options' => []]];
        }
        return ['ids' => array_values(array_unique($ids)), 'problem' => null];
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

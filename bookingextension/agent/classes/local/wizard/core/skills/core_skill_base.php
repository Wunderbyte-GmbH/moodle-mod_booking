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

namespace bookingextension_agent\local\wizard\core\skills;

use context_course;
use context_system;
use bookingextension_agent\local\wizard\base_skill;
use bookingextension_agent\local\wizard\services\observation_time;
use bookingextension_agent\local\wizard\services\requester_reference;

/**
 * Shared helper base for core Moodle data skills.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class core_skill_base extends base_skill {
    /**
     * Resolve preferred output language from skill input.
     *
     * @param array $input
     * @return string
     */
    protected function get_output_language(array $input): string {
        foreach (['outputlang', 'user_lang', 'lang'] as $key) {
            $value = trim((string)($input[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Resolve a plugin-localised string in the requested language.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    protected function localized_string(string $identifier, $a = null, string $lang = ''): string {
        $lang = trim($lang);
        if ($lang === '') {
            return get_string($identifier, 'bookingextension_agent', $a);
        }

        return get_string_manager()->get_string($identifier, 'bookingextension_agent', $a, $lang);
    }

    /**
     * Build a compact deterministic debug message for skill results.
     *
     * @param string $skillname
     * @param array $input
     * @param array $additionallines
     * @return string
     */
    protected function build_skill_debug_message(string $skillname, array $input, array $additionallines = []): string {
        $lines = ['Skill: ' . $skillname];

        if (!empty($input)) {
            $debugpairs = [];
            foreach ($input as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                $debugpairs[] = $key . '=' . $this->stringify_debug_value($value);
            }
            if (!empty($debugpairs)) {
                $lines[] = 'Input: ' . implode(', ', $debugpairs);
            }
        }

        foreach ($additionallines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Add compact prompt metadata to a schema when the skill does not declare it explicitly.
     *
     * @param array $schema
     * @return array
     */
    protected function enrich_schema_with_prompt_meta(array $schema): array {
        if (!empty($schema['prompt_meta']) && is_array($schema['prompt_meta'])) {
            return $schema;
        }

        $properties = (array)($schema['properties'] ?? []);
        $requiredfields = [];
        foreach ($properties as $name => $spec) {
            if (!is_string($name) || !is_array($spec)) {
                continue;
            }
            if (!empty($spec['required'])) {
                $requiredfields[] = $name;
            }
        }

        $anchorfields = [];
        foreach (['query', 'question', 'optionquery', 'userquery', 'coursequery', 'groupquery'] as $field) {
            if (array_key_exists($field, $properties)) {
                $anchorfields[] = $field;
            }
        }

        $schema['prompt_meta'] = [
            'input_fields_for_prompt' => array_values($requiredfields),
            'anchor_fields' => array_values($anchorfields),
        ];

        return $schema;
    }

    /**
     * Convert an arbitrary value into a stable short debug string.
     *
     * @param mixed $value
     * @return string
     */
    private function stringify_debug_value($value): string {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : '[complex]';
    }

    /**
     * Resolve user id from optional userquery.
     *
     * @param array $input
     * @param int $currentuserid
     * @return int
     */
    protected function resolve_userid(array $input, int $currentuserid): int {
        $query = trim((string)($input['userquery'] ?? ''));
        // No person named means the requester; so does the requester marker the engine puts where the constructor
        // named the requester (#2569). Wave 26 / F81: the former "current" / "me" word check was a language-bound
        // detection.
        if ($query === '') {
            return $currentuserid;
        }

        if (ctype_digit($query)) {
            return (int)$query;
        }
        if (requester_reference::is_marker($query)) {
            global $USER;
            return $currentuserid > 0 ? $currentuserid : (int)($USER->id ?? 0);
        }

        // An address-shaped token is an address wherever it stands ("Herr <e-mail>", run 25; #2453).
        $address = \bookingextension_agent\local\wizard\services\target_query_normalizer::address_token($query);
        if ($address !== '') {
            $user = \core_user::get_user_by_email($address, 'id', null, IGNORE_MISSING);
            if ($user && !empty($user->id)) {
                return (int)$user->id;
            }
        }

        $matches = $this->search_user_candidates_for_preview($query, 2);
        if (count($matches) === 1) {
            return (int)($matches[0]['userid'] ?? 0);
        }
        if (empty($matches)) {
            // Wave 26: "Mr Okafor" as one string matches nobody; the tokens that do match must agree.
            $matches = \bookingextension_agent\local\wizard\services\target_query_normalizer::narrow_by_tokens(
                $query,
                fn(string $token, int $limit): array => $this->search_user_candidates_for_preview($token, $limit)
            );
            if (count($matches) === 1) {
                return (int)($matches[0]['userid'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Resolve course id from optional coursequery.
     *
     * @param array $input
     * @return int
     */
    protected function resolve_courseid(array $input): int {
        $query = trim((string)($input['coursequery'] ?? ''));
        if ($query === '') {
            return 0;
        }

        if (ctype_digit($query)) {
            return (int)$query;
        }

        // Wider than 1 so a unique exact name match is not lost behind fuzzy substring matches.
        $matches = $this->search_course_candidates_for_preview($query, 10);
        if (count($matches) === 1) {
            return (int)($matches[0]['courseid'] ?? 0);
        }

        // A substring search makes "booking" also match "slotbooking", which previously made the
        // resolver give up as ambiguous even though one course is named exactly "booking". Prefer a
        // UNIQUE exact (case-insensitive) match on shortname or fullname before treating it as ambiguous.
        $needle = \core_text::strtolower($query);
        $exact = [];
        foreach ($matches as $match) {
            $shortname = \core_text::strtolower((string)($match['shortname'] ?? ''));
            $fullname = \core_text::strtolower((string)($match['fullname'] ?? ''));
            if ($shortname === $needle || $fullname === $needle) {
                $exact[] = (int)($match['courseid'] ?? 0);
            }
        }
        $exact = array_values(array_unique(array_filter($exact)));
        if (count($exact) === 1) {
            return $exact[0];
        }

        // Genuinely ambiguous or unresolved -> 0 (callers turn this into a clarification).
        return 0;
    }

    /**
     * Whether the course named in a readonly skill's input refers to the current operating course.
     *
     * Shared framework primitive for "readonly eager resolution" (see flowchart PP_RUN/LG_CTX). Readonly
     * (R0) skills self-resolve their target because the engine skips preflight for them. A site-wide
     * course search can fail to pin a common course name (e.g. "booking") to a single match even when that
     * name IS the course we are already in. This predicate lets a readonly skill fall back to its ambient
     * operating course in that case — so readonly NEVER blocks on resolution — WITHOUT ever silently
     * substituting a genuinely different named course: it matches only by explicit courseid, or by an
     * exact (case-insensitive) shortname / fullname / idnumber match against the operating course.
     *
     * Readonly-only by design. Mutating (R1+) skills MUST NOT use this: they keep the conservative engine
     * path (skill_operating_context_resolver → CONTEXT_TARGET_UNRESOLVED → clarification + confirmation),
     * so a write never lands on a silently-substituted course.
     *
     * @param array $input skill input (courseid / coursequery)
     * @param int $operatingcontextid the ambient/operating context the skill runs in
     * @return bool
     */
    protected function course_input_targets_operating_context(array $input, int $operatingcontextid): bool {
        $courseid = (int)($input['courseid'] ?? 0);
        $coursequery = trim((string)($input['coursequery'] ?? ''));
        $context = \context::instance_by_id($operatingcontextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return false;
        }
        $operatingcourseid = (int)$coursecontext->instanceid;
        if ($courseid > 0) {
            return $courseid === $operatingcourseid;
        }
        if ($coursequery === '') {
            return false;
        }
        try {
            $course = get_course($operatingcourseid);
        } catch (\Throwable $e) {
            return false;
        }
        $needle = \core_text::strtolower($coursequery);
        foreach ([$course->shortname, $course->fullname, $course->idnumber] as $candidate) {
            $candidate = \core_text::strtolower(trim((string)$candidate));
            if ($candidate !== '' && $candidate === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve the course id a readonly skill should operate on — eager and safe.
     *
     * Shared "readonly eager resolution" entry point: explicit `courseid` → unique `coursequery`
     * match → ambient/operating course (when no course was named, or the named course IS the current
     * one per {@see course_input_targets_operating_context()}). Returns 0 ONLY when the user named a
     * course that resolves to neither a single match nor the current course — the caller then emits a
     * clean "course not found" instead of silently analysing the wrong course. Never blocks readonly on
     * resolution for the common "the course I'm in" case (thread 515).
     *
     * @param array $input skill input (courseid / coursequery)
     * @param int $ambientcontextid the context the skill runs in
     * @return int resolved course id, or 0 if a named-but-different course could not be resolved
     */
    protected function resolve_readonly_course_context_id(array $input, int $ambientcontextid): int {
        $courseid = (int)($input['courseid'] ?? 0);
        if ($courseid > 0) {
            return $courseid;
        }
        $courseid = $this->resolve_courseid($input);
        if ($courseid > 0) {
            return $courseid;
        }
        $namedacourse = trim((string)($input['coursequery'] ?? '')) !== '';
        if (!$namedacourse || $this->course_input_targets_operating_context($input, $ambientcontextid)) {
            $context = \context::instance_by_id($ambientcontextid, IGNORE_MISSING);
            $coursecontext = $context ? $context->get_course_context(false) : false;
            if ($coursecontext) {
                return (int)$coursecontext->instanceid;
            }
        }
        return 0;
    }

    /**
     * Explicit preflight for core readonly skills — validates structure and passes input unchanged.
     *
     * Core skills are read-only. No domain writes are performed. This override makes the
     * preflight contract explicit for all concrete core skills without requiring individual
     * overrides in each skill file.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }
        return $this->pass($input);
    }

    /**
     * Build a normalized user payload with core Moodle user data.
     *
     * @param \stdClass $user
     * @return array
     */
    protected function build_user_payload(\stdClass $user): array {
        global $CFG;

        if (empty($user->id)) {
            return [];
        }

        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $user = clone $user;
        if (function_exists('profile_load_data')) {
            profile_load_data($user);
        }

        $enrolledcourses = $this->build_user_courses_payload((int)$user->id);

        return [
            'userid' => (int)$user->id,
            'username' => (string)($user->username ?? ''),
            'fullname' => fullname($user),
            'firstname' => (string)($user->firstname ?? ''),
            'lastname' => (string)($user->lastname ?? ''),
            'email' => (string)($user->email ?? ''),
            'idnumber' => (string)($user->idnumber ?? ''),
            'institution' => (string)($user->institution ?? ''),
            'department' => (string)($user->department ?? ''),
            'city' => (string)($user->city ?? ''),
            'country' => (string)($user->country ?? ''),
            'address' => (string)($user->address ?? ''),
            'phone1' => (string)($user->phone1 ?? ''),
            'phone2' => (string)($user->phone2 ?? ''),
            'lang' => (string)($user->lang ?? ''),
            'timezone' => (string)($user->timezone ?? ''),
            // Activity/recency signals — answer "when was X last here?" and "can mail even reach them?".
            'emailstop' => (int)($user->emailstop ?? 0),
            'lastaccess' => $this->format_user_timestamp((int)($user->lastaccess ?? 0)),
            'lastlogin' => $this->format_user_timestamp((int)($user->lastlogin ?? 0)),
            'currentlogin' => $this->format_user_timestamp((int)($user->currentlogin ?? 0)),
            'firstaccess' => $this->format_user_timestamp((int)($user->firstaccess ?? 0)),
            'description' => (string)($user->description ?? ''),
            'descriptionformat' => (int)($user->descriptionformat ?? 0),
            'auth' => (string)($user->auth ?? ''),
            'confirmed' => (int)($user->confirmed ?? 0),
            'suspended' => (int)($user->suspended ?? 0),
            'deleted' => (int)($user->deleted ?? 0),
            'profileurl' => \core_user::get_profile_url($user)->out(false),
            'enrolledcourses' => $enrolledcourses,
            'roles' => $this->build_user_roles_payload((int)$user->id, $enrolledcourses),
            'customprofilefields' => $this->extract_custom_profile_fields($user),
        ];
    }

    /**
     * Build course enrolment payload for a user.
     *
     * @param int $userid User whose enrolments are listed.
     * @param int $vieweruserid Acting user to scope the list to (0 = no scoping, self observation).
     * @return array[]
     */
    protected function build_user_courses_payload(int $userid, int $vieweruserid = 0): array {
        global $DB;

        $courses = enrol_get_users_courses($userid, true, 'id, fullname, shortname, visible, category, sortorder');

        // Cross-user scoping: when a viewer other than the subject is given, restrict this cross-course
        // overview to the courses that viewer may actually access — otherwise it would expose a
        // subject's unrelated enrolments (and their roles) to an actor who cannot see them. The subject
        // viewing themselves, a site admin, or a holder of the site-wide moodle/user:viewalldetails see
        // the full list. Called without a viewer (self observation) the behaviour is unchanged.
        $restrict = false;
        $vieweruser = null;
        if ($vieweruserid > 0 && $vieweruserid !== $userid) {
            $syscontext = \context_system::instance();
            if (
                !is_siteadmin($vieweruserid)
                && !has_capability('moodle/user:viewalldetails', $syscontext, $vieweruserid)
            ) {
                $vieweruser = \core_user::get_user($vieweruserid, '*', IGNORE_MISSING);
                if (!$vieweruser) {
                    return []; // Unknown viewer → fail closed.
                }
                $restrict = true;
            }
        }

        $payload = [];

        // One cheap lookup of the per-course last access for all the user's courses.
        $lastaccessmap = $DB->get_records_menu('user_lastaccess', ['userid' => $userid], '', 'courseid, timeaccess');

        foreach ($courses as $course) {
            $courseid = (int)($course->id ?? 0);
            if ($courseid <= 0) {
                continue;
            }
            if ($restrict && !can_access_course($course, $vieweruser)) {
                continue;
            }

            $coursepayload = [
                'courseid' => $courseid,
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'fullname' => (string)($course->fullname ?? ''),
                'shortname' => (string)($course->shortname ?? ''),
                'visible' => (int)($course->visible ?? 1),
                'category' => (int)($course->category ?? 0),
                'sortorder' => (int)($course->sortorder ?? 0),
                'lastaccess' => $this->format_user_timestamp((int)($lastaccessmap[$courseid] ?? 0)),
                'roles' => [],
            ];

            $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
            if ($coursecontext) {
                foreach (get_user_roles($coursecontext, $userid, false, 'r.sortorder ASC') as $assignment) {
                    $coursepayload['roles'][] = [
                        'roleid' => (int)($assignment->roleid ?? 0),
                        'shortname' => (string)($assignment->shortname ?? ''),
                        'name' => (string)($assignment->name ?? ''),
                        'contextid' => (int)($assignment->contextid ?? 0),
                        'contextlevel' => (int)($assignment->contextlevel ?? 0),
                        'courseid' => $courseid,
                    ];
                }
            }

            $payload[] = $coursepayload;
        }

        return $payload;
    }

    /**
     * Build flattened role assignment payload for a user.
     *
     * @param int $userid
     * @param array[] $courses
     * @return array[]
     */
    protected function build_user_roles_payload(int $userid, array $courses = []): array {
        $payload = [];
        $seen = [];

        $appendassignments = static function (array $assignments, ?int $courseid = null) use (&$payload, &$seen): void {
            foreach ($assignments as $assignment) {
                $roleid = (int)($assignment->roleid ?? 0);
                $contextid = (int)($assignment->contextid ?? 0);
                $key = $contextid . ':' . $roleid;
                if ($roleid <= 0 || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $payload[] = [
                    'roleid' => $roleid,
                    'shortname' => (string)($assignment->shortname ?? ''),
                    'name' => (string)($assignment->name ?? ''),
                    'contextid' => $contextid,
                    'contextlevel' => (int)($assignment->contextlevel ?? 0),
                    'courseid' => $courseid,
                ];
            }
        };

        $appendassignments(get_user_roles(context_system::instance(), $userid, false, 'r.sortorder ASC'), null);

        foreach ($courses as $course) {
            $courseid = (int)($course['courseid'] ?? 0);
            if ($courseid <= 0) {
                continue;
            }

            $coursecontext = context_course::instance($courseid, IGNORE_MISSING);
            if ($coursecontext) {
                $appendassignments(get_user_roles($coursecontext, $userid, false, 'r.sortorder ASC'), $courseid);
            }
        }

        return $payload;
    }

    /**
     * Extract loaded custom profile fields from a user object.
     *
     * @param \stdClass $user
     * @return array
     */
    protected function extract_custom_profile_fields(\stdClass $user): array {
        $fields = [];
        foreach (get_object_vars($user) as $key => $value) {
            if (strpos((string)$key, 'profile_field_') !== 0) {
                continue;
            }

            $fields[substr((string)$key, strlen('profile_field_'))] = $value;
        }

        return $fields;
    }

    /**
     * Search user candidates using Moodle core APIs only.
     *
     * @param string $query
     * @param int $limit
     * @return array[]
     */
    protected function search_user_candidates_for_preview(string $query, int $limit = 10): array {
        global $CFG;

        $query = trim($query);
        if ($query === '') {
            return [];
        }
        // The requester marker (#2569) names the acting user; every person lookup of the core skills passes here.
        if (requester_reference::is_marker($query)) {
            global $USER;
            $query = (string)(int)($USER->id ?? 0);
        }

        require_once($CFG->libdir . '/datalib.php');

        if (preg_match('/^\d+$/', $query)) {
            $user = \core_user::get_user((int)$query, 'id, firstname, lastname, email', IGNORE_MISSING);
            if ($user && !empty($user->id)) {
                return [[
                    'userid' => (int)$user->id,
                    'firstname' => (string)($user->firstname ?? ''),
                    'lastname' => (string)($user->lastname ?? ''),
                    'email' => (string)($user->email ?? ''),
                ]];
            }
        }

        $result = search_users(0, 0, $query, 'lastname ASC, firstname ASC, id ASC');
        $normalized = [];
        foreach ((array)$result as $user) {
            $normalized[] = [
                'userid' => (int)($user->id ?? 0),
                'firstname' => (string)($user->firstname ?? ''),
                'lastname' => (string)($user->lastname ?? ''),
                'email' => (string)($user->email ?? ''),
            ];
        }

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * Search course candidates using Moodle core APIs only.
     *
     * @param string $query Search text or numeric course id.
     * @param int $limit Maximum number of matches.
     * @return array[]
     */
    protected function search_course_candidates_for_preview(string $query, int $limit = 10): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        if (preg_match('/^\d+$/', $query)) {
            $course = get_course((int)$query);
            if (!empty($course->id)) {
                $courseid = (int)$course->id;
                return [[
                    'courseid' => $courseid,
                    'fullname' => (string)($course->fullname ?? ''),
                    'shortname' => (string)($course->shortname ?? ''),
                    'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                    'activeenrolledcount' => $this->count_active_course_enrolments($courseid),
                ]];
            }
        }

        $courses = \core_course_category::search_courses(
            ['search' => $query],
            ['limit' => max(1, $limit), 'sort' => ['fullname' => 1]]
        );

        $normalized = [];
        foreach ($courses as $course) {
            $courseid = (int)($course->id ?? 0);
            if ($courseid <= 0) {
                continue;
            }
            $normalized[] = [
                'courseid' => $courseid,
                'fullname' => (string)($course->fullname ?? ''),
                'shortname' => (string)($course->shortname ?? ''),
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'activeenrolledcount' => $this->count_active_course_enrolments($courseid),
            ];
        }

        return array_slice($normalized, 0, max(1, $limit));
    }

    /**
     * List all courses visible to the current user (empty-query course listing).
     *
     * Returns the first $limit normalized course candidates plus the total count
     * so callers can report truncation. Enrolment counts are only computed for
     * the returned slice to keep large sites cheap.
     *
     * @param int $limit Maximum number of courses to return.
     * @return array{courses: array[], total: int}
     */
    protected function list_course_candidates_for_preview(int $limit = 50): array {
        $records = get_courses('all', 'c.fullname ASC', 'c.id, c.fullname, c.shortname, c.visible, c.category');

        $visible = [];
        foreach ($records as $record) {
            $courseid = (int)($record->id ?? 0);
            if ($courseid <= 0 || $courseid === SITEID) {
                continue;
            }
            if (!\core_course_category::can_view_course_info($record)) {
                continue;
            }
            $visible[] = $record;
        }

        $total = count($visible);
        $courses = [];
        foreach (array_slice($visible, 0, max(1, $limit)) as $record) {
            $courseid = (int)$record->id;
            $courses[] = [
                'courseid' => $courseid,
                'fullname' => (string)($record->fullname ?? ''),
                'shortname' => (string)($record->shortname ?? ''),
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'activeenrolledcount' => $this->count_active_course_enrolments($courseid),
            ];
        }

        return ['courses' => $courses, 'total' => $total];
    }

    /**
     * Count currently active enrolments for a course.
     *
     * @param int $courseid
     * @return int
     */
    protected function count_active_course_enrolments(int $courseid): int {
        if ($courseid <= 0) {
            return 0;
        }

        $context = context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return 0;
        }

        return (int)count_enrolled_users($context, '', 0, true);
    }

    /**
     * Build a full observation string for one or more normalized user payloads.
     *
     * @param array[] $users
     * @return string
     */
    protected function build_user_observation_full(array $users): string {
        $count = count($users);
        if ($count === 0) {
            return 'Found 0 user(s).';
        }

        $lines = ["Found {$count} user(s):"];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $identityparts = [
                'userid=' . $this->format_observation_scalar($user['userid'] ?? null),
                // Real moodle_url profile link travels with every user mention so the
                // synchronizer can present it clickable without inventing URLs.
                'profileurl=' . $this->format_observation_scalar($user['profileurl'] ?? null),
                'username=' . $this->format_observation_scalar($user['username'] ?? null),
                'fullname=' . $this->format_observation_scalar($user['fullname'] ?? null),
                'firstname=' . $this->format_observation_scalar($user['firstname'] ?? null),
                'lastname=' . $this->format_observation_scalar($user['lastname'] ?? null),
                'email=' . $this->format_observation_scalar($user['email'] ?? null),
                'idnumber=' . $this->format_observation_scalar($user['idnumber'] ?? null),
                'institution=' . $this->format_observation_scalar($user['institution'] ?? null),
                'department=' . $this->format_observation_scalar($user['department'] ?? null),
                'city=' . $this->format_observation_scalar($user['city'] ?? null),
                'country=' . $this->format_observation_scalar($user['country'] ?? null),
                'address=' . $this->format_observation_scalar($user['address'] ?? null),
                'phone1=' . $this->format_observation_scalar($user['phone1'] ?? null),
                'phone2=' . $this->format_observation_scalar($user['phone2'] ?? null),
                'lang=' . $this->format_observation_scalar($user['lang'] ?? null),
                'timezone=' . $this->format_observation_scalar($user['timezone'] ?? null),
                'lastaccess=' . $this->format_observation_scalar($user['lastaccess'] ?? null),
                'lastlogin=' . $this->format_observation_scalar($user['lastlogin'] ?? null),
                'currentlogin=' . $this->format_observation_scalar($user['currentlogin'] ?? null),
                'firstaccess=' . $this->format_observation_scalar($user['firstaccess'] ?? null),
                'emailstop=' . $this->format_observation_scalar($user['emailstop'] ?? null),
                'auth=' . $this->format_observation_scalar($user['auth'] ?? null),
                'confirmed=' . $this->format_observation_scalar($user['confirmed'] ?? null),
                'suspended=' . $this->format_observation_scalar($user['suspended'] ?? null),
                'deleted=' . $this->format_observation_scalar($user['deleted'] ?? null),
                'profile=' . $this->format_observation_scalar($user['profileurl'] ?? null),
            ];

            $description = trim((string)($user['description'] ?? ''));
            if ($description !== '') {
                $identityparts[] = 'description=' . $description;
            }

            $lines[] = '- user: ' . implode(', ', $identityparts);
            $lines[] = '  enrolledcourses=' . $this->format_course_observation((array)($user['enrolledcourses'] ?? []));
            $lines[] = '  roles=' . $this->format_role_observation((array)($user['roles'] ?? []));
            $lines[] = '  customprofilefields=' . $this->format_custom_profile_field_observation(
                (array)($user['customprofilefields'] ?? [])
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Format a user/access timestamp for observation + payload output.
     *
     * @param int $timestamp
     * @return string Human-readable date, or 'never' for an empty timestamp.
     */
    protected function format_user_timestamp(int $timestamp): string {
        return observation_time::format($timestamp);
    }

    /**
     * Format a scalar value for observation output.
     *
     * @param mixed $value
     * @return string
     */
    protected function format_observation_scalar($value): string {
        if ($value === null) {
            return '-';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $text = trim((string)$value);
        return $text === '' ? '-' : $text;
    }

    /**
     * Format enrolled course payloads for observation output.
     *
     * @param array[] $courses
     * @return string
     */
    protected function format_course_observation(array $courses): string {
        if (empty($courses)) {
            return '[]';
        }

        $parts = [];
        foreach ($courses as $course) {
            if (!is_array($course)) {
                continue;
            }
            $parts[] = '{courseid=' . $this->format_observation_scalar($course['courseid'] ?? null)
                . ', shortname=' . $this->format_observation_scalar($course['shortname'] ?? null)
                . ', fullname=' . $this->format_observation_scalar($course['fullname'] ?? null)
                . ', courseurl=' . $this->format_observation_scalar($course['courseurl'] ?? null)
                . ', visible=' . $this->format_observation_scalar($course['visible'] ?? null)
                . ', category=' . $this->format_observation_scalar($course['category'] ?? null)
                . ', lastaccess=' . $this->format_observation_scalar($course['lastaccess'] ?? null)
                . ', roles=' . $this->format_role_observation((array)($course['roles'] ?? []))
                . '}';
        }

        return '[' . implode('; ', $parts) . ']';
    }

    /**
     * Format role payloads for observation output.
     *
     * @param array[] $roles
     * @return string
     */
    protected function format_role_observation(array $roles): string {
        if (empty($roles)) {
            return '[]';
        }

        $parts = [];
        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }
            $parts[] = '{roleid=' . $this->format_observation_scalar($role['roleid'] ?? null)
                . ', shortname=' . $this->format_observation_scalar($role['shortname'] ?? null)
                . ', name=' . $this->format_observation_scalar($role['name'] ?? null)
                . ', contextid=' . $this->format_observation_scalar($role['contextid'] ?? null)
                . ', contextlevel=' . $this->format_observation_scalar($role['contextlevel'] ?? null)
                . ', courseid=' . $this->format_observation_scalar($role['courseid'] ?? null)
                . '}';
        }

        return '[' . implode('; ', $parts) . ']';
    }

    /**
     * Format custom profile fields for observation output.
     *
     * @param array $fields
     * @return string
     */
    protected function format_custom_profile_field_observation(array $fields): string {
        if (empty($fields)) {
            return '[]';
        }

        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = $key . '=' . $this->format_observation_scalar($value);
        }

        return '[' . implode('; ', $parts) . ']';
    }

    /**
     * Activities of one module type whose name matches, across every course the user may see.
     *
     * Run-23 finding: the course skills searched the ambient course only. A user who says "rename
     * the Abschlusstest" while sitting in a booking activity got "no quiz called that in this
     * course" - about a quiz that exists one course over. The ambient course stays the first place
     * to look; this is what happens when it holds no match, so a named target is never lost to the
     * course boundary alone.
     *
     * Visibility is not a detail here: the search runs over the user's own modinfo per course, so
     * an activity they may not see never becomes a candidate.
     *
     * @param string|string[] $modnames One module type, or several to search in turn.
     * @param string $query Name or part of it.
     * @param int $userid
     * @param int $limit Stop after this many candidates.
     * @return array[] {cmid, name, courseid, coursename, modname}
     */
    protected function find_activities_site_wide($modnames, string $query, int $userid, int $limit = 10): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $out = [];
        foreach ((array)$modnames as $modname) {
            foreach ($this->find_one_modtype_site_wide((string)$modname, $query, $userid, $limit) as $found) {
                $out[] = $found;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }

        return $out;
    }

    /**
     * One module type's matching activities across all courses the user may see.
     *
     * @param string $modname
     * @param string $query
     * @param int $userid
     * @param int $limit
     * @return array[]
     */
    private function find_one_modtype_site_wide(string $modname, string $query, int $userid, int $limit): array {
        global $DB;

        $modname = trim($modname);
        // The module name reaches SQL as a table name, which no bound parameter can carry, so it is
        // checked against what Moodle has installed rather than trusted.
        if ($modname === '' || !$DB->record_exists('modules', ['name' => $modname])) {
            return [];
        }

        $sql = "SELECT cm.id AS cmid, inst.name AS name, c.id AS courseid, c.fullname AS coursename
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {" . $modname . "} inst ON inst.id = cm.instance
                  JOIN {course} c ON c.id = cm.course
                 WHERE " . $DB->sql_like('inst.name', ':needle', false, false) . "
                   AND cm.deletioninprogress = 0
              ORDER BY c.id, inst.name";

        $rows = $DB->get_records_sql($sql, [
            'modname' => $modname,
            'needle' => '%' . $DB->sql_like_escape($query) . '%',
        ], 0, $limit * 5);

        $out = [];
        foreach ($rows as $row) {
            try {
                $modinfo = get_fast_modinfo((int)$row->courseid, $userid);
                if (!$modinfo->get_cm((int)$row->cmid)->uservisible) {
                    continue;
                }
            } catch (\Throwable $e) {
                continue;
            }
            $out[] = [
                'cmid' => (int)$row->cmid,
                'name' => (string)$row->name,
                'courseid' => (int)$row->courseid,
                'coursename' => format_string($row->coursename),
                'modname' => $modname,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}

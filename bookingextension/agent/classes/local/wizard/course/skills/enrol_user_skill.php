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

namespace bookingextension_agent\local\wizard\course\skills;

use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\preflight_clarification;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use context;
use moodle_url;

/**
 * Generic skill: enrol an existing user into a course (course.enrol_user).
 *
 * Manual enrolment only, mirroring core's enrol-users dialog: gated by enrol/manual:enrol at
 * the course context, role restricted to what the acting user may assign there (default: the
 * manual instance's configured role, falling back to the student archetype). Resolves the
 * target person from the user's wording (name/email/id) and clarifies on ambiguity; the
 * course target travels through the generic course_targeted_skill selector. Does NOT create
 * user accounts and does NOT touch booking options (that is mod_booking.book_users).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_user_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    use preflight_clarification;

    /** Skill name constant. */
    public const SKILL_NAME = 'course.enrol_user';

    /** Cap for candidate lists embedded in clarification messages. */
    private const MAX_CANDIDATES = 10;

    /**
     * Constructor. Mutating skill (grants a person course access) — broad write, requires confirmation.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Enrolment happens in a course, so this skill needs course scope.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }

    /**
     * The cross-context target is a course.
     *
     * @return int
     */
    public function get_target_context_level(): int {
        return CONTEXT_COURSE;
    }

    /**
     * Native capability required to manually enrol (Gate 2) — the same gate core's
     * enrol-users dialog runs behind. Role choice is additionally limited to
     * get_assignable_roles() in preflight.
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['enrol/manual:enrol'];
    }

    /**
     * Human-readable preview of the enrolment to be performed (tier-3 confirmation preview).
     *
     * @param array $input Prepared input (preflight ran first for mutating skills).
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $user = trim((string)($input['userfullname'] ?? ($input['userquery'] ?? '')));
        $course = trim((string)($input['coursefullname'] ?? ($input['coursequery'] ?? '')));
        $role = trim((string)($input['rolename'] ?? ($input['role'] ?? '')));

        $rows = [];
        $rows[] = [
            'label' => get_string('previewlabel_user', 'bookingextension_agent'),
            'value' => $user !== '' ? $user : '-',
        ];
        $rows[] = [
            'label' => get_string('previewlabel_course', 'bookingextension_agent'),
            'value' => $course !== '' ? $course : get_string('course'),
        ];
        if ($role !== '') {
            $rows[] = [
                'label' => get_string('previewlabel_role', 'bookingextension_agent'),
                'value' => $role,
            ];
        }

        return [
            'title' => get_string('previewtitle_enroluser', 'bookingextension_agent', $user !== '' ? $user : '-'),
            'summary' => trim($user . ' → ' . $course . ($role !== '' ? ' (' . $role . ')' : '')),
            'rows' => $rows,
        ];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            // First 240 characters = selector window (#2419, #2423 EU-4 "needs access to the first-aid course").
            // Wave 32 (EU-2, runs L31-L41): "als Trainer im Beschaffungskurs eintragen" went to the booking trainer
            // skill six times; this card never said that a course role other than student is part of an enrolment.
            'description' => 'Give an EXISTING user — or the asking person — access to a Moodle course (coursequery) by enrolling '
                . 'them with a course role: student by default, or the teacher/trainer role the user names. Use when the '
                . 'user wants to enrol/add/put a person (or themselves) into a course.',
            'is' => 'Enrolling a person into a Moodle course, as student or with a named course role such as teacher.',
            'not' => 'Booking into a booking option (mod_booking.book_users); creating accounts.',
            'readonly' => false,
            'example_utterances' => [
                'enrol Anna into First Aid',
                'add user john@example.com to Biology 101',
                'enrol Max as a teacher in this course',
                'put me in the marketing course',
                'schreibe Anna in den Kurs Erste Hilfe ein',
            ],
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    // Wave 32 (EU-3, runs L36-L41): since F81 (e08b63b) "me" is no self-reference any more — an EMPTY
                    // person field is the requester. The old text told the constructor to write "me", and the skill
                    // then searched the site for people matching "me" (ENROL_USER_AMBIGUOUS in six runs).
                    'description' => 'Who to enrol: a name, an email address or a numeric user id, as the user wrote it. '
                        . 'Leave it empty when the user means themselves. The system resolves it.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the user to enrol, when already known from an earlier step. '
                        . 'Never guess an id.',
                    'required' => false,
                ],
                'role' => [
                    'type' => 'string',
                    'description' => 'Optional enrolment role, verbatim (e.g. "student", "teacher"). Leave empty for '
                        . 'the course default role; the system matches it and asks if needed.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'The course the user named, in this message or an earlier one, verbatim - never '
                        . '"the current course" then. Empty only if no course was named.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the target course, when already known. Leave empty for the '
                        . 'current course; never guess an id.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['userquery', 'role'],
                'anchor_fields' => ['coursequery'],
                // Mandatory for R2: the write is scoped to one course.
                'context_scopes' => ['course'],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'userquery' => 'anna.muster@example.com',
            'role' => 'student',
            'coursequery' => 'First Aid',
        ];
    }

    /**
     * Return message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.enrol_user_request',
                'description' => 'The user wants a person, or themselves, enrolled into a Moodle course, optionally with a role.',
            ],
        ];
    }

    /**
     * Construction-phase guidance surfaced once this skill is selected.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'course.enrol_user',
                'triggers' => [
                    'enrol', 'enroll', 'enrol user', 'add user to course', 'register user',
                    'einschreiben', 'in den kurs einschreiben',
                ],
                'guidance' => [
                    '- course.enrol_user enrols an EXISTING user into a course; it never creates accounts.',
                    '- Pass the person exactly as the user wrote them (name, email or id) in input.userquery; '
                        . 'leave it empty when the user means themselves. The system resolves and asks when several '
                        . 'people match.',
                    '- Only set input.role when the user named one; otherwise leave it empty for the default role.',
                    '- For booking people into booking OPTIONS use mod_booking.book_users instead, not this skill.',
                ],
            ],
        ];
    }

    /**
     * Structural validation (pure, no DB).
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];

        // An empty person (no userquery, no userid) is the requester (F81): no structural error.
        $userquery = trim((string)($input['userquery'] ?? ''));
        if (strlen($userquery) > 255) {
            $errors[] = 'userquery is too long.';
        }
        if (strlen(trim((string)($input['role'] ?? ''))) > 100) {
            $errors[] = 'role is too long.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Deep validation: resolve course, target user, manual instance and role (all read-only).
     *
     * @param array $input
     * @param int   $contextid Operating context (the target course context when one was named).
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        // 1) Resolve the course context.
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return $this->clarify(
                'Users can only be enrolled into a course. Please open a course, or tell me which course '
                    . '(by name) the user should be enrolled into.',
                'ENROL_NO_COURSE'
            );
        }

        // 2) Gate 2 (static): the acting user must natively be allowed to enrol in this course.
        if (!has_capability('enrol/manual:enrol', $coursecontext, $userid)) {
            return $this->clarify(
                get_string('nopermissions', 'error', 'enrol/manual:enrol'),
                'NO_NATIVE_CAPABILITY'
            );
        }

        $course = get_course($coursecontext->instanceid);

        // 3) Resolve the target person.
        $userresolution = $this->resolve_target_user($input, $userid);
        if (isset($userresolution['clarify'])) {
            return $userresolution['clarify'];
        }
        $targetuser = $userresolution['user'];

        // 4) An enabled manual enrolment instance must exist.
        $instance = $this->find_manual_instance((int)$course->id);
        if ($instance === null) {
            return $this->clarify(
                'Manual enrolment is disabled in the course "' . $course->fullname . '", so I cannot enrol anyone '
                    . 'there. Enable the manual enrolment method in the course first.',
                'ENROL_NO_MANUAL_INSTANCE'
            );
        }

        // 5) Already enrolled: honest stop before a pointless confirmation round-trip.
        if (is_enrolled($coursecontext, $targetuser->id)) {
            return $this->clarify(
                fullname($targetuser) . ' is already enrolled in "' . $course->fullname . '" — nothing to do.',
                'ENROL_ALREADY_ENROLLED'
            );
        }

        // 6) Resolve the role (default: the instance's configured role; named: must be assignable).
        $roleresolution = $this->resolve_role($coursecontext, $userid, trim((string)($input['role'] ?? '')), $instance);
        if (isset($roleresolution['clarify'])) {
            return $roleresolution['clarify'];
        }

        return $this->pass([
            'userid' => (int)$targetuser->id,
            'userfullname' => fullname($targetuser),
            'courseid' => (int)$course->id,
            'coursefullname' => (string)$course->fullname,
            'enrolinstanceid' => (int)$instance->id,
            'roleid' => (int)$roleresolution['roleid'],
            'rolename' => (string)$roleresolution['rolename'],
        ]);
    }

    /**
     * Perform the enrolment.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $targetuserid = (int)($preparedinput['userid'] ?? 0);
        $courseid = (int)($preparedinput['courseid'] ?? 0);
        $instanceid = (int)($preparedinput['enrolinstanceid'] ?? 0);
        $roleid = (int)($preparedinput['roleid'] ?? 0);

        if ($targetuserid <= 0 || $courseid <= 0 || $instanceid <= 0) {
            return $this->build_error_result('Missing prepared user, course or enrolment instance.');
        }

        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->build_error_result('Target course could not be loaded.');
        }
        $coursecontext = \context_course::instance($courseid);

        // Idempotency guard for the confirm-time window: a no-op is a truthful success.
        if (is_enrolled($coursecontext, $targetuserid)) {
            return $this->build_success_result($preparedinput, $course, true);
        }

        // Re-fetch the instance defensively (it may have been disabled between confirm and execute).
        $instance = null;
        foreach (enrol_get_instances($courseid, true) as $candidate) {
            if ((int)$candidate->id === $instanceid && $candidate->enrol === 'manual') {
                $instance = $candidate;
                break;
            }
        }
        $enrol = enrol_get_plugin('manual');
        if ($instance === null || $enrol === null) {
            return $this->build_error_result(
                'Manual enrolment is no longer available in the course "' . $course->fullname . '".'
            );
        }

        try {
            $enrol->enrol_user($instance, $targetuserid, $roleid > 0 ? $roleid : null);
        } catch (\Throwable $e) {
            return $this->build_error_result('Enrolment failed: ' . $e->getMessage());
        }

        return $this->build_success_result($preparedinput, $course, false);
    }

    /**
     * Resolve the target person from explicit userid or userquery.
     *
     * @param array $input
     * @param int   $actinguserid
     * @return array{user:\stdClass}|array{clarify:array}
     */
    private function resolve_target_user(array $input, int $actinguserid): array {
        $explicitid = (int)($input['userid'] ?? 0);
        $resolvedid = $explicitid > 0 ? $explicitid : $this->resolve_userid($input, $actinguserid);
        $query = trim((string)($input['userquery'] ?? ''));

        if ($resolvedid <= 0) {
            // Distinguish "nobody matched" from "several matched" for an actionable clarification.
            $candidates = $query !== '' ? $this->search_user_candidates_for_preview($query, self::MAX_CANDIDATES) : [];
            if (empty($candidates)) {
                return ['clarify' => $this->clarify(
                    'No user matched "' . $query . '". Please give the person\'s full name, email address or id.',
                    'ENROL_USER_NOT_FOUND'
                )];
            }
            return ['clarify' => $this->clarify(
                'Several users match "' . $query . '". Who should be enrolled? '
                    . $this->format_user_candidates($candidates),
                'ENROL_USER_AMBIGUOUS'
            )];
        }

        $user = \core_user::get_user($resolvedid, '*', IGNORE_MISSING);
        if (!$user || !empty($user->deleted) || isguestuser($user)) {
            return ['clarify' => $this->clarify(
                'The user "' . ($query !== '' ? $query : (string)$resolvedid) . '" cannot be enrolled '
                    . '(not found, deleted, or the guest account).',
                'ENROL_USER_NOT_FOUND'
            )];
        }

        return ['user' => $user];
    }

    /**
     * Find the first enabled manual enrolment instance of a course.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    private function find_manual_instance(int $courseid): ?\stdClass {
        foreach (enrol_get_instances($courseid, true) as $instance) {
            if ($instance->enrol === 'manual') {
                return $instance;
            }
        }
        return null;
    }

    /**
     * Resolve the role for the enrolment.
     *
     * Empty input: the manual instance's configured default role, falling back to the student
     * archetype. Named input: must uniquely match a role the acting user may assign here
     * (localized name or shortname, case-insensitive) — get_assignable_roles() already filters
     * by the actor's rights, so no separate role:assign check is needed.
     *
     * @param context   $coursecontext
     * @param int       $actinguserid
     * @param string    $rolequery
     * @param \stdClass $instance
     * @return array{roleid:int,rolename:string}|array{clarify:array}
     */
    private function resolve_role(context $coursecontext, int $actinguserid, string $rolequery, \stdClass $instance): array {
        global $DB;

        $assignable = get_assignable_roles($coursecontext, ROLENAME_ALIAS, false, $actinguserid);

        if ($rolequery === '') {
            $roleid = (int)$instance->roleid;
            if ($roleid <= 0) {
                $studentroles = get_archetype_roles('student');
                $studentrole = reset($studentroles);
                $roleid = $studentrole ? (int)$studentrole->id : 0;
            }
            $rolename = $assignable[$roleid] ?? (string)role_get_name(
                $DB->get_record('role', ['id' => $roleid]) ?: null,
                $coursecontext
            );
            return ['roleid' => $roleid, 'rolename' => (string)$rolename];
        }

        $needle = \core_text::strtolower($rolequery);
        $shortnames = empty($assignable)
            ? []
            : $DB->get_records_list('role', 'id', array_keys($assignable), '', 'id, shortname');
        $matches = [];
        foreach ($assignable as $roleid => $name) {
            $shortname = isset($shortnames[$roleid]) ? \core_text::strtolower((string)$shortnames[$roleid]->shortname) : '';
            if (\core_text::strtolower((string)$name) === $needle || $shortname === $needle) {
                $matches[$roleid] = (string)$name;
            }
        }

        // Wave 32 (EU-2, run L42sol thread 13095): "Trainer" matched neither "Trainer/in" nor
        // "Trainer/in ohne Bearbeitungsrecht" exactly and was reported as NOT ASSIGNABLE, although it is.
        // Structural fallback, no word list: a role whose name carries every token of the query is a
        // candidate; among several, the one with the fewest tokens is the unqualified role. The choice is
        // shown in the confirmation preview (R2), so nothing is written without the user seeing the role.
        if (empty($matches)) {
            $matches = self::roles_covering_query($rolequery, $assignable, $shortnames);
        }

        if (count($matches) > 1) {
            $available = [];
            $options = [];
            foreach ($matches as $roleid => $name) {
                $shortname = isset($shortnames[$roleid]) ? (string)$shortnames[$roleid]->shortname : '';
                $available[] = $name . ($shortname !== '' ? ' (' . $shortname . ')' : '');
                $options[] = ['roleid' => (int)$roleid, 'name' => (string)$name];
            }
            return ['clarify' => $this->clarify(
                get_string('agent_enrol_role_ambiguous', 'bookingextension_agent', (object)[
                    'query' => $rolequery,
                    'candidates' => implode('; ', $available),
                ]),
                'ENROL_ROLE_AMBIGUOUS',
                $options
            )];
        }

        if (count($matches) !== 1) {
            $available = [];
            foreach (array_slice($assignable, 0, self::MAX_CANDIDATES, true) as $roleid => $name) {
                $shortname = isset($shortnames[$roleid]) ? (string)$shortnames[$roleid]->shortname : '';
                $available[] = $name . ($shortname !== '' ? ' (' . $shortname . ')' : '');
            }
            return ['clarify' => $this->clarify(
                'The role "' . $rolequery . '" does not match a role you may assign in this course. '
                    . 'Available: ' . implode('; ', $available) . '.',
                'ENROL_ROLE_NOT_ASSIGNABLE'
            )];
        }

        return ['roleid' => (int)array_key_first($matches), 'rolename' => reset($matches)];
    }

    /**
     * Render user candidates for a clarification message.
     *
     * @param array $candidates
     * @return string
     */
    private function format_user_candidates(array $candidates): string {
        $parts = [];
        foreach ($candidates as $candidate) {
            $parts[] = trim((string)($candidate['firstname'] ?? '') . ' ' . (string)($candidate['lastname'] ?? ''))
                . ' (id ' . (int)($candidate['userid'] ?? 0)
                . (!empty($candidate['email']) ? ', ' . $candidate['email'] : '') . ')';
        }
        return 'Candidates: ' . implode('; ', $parts) . '.';
    }

    /**
     * Build the success result payload.
     *
     * @param array     $prepared
     * @param \stdClass $course
     * @param bool      $wasnoop Whether the user was already enrolled at execute time.
     * @return array
     */
    private function build_success_result(array $prepared, \stdClass $course, bool $wasnoop): array {
        $userfullname = (string)($prepared['userfullname'] ?? '');
        $rolename = (string)($prepared['rolename'] ?? '');
        $courseurl = (new moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false);

        $message = $wasnoop
            ? $userfullname . ' is already enrolled in "' . $course->fullname . '" — nothing was changed.'
            : 'Enrolled ' . $userfullname . ' in the course "' . $course->fullname . '"'
                . ($rolename !== '' ? ' as ' . $rolename : '') . '.';

        $observation = implode("\n", [
            ($wasnoop ? 'No-op: user already enrolled.' : 'Enrolled user.')
                . ' userid=' . (int)($prepared['userid'] ?? 0) . ' user="' . $userfullname . '"'
                . ' courseid=' . (int)$course->id . ' course="' . $course->fullname . '"'
                . ($rolename !== '' ? ' role="' . $rolename . '"' : ''),
            'Course URL: ' . $courseurl,
        ]);

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message . ' You can open the course here: ' . $courseurl,
            'resultid' => (int)($prepared['userid'] ?? 0),
            'enrolled_userid' => (int)($prepared['userid'] ?? 0),
            'enrolled_fullname' => $userfullname,
            'courseid' => (int)$course->id,
            'coursefullname' => (string)$course->fullname,
            'rolename' => $rolename,
            'course_url' => $courseurl,
            'observation_full' => $observation,
            'produced_outputs' => [
                'userid' => (int)($prepared['userid'] ?? 0),
                'courseid' => (int)$course->id,
            ],
        ];
    }

    /**
     * Roles whose name (or shortname) carries every token of the query, narrowed to the least qualified one.
     *
     * Tokens are runs of letters/digits of any script; no word is known here. "Trainer" is carried by
     * "Trainer/in" (2 tokens) and "Trainer/in ohne Bearbeitungsrecht" (4 tokens): the role with the fewest
     * tokens wins when it is the only one with that count, otherwise every carrying role is returned so the
     * caller can ask.
     *
     * @param string $rolequery
     * @param array $assignable roleid => localized name
     * @param array $shortnames roleid => record with shortname
     * @return array roleid => name
     */
    public static function roles_covering_query(string $rolequery, array $assignable, array $shortnames): array {
        $tokenize = static function (string $text): array {
            $parts = preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            return array_values(array_unique($parts));
        };
        $querytokens = $tokenize($rolequery);
        if (empty($querytokens)) {
            return [];
        }

        $covering = [];
        $sizes = [];
        foreach ($assignable as $roleid => $name) {
            $shortname = isset($shortnames[$roleid]) ? (string)$shortnames[$roleid]->shortname : '';
            foreach ([(string)$name, $shortname] as $label) {
                $labeltokens = $tokenize($label);
                if ($labeltokens !== [] && array_diff($querytokens, $labeltokens) === []) {
                    $covering[(int)$roleid] = (string)$name;
                    $sizes[(int)$roleid] = min($sizes[(int)$roleid] ?? PHP_INT_MAX, count($labeltokens));
                }
            }
        }
        if (count($covering) <= 1) {
            return $covering;
        }

        $smallest = min($sizes);
        $least = array_keys(array_filter($sizes, static fn(int $size): bool => $size === $smallest));
        return count($least) === 1 ? [$least[0] => $covering[$least[0]]] : $covering;
    }

    /**
     * Build an error result payload.
     *
     * @param string $message
     * @return array
     */
    private function build_error_result(string $message): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'observation_full' => $message,
        ];
    }
}

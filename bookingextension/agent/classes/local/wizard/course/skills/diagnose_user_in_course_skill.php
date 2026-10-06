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

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\diagnostics\aspects\access_aspect_diagnoser;
use bookingextension_agent\local\wizard\diagnostics\aspects\enrolment_aspect_diagnoser;
use bookingextension_agent\local\wizard\diagnostics\aspects\grades_aspect_diagnoser;
use bookingextension_agent\local\wizard\diagnostics\aspects\progress_aspect_diagnoser;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_checklist_preview;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\course\course_context_loader;
use bookingextension_agent\local\wizard\services\target_query_normalizer;
use context_course;

/**
 * Readonly diagnosis of a user's situation in a course — one skill, four aspects.
 *
 * Consolidates the former course.diagnose_access / _enrolment / _progress / _grades skills (see
 * docs/Blueprints/COURSE_DIAGNOSE_SKILL_CONSOLIDATION.md). The selector picks THIS skill; the
 * constructor sets the `aspect` (access | enrolment | progress | grades). Each aspect's verbatim
 * diagnostic logic lives in a per-aspect diagnoser; this skill only resolves course/user/activity and
 * assembles the focused checklist.
 *
 * Readonly-eager + enumerate-then-reason: course resolution falls back to the current course
 * (resolve_readonly_course_context_id); an activity reference that does not match exactly one activity
 * is NOT guessed — the course inventory is handed back so the LLM picks the concrete activityid (or asks).
 *
 * R0/readonly: the engine skips preflight, so all resolution and per-aspect capability gates live here.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_user_in_course_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'course.diagnose_user_in_course';

    /** Valid aspects. */
    private const ASPECTS = ['access', 'enrolment', 'progress', 'grades'];

    /**
     * Constructor. Read-only diagnosis (R0).
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Course-scoped (resolved inside execute, R0 skips preflight).
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }

    /**
     * Read-only.
     *
     * @return bool
     */
    public function is_read_only(): bool {
        return true;
    }

    /**
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            // First 240 characters = selector window (#2419, #2423 DBI-1/DUC-4): open/grade vs book.
            'description' => 'Diagnose a person in a Moodle course (read-only): cannot OPEN it, enrolment incl. cohort sync, '
                . 'completion or a missing grade. Set "aspect" to the facet asked about. Details per aspect: access covers '
                . 'restrictions and visibility; enrolment covers self-enrolment, cohort sync and suspended/expired, and omitting '
                . 'the course lists all of the person\'s courses; grades covers a missing or wrong grade.',
            'is' => 'Access, enrolment, completion and grades in a course.',
            'not' => 'Bookings (mod_booking.diagnose_booking_issue, mod_booking.diagnose_user_booking); roles '
                . '(core.diagnose_permissions); HR feed (local_taskflow.diagnose_import).',
            'readonly' => true,
            'example_utterances' => [
                'why can\'t this student open the quiz',
                'why is the activity greyed out for her',
                'why wasn\'t he auto-enrolled in the course',
                'his enrolment expired and he\'s no longer in the course',
                'which courses is this user enrolled in',
                'list the courses he is enrolled in',
                'how far has this learner progressed in the course',
                'why is the activity not marked complete for her',
                'he completed the quiz but is not advancing to the next activity',
                'why can\'t the student see their grade',
                'her grade for the assignment is missing',
            ],
            'properties' => [
                'aspect' => [
                    'type' => 'string',
                    'description' => 'Which facet to diagnose: "access" (can see/open), "enrolment" (is/was '
                        . 'enrolled), "progress" (activity completion), "grades" (grade items). Pick the one the '
                        . 'user is asking about. Defaults to access.',
                    'required' => false,
                ],
                'userquery' => [
                    'type' => 'string',
                    // Wave 32: since F81 (e08b63b) "me" is no self-reference; an empty field is the requester.
                    'description' => 'Name, e-mail or id of the person, as the user wrote it. Leave it empty when the user '
                        . 'asks about themselves. The system resolves it and asks when several match.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Numeric user id when known. Takes precedence over userquery.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Course name when not the current course. Leave empty for the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id when known. Leave empty for the current course; never guess.',
                    'required' => false,
                ],
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'For aspect=access/progress: the name of a specific activity (e.g. "Quiz 3"). '
                        . 'If it does not match exactly one activity, the course activity list is returned so you can '
                        . 'pick the right one — then re-call with "activityid". Leave empty for a course-wide view.',
                    'required' => false,
                ],
                'activityid' => [
                    'type' => 'integer',
                    'description' => 'For aspect=access/progress: the resolved course-module id of the activity, when '
                        . 'you already identified it from the returned activity list. Takes precedence over activityquery.',
                    'required' => false,
                ],
                'itemquery' => [
                    'type' => 'string',
                    'description' => 'For aspect=grades: the name of a specific grade item (e.g. "Assignment 1"). '
                        . 'Leave empty for a grades overview.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['aspect', 'userquery', 'coursequery', 'activityquery', 'itemquery'],
                'anchor_fields' => ['aspect', 'userquery', 'coursequery', 'activityquery'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['aspect' => 'access', 'userquery' => 'Maria Jones', 'activityquery' => 'Quiz 3'];
    }

    /**
     * Discovery triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.diagnose_user_in_course_request',
                'description' => 'The user asks why a person cannot access or see a course or activity, was not enrolled, has not'
                    . ' completed something, or lacks a grade.',
                'examples' => [
                    'Why can Maria not see Quiz 3?',
                    'Why was Tom not enrolled in the course "Mathematics"?',
                    'Why is the assignment not marked complete for this student?',
                    'Why can\'t she see her grade for the quiz?',
                ],
            ],
        ];
    }

    /**
     * Contextual guidance.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'course.diagnose_user_in_course',
                'triggers' => [
                    'cannot see', 'cannot open', 'no access', 'greyed out', 'not available',
                    'not enrolled', 'auto-enrol', 'cohort', 'enrolment expired',
                    'not complete', 'progress', 'completion',
                    'grade missing', 'wrong grade', 'can\'t see grade',
                ],
                'guidance' => [
                    '- course.diagnose_user_in_course diagnoses a person IN a course (read-only). Set "aspect":',
                    '  access (see/open), enrolment (is/was enrolled), progress (completion), grades (grade items).',
                    '- "How far has X got", "why is X not advancing / not moving to the next activity after',
                    '  completing one", "completion / progress" → aspect=progress. It covers the WHOLE course in',
                    '  ONE call (per-activity completion + unmet completion rules) — do NOT use aspect=access with a',
                    '  single activity for a progress/completion question.',
                    '- Use aspect=access only for "cannot see/open/reach THIS activity" (visibility/availability).',
                    '- For aspect=progress, leave activityquery EMPTY for a whole-course question; set it ONLY when',
                    '  the user explicitly names ONE activity — never invent/guess an activity name.',
                    '- NOT for "cannot book" (mod_booking.diagnose_booking_issue).',
                    '- Identify the person via userquery/userid (default: the asking user). For aspect=access about one',
                    '  activity, pass activityquery (name) OR activityid; if the returned list shows several, re-call',
                    '  with the activityid value from that list.',
                    '- Answer strictly from the returned checklist findings; do not infer rules yourself.',
                ],
            ],
        ];
    }

    /**
     * Structural validation (pure).
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    /**
     * Run the diagnosis (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid Ambient context.
     * @param int   $userid    Acting user.
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $aspect = \core_text::strtolower(trim((string)($input['aspect'] ?? '')));
        if (!in_array($aspect, self::ASPECTS, true)) {
            $aspect = 'access';
        }

        // Resolve target user: explicit id > userquery > (self for user-centric aspects).
        $targetuserid = (int)($input['userid'] ?? 0);
        if ($targetuserid <= 0 && trim((string)($input['userquery'] ?? '')) !== '') {
            $targetuserid = $this->resolve_userid($input, $userid);
            if ($targetuserid <= 0) {
                return $this->error_result(
                    'I could not identify that person. Give a full name, e-mail address or numeric user id.',
                    'user_unresolved'
                );
            }
        }

        // Resolve the target course (eager, readonly).
        $courseid = $this->resolve_readonly_course_context_id($input, $contextid);
        if ($courseid <= 0) {
            // No course named. With a specific person already resolved, do NOT hard-fail: hand the
            // planner the person's enrolled courses as a clarification (status 'executed', not an error
            // row) so a "for each course" request can fan out one diagnosis per course. A hard error
            // here would also wrongly mark an otherwise-successful multi-course turn as failed.
            if ($targetuserid > 0) {
                $overviewuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
                if ($overviewuser && empty($overviewuser->deleted)) {
                    // The enrolment aspect keeps its dedicated cross-course overview. The acting user
                    // is passed so the overview is scoped to the courses that actor may access — a
                    // no-course overview must not expose an arbitrary user's unrelated enrolments.
                    return $aspect === 'enrolment'
                        ? $this->enrolment_overview_result($overviewuser, $userid)
                        : $this->missing_course_clarification_result($overviewuser, $aspect, $userid);
                }
            }
            return $this->error_result(
                'Please tell me which course to check (by name), or open the course first.',
                'missing_course'
            );
        }
        // Wave 32 (DUC-4, L30-L41 + L42sol): with no course named, the ambient course is only a guess. "Bei der
        // Krausler fehlt die Note für die Zertifikatsprüfung" was diagnosed in 10 of 11 threads in the booking
        // activity's course (id 11), where no such grade item exists - a wrong answer counted as clean. When the
        // named item or activity is not in the ambient course, the person's own courses decide.
        // L43 (thread 13315, constructor 83865/83869): the model copied the ambient course id from the runtime
        // context into courseid ({"courseid":11}) although the user named no course - an id equal to the ambient
        // course is the default, not a named course, and must not switch the relocation off.
        $explicitcourseid = (int)($input['courseid'] ?? 0);
        $namedacourse = trim((string)($input['coursequery'] ?? '')) !== ''
            || ($explicitcourseid > 0 && $explicitcourseid !== $this->ambient_course_id($contextid));
        if (!$namedacourse && $targetuserid > 0) {
            $relocation = $this->relocate_to_course_with_named_target($courseid, $aspect, $input, $targetuserid, $userid);
            if (isset($relocation['courseid'])) {
                $courseid = (int)$relocation['courseid'];
            } else if (isset($relocation['candidates'])) {
                $overviewuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
                if ($overviewuser && empty($overviewuser->deleted)) {
                    return $this->missing_course_clarification_result(
                        $overviewuser,
                        $aspect,
                        $userid,
                        (array)$relocation['candidates']
                    );
                }
            }
        }

        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->error_result('That course could not be found.', 'course_not_found');
        }
        $coursecontext = context_course::instance($courseid);

        // Default to self for user-centric aspects (enrolment without a user = method overview).
        if ($targetuserid <= 0 && $aspect !== 'enrolment') {
            $targetuserid = $userid;
        }
        if ($targetuserid > 0) {
            $targetuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
            if (!$targetuser || !empty($targetuser->deleted)) {
                return $this->error_result('That user no longer exists.', 'user_not_found');
            }
        }

        // Enumerate-then-reason for an activity reference (access/progress): resolve to exactly one, else
        // hand the inventory to the LLM. No ordinal/type guessing in code.
        if (in_array($aspect, ['access', 'progress'], true)) {
            $activityquery = trim((string)($input['activityquery'] ?? ''));
            $activityid = (int)($input['activityid'] ?? 0);
            if ($activityquery !== '' || $activityid > 0) {
                $foruser = $targetuserid > 0 ? $targetuserid : $userid;
                $loader = new course_context_loader();
                $inventory = $loader->build_inventory($course, $foruser);
                $resolution = $loader->resolve_activity($inventory, $activityquery, $activityid, '');
                if ($resolution['status'] === 'resolved') {
                    // Feed the diagnoser an exact, unique name so its internal name match is unambiguous.
                    $input['activityquery'] = (string)$resolution['row']['name'];
                } else if ($resolution['status'] === 'unresolved') {
                    return $this->inventory_observation_result($loader, $activityquery, $resolution['candidates']);
                } else {
                    // No activities at all -> let the diagnoser produce its course-wide / no-activity row.
                    unset($input['activityquery']);
                }
            }
        }

        // Run the selected aspect's verbatim diagnoser.
        $links = new diagnostic_link_builder();
        $diagnoser = match ($aspect) {
            'enrolment' => new enrolment_aspect_diagnoser(),
            'progress' => new progress_aspect_diagnoser(),
            'grades' => new grades_aspect_diagnoser(),
            default => new access_aspect_diagnoser(),
        };
        $outcome = $diagnoser->diagnose($course, $courseid, $coursecontext, $targetuserid, $userid, $input, $links);
        if (!empty($outcome['error'])) {
            return $this->error_result(
                (string)$outcome['error']['message'],
                (string)$outcome['error']['error_class']
            );
        }

        return $this->build_result($aspect, $course, $courseid, $targetuserid, $userid, (array)$outcome['rows']);
    }

    /**
     * Assemble the final checklist result (observation + preview rows).
     *
     * @param string $aspect
     * @param \stdClass $course
     * @param int $courseid
     * @param int $targetuserid 0 = course-level (enrolment overview)
     * @param int $actinguserid
     * @param array[] $rows
     * @return array
     */
    private function build_result(
        string $aspect,
        \stdClass $course,
        int $courseid,
        int $targetuserid,
        int $actinguserid,
        array $rows
    ): array {
        $label = [
            'access' => 'Access',
            'enrolment' => 'Enrolment',
            'progress' => 'Progress',
            'grades' => 'Grades',
        ][$aspect] ?? 'Diagnosis';
        $coursename = format_string($course->fullname);

        $subject = 'the course (overview)';
        if ($targetuserid > 0) {
            if ($targetuserid === $actinguserid) {
                $subject = 'you';
            } else {
                $tu = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
                $subject = $tu ? fullname($tu) : ('user #' . $targetuserid);
            }
        }

        $lines = [$label . ' diagnosis for ' . $subject . ' in course "' . $coursename . '" (id=' . $courseid . '):'];
        foreach ($rows as $r) {
            $line = diagnostic_result_builder::glyph((string)$r['status']) . ' ' . $r['check'];
            if (trim((string)$r['finding']) !== '') {
                $line .= ' — ' . $r['finding'];
            }
            if (!empty($r['url'])) {
                $line .= ' (' . $r['url'] . ')';
            }
            $lines[] = $line;
        }
        $lines[] = 'Note: automated ' . \core_text::strtolower($label) . ' check. State only the findings above; '
            . 'do not infer rules beyond them.';

        $usermessage = $label . ' check for ' . $subject . ' in "' . $coursename . '" completed.';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $targetuserid > 0 ? $targetuserid : $courseid,
            'diagnosis' => [
                'aspect' => $aspect,
                'courseid' => $courseid,
                'targetuserid' => $targetuserid,
                'checklist' => $rows,
            ],
            'checklist_rows' => $rows,
            'checklist_title' => $label . ' check: ' . $subject . ' · ' . $coursename,
            'observation_full' => implode("\n", $lines),
        ];
    }

    /**
     * Return the activity inventory as an observation so the LLM can resolve the reference (enumerate-then-reason).
     *
     * @param course_context_loader $loader
     * @param string $activityquery
     * @param array[] $candidates
     * @return array
     */
    private function inventory_observation_result(
        course_context_loader $loader,
        string $activityquery,
        array $candidates
    ): array {
        $observation = $loader->build_resolution_observation($activityquery, $candidates, self::SKILL_NAME);
        $usermessage = 'Several activities could match — picking the right one.';
        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => null,
            'observation_full' => $observation,
            // Not engine-static: the observation repeats the user's activity words and stays masked (the flag never
            // took effect before L43 - execution_feedback_service dropped it).
        ];
    }

    /**
     * Cross-course enrolment overview for a named user when no course was given.
     *
     * Mirrors the former diagnose_enrolment behaviour, reusing {@see core_skill_base} payload helpers so
     * the course list/links stay identical to core.search_users.
     *
     * @param \stdClass $targetuser
     * @param int $actinguserid Acting user, so the overview is scoped to courses they may access.
     * @return array
     */
    private function enrolment_overview_result(\stdClass $targetuser, int $actinguserid): array {
        $targetuserid = (int)$targetuser->id;
        $courses = $this->build_user_courses_payload($targetuserid, $actinguserid);
        $subject = fullname($targetuser);

        $usermessage = !empty($courses)
            ? ($subject . ' is enrolled in ' . count($courses) . ' course(s).')
            : ($subject . ' is not enrolled in any course.');

        $lines = [
            'No course was named — enrolment overview for ' . $subject . ' (id=' . $targetuserid . ').',
            'Enrolled courses (with links): ' . $this->format_course_observation($courses),
            'Note: no specific course was given. To diagnose why ' . $subject . ' is (not) enrolled in a '
                . 'particular course, name that course and re-run; otherwise answer from the overview above.',
        ];

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $targetuserid,
            'enrolment_overview' => [
                'targetuserid' => $targetuserid,
                'courses' => $courses,
            ],
            'observation_full' => implode("\n", $lines),
        ];
    }

    /**
     * No-course clarification for a non-enrolment aspect: hand the planner the person's enrolled
     * courses so a "for each course" request can fan out one diagnosis per course.
     *
     * Returns a NON-error observation (status 'executed'), deliberately not error_result(): a missing
     * course when the person IS known is a clarification, not a failure — a hard error here would mark
     * an otherwise-successful multi-course turn as failed and get its answer discarded.
     *
     * @param \stdClass $targetuser
     * @param string $aspect
     * @param int $actinguserid Acting user, so the course list is scoped to courses they may access.
     * @param array[]|null $onlycourses Course payloads to offer instead of all of the person's courses.
     * @return array
     */
    private function missing_course_clarification_result(
        \stdClass $targetuser,
        string $aspect,
        int $actinguserid,
        ?array $onlycourses = null
    ): array {
        $targetuserid = (int)$targetuser->id;
        $courses = $onlycourses ?? $this->build_user_courses_payload($targetuserid, $actinguserid);
        $subject = fullname($targetuser);

        if (empty($courses)) {
            $usermessage = $subject . ' is not enrolled in any course.';
            $lines = [
                'No course was named for the ' . $aspect . ' diagnosis of ' . $subject
                    . ' (id=' . $targetuserid . '), and they are not enrolled in any course.',
                'Report that there is no course to check.',
            ];
        } else {
            $usermessage = 'Picking which course to check for ' . $subject . '.';
            $lines = [
                'No single course was named for the ' . $aspect . ' diagnosis of ' . $subject
                    . ' (id=' . $targetuserid . ').',
                'Enrolled courses (with links): ' . $this->format_course_observation($courses),
                'To check ' . $aspect . ' for ' . $subject . ' in each course, run one '
                    . self::SKILL_NAME . ' per course passing that course\'s courseid; or name a single course.',
            ];
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $targetuserid,
            'course_clarification' => [
                'targetuserid' => $targetuserid,
                'aspect' => $aspect,
                'courses' => $courses,
            ],
            // Not engine-static: the observation names the person, so it stays masked (HARD RULE anonymizer; the flag
            // never took effect before L43 - execution_feedback_service dropped it).
            'observation_full' => implode("\n", $lines),
        ];
    }

    /**
     * Where the named item or activity of an unnamed course lives (wave 32, DUC-4).
     *
     * Only called when the user named no course. The ambient course keeps the diagnosis when it holds the named
     * target (or when nothing is named). Otherwise the person's own courses - scoped to what the acting user may
     * access - are searched: exactly one holding the target becomes the course; several are returned as
     * candidates for the caller's question; none leaves the ambient course (the diagnoser then says so).
     * Names are compared by {@see target_query_normalizer::name_key()}: shape only, no word is read.
     *
     * @param int $ambientcourseid
     * @param string $aspect
     * @param array $input
     * @param int $targetuserid
     * @param int $actinguserid
     * @return array{courseid?:int,candidates?:array[]}
     */
    private function relocate_to_course_with_named_target(
        int $ambientcourseid,
        string $aspect,
        array $input,
        int $targetuserid,
        int $actinguserid
    ): array {
        // A grade item is named by itemquery; the constructor sometimes puts it into activityquery (L37 thread
        // 11133), and an activity's grade item carries the activity's name, so either field names the target.
        $itemquery = trim((string)($input['itemquery'] ?? ''));
        $activityquery = trim((string)($input['activityquery'] ?? ''));
        $query = $aspect === 'grades'
            ? ($itemquery !== '' ? $itemquery : $activityquery)
            : (in_array($aspect, ['access', 'progress'], true) ? $activityquery : '');
        if ($query === '' || (int)($input['activityid'] ?? 0) > 0) {
            return [];
        }
        if ($ambientcourseid > 0 && $this->course_holds_named_target($ambientcourseid, $aspect, $query)) {
            return [];
        }

        $hits = [];
        foreach ($this->build_user_courses_payload($targetuserid, $actinguserid) as $candidate) {
            $candidateid = (int)($candidate['courseid'] ?? 0);
            if (
                $candidateid > 0 && $candidateid !== $ambientcourseid
                    && $this->course_holds_named_target($candidateid, $aspect, $query)
            ) {
                $hits[] = $candidate;
            }
        }
        if (count($hits) === 1) {
            return ['courseid' => (int)$hits[0]['courseid']];
        }
        return count($hits) > 1 ? ['candidates' => $hits] : [];
    }

    /**
     * The id of the course the ambient context lies in (0 when it lies in none, e.g. the system context).
     *
     * @param int $contextid
     * @return int
     */
    private function ambient_course_id(int $contextid): int {
        try {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            $coursecontext = $context ? $context->get_course_context(false) : false;
        } catch (\Throwable $e) {
            return 0;
        }
        return $coursecontext ? (int)$coursecontext->instanceid : 0;
    }

    /**
     * Whether a course holds a grade item (grades) or an activity (access/progress) carrying the queried name.
     *
     * @param int $courseid
     * @param string $aspect
     * @param string $query
     * @return bool
     */
    private function course_holds_named_target(int $courseid, string $aspect, string $query): bool {
        global $CFG;

        $needle = target_query_normalizer::name_key($query);
        if ($needle === '') {
            return false;
        }
        $names = [];
        try {
            if ($aspect === 'grades') {
                require_once($CFG->libdir . '/gradelib.php');
                foreach (\grade_item::fetch_all(['courseid' => $courseid]) ?: [] as $item) {
                    $names[] = (string)$item->get_name();
                }
            } else {
                foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
                    $names[] = (string)$cm->name;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        foreach ($names as $name) {
            $key = target_query_normalizer::name_key($name);
            if ($key !== '' && str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render the checklist preview.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $rows = (array)($resultentry['checklist_rows'] ?? []);
        if (empty($rows)) {
            return null;
        }
        return (new diagnostic_checklist_preview())->render(
            $rows,
            (string)($resultentry['checklist_title'] ?? ''),
            ['courseid' => (int)($resultentry['diagnosis']['courseid'] ?? 0)]
        );
    }

    /**
     * Build an error result (error-messaging contract: carries an error_class for the synchronizer).
     *
     * @param string $message
     * @param string $errorclass
     * @return array
     */
    private function error_result(string $message, string $errorclass): array {
        return diagnostic_result_builder::error_result($message, $errorclass, 'Diagnosis could not run: ');
    }
}

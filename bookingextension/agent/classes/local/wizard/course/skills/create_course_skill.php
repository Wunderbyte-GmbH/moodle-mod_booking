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

use bookingextension_agent\local\wizard\preflight_clarification;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\queue_identity_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use core_course_category;
use moodle_url;

/**
 * Dedicated skill: create a new Moodle course (course.create_course).
 *
 * The target is a course CATEGORY, not an existing course, so this skill does not use the
 * course-targeted trait: the category is resolved skill-internally in preflight (U1a) — an
 * unambiguous categoryquery (or exactly one writable category) resolves silently, anything
 * else asks with a candidate list. Category policy is ASK, never a hardwired default (§4/1).
 *
 * First link of the compound authoring chain
 * (create_course → scaffold_course_content → create_selflearning_option with
 * linkedcoursequery); the created courseid/fullname travel via the execution observation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course_skill extends core_skill_base implements
    queue_identity_provider_interface,
    skill_trigger_provider_interface {
    use preflight_clarification;

    /** Skill name. */
    public const SKILL_NAME = 'course.create_course';

    /** Maximum category candidates listed in a clarification. */
    private const MAX_CATEGORY_CANDIDATES = 10;

    /**
     * Constructor. Mutating skill (creates a course) — broad write, requires confirmation.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
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
     * Course creation is a site-level action: the category is resolved in preflight.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_SYSTEM;
    }

    /**
     * Native capability required to create a course (Gate 2), checked at the resolved
     * category context in preflight.
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/course:create'];
    }

    /**
     * Human-readable preview of the course to be created (tier-3 confirmation preview).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $rows = [
            ['label' => 'Course name', 'value' => (string)($input['fullname'] ?? '')],
            ['label' => 'Short name', 'value' => (string)($input['shortname'] ?? '')],
            ['label' => 'Category', 'value' => (string)($input['categoryname'] ?? '')],
            ['label' => 'Visible', 'value' => empty($input['visible']) ? 'no' : 'yes'],
        ];
        $summary = trim((string)($input['summary'] ?? ''));
        if ($summary !== '') {
            $rows[] = ['label' => 'Summary', 'value' => $summary];
        }

        return [
            'title' => 'Create course "' . (string)($input['fullname'] ?? '') . '"',
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Business identity for queue deduplication: one course per fullname+category.
     *
     * @param array $input
     * @return array<string,mixed>
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task' => self::SKILL_NAME,
            'fullname' => trim((string)($input['fullname'] ?? '')),
            'category' => trim((string)($input['categoryquery'] ?? '')),
        ];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return $this->enrich_schema_with_prompt_meta([
            'version' => 1,
            'description' => 'Create a NEW Moodle course (the course container itself). Use when the user asks to create/set up a '
                . 'course, e.g. "create a course about the Vikings". The system asks which course category to use unless the user '
                . 'named one or only one is available. This creates an EMPTY course — content is added afterwards.',
            'is' => 'The course container.',
            // Wave 32 (SCC-4, mutual fence with course.scaffold_course_content; owner of this card: group A1).
            'not' => 'Booking options or activities inside an existing course (add_activity, mod_booking.create_option); '
                . 'content for an existing course (scaffold_course_content).',
            'readonly' => false,
            'example_utterances' => [
                'Create a new course about the life of the Vikings',
                'Set up an empty course called Onboarding 2027',
                'Ich brauche einen neuen Kurs zum Thema Erste Hilfe',
                'Create a course and make it bookable',
            ],
            'properties' => [
                'fullname' => [
                    'type' => 'string',
                    'description' => 'The full course name/title. Required.',
                    'required' => true,
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Optional short course description shown in course listings. Compose 1-3 '
                        . 'sentences from the user\'s topic when none was given verbatim.',
                    'required' => false,
                ],
                'categoryquery' => [
                    'type' => 'string',
                    'description' => 'The course category the user named, verbatim. Leave empty when none was '
                        . 'named — the system then resolves it (asking when several categories are available). '
                        . 'Never guess or invent a category name.',
                    'required' => false,
                ],
                'shortname' => [
                    'type' => 'string',
                    'description' => 'Optional unique short name. Leave empty to derive one from the full name.',
                    'required' => false,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Whether the course is visible to students (default true).',
                    'required' => false,
                ],
                'override' => [
                    'type' => 'array',
                    'description' => 'Explicit override tokens for confirmed exceptions (e.g. duplicate_fullname).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['fullname', 'summary', 'categoryquery'],
                'anchor_fields' => ['categoryquery'],
                'context_scopes' => ['system'],
            ],
        ]);
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['fullname' => 'Das Leben der Wikinger', 'summary' => 'Alltag, Seefahrt und Kultur der Wikinger.'];
    }

    /**
     * Message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.create_course_request',
                'description' => 'The user wants a new Moodle course created; a course that should also be bookable starts here,'
                    . ' the booking option follows.',
            ],
        ];
    }

    /**
     * Contextual guidance for the construction prompt.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'course.create_course',
                'triggers' => ['create a course', 'new course', 'kurs anlegen', 'kurs erstellen'],
                'guidance' => [
                    '- course.create_course creates the EMPTY course container only. Plan content'
                        . ' (course.scaffold_course_content / add_activity / add_quiz) and bookability'
                        . ' (via mod_booking.create_option with linkedcoursequery) as separate planned steps.',
                    '- Pass categoryquery ONLY when the user named a category; the system otherwise'
                        . ' resolves or asks. Never invent category names.',
                    '- Compose a 1-3 sentence summary from the user\'s topic when none was given.',
                ],
            ],
        ];
    }

    /**
     * Structural validation (pure).
     *
     * @param array $input
     * @return array{valid:bool,errors:string[]}
     */
    public function check_structure(array $input): array {
        // F3 two-channel cause: errors = user_cause, repair = the planner instruction.
        // The user_cause is plain-English LLM MATERIAL, never rendered directly — the
        // synchronizer formulates the reply in the user's language (hard rule: language
        // agnosticism). RECOVERABLE_INPUT_ERROR routes it as a real clarification turn.
        if (trim((string)($input['fullname'] ?? '')) === '') {
            return [
                'valid' => false,
                'errors' => ['What should the course be called?'],
                'repair' => ['fullname is required: the course needs a name.'],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            ];
        }
        return ['valid' => true, 'errors' => [], 'repair' => []];
    }

    /**
     * Resolve category + shortname and gate on the native capability (read-only).
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        global $DB;

        $fullname = trim((string)($input['fullname'] ?? ''));
        if ($fullname === '') {
            return $this->clarify('What should the course be called?', 'CREATE_COURSE_NAME_REQUIRED');
        }

        // Duplicate full name is legal in Moodle but usually an accident — soft-block once, and BEFORE the
        // category question: baseline runs 25-32, SCC-4 ("make a finished course out of 'Winter School 2027'")
        // was asked which of five categories to use while the course existed. Whether to fill it or really
        // create a second one is the decisive question; the category is not.
        $overrides = array_map('strval', is_array($input['override'] ?? null) ? $input['override'] : []);
        if (
            !in_array('duplicate_fullname', $overrides, true)
            && $DB->record_exists('course', ['fullname' => $fullname])
        ) {
            $existing = [];
            $courses = $DB->get_records('course', ['fullname' => $fullname], 'id ASC', 'id, fullname, shortname', 0, 5);
            foreach ($courses as $course) {
                $existing[] = [
                    'id' => (int)$course->id,
                    'label' => (string)$course->fullname,
                    'shortname' => (string)$course->shortname,
                ];
            }
            return $this->invalid([
                [
                    'code' => 'DUPLICATE_COURSE_FULLNAME_CONFIRM_REQUIRED',
                    'severity' => 'needs_confirmation',
                    'message' => 'A course named "' . $fullname . '" already exists.',
                    'user_question' => 'A course with this exact name already exists. Create another one anyway?',
                    'remedy_options' => ['CONFIRM_CREATE_WITH_DUPLICATE_FULLNAME', 'USE_EXISTING_COURSE'],
                ],
                // The existing course is offered as a choice: filling it is another skill's job.
                [
                    'code' => 'DUPLICATE_COURSE_FULLNAME_EXISTING_OFFERED',
                    'severity' => 'needs_clarification',
                    'message' => 'A course named "' . $fullname . '" already exists. Content for it is '
                        . 'course.scaffold_course_content with this courseid; a second course with the same name needs '
                        . 'the user\'s yes.',
                    'field' => 'courseid',
                    'candidates' => $existing,
                ],
            ]);
        }

        $category = $this->resolve_category(trim((string)($input['categoryquery'] ?? '')), $userid);
        if (isset($category['clarify'])) {
            return $category['clarify'];
        }
        $categoryid = (int)$category['id'];
        $categoryname = (string)$category['name'];

        $shortname = trim((string)($input['shortname'] ?? ''));
        if ($shortname !== '' && $DB->record_exists('course', ['shortname' => $shortname])) {
            return $this->clarify(
                'The short name "' . $shortname . '" is already taken. Please provide a different one '
                . '(or leave it empty to derive one automatically).',
                'CREATE_COURSE_SHORTNAME_TAKEN'
            );
        }
        if ($shortname === '') {
            $shortname = $this->derive_unique_shortname($fullname);
        }

        return $this->pass([
            'fullname' => $fullname,
            'shortname' => $shortname,
            'categoryid' => $categoryid,
            'categoryname' => $categoryname,
            'summary' => trim((string)($input['summary'] ?? '')),
            'visible' => array_key_exists('visible', $input) ? (int)(bool)$input['visible'] : 1,
            'outputlang' => trim((string)($input['outputlang'] ?? '')),
        ]);
    }

    /**
     * Create the course.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $fullname = (string)($preparedinput['fullname'] ?? '');
        $categoryid = (int)($preparedinput['categoryid'] ?? 0);
        if ($fullname === '' || $categoryid <= 0) {
            return [
                'status' => 'error',
                'detail' => 'Missing prepared course name or category.',
                'usermessage' => 'Missing prepared course name or category.',
                'resultid' => null,
                'observation_full' => 'Missing prepared course name or category.',
            ];
        }

        try {
            $course = create_course((object)[
                'fullname' => $fullname,
                'shortname' => (string)($preparedinput['shortname'] ?? ''),
                'category' => $categoryid,
                'summary' => (string)($preparedinput['summary'] ?? ''),
                'summaryformat' => FORMAT_HTML,
                'visible' => (int)($preparedinput['visible'] ?? 1),
            ]);
        } catch (\Throwable $e) {
            $message = 'Could not create the course: ' . $e->getMessage();
            return [
                'status' => 'error',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
                'observation_full' => $message,
            ];
        }

        // B3/G5 creatornewroleid parity: the Moodle course-creation UI enrols the creator into
        // the new course with $CFG->creatornewroleid so they can see and manage what they just
        // made. create_course() itself does NOT, so a non-admin agent creator would get no role —
        // and booking::load_courses (which linkedcoursequery resolves through) only lists courses
        // the user may manually enrol into, hiding the fresh course from its own creator. Mirror
        // the UI so the create -> link chain works for non-admins. Non-fatal: the course exists.
        global $CFG;
        try {
            $coursecontext = \context_course::instance((int)$course->id);
            // Enrol the creator with creatornewroleid unless they are a site admin (who already
            // sees and manages every course via the admin bypass) or already enrolled. NB: the
            // Moodle UI additionally skips when is_viewing() is true, but that only means the user
            // can VIEW the course — it does not grant enrol/manual:enrol, which booking::load_courses
            // (behind linkedcoursequery) filters on. A non-admin creator with a site-level
            // course:view therefore still needs the enrolment to resolve their own course (B3).
            $needscreatorenrol = !empty($CFG->creatornewroleid)
                && !is_siteadmin($userid)
                && !is_enrolled($coursecontext, $userid);
            if ($needscreatorenrol) {
                enrol_try_internal_enrol((int)$course->id, $userid, (int)$CFG->creatornewroleid);
            }
        } catch (\Throwable $e) {
            unset($e);
        }

        // Baseline of Moodle's own default activities (e.g. the announcements forum the
        // course_created observer adds): downstream skills treat these as EXPECTED and must
        // not mistake the fresh course for a non-empty one (expected-activities contract,
        // blueprint F2; a template-manifest source joins this channel in V2).
        $baselinecmids = [];
        try {
            foreach (get_fast_modinfo($course)->get_cms() as $cm) {
                $baselinecmids[] = (int)$cm->id;
            }
        } catch (\Throwable $e) {
            $baselinecmids = [];
        }

        $courseurl = (new moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false);
        $detail = 'Course created (fullname="' . $course->fullname . '", id=' . (int)$course->id
            . ', category="' . (string)($preparedinput['categoryname'] ?? '') . '", link=' . $courseurl . ').';

        return [
            'status' => 'executed',
            'detail' => $detail,
            'usermessage' => $detail,
            'resultid' => (int)$course->id,
            'observation_full' => $detail . "\n"
                . 'The course has no content yet'
                . (empty($baselinecmids)
                    ? ''
                    : ' (Moodle auto-created ' . count($baselinecmids) . ' default activity(ies))')
                . '. Planned follow-up steps (content, bookable option via '
                . 'linkedcoursequery="' . $course->fullname . '") can now target it by this exact full name.',
            'produced_outputs' => [
                'courseid' => (int)$course->id,
                'coursefullname' => (string)$course->fullname,
                'courseshortname' => (string)$course->shortname,
                'baseline_cmids' => $baselinecmids,
            ],
        ];
    }

    /**
     * Resolve the target category: named query > single writable category > ask (§4/1).
     *
     * @param string $categoryquery
     * @param int $userid
     * @return array{id?:int,name?:string,clarify?:array}
     */
    private function resolve_category(string $categoryquery, int $userid): array {
        $writable = core_course_category::make_categories_list('moodle/course:create');
        if (empty($writable)) {
            return ['clarify' => $this->clarify(
                'You have no course category where you may create courses.',
                'CREATE_COURSE_NO_WRITABLE_CATEGORY'
            )];
        }

        if ($categoryquery !== '') {
            // Numeric queries are category ids — the clarification lists them as "Name (id N)",
            // so "2" or "id 2" must resolve (thread 585: "Id 2" bounced although the reply
            // itself offered answering by id).
            if (preg_match('/^(?:id\s*)?(\d+)$/i', $categoryquery, $idmatch)) {
                $categoryid = (int)$idmatch[1];
                if (isset($writable[$categoryid])) {
                    return ['id' => $categoryid, 'name' => (string)$writable[$categoryid]];
                }
            }
            $matches = [];
            $needle = \core_text::strtolower($categoryquery);
            foreach ($writable as $id => $name) {
                if (str_contains(\core_text::strtolower((string)$name), $needle)) {
                    $matches[$id] = (string)$name;
                }
            }
            if (count($matches) === 1) {
                return ['id' => (int)array_key_first($matches), 'name' => reset($matches)];
            }
            $candidates = empty($matches) ? $writable : $matches;
            return ['clarify' => $this->clarify(
                (empty($matches)
                    ? 'No category matched "' . $categoryquery . '". '
                    : 'Several categories match "' . $categoryquery . '". ')
                . 'Which one should the course go into? '
                . $this->format_category_candidates($candidates),
                'CREATE_COURSE_CATEGORY_AMBIGUOUS'
            )];
        }

        if (count($writable) === 1) {
            return ['id' => (int)array_key_first($writable), 'name' => (string)reset($writable)];
        }

        return ['clarify' => $this->clarify(
            'Which course category should the new course go into? '
            . $this->format_category_candidates($writable),
            'CREATE_COURSE_CATEGORY_REQUIRED'
        )];
    }

    /**
     * Format up to MAX_CATEGORY_CANDIDATES category names for a clarification question.
     *
     * @param array $categories id => hierarchical name
     * @return string
     */
    private function format_category_candidates(array $categories): string {
        $entries = [];
        foreach ($categories as $id => $name) {
            $entries[] = (string)$name . ' (id ' . (int)$id . ')';
        }
        $shown = array_slice($entries, 0, self::MAX_CATEGORY_CANDIDATES);
        $suffix = count($entries) > self::MAX_CATEGORY_CANDIDATES
            ? ' (and ' . (count($entries) - self::MAX_CATEGORY_CANDIDATES) . ' more)'
            : '';
        return 'Available: ' . implode('; ', $shown) . $suffix . '.';
    }

    /**
     * Derive a unique shortname from the full name.
     *
     * @param string $fullname
     * @return string
     */
    private function derive_unique_shortname(string $fullname): string {
        global $DB;

        $base = trim(preg_replace('/[^a-z0-9]+/', '-', \core_text::strtolower($fullname)) ?? '', '-');
        $base = \core_text::substr($base !== '' ? $base : 'course', 0, 80);

        $candidate = $base;
        $suffix = 2;
        while ($DB->record_exists('course', ['shortname' => $candidate])) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }
        return $candidate;
    }
}

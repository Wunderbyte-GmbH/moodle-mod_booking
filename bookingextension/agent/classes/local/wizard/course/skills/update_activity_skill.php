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
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\activities\activity_creation_service;
use bookingextension_agent\local\wizard\services\activities\activity_preview_renderer;
use bookingextension_agent\local\wizard\services\activities\module_catalog_service;
use bookingextension_agent\local\wizard\services\activities\module_form_contract;
use bookingextension_agent\local\wizard\services\activities\section_resolver_service;
use bookingextension_agent\local\wizard\services\activity_preview_builder;
use bookingextension_agent\local\wizard\services\target_query_normalizer;
use context;
use context_course;

/**
 * Generic skill: edit an existing activity in a course (course.update_activity).
 *
 * Partial update: changes only the fields the user provides (name / intro / visibility / module-specific
 * settings); everything else keeps its current value (sourced from the activity's real mod_form via
 * get_moduleinfo_data). The same headless mod_form is the validation contract, then update_moduleinfo()
 * applies the change. No engine changes — clarifications (which activity? bad field?) and the created
 * preview all travel over the existing generic channels. R2 (confirm before mutation).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_activity_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    use preflight_clarification;

    /** Skill name. */
    public const SKILL_NAME = 'course.update_activity';

    /** How many update attempts before giving up (guards transient DB errors). */
    private const MAX_RETRIES = 2;

    /** Token search: tokens shorter than this match too much to be searched on their own. */
    private const MIN_TOKEN_CHARS = 3;

    /** Token search: at most this many query tokens are searched. */
    private const MAX_QUERY_TOKENS = 8;

    /** Name match: every token of the activity name stands in the user's words. */
    public const MATCH_NAME_TOKENS = 1;

    /** Name match: the whole query, compared by shape, is part of the activity name. */
    public const MATCH_WHOLE_QUERY = 2;

    /**
     * Constructor. Mutating skill (edits a course module) — broad write, requires confirmation.
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
     * Human-readable preview of the activity update (tier-3): target + changed fields.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return activity_preview_builder::update_activity_descriptor($input);
    }

    /**
     * Activities live in a course.
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
     * Native capability required to edit an activity (Gate 2).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/course:manageactivities'];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Edit / change an existing activity or resource in a course — rename it, change its description, show '
                . 'or hide it, change a module-specific setting (e.g. a URL\'s link), or MOVE it to a different section/topic. Use '
                . 'for "rename the page to X", "hide the forum", "change the activity\'s URL", "hide the quiz", "move the label to '
                . 'section 2", "move the page one section down". Only the fields you give are changed.',
            'is' => 'Changing an activity that already exists: name, description, visibility, section, a module setting.',
            // UQ-2 (L47 thread 16040): a quiz request came here, and this skill cannot edit quizzes. A/B at the recorded
            // selector call: update_quiz 18 -> 20 of 20 with the mutual fence, control UA-2 unchanged 20/20.
            'not' => 'Creating a new activity (add_activity); a quiz or test (course.update_quiz); the settings of a '
                . 'booking activity (mod_booking.configure_booking_instance).',
            'readonly' => false,
            'example_utterances' => [
                'rename the Welcome page to Course intro',
                'hide the forum from students',
                'change the description of the folder',
                'make the link point to a new URL',
                'show the page that is currently hidden',
                'move the label to section 1',
                'move the page one section down',
            ],
            'properties' => [
                'activityquery' => [
                    'type' => 'string',
                    // Wave 32 (UA-3, L41 thread 12704, call 80224): the constructor sees 160 characters of this
                    // text. The old one was cut after "Omit only when editing the activity of the", and inside a
                    // booking activity the constructor asked for "the parameter field of the booking activity"
                    // instead of passing the user's words for the link. Both halves now fit the window.
                    'description' => 'The activity as the user names or describes it, in their words; other courses are '
                        . 'searched too. Leave it empty only for the activity whose page the user is on.',
                    'required' => false,
                ],
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id of the activity, when already known. Never guess.',
                    'required' => false,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New name/title. Leave empty to keep the current name.',
                    'required' => false,
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'New description/intro text. Leave empty to keep the current description.',
                    'required' => false,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Set true to show the activity, false to hide it. Omit to keep current visibility.',
                    'required' => false,
                ],
                'settings' => [
                    'type' => 'object',
                    'description' => 'Module-specific fields to change, as an object. Example: for a URL '
                        . '{"externalurl":"https://…"}; for a Page {"content":"…"}. Only set what should change. '
                        . 'This is NOT for moving the activity — use "section" for that.',
                    'required' => false,
                ],
                'section' => [
                    'type' => 'integer',
                    'description' => 'Section the activity moves to, as a number: "the top section" = 0, "section 2" = 2. '
                        . 'For "one section down/up" use sectiondelta. Omit to leave it.',
                    'required' => false,
                ],
                'sectiondelta' => [
                    'type' => 'integer',
                    'description' => 'Move the activity RELATIVE to where it is now: 1 = one section down, '
                        . '-1 = one section up, 2 = two down. Use this whenever the user describes the movement '
                        . 'relative ("one section down") instead of naming a number — you do not need to know the '
                        . 'current section, this skill resolves it. Do not combine with "section".',
                    'required' => false,
                ],
                'position' => [
                    'type' => 'string',
                    'enum' => ['up', 'down', 'top', 'bottom'],
                    'description' => 'Order INSIDE its own section only: up/down = one place, top = first, bottom = last. '
                        . '"To the top section" or to another section: use section, not this.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Target a DIFFERENT course than the current one, ONLY when the user names one. '
                        . 'The system resolves the name; no course.search_courses lookup first. Leave empty otherwise.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the target course, when already known. Leave empty for the current '
                        . 'course; never guess.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['activityquery', 'name', 'intro', 'visible', 'settings', 'section', 'sectiondelta',
                    'position'],
                'anchor_fields' => ['activityquery', 'coursequery'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['activityquery' => 'Welcome page', 'name' => 'Course introduction'];
    }

    /**
     * Message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.update_activity_request',
                'description' => 'The user wants an existing activity or resource changed: renamed, described differently, shown'
                    . ' or hidden, a setting changed, or moved to another section.',
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
                'id' => 'course.update_activity',
                'triggers' => [
                    'rename', 'hide', 'show', 'unhide',
                    'make visible', 'change the', 'edit the', 'update the activity',
                    'change the url', 'rename page', 'hide forum',
                    'move', 'verschieben', 'section', 'one section down', 'one section up',
                ],
                'guidance' => [
                    '- course.update_activity edits an EXISTING activity; to create a new one use course.add_activity.',
                    '- Identify the activity via activityquery (its current name) or cmid; if unclear the system asks.',
                    '- Set only the fields that should change (name, intro, visible, or settings{}); omit the rest —',
                    '  they keep their current value. Do NOT invent values.',
                    '- To MOVE the activity to another section/topic, set "section" to the target section number.',
                    '  For a RELATIVE movement ("one section down/up") set "sectiondelta" instead (1 = down, -1 = up);',
                    '  you do not know the current section and must not ask for it. Never use settings{} to move.',
                    '- To move it WITHIN its section set "position": up | down | top | bottom.',
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
        $errors = [];
        if (isset($input['settings']) && $input['settings'] !== '' && !is_array($input['settings'])) {
            $errors[] = 'settings must be an object of module-specific fields.';
        }
        if (isset($input['position']) && $input['position'] !== '' && $input['position'] !== null) {
            if (!in_array((string)$input['position'], self::POSITION_MOVES, true)) {
                $errors[] = 'position must be one of: ' . implode(', ', self::POSITION_MOVES) . '.';
            }
        }
        $hasdelta = isset($input['sectiondelta']) && $input['sectiondelta'] !== '' && $input['sectiondelta'] !== null;
        if ($hasdelta) {
            if (!is_numeric($input['sectiondelta']) || (int)$input['sectiondelta'] != $input['sectiondelta']) {
                $errors[] = 'sectiondelta must be a whole number (1 = one section down, -1 = one section up).';
            }
            if (isset($input['section']) && $input['section'] !== '' && $input['section'] !== null) {
                $errors[] = 'Use either section (absolute) or sectiondelta (relative), not both.';
            }
        }
        if (isset($input['section']) && $input['section'] !== '' && $input['section'] !== null) {
            if (!is_numeric($input['section']) || (int)$input['section'] < 0) {
                $errors[] = 'section must be a non-negative section number.';
            }
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Deep validation: resolve course + target activity + the requested changes (read-only).
     *
     * @param array $input
     * @param int   $contextid Operating context (target course context when one was named).
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return $this->clarify(
                'Editing activities works inside a course. Please open a course, or name one.',
                'UPDATE_ACTIVITY_NO_COURSE'
            );
        }
        if (!has_capability('moodle/course:manageactivities', $coursecontext, $userid)) {
            return $this->clarify(
                get_string('nopermissions', 'error', 'moodle/course:manageactivities'),
                'NO_NATIVE_CAPABILITY'
            );
        }
        $course = get_course($coursecontext->instanceid);

        // Resolve the target course module.
        $cmresolution = $this->resolve_target_cm($course, $context, $input, $userid);
        if (is_array($cmresolution)) {
            return $cmresolution;
        }
        $cm = $cmresolution;
        $modname = (string)$cm->modname;

        // The activity may live in another course than the one we are sitting in (run 23). The right
        // to edit it is decided THERE, not here.
        if ((int)$cm->course !== (int)$course->id) {
            $course = get_course((int)$cm->course);
            $coursecontext = context_course::instance((int)$cm->course);
            if (!has_capability('moodle/course:manageactivities', $coursecontext, $userid)) {
                return $this->clarify(
                    get_string('nopermissions', 'error', 'moodle/course:manageactivities'),
                    'NO_NATIVE_CAPABILITY'
                );
            }
        }

        // Collect the requested field changes (name / intro / visibility / module settings).
        $changes = $this->collect_changes($input);

        // A section move is a course-structure operation, resolved separately from the mod_form fields.
        $sectionmove = $this->resolve_section_move($input, $course, $cm);
        if (is_array($sectionmove)) {
            return $sectionmove;
        }

        if (empty($changes) && $sectionmove === null && trim((string)($input['position'] ?? '')) === '') {
            return $this->clarify(
                'What should I change about "' . format_string($cm->name) . '"? (name, description, visibility, '
                    . 'a module setting, or move it to another section)',
                'UPDATE_ACTIVITY_NO_CHANGES'
            );
        }

        $cmrecord = get_coursemodule_from_id('', (int)$cm->id, (int)$course->id, false, IGNORE_MISSING);
        if (!$cmrecord) {
            return $this->clarify('That activity could not be loaded.', 'UPDATE_ACTIVITY_CM_GONE');
        }

        // Validate the field changes against the activity's real mod_form (only when there are any).
        if (!empty($changes)) {
            $validation = (new module_form_contract())->validate_update($course, $cmrecord, $changes);
            if (!$validation['ok'] && !empty($validation['errors'])) {
                return $this->clarify(
                    $this->format_field_errors($modname, $validation['errors']),
                    'UPDATE_ACTIVITY_FIELDS_INVALID'
                );
            }
        }

        return $this->pass([
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'modname' => $modname,
            'changes' => $changes,
            'section_move' => $sectionmove,
            'position_move' => trim((string)($input['position'] ?? '')),
            'before' => [
                'name' => (string)$cm->name,
                'visible' => (int)$cm->visible,
                'section' => (int)$cm->sectionnum,
            ],
        ]);
    }

    /**
     * Apply the change, retrying once on a transient failure.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $courseid = (int)($preparedinput['courseid'] ?? 0);
        $cmid = (int)($preparedinput['cmid'] ?? 0);
        $changes = (array)($preparedinput['changes'] ?? []);
        $sectionmove = $preparedinput['section_move'] ?? null;
        $sectionmove = ($sectionmove === null) ? null : (int)$sectionmove;
        $hasposition = trim((string)($preparedinput['position_move'] ?? '')) !== '';
        if ($courseid <= 0 || $cmid <= 0 || (empty($changes) && $sectionmove === null && !$hasposition)) {
            return $this->build_error_result('Missing prepared activity or changes for the update.');
        }

        try {
            $course = get_course($courseid);
            $cmrecord = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
        } catch (\Throwable $e) {
            return $this->build_error_result('The activity to edit could not be loaded.');
        }

        $updater = new activity_creation_service();

        // 1) Field changes (name / intro / visibility / settings), retrying once on a transient failure.
        $updated = null;
        $attempts = 1;
        if (!empty($changes)) {
            $contract = new module_form_contract();
            $lasterror = '';
            for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
                try {
                    $moduleinfo = $contract->build_prepared_update_moduleinfo($course, $cmrecord, $changes);
                    $updated = $updater->update($cmrecord, $moduleinfo, $course);
                    $attempts = $attempt;
                    break;
                } catch (\Throwable $e) {
                    $lasterror = $e->getMessage();
                    $updated = null;
                }
            }
            if ($updated === null) {
                return $this->build_error_result(
                    'Could not update the activity after ' . self::MAX_RETRIES . ' attempt(s). Last error: ' . $lasterror
                );
            }
        }

        // 2) Section move (course-structure op) — re-read the cm so its current section id is fresh.
        $movedto = null;
        if ($sectionmove !== null) {
            try {
                $cmrecord = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
                $movedto = $updater->move_to_section($cmrecord, $sectionmove, $course);
            } catch (\Throwable $e) {
                return $this->build_error_result(
                    'Could not move the activity to section ' . $sectionmove . '. ' . $e->getMessage()
                );
            }
        }

        // 3) Ordering inside the section (up / down / top / bottom). Runs after a section move so the
        // order is computed in the section the activity ends up in.
        $position = trim((string)($preparedinput['position_move'] ?? ''));
        if ($position !== '') {
            try {
                $cmrecord = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
                $this->apply_position_move($course, $cmrecord, $position);
            } catch (\Throwable $e) {
                return $this->build_error_result('Could not move the activity within its section. ' . $e->getMessage());
            }
        }

        // Move-only update: synthesize the descriptor from the (moved) module.
        if ($updated === null) {
            $updated = $this->describe_current_module($course, $cmid);
        }

        return $this->build_success_result(
            $updated,
            $changes,
            (array)($preparedinput['before'] ?? []),
            $attempts,
            $movedto
        );
    }

    /**
     * Reorder the activity inside its own section.
     *
     * @param \stdClass $course
     * @param \stdClass $cmrecord
     * @param string $move One of POSITION_MOVES.
     * @return void
     */
    private function apply_position_move(\stdClass $course, \stdClass $cmrecord, string $move): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $modinfo = get_fast_modinfo($course);
        $sectionnum = (int)$cmrecord->sectionnum;
        $order = array_values(array_map('intval', (array)($modinfo->sections[$sectionnum] ?? [])));
        $target = self::target_position_index($order, (int)$cmrecord->id, $move);
        $current = array_search((int)$cmrecord->id, $order, true);
        if ($target === null || $current === false || $target === $current) {
            return;
        }

        // Moodle places the module BEFORE the one handed to moveto_module(); moving to the end passes null.
        $remaining = array_values(array_filter($order, static fn(int $id): bool => $id !== (int)$cmrecord->id));
        $beforeid = $remaining[$target] ?? null;
        $beforemod = $beforeid === null
            ? null
            : get_coursemodule_from_id('', (int)$beforeid, (int)$course->id, false, IGNORE_MISSING);
        $section = $modinfo->get_section_info($sectionnum);
        moveto_module($cmrecord, $section, $beforemod ?: null);
    }

    /** Movements within a section. */
    public const POSITION_MOVES = ['up', 'down', 'top', 'bottom'];

    /**
     * Index the activity should end up at inside its section.
     *
     * @param int[] $order Course module ids in their current order within the section.
     * @param int $cmid Module being moved.
     * @param string $move One of POSITION_MOVES.
     * @return int|null Target index, or null when the module is not in this section.
     */
    public static function target_position_index(array $order, int $cmid, string $move): ?int {
        $order = array_values(array_map('intval', $order));
        $current = array_search($cmid, $order, true);
        if ($current === false) {
            return null;
        }
        $last = count($order) - 1;
        switch ($move) {
            case 'up':
                return max(0, $current - 1);
            case 'down':
                return min($last, $current + 1);
            case 'top':
                return 0;
            case 'bottom':
                return $last;
            default:
                return $current;
        }
    }

    /**
     * Target section for a relative movement, clamped to the sections the course has.
     *
     * @param int $current Section the activity is in.
     * @param int $delta Requested movement (1 = one down, -1 = one up).
     * @param int $lastsection Highest existing section number.
     * @return int
     */
    public static function target_section_for_delta(int $current, int $delta, int $lastsection): int {
        return max(0, min($lastsection, $current + $delta));
    }

    /**
     * Resolve the target section of a move, absolute or relative.
     *
     * @param array $input
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return int|array|null Target section, a clarification payload, or null when nothing moves.
     */
    private function resolve_section_move(array $input, \stdClass $course, \cm_info $cm) {
        $raw = $input['section'] ?? null;
        $delta = $input['sectiondelta'] ?? null;
        if (($raw === null || $raw === '' || !is_numeric($raw)) && ($delta === null || $delta === '' || !is_numeric($delta))) {
            return null;
        }
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            // Relative movement: the model states the direction, the skill knows where the activity is.
            $last = (new section_resolver_service())->last_section_number($course);
            $target = self::target_section_for_delta((int)$cm->sectionnum, (int)$delta, $last);
        } else {
            $target = (int)$raw;
        }

        // Site front page: everything lives in section 1 (section 0 is not rendered there).
        if (section_resolver_service::is_site_front_page($course)) {
            $target = section_resolver_service::SITE_FRONT_PAGE_SECTION;
        }

        if ($target === (int)$cm->sectionnum) {
            // Already in the target section — nothing to move.
            return null;
        }
        if (!(new section_resolver_service())->section_exists($course, $target)) {
            return $this->clarify(
                'Section ' . $target . ' does not exist in this course.',
                'UPDATE_ACTIVITY_SECTION_INVALID'
            );
        }
        return $target;
    }

    /**
     * Build an update-result descriptor from the current state of a module (used for move-only updates).
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return array
     */
    private function describe_current_module(\stdClass $course, int $cmid): array {
        $coursecontextid = (int)\context_course::instance($course->id)->id;
        try {
            $cm = get_fast_modinfo($course)->get_cm($cmid);
        } catch (\Throwable $e) {
            return ['cmid' => $cmid, 'modname' => '', 'name' => '', 'url' => '', 'coursecontextid' => $coursecontextid];
        }
        $url = ($cm->url instanceof \moodle_url)
            ? $cm->url->out(false)
            : (new \moodle_url('/course/view.php', ['id' => (int)$course->id]))->out(false);
        return [
            'cmid' => $cmid,
            'modname' => (string)$cm->modname,
            'name' => (string)$cm->name,
            'url' => $url,
            'coursecontextid' => $coursecontextid,
        ];
    }

    /**
     * Render the updated activity inline for the preview pane.
     *
     * @param array $resultentry
     * @param int   $contextid
     * @param int   $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $cmid = (int)($resultentry['updated_cmid'] ?? 0);
        $courseid = (int)($resultentry['updated_courseid'] ?? 0);
        if ($cmid <= 0 || $courseid <= 0) {
            return null;
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return null;
        }
        $html = (new activity_preview_renderer())->render(
            $course,
            $cmid,
            (string)($resultentry['updated_modname'] ?? ''),
            (string)($resultentry['updated_name'] ?? ''),
            (string)($resultentry['activity_url'] ?? '')
        );
        if (trim($html) === '') {
            return null;
        }
        return [
            'type' => 'updated_activity',
            'html' => $html,
            'payload' => ['cmid' => $cmid, 'activity_url' => (string)($resultentry['activity_url'] ?? '')],
        ];
    }

    /**
     * Resolve the target course module: cmid > activityquery (name) > ambient module context.
     *
     * @param \stdClass $course
     * @param context|false $context Ambient context.
     * @param array $input
     * @param int $userid
     * @return \cm_info|array
     */
    private function resolve_target_cm(\stdClass $course, $context, array $input, int $userid) {
        $catalog = new module_catalog_service();
        $modinfo = get_fast_modinfo($course);

        // 1) Explicit cmid.
        $cmid = (int)($input['cmid'] ?? 0);
        if ($cmid > 0) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\Throwable $e) {
                return $this->clarify('I could not find an activity with that id in this course.', 'UPDATE_ACTIVITY_CM_NOT_FOUND');
            }
            if (!$catalog->is_whitelisted($cm->modname)) {
                return $this->clarify(
                    'Editing "' . $cm->modname . '" activities is not supported yet.',
                    'UPDATE_ACTIVITY_UNSUPPORTED'
                );
            }
            return $cm;
        }

        // 2) By name.
        $query = trim((string)($input['activityquery'] ?? ''));
        if ($query !== '') {
            $matches = self::cms_matching_query(
                array_values(array_filter(
                    $modinfo->get_cms(),
                    static fn($cm): bool => $catalog->is_whitelisted($cm->modname)
                )),
                $query
            );
            if (count($matches) === 1) {
                return $matches[0];
            }
            if (count($matches) > 1) {
                return $this->build_activity_clarification(
                    $matches,
                    'More than one activity matches "' . $query . '". Which one?'
                );
            }
            // Nothing here - but the user named it, so look beyond the ambient course before giving
            // up (run 23, UA-2: the forum existed one course over).
            $elsewhere = $this->find_activities_site_wide(
                \bookingextension_agent\local\wizard\services\activities\module_catalog_service::WHITELIST,
                $query,
                $userid
            );
            if (empty($elsewhere)) {
                $elsewhere = $this->find_activities_site_wide_by_tokens($query, $userid);
            }
            if (count($elsewhere) === 1) {
                try {
                    return get_fast_modinfo((int)$elsewhere[0]['courseid'], $userid)
                        ->get_cm((int)$elsewhere[0]['cmid']);
                } catch (\Throwable $e) {
                    unset($e);
                }
            }
            if (count($elsewhere) > 1) {
                $lines = ['More than one activity matches "' . $query . '". Which one?', ''];
                $options = [];
                foreach ($elsewhere as $candidate) {
                    $lines[] = '- ' . $candidate['name'] . ' in ' . $candidate['coursename']
                        . ' [cmid ' . (int)$candidate['cmid'] . ']';
                    $options[] = ['cmid' => (int)$candidate['cmid'], 'name' => $candidate['name']];
                }
                return $this->clarify(implode("\n", $lines), 'UPDATE_ACTIVITY_AMBIGUOUS', $options);
            }
            return $this->clarify(
                'I could not find an editable activity called "' . $query . '".',
                'UPDATE_ACTIVITY_NOT_FOUND'
            );
        }

        // 3) Ambient module context (editing the activity of the current page).
        if ($context && (int)$context->contextlevel === CONTEXT_MODULE) {
            try {
                $cm = $modinfo->get_cm((int)$context->instanceid);
                if ($catalog->is_whitelisted($cm->modname)) {
                    return $cm;
                }
            } catch (\Throwable $e) {
                // Fall through to the clarification.
                unset($e);
            }
        }

        return $this->clarify(
            'Which activity should I edit? Name it (e.g. "the Welcome page").',
            'UPDATE_ACTIVITY_TARGET_REQUIRED'
        );
    }

    /**
     * The activities of one course that a name query points to.
     *
     * A name containing the query; when none does, the same comparison by shape (wave 32: hyphens, spaces, case - see
     * self::name_match_strength()). Only the full-query strength there: a short name that merely stands inside the
     * user's words must not win in one course before the other courses were searched.
     *
     * @param \cm_info[] $cms Editable activities of one course.
     * @param string $query
     * @return \cm_info[]
     */
    private static function cms_matching_query(array $cms, string $query): array {
        $needle = \core_text::strtolower($query);
        $matches = array_values(array_filter(
            $cms,
            static fn($cm): bool => str_contains(\core_text::strtolower((string)$cm->name), $needle)
        ));
        if (!empty($matches)) {
            return $matches;
        }
        return self::strongest_matches($cms, $query, static fn($cm): string => (string)$cm->name, self::MATCH_WHOLE_QUERY);
    }

    /**
     * Of several courses the named course fits, the one that holds the named activity (declarative hook of
     * skill_operating_context_resolver).
     *
     * Baseline UA-1 (thread 16750): "the 'Untitled page' in Winter School" - two courses are called "Winter School ...",
     * the page exists in one. Only courses the user can open count, only activities the user can see and edit here.
     * No activity named, or the activity in none or several of the courses: null, the course question stays.
     *
     * @param array[] $candidates Course candidates ({id, name, shortname}).
     * @param array $input The command input.
     * @param int $userid
     * @return context|null
     */
    public function decide_ambiguous_target(array $candidates, array $input, int $userid): ?context {
        $query = trim((string)($input['activityquery'] ?? ''));
        $cmid = (int)($input['cmid'] ?? 0);
        $user = \core_user::get_user($userid);
        if (($query === '' && $cmid <= 0) || !$user) {
            return null;
        }
        $catalog = new module_catalog_service();
        $holding = [];
        foreach ($candidates as $candidate) {
            $courseid = (int)($candidate['id'] ?? 0);
            $course = $courseid > SITEID ? get_course($courseid) : null;
            if (!$course || !can_access_course($course, $user, '', true)) {
                continue;
            }
            $cms = array_filter(
                get_fast_modinfo($course, $userid)->get_cms(),
                static fn($cm): bool => $cm->uservisible && $catalog->is_whitelisted($cm->modname)
            );
            $holds = $cmid > 0 ? isset($cms[$cmid]) : !empty(self::cms_matching_query(array_values($cms), $query));
            if ($holds) {
                $holding[] = $courseid;
            }
        }
        return count($holding) === 1 ? context_course::instance($holding[0]) : null;
    }

    /**
     * Site-wide candidates for a query the plain LIKE search missed (wave 32, UA-2 and UA-4).
     *
     * UA-2 (ten runs): "Vorstellungs-Forum" never reached the forum "Vorstellungsforum" - the LIKE compares the
     * raw string. UA-4 (L31, L38, L41): "die Seite mit den Übungsdaten" never reached the page "Übungsdaten" -
     * the user's words wrap the name. Every token of the query is searched on its own, and only candidates whose
     * name matches the whole query by {@see self::name_match_strength()} survive. No word is known here.
     *
     * @param string $query
     * @param int $userid
     * @return array[] {cmid, name, courseid, coursename, modname}
     */
    private function find_activities_site_wide_by_tokens(string $query, int $userid): array {
        $candidates = [];
        $agreed = null;
        foreach (array_slice(self::name_tokens($query), 0, self::MAX_QUERY_TOKENS) as $token) {
            if (\core_text::strlen($token) < self::MIN_TOKEN_CHARS) {
                continue;
            }
            $hits = [];
            foreach ($this->find_activities_site_wide(module_catalog_service::WHITELIST, $token, $userid) as $found) {
                $candidates[(int)$found['cmid']] = $found;
                // A word of the phrase counts as a match only as a whole word of the name (accents folded): "der"
                // stands inside "Fédération", but no activity is called "der".
                $namewords = array_map([self::class, 'fold'], self::name_tokens((string)$found['name']));
                if (in_array(self::fold($token), $namewords, true)) {
                    $hits[(int)$found['cmid']] = $found;
                }
            }
            if (!empty($hits)) {
                $agreed = $agreed === null ? $hits : array_intersect_key($agreed, $hits);
            }
        }

        $strongest = self::strongest_matches(
            array_values($candidates),
            $query,
            static fn(array $candidate): string => (string)$candidate['name']
        );
        if (!empty($strongest)) {
            return $strongest;
        }
        // Wave 37 (UA-3, N46 threads 18455/18538): "le lien vers la fédération" describes the URL "Fédération nationale
        // d'apiculture" - no name holds the phrase, and the name's words are not all in the phrase. As for persons and
        // courses (target_query_normalizer::narrow_by_tokens): the words that match any activity must agree on the
        // candidates, a word that matches nothing carries no meaning. One is the target, several are the choices.
        return array_values((array)$agreed);
    }

    /**
     * The candidates with the strongest name match, or none.
     *
     * @param array $candidates
     * @param string $query
     * @param callable $nameof fn($candidate): string
     * @param int $minstrength Weakest strength accepted (MATCH_NAME_TOKENS or MATCH_WHOLE_QUERY).
     * @return array
     */
    public static function strongest_matches(
        array $candidates,
        string $query,
        callable $nameof,
        int $minstrength = self::MATCH_NAME_TOKENS
    ): array {
        $bystrength = [];
        foreach ($candidates as $candidate) {
            $strength = self::name_match_strength((string)$nameof($candidate), $query);
            if ($strength >= $minstrength && $strength > 0) {
                $bystrength[$strength][] = $candidate;
            }
        }
        if (empty($bystrength)) {
            return [];
        }
        krsort($bystrength);
        return reset($bystrength);
    }

    /**
     * How an activity name matches the user's words: 2, 1 or 0.
     *
     * 2 = the query, compared by shape (letters and digits only, lower case), is part of the name:
     *     "Vorstellungs-Forum" and "Vorstellungsforum" (target_query_normalizer::name_key).
     * 1 = every token of the name stands in the query: "die Seite mit den Übungsdaten" carries "Übungsdaten".
     * Strength 2 always wins over 1, so a short name inside the query ("Forum") never competes with a name
     * that holds the whole query.
     *
     * @param string $name
     * @param string $query
     * @return int
     */
    public static function name_match_strength(string $name, string $query): int {
        $namekey = target_query_normalizer::name_key($name);
        $querykey = target_query_normalizer::name_key($query);
        if ($namekey === '' || $querykey === '') {
            return 0;
        }
        if (str_contains($namekey, $querykey)) {
            return self::MATCH_WHOLE_QUERY;
        }
        $nametokens = self::name_tokens($name);
        if ($nametokens !== [] && array_diff($nametokens, self::name_tokens($query)) === []) {
            return self::MATCH_NAME_TOKENS;
        }
        return 0;
    }

    /**
     * A word with its accents folded, for whole-word comparison across spellings ("fédération" / "federation").
     *
     * @param string $word
     * @return string
     */
    private static function fold(string $word): string {
        return \core_text::strtolower(\core_text::specialtoascii($word));
    }

    /**
     * Lower-case runs of letters and digits of any script.
     *
     * @param string $text
     * @return string[]
     */
    private static function name_tokens(string $text): array {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', \core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($parts));
    }

    /**
     * Collect the requested changes from input (only provided, meaningful fields).
     *
     * @param array $input
     * @return array
     */
    private function collect_changes(array $input): array {
        $changes = [];
        $name = trim((string)($input['name'] ?? ''));
        if ($name !== '') {
            $changes['name'] = $name;
        }
        $intro = trim((string)($input['intro'] ?? ''));
        if ($intro !== '') {
            $changes['intro'] = $intro;
        }
        if (is_array($input['settings'] ?? null) && !empty($input['settings'])) {
            $changes['settings'] = (array)$input['settings'];
        }
        if (array_key_exists('visible', $input) && $input['visible'] !== '' && $input['visible'] !== null) {
            $changes['visible'] = $this->parse_visible($input['visible']);
        }
        return $changes;
    }

    /**
     * Parse a visibility input into 1 (show) / 0 (hide).
     *
     * @param mixed $value
     * @return int
     */
    private function parse_visible($value): int {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        $v = \core_text::strtolower(trim((string)$value));
        if (in_array($v, ['0', 'false', 'no', 'hide', 'hidden'], true)) {
            return 0;
        }
        return 1;
    }

    /**
     * Build a needs_clarification listing candidate activities.
     *
     * @param \cm_info[] $cms
     * @param string $lead
     * @return array
     */
    private function build_activity_clarification(array $cms, string $lead): array {
        $lines = [$lead, ''];
        $options = [];
        foreach ($cms as $cm) {
            $lines[] = '- ' . $cm->name . ' (' . $cm->modname . ') [cmid ' . (int)$cm->id . ']';
            $options[] = ['cmid' => (int)$cm->id, 'name' => $cm->name, 'modname' => $cm->modname];
        }
        $lines[] = '';
        $lines[] = 'Reply with the activity name and I will continue.';
        return $this->clarify(implode("\n", $lines), 'UPDATE_ACTIVITY_AMBIGUOUS', $options);
    }

    /**
     * Format real mod_form field errors into a clarification message.
     *
     * @param string $modname
     * @param array $errors
     * @return string
     */
    private function format_field_errors(string $modname, array $errors): string {
        $lines = ['That change is not valid for the ' . $modname . ' activity:', ''];
        foreach ($errors as $field => $message) {
            $lines[] = '- ' . $field . ': ' . $message;
        }
        return implode("\n", $lines);
    }


    /**
     * Build the success result payload (with a human-readable before/after).
     *
     * @param array $updated
     * @param array $changes
     * @param array $before
     * @param int $attempts
     * @param int|null $movedto Target section number when the activity was moved, else null.
     * @return array
     */
    private function build_success_result(
        array $updated,
        array $changes,
        array $before,
        int $attempts,
        ?int $movedto = null
    ): array {
        $cmid = (int)($updated['cmid'] ?? 0);
        $modname = (string)($updated['modname'] ?? '');
        $name = (string)($updated['name'] ?? '');
        $url = (string)($updated['url'] ?? '');
        $courseid = 0;
        if (!empty($updated['coursecontextid'])) {
            $cc = \context::instance_by_id((int)$updated['coursecontextid'], IGNORE_MISSING);
            $courseid = $cc ? (int)$cc->instanceid : 0;
        }

        $changed = $this->describe_changes($changes, $before, $movedto);
        $message = 'Updated the activity "' . $name . '" (' . $modname . '). Changed: ' . $changed . '.';

        $observation = implode("\n", [
            'Updated course module cmid=' . $cmid . ' modname=' . $modname . ' (after ' . $attempts . ' attempt(s)).',
            'Changes: ' . $changed,
            'Activity URL: ' . $url,
        ]);

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message . ($url !== '' ? ' ' . $url : ''),
            'resultid' => null,
            'updated_cmid' => $cmid,
            'updated_courseid' => $courseid,
            'updated_modname' => $modname,
            'updated_name' => $name,
            'activity_url' => $url,
            'affected_scope_summary' => $changed,
            'observation_full' => $observation,
        ];
    }

    /**
     * Build a short human-readable description of the changes (old → new where known).
     *
     * @param array $changes
     * @param array $before
     * @param int|null $movedto Target section number when the activity was moved, else null.
     * @return string
     */
    private function describe_changes(array $changes, array $before, ?int $movedto = null): string {
        $parts = [];
        if (isset($changes['name'])) {
            $parts[] = 'name "' . (string)($before['name'] ?? '') . '" → "' . (string)$changes['name'] . '"';
        }
        if (isset($changes['intro'])) {
            $parts[] = 'description updated';
        }
        if (array_key_exists('visible', $changes)) {
            $was = (int)($before['visible'] ?? 1) === 1 ? 'shown' : 'hidden';
            $now = (int)$changes['visible'] === 1 ? 'shown' : 'hidden';
            $parts[] = 'visibility ' . $was . ' → ' . $now;
        }
        if (isset($changes['settings'])) {
            $keys = array_keys((array)$changes['settings']);
            $parts[] = 'settings (' . implode(', ', $keys) . ')';
        }
        if ($movedto !== null) {
            $parts[] = 'moved from section ' . (int)($before['section'] ?? 0) . ' to section ' . $movedto;
        }
        return empty($parts) ? 'nothing' : implode('; ', $parts);
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

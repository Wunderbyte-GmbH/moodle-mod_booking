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
use bookingextension_agent\local\wizard\services\activities\module_form_contract;
use bookingextension_agent\local\wizard\services\activities\quiz_question_service;
use bookingextension_agent\local\wizard\services\activity_preview_builder;
use bookingextension_agent\local\wizard\services\questions\course_pdf_source;
use context;
use context_course;

/**
 * Dedicated skill: edit a quiz and/or add questions to it (course.update_quiz).
 *
 * Two things, either or both:
 *  - settings edit (name / intro / visibility) via the shared module_form_contract update mode,
 *  - add questions (generate / specific ids / random from a category) via the shared quiz_question_service,
 *    with the same "which source?" clarification (incl. available categories) as add_quiz.
 *
 * v1 does not remove/reorder questions or regrade (that is v2). R2, cross-context, no engine changes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_quiz_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    use preflight_clarification;

    /** Skill name. */
    public const SKILL_NAME = 'course.update_quiz';

    /**
     * Constructor. Mutating skill — broad write, requires confirmation.
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
     * Human-readable preview of the quiz update (tier-3): target + changed fields + questions.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return activity_preview_builder::update_quiz_descriptor($input);
    }

    /**
     * Quizzes live in a course.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }


    /**
     * Target level.
     *
     * @return int
     */
    public function get_target_context_level(): int {
        return CONTEXT_COURSE;
    }


    /**
     * Native capability (Gate 2). Generation additionally needs moodle/question:add (checked in preflight).
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
            'description' => 'Edit an existing quiz and/or add questions to it. Rename it, change its description, show/hide it, '
                . 'and/or add questions (generated from a topic or the course PDFs, specific existing ones, or random from a '
                . 'category). Use for "add 5 '
                . 'questions to Quiz 3", "rename the quiz", "add questions to the quiz", "hide the quiz".',
            'is' => 'Changing a quiz that already exists.',
            'not' => 'Creating a new quiz (add_quiz); bank questions without a quiz (question.generate_questions); other '
                . 'activities and resources (course.update_activity).',
            'readonly' => false,
            'example_utterances' => [
                'add 5 questions to the existing quiz',
                'put more questions into Chapter 1 quiz',
                'rename Quiz 3 to Final exam',
                'hide the quiz from students',
                'add questions from the algebra category to the quiz',
            ],
            'properties' => [
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Which quiz, by its current name. The system resolves it; if several match it '
                        . 'asks. Omit when editing the quiz of the current page or when cmid is given.',
                    'required' => false,
                ],
                'cmid' => [
                    'type' => 'integer',
                    'description' => 'Course module id of the quiz, when known. Never guess.',
                    'required' => false,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'New quiz name. Leave empty to keep it.',
                    'required' => false,
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'New quiz description. Leave empty to keep it.',
                    'required' => false,
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'true to show, false to hide. Omit to keep current visibility.',
                    'required' => false,
                ],
                'addquestions' => [
                    'type' => 'boolean',
                    'description' => 'Set true when the user wants to add questions but has not said from where — the '
                        . 'system then asks which source to use.',
                    'required' => false,
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Source material to GENERATE questions from (topic/facts/document text). Setting '
                        . 'this adds generated questions. Do not author questions yourself.',
                    'required' => false,
                ],
                'usecoursepdfs' => [
                    'type' => 'boolean',
                    'description' => 'true = generate the questions from the PDFs stored in the '
                        . 'course ("the PDF in the course"). The '
                        . 'system reads them - never ask for the file.',
                    'required' => false,
                ],
                'resourcecmid' => [
                    'type' => 'integer',
                    'description' => 'Course-module id of ONE specific file/resource activity whose PDF is the source, '
                        . 'when known. Never guess an id.',
                    'required' => false,
                ],
                'count' => [
                    'type' => 'integer',
                    'description' => 'How many questions to generate / add (default 5).',
                    'required' => false,
                ],
                'qtypes' => [
                    'type' => 'array',
                    'description' => 'Generation question types: multichoice, truefalse, shortanswer.',
                    'items' => ['type' => 'string'],
                    'required' => false,
                ],
                'difficulty' => [
                    'type' => 'string',
                    'description' => 'Generation difficulty: easy, medium or hard.',
                    'required' => false,
                ],
                'questionids' => [
                    'type' => 'array',
                    'description' => 'Ids of specific existing questions to add.',
                    'items' => ['type' => 'integer'],
                    'required' => false,
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Question category name to take random questions from. The system lists categories '
                        . 'if it is unclear.',
                    'required' => false,
                ],
                'randomcount' => [
                    'type' => 'integer',
                    'description' => 'How many random questions from the category (default 5).',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Target a DIFFERENT course, ONLY when named. Leave empty for the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric target course id when known. Leave empty for the current course.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['activityquery', 'name', 'intro', 'visible', 'content', 'usecoursepdfs', 'count',
                    'category'],
                'anchor_fields' => ['activityquery', 'coursequery', 'category'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['activityquery' => 'Chapter 1 quiz', 'content' => 'Photosynthesis', 'count' => 5];
    }

    /**
     * Message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.update_quiz_request',
                'description' => 'The user wants an existing quiz changed, or questions added to it (generated from a topic '
                    . 'or the course PDFs, specific, or from a category).',
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
                'id' => 'course.update_quiz',
                'triggers' => [
                    'add questions to', 'add to quiz', 'rename quiz',
                    'rename quiz', 'edit quiz', 'hide quiz', 'more questions',
                ],
                'guidance' => [
                    '- course.update_quiz edits an EXISTING quiz and/or adds questions; to create a new quiz use',
                    '  course.add_quiz, to only add questions to the bank use question.generate_questions.',
                    '- Identify the quiz via activityquery (its name) or cmid; if unclear the system asks.',
                    '- For adding questions with no clear source, set addquestions=true; the system then asks',
                    '  (generate / specific / from a category, listing categories). Put generation material into',
                    '  input.content; do not author questions yourself. v1 does not remove/reorder questions.',
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
     * Resolve target quiz + settings changes + question source (read-only).
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return $this->clarify(
                'Editing a quiz works inside a course. Please open a course, or name one.',
                'UPDATE_QUIZ_NO_COURSE'
            );
        }
        if (!has_capability('moodle/course:manageactivities', $coursecontext, $userid)) {
            return $this->clarify(
                get_string('nopermissions', 'error', 'moodle/course:manageactivities'),
                'NO_NATIVE_CAPABILITY'
            );
        }
        $course = get_course($coursecontext->instanceid);

        $cm = $this->resolve_target_quiz($course, $context, $input, $userid);
        if (is_array($cm)) {
            return $cm;
        }

        // The quiz may live in another course than the one we are sitting in (run 23). The right to
        // edit it is decided THERE, not here, and everything below - question categories, the
        // generate permission - has to follow the quiz as well.
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

        $changes = $this->collect_settings_changes($input);

        $service = new quiz_question_service();
        $plan = $service->resolve_source($input, $coursecontext, $userid);

        if (($plan['mode'] ?? '') === 'clarify') {
            return $this->build_source_clarification($service->list_available_categories($coursecontext, $userid));
        }
        if (($plan['mode'] ?? '') === 'generate' && isset($plan['pdfsource'])) {
            // The course PDFs as source (wave 30, UQ-4): a missing or unreadable PDF is a question, not an error.
            $pdfissues = (new course_pdf_source())->check(
                (int)($plan['pdfsource']['resourcecmid'] ?? 0),
                (int)$coursecontext->instanceid,
                $userid,
                $this->get_output_language($input)
            );
            if (!empty($pdfissues)) {
                return $this->invalid($pdfissues);
            }
        }
        $wantsquestions = ($plan['mode'] ?? 'none') !== 'none';

        if (empty($changes) && !$wantsquestions) {
            return $this->clarify(
                'What should I change about "' . format_string($cm->name) . '" — rename / description / visibility, '
                    . 'or add questions?',
                'UPDATE_QUIZ_NOTHING_TO_DO'
            );
        }
        if (($plan['mode'] ?? '') === 'generate' && !has_capability('moodle/question:add', $coursecontext, $userid)) {
            return $this->clarify(get_string('nopermissions', 'error', 'moodle/question:add'), 'UPDATE_QUIZ_NO_QUESTION_CAP');
        }
        if (($plan['mode'] ?? '') === 'category') {
            $cats = $service->list_available_categories($coursecontext, $userid);
            $needle = \core_text::strtolower((string)$plan['category']);
            $match = false;
            foreach ($cats as $cat) {
                if (str_contains(\core_text::strtolower((string)$cat['categoryname']), $needle)) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return $this->build_source_clarification(
                    $cats,
                    'I could not find a category called "' . (string)$plan['category'] . '". Choose one:'
                );
            }
        }

        return $this->pass([
            'courseid' => (int)$course->id,
            'cmid' => (int)$cm->id,
            'instance' => (int)$cm->instance,
            'changes' => $changes,
            'plan' => $plan,
            'ambientcontextid' => (int)$contextid,
        ]);
    }

    /**
     * Apply settings changes and/or add questions.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $courseid = (int)($preparedinput['courseid'] ?? 0);
        $cmid = (int)($preparedinput['cmid'] ?? 0);
        $instance = (int)($preparedinput['instance'] ?? 0);
        if ($courseid <= 0 || $cmid <= 0) {
            return $this->build_error_result('Missing prepared quiz reference.');
        }
        try {
            $course = get_course($courseid);
            $cmrecord = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
        } catch (\Throwable $e) {
            return $this->build_error_result('The quiz to edit could not be loaded.');
        }
        $coursecontext = context_course::instance($courseid);
        $changes = (array)($preparedinput['changes'] ?? []);
        $plan = (array)($preparedinput['plan'] ?? ['mode' => 'none']);
        $mode = (string)($plan['mode'] ?? 'none');
        $ambient = (int)($preparedinput['ambientcontextid'] ?? $contextid);
        $service = new quiz_question_service();
        $stages = [];

        // 1) Settings edit.
        if (!empty($changes)) {
            try {
                $contract = new module_form_contract();
                $moduleinfo = $contract->build_prepared_update_moduleinfo($course, $cmrecord, $changes);
                // Quiz overall-feedback fields must be well-formed for update_moduleinfo (preserve text, fix format).
                quiz_question_service::ensure_quiz_feedback($moduleinfo);
                (new activity_creation_service())->update($cmrecord, $moduleinfo, $course);
                $stages[] = 'updated settings (' . implode(', ', array_keys($changes)) . ')';
            } catch (\Throwable $e) {
                return $this->build_error_result('Could not update the quiz settings: ' . $e->getMessage());
            }
        }

        // 2) Add questions (generation-first for the generate source).
        if ($mode === 'generate') {
            $gen = $service->generate_into_bank($course, $coursecontext, $plan, $userid, $ambient);
            if ($gen['error'] !== null) {
                $stages[] = 'WARNING: question generation failed: ' . $gen['error'];
            } else {
                $ref = $service->reference_existing($course, $instance, $gen['questionids']);
                $stages[] = ($ref['error'] ?? null) !== null
                    ? 'WARNING: questions generated in bank but not added: ' . $ref['error']
                    : 'added ' . (int)$ref['added'] . ' generated question(s)';
            }
        } else if (in_array($mode, ['ids', 'category'], true)) {
            $pop = $service->add_questions_to_quiz($course, $coursecontext, $instance, $plan, $userid, $ambient);
            $stages[] = ($pop['error'] ?? null) !== null
                ? 'WARNING: questions could not be added: ' . $pop['error']
                : 'added ' . (int)$pop['added'] . ' question(s)';
        }

        return $this->build_success_result($course, $courseid, $cmid, $stages);
    }

    /**
     * Render the quiz preview.
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
            $cm = get_fast_modinfo($course)->get_cm($cmid);
        } catch (\Throwable $e) {
            return null;
        }
        $url = $cm->url ? $cm->url->out(false) : '';
        $html = (new activity_preview_renderer())->render($course, $cmid, 'quiz', $cm->name, $url);
        if (trim($html) === '') {
            return null;
        }
        return ['type' => 'updated_activity', 'html' => $html, 'payload' => ['cmid' => $cmid, 'activity_url' => $url]];
    }

    /**
     * Resolve the target quiz cm (cmid > name in this course > name anywhere > ambient module).
     *
     * @param \stdClass $course
     * @param context|false $context
     * @param array $input
     * @param int $userid Whose visibility decides which activities may be found.
     * @return \cm_info|array
     */
    private function resolve_target_quiz(\stdClass $course, $context, array $input, int $userid) {
        $modinfo = get_fast_modinfo($course);

        $cmid = (int)($input['cmid'] ?? 0);
        if ($cmid > 0) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\Throwable $e) {
                return $this->clarify('I could not find an activity with that id here.', 'UPDATE_QUIZ_CM_NOT_FOUND');
            }
            if ($cm->modname !== 'quiz') {
                return $this->clarify(
                    'That activity is not a quiz. Use course.update_activity for other types.',
                    'UPDATE_QUIZ_NOT_A_QUIZ'
                );
            }
            return $cm;
        }

        $query = trim((string)($input['activityquery'] ?? ''));
        if ($query !== '') {
            $needle = \core_text::strtolower($query);
            $matches = [];
            foreach ($modinfo->get_instances_of('quiz') as $cm) {
                if (str_contains(\core_text::strtolower($cm->name), $needle)) {
                    $matches[] = $cm;
                }
            }
            if (count($matches) === 1) {
                return $matches[0];
            }
            if (count($matches) > 1) {
                $lines = ['More than one quiz matches "' . $query . '". Which one?', ''];
                $options = [];
                foreach ($matches as $cm) {
                    $lines[] = '- ' . $cm->name . ' [cmid ' . (int)$cm->id . ']';
                    $options[] = ['cmid' => (int)$cm->id, 'name' => $cm->name];
                }
                return $this->clarify(implode("\n", $lines), 'UPDATE_QUIZ_AMBIGUOUS', $options);
            }
            // Nothing here - but the user named it, so look beyond the ambient course before giving
            // up (run 23, UQ-2/UQ-4: the quiz existed one course over and the turn ended as a
            // not-found about an activity that was there all along).
            $elsewhere = $this->find_activities_site_wide('quiz', $query, $userid);
            if (count($elsewhere) === 1) {
                try {
                    return get_fast_modinfo((int)$elsewhere[0]['courseid'], $userid)
                        ->get_cm((int)$elsewhere[0]['cmid']);
                } catch (\Throwable $e) {
                    unset($e);
                }
            }
            if (count($elsewhere) > 1) {
                $lines = ['More than one quiz matches "' . $query . '". Which one?', ''];
                $options = [];
                foreach ($elsewhere as $candidate) {
                    $lines[] = '- ' . $candidate['name'] . ' in ' . $candidate['coursename']
                        . ' [cmid ' . (int)$candidate['cmid'] . ']';
                    $options[] = ['cmid' => (int)$candidate['cmid'], 'name' => $candidate['name']];
                }
                return $this->clarify(implode("\n", $lines), 'UPDATE_QUIZ_AMBIGUOUS', $options);
            }
            // Wave 37 (UQ-3, L49 thread 17795): "le quiz existant" names no quiz, and the turn asked for the exact name
            // although the course holds the quizzes to choose from. Choices, not a bare miss (wave 32 rule for
            // options): the quizzes of this course are listed, the model picks the cmid - nothing is guessed here.
            $candidates = [];
            foreach ($modinfo->get_instances_of('quiz') as $cm) {
                if ($cm->uservisible) {
                    $candidates[] = ['id' => (int)$cm->id, 'label' => (string)$cm->name];
                }
            }
            if (empty($candidates)) {
                return $this->clarify('I could not find a quiz called "' . $query . '".', 'UPDATE_QUIZ_NOT_FOUND');
            }
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => 'I could not find a quiz called "' . $query . '". The quizzes of this course are listed.',
                'code' => 'UPDATE_QUIZ_NOT_FOUND',
                'field' => 'cmid',
                'candidates' => $candidates,
            ]]);
        }

        if ($context && (int)$context->contextlevel === CONTEXT_MODULE) {
            try {
                $cm = $modinfo->get_cm((int)$context->instanceid);
                if ($cm->modname === 'quiz') {
                    return $cm;
                }
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        return $this->clarify('Which quiz should I edit? Name it (e.g. "Chapter 1 quiz").', 'UPDATE_QUIZ_TARGET_REQUIRED');
    }

    /**
     * Collect settings changes (name/intro/visible) — only provided ones.
     *
     * @param array $input
     * @return array
     */
    private function collect_settings_changes(array $input): array {
        $changes = [];
        $name = trim((string)($input['name'] ?? ''));
        if ($name !== '') {
            $changes['name'] = $name;
        }
        $intro = trim((string)($input['intro'] ?? ''));
        if ($intro !== '') {
            $changes['intro'] = $intro;
        }
        if (array_key_exists('visible', $input) && $input['visible'] !== '' && $input['visible'] !== null) {
            $changes['visible'] = (is_bool($input['visible']) ? $input['visible'] : !in_array(
                \core_text::strtolower(trim((string)$input['visible'])),
                ['0', 'false', 'no', 'hide', 'hidden'],
                true
            )) ? 1 : 0;
        }
        return $changes;
    }

    /**
     * Build the question-source clarification (three options + available categories).
     *
     * @param array[] $categories
     * @param string $lead
     * @return array
     */
    private function build_source_clarification(array $categories, string $lead = ''): array {
        $lead = $lead !== '' ? $lead : 'Which questions should I add?';
        $content = quiz_question_service::build_source_clarification($categories, $lead);
        return $this->clarify($content['message'], 'UPDATE_QUIZ_QUESTION_SOURCE', $content['options']);
    }


    /**
     * Build the success result with staged feedback.
     *
     * @param \stdClass $course
     * @param int $courseid
     * @param int $cmid
     * @param string[] $stages
     * @return array
     */
    private function build_success_result(\stdClass $course, int $courseid, int $cmid, array $stages): array {
        try {
            $cm = get_fast_modinfo($course)->get_cm($cmid);
            $name = $cm->name;
            $url = $cm->url ? $cm->url->out(false) : '';
        } catch (\Throwable $e) {
            $name = 'quiz';
            $url = '';
        }
        $did = empty($stages) ? 'nothing' : implode('; ', $stages);
        $message = 'Updated the quiz "' . $name . '": ' . $did . '.';
        $observation = array_merge(['Quiz update steps:'], array_map(static fn($s): string => '- ' . $s, $stages));
        $observation[] = 'Quiz URL: ' . $url;

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message . ($url !== '' ? ' ' . $url : ''),
            'resultid' => null,
            'updated_cmid' => $cmid,
            'updated_courseid' => $courseid,
            'affected_scope_summary' => $did,
            'observation_full' => implode("\n", $observation),
        ];
    }

    /**
     * Build an error result.
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

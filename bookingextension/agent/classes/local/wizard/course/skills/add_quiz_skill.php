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
use bookingextension_agent\local\wizard\services\activities\section_resolver_service;
use bookingextension_agent\local\wizard\services\activity_preview_builder;
use bookingextension_agent\local\wizard\services\questions\course_pdf_source;
use context;
use context_course;

/**
 * Dedicated skill: create a quiz and (optionally) populate it with questions (course.add_quiz).
 *
 * The quiz hull is built on the shared activity foundation (module_form_contract + activity_creation_service)
 * with a thin quiz-specific patch (overall-feedback defaults). Questions come via the shared
 * quiz_question_service from three sources (generate / specific ids / random from a category). It is smart
 * about incomplete requests: an empty quiz is allowed ("create the quiz now, add questions later"), and when
 * questions are wanted but the source is unclear it asks (generate from a document / make them up / use an
 * existing category — listing the available categories).
 *
 * Generation-first: questions are generated into the bank BEFORE the quiz is created, so a later failure
 * never loses the generated questions. R2 (confirm). No engine changes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_quiz_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    use preflight_clarification;

    /** Skill name. */
    public const SKILL_NAME = 'course.add_quiz';

    /**
     * Constructor. Mutating skill (creates a quiz) — broad write, requires confirmation.
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
     * Human-readable preview of the quiz to be created (tier-3 confirmation preview).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        return activity_preview_builder::add_quiz_descriptor($input);
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
     * The cross-context target is a course.
     *
     * @return int
     */
    public function get_target_context_level(): int {
        return CONTEXT_COURSE;
    }


    /**
     * Native capability required to add a quiz (Gate 2). Question generation additionally needs
     * moodle/question:add, checked in preflight when that source is chosen.
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
            // First 240 characters = selector window: a named course goes into coursequery (no lookup step).
            'description' => 'Create a quiz activity in a course (a named course: coursequery, no lookup needed), empty or with '
                . 'generated, existing or random questions. Use for "create a quiz", "make a quiz from this PDF". The quiz can be '
                . 'created empty (add questions later) or populated from one of three sources: newly generated questions (from a '
                . 'document/PDF or a topic), specific existing questions, or random questions from a question category.',
            'is' => 'Creating the quiz activity itself.',
            'not' => 'Bank questions only (question.generate_questions); editing a quiz (update_quiz); other activities '
                . '(add_activity); a whole course (scaffold_course_content).',
            'readonly' => false,
            'example_utterances' => [
                'create a quiz for this course',
                'add a new test with 10 questions',
                'make a quiz from this PDF',
                // George 2026-09-26 (w32 A2 §5.1): neutral wording instead of "build a test from the uploaded
                // document", which stood too close to the AQ-1 test prompt (overfitting).
                'create a quiz activity whose questions come from an attached file',
                'set up an empty quiz I can fill later',
                'build a quiz from the photosynthesis topic',
            ],
            'properties' => [
                // George 2026-09-26 (w32 A2): the name was "Required." in words only; the reminder said "ask for
                // nothing" and the construction invented names (AQ-2 "Test Shell", asset AQ-3). Declared required,
                // the constructor asks when the user gave none (frozen rule 2) and never invents one (rule 1).
                'name' => [
                    'type' => 'string',
                    'description' => 'The quiz name/title, in the user\'s words.',
                    'required' => true,
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'Optional quiz description/intro.',
                    'required' => false,
                ],
                'section' => [
                    'type' => 'string',
                    'description' => 'Optional placement ("top"/"bottom"/section name). Defaults to the top.',
                    'required' => false,
                ],
                'addquestions' => [
                    'type' => 'boolean',
                    'description' => 'Set true when the user wants questions but has not said from where — the system '
                        . 'then asks which source to use. Leave false/empty to create an empty quiz.',
                    'required' => false,
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Source material to generate questions from: a topic, facts or document text the user gave. '
                        . 'Never write the questions yourself.',
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
                    'description' => 'How many questions. No default when generating: the user\'s number, else leave out and the '
                        . 'system asks - never invent one.',
                    'required' => false,
                ],
                'qtypes' => [
                    'type' => 'array',
                    'description' => 'Question types for generation: multichoice, truefalse, shortanswer.',
                    'items' => ['type' => 'string'],
                    'required' => false,
                ],
                'difficulty' => [
                    'type' => 'string',
                    'description' => 'Difficulty for generation: easy, medium or hard.',
                    'required' => false,
                ],
                'questionids' => [
                    'type' => 'array',
                    'description' => 'Ids of specific existing questions to add to the quiz.',
                    'items' => ['type' => 'integer'],
                    'required' => false,
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Name of a question category to take existing questions from (random). Pass the '
                        . 'user\'s wording; the system lists the available categories if it is unclear.',
                    'required' => false,
                ],
                'randomcount' => [
                    'type' => 'integer',
                    'description' => 'How many random questions to take from the category (default 5).',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    // L45 AQ-4 (thread 15013): "A DIFFERENT course, only when ..." put the exception first; the constructor left
                    // the named "Apikultur-Kurs" out and the quiz went to the current course.
                    'description' => 'The course the user names for the quiz, in their words; the system resolves it. '
                        . 'Leave it empty only when no course is named: then the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    // Wave 32: constructions copied the page's course id (11) although the user named another course.
                    'description' => 'Numeric course id only when the user gave it or an earlier step returned it - never '
                        . 'the current page\'s course. A named course goes into coursequery.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['name'],
                'anchor_fields' => ['coursequery', 'category'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        // The constructor only sees example VALUES: show that a named course is passed as coursequery.
        return [
            'name' => 'Chapter 1 quiz',
            'content' => 'Photosynthesis basics',
            'coursequery' => 'Biology 101',
        ];
    }

    /**
     * Message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.add_quiz_request',
                'description' => 'The user wants a new quiz or test activity in a course: empty, or with questions generated'
                    . ' from an uploaded document, the course PDFs or a topic, or existing or random ones.',
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
                'id' => 'course.add_quiz',
                'triggers' => [
                    'create a quiz', 'create a test', 'make a quiz', 'make a test', 'add a quiz',
                    'create quiz', 'quiz from pdf', 'quiz with questions', 'new quiz', 'new test',
                ],
                'guidance' => [
                    '- course.add_quiz CREATES the quiz activity; question.generate_questions only adds questions to',
                    '  the bank (no quiz). For "make a quiz from this PDF" use course.add_quiz with input.content.',
                    '- The quiz may be created EMPTY (add questions later) — do not force a question source.',
                    '- If the user wants questions but no source is clear, set input.addquestions=true; the system then',
                    '  asks (generate / specific / from a category, listing categories). Put generation material into',
                    '  input.content; do not write the questions yourself.',
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
        // B4 (Georg 2026-07-14): when the quiz is populated by GENERATING questions from provided
        // content, the number of questions is never silently defaulted — ask if the user did not
        // say how many. RECOVERABLE_INPUT_ERROR routes it as a clarification, not a confirm card
        // with a fabricated number (thread 587). Other sources (a category, specific ids, or an
        // empty quiz) carry no generation count and are unaffected.
        $content = trim((string)($input['content'] ?? ''));
        if ($content !== '' && (!isset($input['count']) || trim((string)$input['count']) === '')) {
            return [
                'valid' => false,
                'errors' => ['How many questions should the quiz have?'],
                'repair' => ['count is required when generating questions from content; never default it.'],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
                'ambiguities' => [],
            ];
        }
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    /**
     * Resolve course + name + section + question source (read-only).
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
                'Quizzes are created inside a course. Please open a course, or name one.',
                'ADD_QUIZ_NO_COURSE'
            );
        }
        if (
            !has_capability('moodle/course:manageactivities', $coursecontext, $userid)
            || !has_capability('mod/quiz:addinstance', $coursecontext, $userid)
        ) {
            return $this->clarify(get_string('nopermissions', 'error', 'mod/quiz:addinstance'), 'NO_NATIVE_CAPABILITY');
        }
        $course = get_course($coursecontext->instanceid);

        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            return $this->clarify('What should the quiz be called?', 'ADD_QUIZ_NAME_REQUIRED');
        }

        $sectionnum = 0;
        $sectionquery = trim((string)($input['section'] ?? ''));
        if ($sectionquery !== '') {
            $resolved = (new section_resolver_service())->resolve_placement($course, $sectionquery);
            if (is_int($resolved)) {
                $sectionnum = $resolved;
            }
        }

        $service = new quiz_question_service();
        $plan = $service->resolve_source($input, $coursecontext, $userid);

        if (($plan['mode'] ?? '') === 'clarify') {
            return $this->build_source_clarification((array)($plan['categories'] ?? []));
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
        if (
            ($plan['mode'] ?? '') === 'generate'
            && !has_capability('moodle/question:add', $coursecontext, $userid)
        ) {
            return $this->clarify(get_string('nopermissions', 'error', 'moodle/question:add'), 'ADD_QUIZ_NO_QUESTION_CAP');
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
                    'I could not find a question category called "' . (string)$plan['category'] . '". Choose one:'
                );
            }
        }

        return $this->pass([
            'courseid' => (int)$course->id,
            'sectionnum' => $sectionnum,
            'name' => $name,
            'intro' => trim((string)($input['intro'] ?? '')),
            'settings' => is_array($input['settings'] ?? null) ? (array)$input['settings'] : [],
            'plan' => $plan,
            'ambientcontextid' => (int)$contextid,
        ]);
    }

    /**
     * Create the quiz (generation-first) and populate it.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $courseid = (int)($preparedinput['courseid'] ?? 0);
        $name = (string)($preparedinput['name'] ?? '');
        if ($courseid <= 0 || $name === '') {
            return $this->build_error_result('Missing prepared course or quiz name.');
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->build_error_result('Target course could not be loaded.');
        }
        $coursecontext = context_course::instance($courseid);
        $plan = (array)($preparedinput['plan'] ?? ['mode' => 'none']);
        $mode = (string)($plan['mode'] ?? 'none');
        $ambient = (int)($preparedinput['ambientcontextid'] ?? $contextid);
        $service = new quiz_question_service();
        $stages = [];

        // 1) Generation-first: questions land in the bank before the quiz exists (durable on later failure).
        $generatedids = [];
        if ($mode === 'generate') {
            $gen = $service->generate_into_bank($course, $coursecontext, $plan, $userid, $ambient);
            if ($gen['error'] !== null) {
                return $this->build_error_result('No quiz was created — question generation failed: ' . $gen['error']);
            }
            $generatedids = $gen['questionids'];
            $stages[] = 'generated ' . count($generatedids) . ' question(s) in the course bank';
        }

        // 2) Create the quiz hull.
        try {
            $created = $this->create_quiz_hull(
                $course,
                (int)($preparedinput['sectionnum'] ?? 0),
                $name,
                (string)($preparedinput['intro'] ?? ''),
                (array)($preparedinput['settings'] ?? [])
            );
        } catch (\Throwable $e) {
            $tail = !empty($generatedids)
                ? ' The ' . count($generatedids) . ' generated question(s) are available in the course question bank.'
                : '';
            return $this->build_error_result('Could not create the quiz: ' . $e->getMessage() . $tail);
        }
        $stages[] = 'created quiz "' . $created['name'] . '"';
        $quizinstance = (int)$created['instance'];

        // 3) Populate.
        $population = ['added' => 0, 'error' => null];
        if ($mode === 'generate') {
            $population = $service->reference_existing($course, $quizinstance, $generatedids);
        } else if (in_array($mode, ['ids', 'category'], true)) {
            $population = $service->add_questions_to_quiz($course, $coursecontext, $quizinstance, $plan, $userid, $ambient);
        }
        if (($population['error'] ?? null) !== null) {
            $stages[] = 'WARNING: questions could not be added: ' . $population['error'];
        } else if ((int)($population['added'] ?? 0) > 0) {
            $stages[] = 'added ' . (int)$population['added'] . ' question(s) to the quiz';
        } else if ($mode === 'none') {
            $stages[] = 'no questions added (empty quiz — add some later)';
        }

        return $this->build_success_result($created, $stages, (int)($population['added'] ?? 0));
    }

    /**
     * Build + persist the quiz hull (generic build + quiz-specific overall-feedback defaults).
     *
     * @param \stdClass $course
     * @param int $sectionnum
     * @param string $name
     * @param string $intro
     * @param array $settings
     * @return array{cmid:int,instance:int,modname:string,name:string,url:string,coursecontextid:int}
     */
    private function create_quiz_hull(\stdClass $course, int $sectionnum, string $name, string $intro, array $settings): array {
        $moduleinfo = (new module_form_contract())->build_prepared_moduleinfo(
            $course,
            'quiz',
            $sectionnum,
            $name,
            $intro,
            $settings
        );
        // Quiz-specific: overall feedback fields add_moduleinfo requires (one empty band = no feedback).
        quiz_question_service::ensure_quiz_feedback($moduleinfo);
        // The quiz form does not export an id-number element headless; default it to avoid an undefined notice.
        if (!isset($moduleinfo->cmidnumber)) {
            $moduleinfo->cmidnumber = '';
        }
        return (new activity_creation_service())->create($moduleinfo, $course);
    }

    /**
     * Render the created quiz inline for the preview pane.
     *
     * @param array $resultentry
     * @param int   $contextid
     * @param int   $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $cmid = (int)($resultentry['created_cmid'] ?? 0);
        $courseid = (int)($resultentry['created_courseid'] ?? 0);
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
            'quiz',
            (string)($resultentry['created_name'] ?? ''),
            (string)($resultentry['activity_url'] ?? '')
        );
        if (trim($html) === '') {
            return null;
        }
        return [
            'type' => 'created_activity',
            'html' => $html,
            'payload' => ['cmid' => $cmid, 'activity_url' => (string)($resultentry['activity_url'] ?? '')],
        ];
    }

    /**
     * Build the question-source clarification (the three options + available categories).
     *
     * @param array[] $categories
     * @param string $lead
     * @return array
     */
    private function build_source_clarification(array $categories, string $lead = ''): array {
        $lead = $lead !== '' ? $lead : 'Which questions should I add to the quiz?';
        $content = quiz_question_service::build_source_clarification($categories, $lead);
        return $this->clarify($content['message'], 'ADD_QUIZ_QUESTION_SOURCE', $content['options']);
    }


    /**
     * Build the success result payload with staged feedback.
     *
     * @param array $created
     * @param string[] $stages
     * @param int $added
     * @return array
     */
    private function build_success_result(array $created, array $stages, int $added): array {
        $cmid = (int)($created['cmid'] ?? 0);
        $name = (string)($created['name'] ?? '');
        $url = (string)($created['url'] ?? '');
        $courseid = 0;
        if (!empty($created['coursecontextid'])) {
            $cc = \context::instance_by_id((int)$created['coursecontextid'], IGNORE_MISSING);
            $courseid = $cc ? (int)$cc->instanceid : 0;
        }

        $message = 'Created the quiz "' . $name . '"'
            . ($added > 0 ? ' with ' . $added . ' question(s)' : ' (empty — you can add questions any time)') . '.';

        $observation = array_merge(['Quiz creation steps:'], array_map(static fn($s): string => '- ' . $s, $stages));
        $observation[] = 'Quiz URL: ' . $url;

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message . ($url !== '' ? ' ' . $url : ''),
            'resultid' => null,
            'created_cmid' => $cmid,
            'created_courseid' => $courseid,
            'created_name' => $name,
            'activity_url' => $url,
            'question_count' => $added,
            'observation_full' => implode("\n", $observation),
        ];
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

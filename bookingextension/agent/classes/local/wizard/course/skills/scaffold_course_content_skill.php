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

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\preflight_clarification;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\activities\activity_creation_service;
use bookingextension_agent\local\wizard\services\activities\module_form_contract;
use bookingextension_agent\local\wizard\services\activities\quiz_question_service;
use bookingextension_agent\local\wizard\services\course\course_content_generation_service;
use context;
use context_course;
use moodle_url;

/**
 * Dedicated skill: fill a course with AI-generated scaffold content
 * (course.scaffold_course_content).
 *
 * ONE consolidated skill call builds the whole "small course" anatomy (welcome section +
 * N chapter pages + closing section, optional practice/final quizzes) instead of an
 * add_activity loop that would burn 5-8 planner steps and confirm clicks. Content is
 * generated INSIDE execute() (generation-first doctrine, like add_quiz) — the confirmation
 * preview shows the deterministic STRUCTURE, never a pre-generated outline (no LLM calls in
 * preflight; confirm_pending re-runs preflight).
 *
 * The structure clarification trigger is deterministic, never lexical: exactly when the
 * input carries NONE of the structure parameters (chapters/practicequizzes/finalquiz), the
 * preflight asks ONE consolidated question with the defaults spelled out.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scaffold_course_content_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;
    use preflight_clarification;

    /** Skill name. */
    public const SKILL_NAME = 'course.scaffold_course_content';

    /** Default number of content chapters. */
    private const DEFAULT_CHAPTERS = 4;

    /** Questions per practice quiz. */
    private const PRACTICE_QUIZ_QUESTIONS = 4;

    /** Questions in the final quiz. */
    private const FINAL_QUIZ_QUESTIONS = 8;

    /**
     * Constructor. Mutating skill (creates sections/activities) — broad write.
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
     * Content lands in a course.
     *
     * @return int
     */
    public function get_required_context_level(): int {
        return CONTEXT_COURSE;
    }

    /**
     * Native capabilities (Gate 2): section management + activity creation.
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/course:update', 'moodle/course:manageactivities'];
    }

    /**
     * Structure preview (tier-3 confirmation): the deterministic anatomy, not generated text.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $chapters = (int)($input['chapters'] ?? self::DEFAULT_CHAPTERS);
        $rows = [
            ['label' => 'Course', 'value' => (string)($input['coursefullname'] ?? '')],
            ['label' => 'Topic', 'value' => (string)($input['topic'] ?? '')],
            ['label' => 'Structure', 'value' => 'Welcome section, ' . $chapters
                . ' chapter page(s), closing section'],
            ['label' => 'Practice quizzes', 'value' => empty($input['practicequizzes']) ? 'no' : 'yes (per chapter)'],
            ['label' => 'Final quiz', 'value' => empty($input['finalquiz']) ? 'no' : 'yes (graded)'],
        ];

        return [
            'title' => 'Generate course content for "' . (string)($input['coursefullname'] ?? '') . '"',
            'summary' => 'The texts are AI-generated when you confirm; the structure above is fixed.',
            'rows' => $rows,
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
            // Wave 32 (SCC-4): the first sentence (selector window) says the course already exists, and the card fences
            // course.create_course both ways - from L35 on the selector chose create_course for a named existing course
            // in every run although this card was in the catalogue (create_course named no sibling for content).
            'description' => 'Fill an EXISTING course (named, the current one or one just created) with AI-generated content '
                . 'in ONE step: a welcome section, N chapter pages and a closing section, optionally practice quizzes per '
                . 'chapter and a graded final quiz. Use it once the course exists (newly created or existing) and the '
                . 'user wants an interesting/ready-to-use course about a topic. It creates all of this in a single call.',
            'is' => 'The content of a course that already exists, created in one call.',
            'not' => 'Creating the course container (course.create_course); separate add_activity or add_quiz steps for the '
                . 'same content.',
            'when' => 'The user wants a named or existing course filled with generated content or turned into a complete, '
                . 'ready-made course about a topic in one go.',
            'readonly' => false,
            'example_utterances' => [
                'Fill the Vikings course with interesting content',
                'Generate 5 chapters with a final quiz about first aid in this course',
                'Erstelle Inhalte für den Kurs, mit Übungsquiz pro Kapitel',
                'Make me an interesting course about the life of the Vikings',
                'Build out an existing course with a full set of chapters on a topic',
            ],
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'description' => 'The content topic, verbatim from the user (e.g. "the life of the Vikings"). '
                        . 'Required.',
                    'required' => true,
                ],
                'chapters' => [
                    'type' => 'integer',
                    'description' => 'Number of content chapters (default 4, max '
                        . course_content_generation_service::MAX_CHAPTERS . '). Set ONLY when the user named a '
                        . 'count.',
                    'required' => false,
                ],
                'practicequizzes' => [
                    'type' => 'boolean',
                    'description' => 'Add an ungraded practice quiz to every chapter (default false). Set ONLY '
                        . 'when the user asked for practice quizzes/exercises.',
                    'required' => false,
                ],
                'finalquiz' => [
                    'type' => 'boolean',
                    'description' => 'Add one graded final quiz drawing on all chapters (default false). Set ONLY '
                        . 'when the user asked for a final/graded quiz or test.',
                    'required' => false,
                ],
                'quizquestions' => [
                    'type' => 'integer',
                    // Wave 32 (SCC-1): the old text passed the 160-character cut before "leave this out".
                    'description' => 'Questions per quiz: the user\'s number. No default: else leave out and the system '
                        . 'asks - never invent one.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    // Wave 32 (SCC-2/SCC-3): the old text was cut at 160 characters ("Leave empty for th").
                    'description' => 'A DIFFERENT course, only when the user names one (their words) or one was just '
                        . 'created (its full name); the system resolves it. Empty = the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    // Wave 32: constructions copied the page's course id (11) although the user named another course.
                    'description' => 'Numeric course id only when the user gave it or an earlier step returned it - never '
                        . 'the current page\'s course. A named course goes into coursequery.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Language code the course content is written in, e.g. de or en. Defaults to '
                        . 'the user\'s language.',
                    'required' => false,
                ],
                'override' => [
                    'type' => 'array',
                    'description' => 'Explicit override tokens for confirmed exceptions (e.g. course_not_empty).',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['topic', 'chapters', 'practicequizzes', 'finalquiz', 'quizquestions', 'coursequery'],
                'anchor_fields' => ['coursequery', 'topic'],
                'context_scopes' => ['course'],
            ],
        ]);
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        // Wave 32 (SCC-1/SCC-4): the shape only. chapters 4, finalquiz true and quizquestions 5 were copied into
        // constructions whose user gave none of them (e51c839 removed the same count from add_quiz/generate_questions).
        return ['topic' => 'Das Leben der Wikinger'];
    }

    /**
     * Message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.scaffold_course_content_request',
                'description' => 'The user wants an existing course filled with generated chapters and content about a topic.',
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
                'id' => 'course.scaffold_course_content',
                'triggers' => ['course content', 'fill the course', 'chapters', 'kursinhalte', 'interesting course'],
                'guidance' => [
                    '- Pass the topic VERBATIM in input.topic; never rewrite or translate it.',
                    '- Set chapters/practicequizzes/finalquiz ONLY when the user stated them; when the user'
                        . ' said nothing about the structure, leave ALL three unset — the system then asks ONE'
                        . ' consolidated structure question.',
                    '- After course.create_course, target the new course via coursequery with its exact full name.',
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
        if (trim((string)($input['topic'] ?? '')) === '') {
            return [
                'valid' => false,
                'errors' => ['What topic should the course content cover?'],
                'repair' => ['topic is required: pass the user\'s content topic verbatim.'],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            ];
        }
        return ['valid' => true, 'errors' => [], 'repair' => []];
    }

    /**
     * Resolve the course, gate capabilities, and ask the ONE structure question when the
     * input carries no structure parameter (deterministic trigger, G2b).
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
                'Course content is generated inside a course. Please open a course, or name one.',
                'SCAFFOLD_NO_COURSE'
            );
        }
        foreach ($this->get_required_native_capabilities() as $capability) {
            if (!has_capability($capability, $coursecontext, $userid)) {
                return $this->clarify(get_string('nopermissions', 'error', $capability), 'NO_NATIVE_CAPABILITY');
            }
        }
        $course = get_course($coursecontext->instanceid);

        $topic = trim((string)($input['topic'] ?? ''));
        if ($topic === '') {
            return $this->clarify('What topic should the course content cover?', 'SCAFFOLD_TOPIC_REQUIRED');
        }

        // Deterministic structure clarification: fires exactly when NONE of the structure
        // parameters were provided — one consolidated question, defaults spelled out. B4 (Georg
        // 2026-07-14): it also asks the per-quiz question count, which has no silent default.
        $hasstructure = array_key_exists('chapters', $input)
            || array_key_exists('practicequizzes', $input)
            || array_key_exists('finalquiz', $input);
        if (!$hasstructure) {
            return $this->clarify(
                'How should the course be structured? Defaults: 4 chapters, no practice quizzes, no final '
                . 'quiz. Ask the user for (a) the number of chapters, (b) practice quiz per chapter yes/no, '
                . '(c) graded final quiz yes/no, and (d) if any quiz is wanted, how many questions per quiz '
                . '(no default) — or to simply confirm the defaults.',
                'SCAFFOLD_STRUCTURE_REQUIRED'
            );
        }

        // B4 (Georg 2026-07-14): the per-quiz question count is never silently defaulted (it was a
        // hard-coded 8). When a quiz IS requested but no count was given, ask — a real clarification,
        // not a confirmation card with a fabricated number (thread 587).
        $wantsquiz = !empty($input['practicequizzes']) || !empty($input['finalquiz']);
        $hasquizcount = array_key_exists('quizquestions', $input)
            && trim((string)$input['quizquestions']) !== '';
        if ($wantsquiz && !$hasquizcount) {
            return $this->clarify(
                'How many questions should each quiz have? There is no default — please give a number.',
                'SCAFFOLD_QUIZ_QUESTIONS_REQUIRED'
            );
        }

        // A chapter count is a number; anything else (SCC-2 L43 carried chapters=true, which (int) made 1) keeps the
        // documented default instead of turning into a one-chapter course.
        $chaptersinput = $input['chapters'] ?? null;
        $chapters = max(1, min(
            course_content_generation_service::MAX_CHAPTERS,
            (is_int($chaptersinput) || (is_string($chaptersinput) && ctype_digit(trim($chaptersinput))))
                ? ((int)$chaptersinput ?: self::DEFAULT_CHAPTERS)
                : self::DEFAULT_CHAPTERS
        ));

        // Expected-activities contract (blueprint F2): only activities BEYOND what the system
        // itself put there count as "not empty". V1 expected set = Moodle's own default
        // activities (announcements forum, detected via module data — forum.type=news — never
        // via names); a template manifest becomes a second expected source in V2, where
        // expected slots are UPDATED instead of blocking. Thread 586: the plain count>0 check
        // fired on the auto-created announcements forum of every fresh course.
        $overrides = array_map('strval', is_array($input['override'] ?? null) ? $input['override'] : []);
        $foreign = $this->list_foreign_activities($course, $userid);
        if (!in_array('course_not_empty', $overrides, true) && !empty($foreign)) {
            return $this->invalid([[
                'code' => 'SCAFFOLD_COURSE_NOT_EMPTY_CONFIRM_REQUIRED',
                'severity' => 'needs_confirmation',
                'message' => 'The course "' . $course->fullname . '" already contains '
                    . count($foreign) . ' activity(ies) beyond Moodle\'s defaults.',
                'user_question' => 'This course is not empty. Generate the scaffold content into it anyway?',
                'remedy_options' => ['CONFIRM_SCAFFOLD_INTO_NON_EMPTY_COURSE', 'PICK_DIFFERENT_COURSE'],
            ]]);
        }

        return $this->pass([
            'courseid' => (int)$course->id,
            'coursefullname' => (string)$course->fullname,
            'topic' => $topic,
            'chapters' => $chapters,
            'practicequizzes' => !empty($input['practicequizzes']),
            'finalquiz' => !empty($input['finalquiz']),
            'quizquestions' => max(1, (int)($input['quizquestions'] ?? 0)),
            'outputlang' => trim((string)($input['outputlang'] ?? '')),
            'ambientcontextid' => (int)$contextid,
        ]);
    }

    /**
     * Generate and create the scaffold (generation happens here, never in preflight).
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $courseid = (int)($preparedinput['courseid'] ?? 0);
        $topic = (string)($preparedinput['topic'] ?? '');
        if ($courseid <= 0 || $topic === '') {
            return $this->build_error_result('Missing prepared course or topic.');
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->build_error_result('Target course could not be loaded.');
        }

        $chapters = (int)($preparedinput['chapters'] ?? self::DEFAULT_CHAPTERS);
        $lang = (string)($preparedinput['outputlang'] ?? '');
        $ambient = (int)($preparedinput['ambientcontextid'] ?? $contextid);
        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $ambient);
        $threadid = $thread ? (int)$thread->id : 0;
        $generator = new course_content_generation_service($store);

        // 1) Outline first: if the model cannot deliver a structure, nothing is written at all.
        $outlineresult = $generator->generate_outline($threadid, $ambient, $userid, $topic, $chapters, $lang);
        if (empty($outlineresult['success'])) {
            return $this->build_error_result(
                'No content was created — outline generation failed: ' . (string)$outlineresult['error']
            );
        }
        $outline = (array)$outlineresult['outline'];
        $chaptertitles = array_values((array)$outline['chapters']);
        $stages = [];
        $warnings = [];

        // 2) Welcome section (section 0 keeps the course default name).
        $this->ensure_sections($course, count($chaptertitles) + 2);
        $course = get_course($courseid);
        $welcometitle = trim((string)($outline['welcometitle'] ?? ''));
        $this->set_section_name($course, 0, $welcometitle);
        $this->create_page(
            $course,
            0,
            $welcometitle !== '' ? $welcometitle : 'Overview',
            (string)($outline['welcomehtml'] ?? ''),
            (string)($outline['overviewhtml'] ?? '<p></p>')
        );
        $stages[] = 'welcome section';

        // 3) Chapter sections: one page (and optionally one practice quiz) each.
        $quizservice = new quiz_question_service();
        // IST quiz-question count actually created (thread 587/C5): the model may deliver fewer
        // parseable GIFT questions than requested (Soll), and the honest number is what landed in
        // the quizzes — reported via produced_outputs so the synchronizer states the real count.
        $questioncount = 0;
        foreach ($chaptertitles as $i => $chaptertitle) {
            $sectionnum = $i + 1;
            $this->set_section_name($course, $sectionnum, (string)$chaptertitle);

            $chapter = $generator->generate_chapter_html(
                $threadid,
                $ambient,
                $userid,
                $topic,
                (string)$chaptertitle,
                $i + 1,
                count($chaptertitles),
                $lang
            );
            $pagehtml = !empty($chapter['success'])
                ? (string)$chapter['html']
                : '<p>' . get_string('agent_scaffold_chapter_failed', 'bookingextension_agent') . '</p>';
            if (empty($chapter['success'])) {
                $warnings[] = 'chapter ' . ($i + 1) . ' content generation failed: ' . (string)$chapter['error'];
            }
            $this->create_page($course, $sectionnum, (string)$chaptertitle, '', $pagehtml);

            if (!empty($preparedinput['practicequizzes'])) {
                $questioncount += $this->create_generated_quiz(
                    $course,
                    $sectionnum,
                    (string)$chaptertitle . ' — Quiz',
                    $pagehtml,
                    (int)($preparedinput['quizquestions'] ?? self::FINAL_QUIZ_QUESTIONS),
                    $lang,
                    $quizservice,
                    $userid,
                    $ambient,
                    $warnings
                );
            }
            $stages[] = 'chapter ' . ($i + 1);
        }

        // 4) Closing section: summary page and optionally the graded final quiz.
        $closingsection = count($chaptertitles) + 1;
        $summarytitle = trim((string)($outline['summarytitle'] ?? ''));
        $this->set_section_name($course, $closingsection, $summarytitle);
        $this->create_page(
            $course,
            $closingsection,
            $summarytitle !== '' ? $summarytitle : 'Summary',
            '',
            (string)($outline['summaryhtml'] ?? '<p></p>')
        );
        if (!empty($preparedinput['finalquiz'])) {
            $questioncount += $this->create_generated_quiz(
                $course,
                $closingsection,
                (string)($outline['summarytitle'] ?? 'Final quiz'),
                $topic . ': ' . implode('; ', array_map('strval', $chaptertitles)),
                (int)($preparedinput['quizquestions'] ?? self::FINAL_QUIZ_QUESTIONS),
                $lang,
                $quizservice,
                $userid,
                $ambient,
                $warnings
            );
        }
        $stages[] = 'closing section';

        $courseurl = (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
        $detail = 'Course content created in "' . $course->fullname . '" (id=' . $courseid . ', '
            . count($chaptertitles) . ' chapters'
            . ($questioncount > 0 ? ', ' . $questioncount . ' quiz question(s) created' : '')
            . ', link=' . $courseurl . ').'
            . (empty($warnings) ? '' : ' WARNINGS: ' . implode(' | ', $warnings));

        return [
            'status' => 'executed',
            'detail' => $detail,
            'usermessage' => $detail,
            'resultid' => $courseid,
            'observation_full' => $detail . "\nStages: " . implode(', ', $stages) . '.',
            'produced_outputs' => [
                'courseid' => $courseid,
                'chapters' => count($chaptertitles),
                'questions' => $questioncount,
            ],
        ];
    }

    /**
     * Activities that are NOT part of the expected set (Moodle defaults for now,
     * template-manifest slots in V2).
     *
     * @param \stdClass $course
     * @param int $userid
     * @return \cm_info[]
     */
    private function list_foreign_activities(\stdClass $course, int $userid): array {
        global $DB;

        $foreign = [];
        foreach (get_fast_modinfo($course, $userid)->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            // Moodle's auto-created announcements forum: detected via module DATA
            // (forum.type = news), never via localized names.
            if (
                $cm->modname === 'forum'
                && (string)$DB->get_field('forum', 'type', ['id' => (int)$cm->instance]) === 'news'
            ) {
                continue;
            }
            $foreign[] = $cm;
        }
        return $foreign;
    }

    /**
     * Make sure the course has at least $count sections (0-based section numbers).
     *
     * @param \stdClass $course
     * @param int $count
     * @return void
     */
    private function ensure_sections(\stdClass $course, int $count): void {
        $modinfo = get_fast_modinfo($course);
        $existing = count($modinfo->get_section_info_all());
        for ($i = $existing; $i < $count; $i++) {
            course_create_section($course);
        }
    }

    /**
     * Name a section (empty name keeps the format default).
     *
     * @param \stdClass $course
     * @param int $sectionnum
     * @param string $name
     * @return void
     */
    private function set_section_name(\stdClass $course, int $sectionnum, string $name): void {
        global $DB;
        if (trim($name) === '') {
            return;
        }
        $section = $DB->get_record(
            'course_sections',
            ['course' => (int)$course->id, 'section' => $sectionnum],
            '*',
            IGNORE_MISSING
        );
        if ($section) {
            course_update_section($course, $section, ['name' => trim($name)]);
        }
    }

    /**
     * Create one mod_page through the shared headless-form foundation.
     *
     * @param \stdClass $course
     * @param int $sectionnum
     * @param string $name
     * @param string $intro
     * @param string $contenthtml
     * @return void
     */
    private function create_page(\stdClass $course, int $sectionnum, string $name, string $intro, string $contenthtml): void {
        $moduleinfo = (new module_form_contract())->build_prepared_moduleinfo(
            $course,
            'page',
            $sectionnum,
            $name,
            $intro,
            ['page' => $contenthtml]
        );
        // The add_moduleinfo() call runs headless (no mform), and page_add_instance() only copies the
        // 'page' editor into the content column when a form is present — set the column
        // directly so the generated body survives the create (thread: empty chapter pages).
        $moduleinfo->content = $contenthtml;
        $moduleinfo->contentformat = FORMAT_HTML;
        (new activity_creation_service())->create($moduleinfo, $course);
    }

    /**
     * Create one quiz populated with generated questions (generation-first, like add_quiz).
     *
     * @param \stdClass $course
     * @param int $sectionnum
     * @param string $name
     * @param string $sourcetext Generation source (chapter html / chapter titles).
     * @param int $count
     * @param string $lang
     * @param quiz_question_service $quizservice
     * @param int $userid
     * @param int $ambientcontextid
     * @param string[] $warnings Collector for non-fatal failures.
     * @return int The number of questions actually referenced into the created quiz (0 on skip/fail).
     */
    private function create_generated_quiz(
        \stdClass $course,
        int $sectionnum,
        string $name,
        string $sourcetext,
        int $count,
        string $lang,
        quiz_question_service $quizservice,
        int $userid,
        int $ambientcontextid,
        array &$warnings
    ): int {
        $coursecontext = context_course::instance((int)$course->id);
        $generated = $quizservice->generate_into_bank(
            $course,
            $coursecontext,
            ['content' => $sourcetext, 'count' => $count, 'outputlang' => $lang],
            $userid,
            $ambientcontextid
        );
        if ($generated['error'] !== null || empty($generated['questionids'])) {
            $warnings[] = 'quiz "' . $name . '" skipped: ' . (string)($generated['error'] ?? 'no questions generated');
            return 0;
        }

        try {
            $moduleinfo = (new module_form_contract())->build_prepared_moduleinfo(
                $course,
                'quiz',
                $sectionnum,
                $name,
                '',
                []
            );
            quiz_question_service::ensure_quiz_feedback($moduleinfo);
            if (!isset($moduleinfo->cmidnumber)) {
                $moduleinfo->cmidnumber = '';
            }
            $created = (new activity_creation_service())->create($moduleinfo, $course);
        } catch (\Throwable $e) {
            $warnings[] = 'quiz "' . $name . '" could not be created: ' . $e->getMessage()
                . ' (the generated questions remain in the question bank)';
            return 0;
        }

        $population = $quizservice->reference_existing($course, (int)$created['instance'], $generated['questionids']);
        if (($population['error'] ?? null) !== null) {
            $warnings[] = 'quiz "' . $name . '": questions could not be added: ' . (string)$population['error'];
            return 0;
        }

        // The IST count is what actually landed in the quiz — the parseable questions referenced.
        return count($generated['questionids']);
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

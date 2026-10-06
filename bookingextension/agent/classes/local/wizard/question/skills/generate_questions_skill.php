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

namespace bookingextension_agent\local\wizard\question\skills;

use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\questions\course_pdf_source;
use bookingextension_agent\local\wizard\services\questions\question_bank_target_resolver;
use bookingextension_agent\local\wizard\services\questions\question_generation_service;
use bookingextension_agent\local\wizard\services\questions\question_import_service;
use bookingextension_agent\local\wizard\services\questions\question_preview_renderer;
use bookingextension_agent\local\wizard\services\preview_support;
use context;
use moodle_url;

/**
 * Core skill: generate Moodle questions (question.generate_questions).
 *
 * Takes its source text from the `content` input the user provided directly in the chat, from PDF
 * files that live IN the target course as resource activities (`resourcecmid`/`usecoursepdfs`, read
 * server-side via course_pdf_resolver — no re-upload needed), or from the most recent uploaded
 * document (injected into the conversation as a "--- DOCUMENT --" block); a document upload is
 * optional. Asks the model to write the questions as GIFT and imports them into the course's
 * question bank (a mod_qbank activity, created if needed). If an import fails, the import errors
 * are fed back to the model and generation is retried.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_questions_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;

    /** Skill name constant. */
    public const SKILL_NAME = 'question.generate_questions';

    /** Default number of questions when not specified. */
    private const DEFAULT_COUNT = 5;

    /** How many generate+import attempts before giving up. */
    private const MAX_RETRIES = 3;

    /** Supported question types (MVP). */
    private const ALLOWED_QTYPES = ['multichoice', 'truefalse', 'shortanswer'];

    /** @var int Runtime thread id injected by the executor (0 = resolve the ambient chat thread). */
    private int $runtimethreadid = 0;

    /**
     * Constructor. Mutating skill (writes questions) — broad write, requires confirmation.
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Receive the executing thread id from the engine (duck-typed executor injection).
     *
     * Chat executions resolve the ambient chat thread themselves, but channel-bound
     * executions (e.g. the MCP facade) run on a thread get_active_thread() cannot see.
     * The executor hands the actual thread id to skills that declare this setter.
     *
     * @param int $threadid
     * @return void
     */
    public function set_runtime_threadid(int $threadid): void {
        $this->runtimethreadid = $threadid;
    }

    /**
     * Resolve the thread this execution belongs to (injected id first, chat thread fallback).
     *
     * @param int $userid
     * @param int $contextid
     * @return int Thread id, or 0 when none exists.
     */
    private function resolve_thread_id(int $userid, int $contextid): int {
        if ($this->runtimethreadid > 0) {
            return $this->runtimethreadid;
        }
        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $contextid);
        return $thread ? (int)$thread->id : 0;
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
     * Human-readable preview of the question generation (tier-3): target + plan.
     *
     * The source content (PDF text) is intentionally not shown — only the plan.
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = preview_support::lang($input);
        $rows = [];
        preview_support::push($rows, preview_support::str('previewlabel_course', $lang), preview_support::course_name($input));
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_category', $lang),
            preview_support::text($input['target_category'] ?? null)
                ?? preview_support::text($input['target_categorylabel'] ?? null)
        );
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_number', $lang),
            preview_support::posint($input['count'] ?? null)
        );
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_types', $lang),
            preview_support::list_value($input['qtypes'] ?? null)
        );
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_difficulty', $lang),
            preview_support::text($input['difficulty'] ?? null)
        );
        preview_support::push(
            $rows,
            preview_support::str('previewlabel_sourcepdfs', $lang),
            $this->describe_pdf_source($input, $lang)
        );
        return [
            'title' => preview_support::str('previewtitle_generatequestions', $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Human-readable label of the category the gate resolved, or null when the user named one / none was chosen.
     *
     * Mirrors step 5 of resolve_target_selection(): only when the user named nothing and the gate picked a
     * target itself does the confirmation card need to say so, before anything is written.
     *
     * @param array $input Raw command input.
     * @param int $categoryid Resolved category id (0 = execute resolves the course default lazily).
     * @param context $context Ambient context of the run.
     * @param int $userid
     * @return string|null
     */
    private function resolved_target_label(array $input, int $categoryid, context $context, int $userid): ?string {
        if ($categoryid <= 0 || trim((string)($input['target_category'] ?? '')) !== '') {
            return null;
        }
        foreach ((new question_bank_target_resolver())->list_writable_targets($context, $userid) as $target) {
            if ((int)$target['categoryid'] === $categoryid) {
                $label = $target['bankname'] . ' › ' . $target['categoryname'];
                return !empty($target['isdefault'])
                    ? get_string('previewvalue_default_category', 'bookingextension_agent', $label)
                    : $label;
            }
        }
        return null;
    }

    /**
     * Preview value for the course-PDF source, or null when the input does not use one.
     *
     * @param array $input Raw command input.
     * @param string $lang Conversation language.
     * @return string|null
     */
    private function describe_pdf_source(array $input, string $lang): ?string {
        $resourcecmid = (int)($input['resourcecmid'] ?? 0);
        if ($resourcecmid > 0) {
            try {
                $cm = get_coursemodule_from_id('resource', $resourcecmid);
                if ($cm) {
                    return format_string($cm->name);
                }
            } catch (\Throwable $e) {
                // Fall through to the generic label; the preview must never throw.
                $cm = null;
            }
            return 'cmid ' . $resourcecmid;
        }
        if (preview_support::truthy($input['usecoursepdfs'] ?? null)) {
            return preview_support::str('ai_generatequestions_previewallcoursepdfs', $lang);
        }
        return null;
    }

    /**
     * The questions land in a course question bank, so this skill needs course scope.
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
     * Native capability required to create questions (Gate 2).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/question:add'];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            // First 240 characters = selector window (#2419, #2423 GQ-4: dictated question into the bank).
            'description' => 'Put questions into the course question bank: generated from an uploaded document, course PDFs '
                . '(usecoursepdfs/resourcecmid) or a topic, or a dictated question and answer. Question types: multiple choice, '
                . 'true/false, short answer; an upload is optional — facts or an explicit question and answer in the chat are '
                . 'enough. It creates questions only, no activity (e.g. "make me a question", "put questions from the document '
                . 'into the question bank").',
            'is' => 'Questions in the question bank.',
            // Wave 30 (UQ-4): "add questions from the PDF to the quiz" belongs to update_quiz since it reads course PDFs.
            'not' => 'Creating the quiz activity itself (course.add_quiz); questions for an existing quiz '
                . '(course.update_quiz); other activities or resources (course.add_activity).',
            'readonly' => false,
            'example_utterances' => [
                // Wave 32 review: no anchor repeats the shape of a test prompt (GQ-1 "turn it into ... bank questions").
                'generate questions from this PDF and store them in the question bank',
                'make me a multiple choice question about photosynthesis',
                'generate 10 questions from the document',
                'use the PDF files of this course as the source for new questions',
                'make questions from the handout file in course Biology 101',
                'add some questions to the question bank',
                'write practice questions on this material into the question bank',
            ],
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'Source material only, verbatim from the chat: topic, facts, '
                        . 'or a dictated question and answer. '
                        . 'Never write the questions. Empty if a document is the source.',
                    'required' => false,
                ],
                'count' => [
                    'type' => 'integer',
                    // Wave 32 (GQ-4): a dictated question is a number too - as many as were dictated.
                    'description' => 'How many questions (max ' . question_generation_service::MAX_COUNT
                        . '): the user\'s number, or as many as they dictated. No default: else leave out and the system asks.',
                    'required' => false,
                ],
                'qtypes' => [
                    'type' => 'array',
                    'description' => 'Question types to use: ' . implode(', ', self::ALLOWED_QTYPES) . '.',
                    'items' => ['type' => 'string'],
                    'required' => false,
                ],
                'difficulty' => [
                    'type' => 'string',
                    'description' => 'Difficulty level: easy, medium or hard.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'ISO 639-1 language code for the questions (e.g. "de", "en").',
                    'required' => false,
                ],
                'target_category' => [
                    'type' => 'string',
                    'description' => 'Question-bank category, only when the user names one (their words). Never ask or invent: the '
                        . 'system lists the categories when it matters.',
                    'required' => false,
                ],
                'target_categoryid' => [
                    'type' => 'integer',
                    'description' => 'Internal id of the chosen category; the system fills it when the user picks from its list. '
                        . 'Never guess an id.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'A DIFFERENT course, only when the user names one (their '
                        . 'words); the system resolves it. Empty = '
                        . 'the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    // Wave 32: constructions copied the page's course id (11) although the user named another course.
                    'description' => 'Numeric course id only when the user gave it or an earlier step returned it - never '
                        . 'the current page\'s course. A named course goes into coursequery.',
                    'required' => false,
                ],
                'resourcecmid' => [
                    'type' => 'integer',
                    'description' => 'Cmid of ONE file/resource whose PDF is the source, only when that id is already known. Never '
                        . 'guess an id.',
                    'required' => false,
                ],
                'usecoursepdfs' => [
                    'type' => 'boolean',
                    'description' => 'true = use the PDFs stored in the target course ("the PDF in the course"). The system reads '
                        . 'them - never ask for the file, its name or content.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => [],
                'anchor_fields' => [],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        // No count: the field has no default and the user's number is taken, never an example's (wave 30).
        return [
            'qtypes' => ['multichoice', 'truefalse'],
            'difficulty' => 'medium',
            'outputlang' => 'en',
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
                'id' => 'question.generate_questions_request',
                'description' => 'The user wants questions generated from an uploaded document, the PDF files of a course or'
                    . ' text they give, and put into the question bank - no new activity.',
            ],
        ];
    }

    /**
     * Construction-phase guidance and discovery triggers.
     *
     * Surfaced unconditionally once this skill is selected, so the constructor knows a document upload
     * is optional and the user's inline content can be used directly.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'question.generate_questions',
                'triggers' => [
                    'make a question', 'create a question', 'generate questions',
                    'questions from pdf', 'questions from document', 'insert question in moodle',
                    'make me a question', 'generate question',
                    'questions from the document', 'insert question into moodle',
                    'questions from the files in the course',
                ],
                'guidance' => [
                    '- question.generate_questions creates Moodle questions and saves them into the course question'
                        . ' bank itself, so do NOT look for a separate skill to "insert" a question.',
                    '- A document/PDF upload is OPTIONAL. If the user states the topic, facts, or an explicit question'
                        . ' and correct answer in the chat, pass that text verbatim as input.content and proceed; do'
                        . ' NOT ask the user to upload a document.',
                    // Wave 32 (AQ-1): no guidance line speaks of a quiz - this skill creates bank questions only.
                    '- When the user wants the questions based on PDFs/files that are already IN the course (e.g.'
                        . ' "questions about the PDFs in this course"), set input.usecoursepdfs=true (or input.resourcecmid'
                        . ' for one specific file whose cmid is known). The system reads and extracts those course'
                        . ' files itself — do NOT ask the user to upload them and do NOT paste file text into'
                        . ' input.content.',
                    '- Only ask the user for a source if NEITHER a document was uploaded NOR course PDFs were requested'
                        . ' NOR any content was provided.',
                    '- Set input.count to the number of questions the user asked for; a question the user dictated'
                        . ' (question and answer given) counts as asked for, so input.count is the number of dictated'
                        . ' questions. If they neither gave a number nor dictated a question,'
                        . ' leave input.count out so the system asks (no silent default). Set input.qtypes when named'
                        . ' (allowed types: multichoice, truefalse, shortanswer).',
                    '- Do NOT ask the user which question bank or category to use, and never invent a category id. Leave'
                        . ' input.target_category and input.target_categoryid empty: the system takes the bank\'s default'
                        . ' category, or lists the categories and asks when no single default exists. Only if the user'
                        . ' explicitly names a category, pass that name verbatim as input.target_category.',
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

        // B4 (Georg 2026-07-14): the question count is NEVER silently defaulted — if the user did
        // not say how many questions, ask. RECOVERABLE_INPUT_ERROR routes it as a clarification turn,
        // not a confirmation card with a fabricated number (thread 587).
        if (!isset($input['count']) || trim((string)$input['count']) === '') {
            return [
                'valid' => false,
                'errors' => ['How many questions should be generated?'],
                'repair' => ['count is required: ask the user for the number of questions; never default it.'],
                'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
                'ambiguities' => [],
            ];
        }

        if ($input['count'] !== '') {
            $count = (int)$input['count'];
            if ($count < 1 || $count > question_generation_service::MAX_COUNT) {
                $errors[] = 'count must be between 1 and ' . question_generation_service::MAX_COUNT . '.';
            }
        }

        if (isset($input['qtypes']) && $input['qtypes'] !== '') {
            foreach ((array)$input['qtypes'] as $qtype) {
                if (!in_array((string)$qtype, self::ALLOWED_QTYPES, true)) {
                    $errors[] = 'Unsupported question type: ' . (string)$qtype . '.';
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Deep validation: document text present + native capability at the course context.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        // The course context comes first: the course-PDF source paths need the target course id.
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        if (!$coursecontext) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => 'Questions can only be generated within a course.',
                'code' => 'GENERATE_QUESTIONS_NO_COURSE',
            ]]);
        }

        $source = $this->resolve_source($input, $contextid, $userid, (int)$coursecontext->instanceid);
        if (!empty($source['issues'])) {
            return $this->invalid($source['issues']);
        }
        $sourcetext = $source['text'];
        if ($sourcetext === null) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => 'I need something to base the questions on. Either upload a document/PDF, or tell me '
                    . 'the topic, the facts, or the exact question and its correct answer.',
                'code' => 'GENERATE_QUESTIONS_NO_SOURCE',
            ]]);
        }

        // Gate 2: the user must natively be allowed to add questions in this course.
        if (!has_capability('moodle/question:add', $coursecontext, $userid)) {
            return $this->invalid([[
                'severity' => 'needs_clarification',
                'message' => get_string('nopermissions', 'error', 'moodle/question:add'),
                'code' => 'NO_NATIVE_CAPABILITY',
            ]]);
        }

        // When the course already offers more than one writable question-bank category, ask the user
        // where exactly to create the questions instead of silently picking the default bank.
        $targetselection = $this->resolve_target_selection($input, $context, $userid);
        if (is_array($targetselection)) {
            return $targetselection;
        }

        $qtypes = array_values(array_filter(array_map('strval', (array)($input['qtypes'] ?? []))));
        $qtypes = array_values(array_intersect($qtypes, self::ALLOWED_QTYPES));

        return $this->pass([
            'sourcetext' => $sourcetext,
            'sourcefiles' => $source['files'],
            'sourcetruncated' => $source['truncated'],
            'count' => max(1, min(question_generation_service::MAX_COUNT, (int)($input['count'] ?? self::DEFAULT_COUNT))),
            'qtypes' => $qtypes,
            'difficulty' => (string)($input['difficulty'] ?? 'medium'),
            'outputlang' => $this->get_output_language($input),
            'target_categoryid' => $targetselection,
            // The confirmation card reads the prepared input: say where the questions will land when the
            // user named no category and the gate resolved it (the bank's default, or the only bank).
            'target_categorylabel' => $this->resolved_target_label($input, $targetselection, $context, $userid),
        ]);
    }

    /**
     * Decide which question-bank category the questions go into.
     *
     * Returns the chosen category id (0 = let execute auto-resolve the course default bank), or a
     * needs_clarification preflight result when the course offers more than one writable target and
     * the user has not picked one yet.
     *
     * @param array   $input
     * @param context $context Ambient context of the run.
     * @param int     $userid
     * @return int|array
     */
    private function resolve_target_selection(array $input, context $context, int $userid) {
        $resolver = new question_bank_target_resolver();
        $targets = $resolver->list_writable_targets($context, $userid);

        // No bank exists yet: nothing to choose between, execute lazily creates the default.
        if (empty($targets)) {
            return 0;
        }

        // 1) An explicit, valid category id (the system filled it in from a prior selection) wins.
        $chosenid = (int)($input['target_categoryid'] ?? 0);
        if ($chosenid > 0) {
            foreach ($targets as $target) {
                if ($target['categoryid'] === $chosenid) {
                    return $chosenid;
                }
            }
        }

        // 2) A category the user named in plain text: resolve it deterministically against the real
        // list here (the planner never knows the ids, so it can only pass the wording).
        $name = trim((string)($input['target_category'] ?? ''));
        if ($name !== '') {
            $matches = $this->match_targets_by_name($targets, $name);
            if (count($matches) === 1) {
                return (int)$matches[0]['categoryid'];
            }
            if (count($matches) > 1) {
                return $this->build_target_clarification(
                    $matches,
                    'More than one question category matches "' . $name . '". Which one did you mean?'
                );
            }
            return $this->build_target_clarification(
                $targets,
                'I could not find a question category called "' . $name . '". Please choose one of these:'
            );
        }

        // 3) An explicit id that did not match (stale / not writable) and no name to fall back on: re-ask.
        if ($chosenid > 0) {
            return $this->build_target_clarification(
                $targets,
                'That question category is not available to you. Please choose one of these:'
            );
        }

        // 4) A single writable target => no ambiguity; execute resolves (and lazily creates) the default.
        if (count($targets) <= 1) {
            return 0;
        }

        // 5) Several writable targets, nothing chosen, but exactly one of them is a bank's own default
        // category => take it. Baseline runs 8-29: GQ-2/3/4 and AQ-1 asked in EVERY run between
        // "Default for ..." and a second category although the user had named none. Moodle's default is
        // not an invented value - it is where a click in the UI would put the questions too - and the
        // confirmation card names it before anything is written (decision George 2026-09-23).
        $defaults = array_values(array_filter($targets, static fn(array $target): bool => !empty($target['isdefault'])));
        if (count($defaults) === 1) {
            return (int)$defaults[0]['categoryid'];
        }
        // 6) Several writable targets and no single default => ask, listing them all.
        return $this->build_target_clarification(
            $targets,
            'This course has more than one question bank category you can add to. '
                . 'Where exactly should I create the questions?'
        );
    }

    /**
     * Match the writable targets against a user-provided category name.
     *
     * Tries an exact (case-insensitive) match on the category name or the "Bank › Category" label
     * first, then falls back to a substring match on the category name.
     *
     * @param array[] $targets
     * @param string $name
     * @return array[]
     */
    private function match_targets_by_name(array $targets, string $name): array {
        $needle = \core_text::strtolower(trim($name));
        if ($needle === '') {
            return [];
        }

        $exact = [];
        foreach ($targets as $target) {
            $category = \core_text::strtolower((string)$target['categoryname']);
            $label = \core_text::strtolower($target['bankname'] . ' › ' . $target['categoryname']);
            if ($category === $needle || $label === $needle) {
                $exact[] = $target;
            }
        }
        if (!empty($exact)) {
            return $exact;
        }

        $partial = [];
        foreach ($targets as $target) {
            if (str_contains(\core_text::strtolower((string)$target['categoryname']), $needle)) {
                $partial[] = $target;
            }
        }
        return $partial;
    }

    /**
     * Build a needs_clarification result that lists the available question-bank categories.
     *
     * The human-readable message carries the category ids, and a structured 'options' list is attached
     * so the answer can be mapped back deterministically to the target_categoryid input.
     *
     * @param array[] $targets
     * @param string $lead Lead-in sentence for the message.
     * @return array
     */
    private function build_target_clarification(array $targets, string $lead): array {
        $lines = [$lead, ''];
        $options = [];
        foreach ($targets as $target) {
            $lines[] = sprintf(
                '- %s › %s (%d question(s)) [category id %d]',
                $target['bankname'],
                $target['categoryname'],
                (int)$target['questioncount'],
                (int)$target['categoryid']
            );
            $options[] = [
                'categoryid' => (int)$target['categoryid'],
                'label' => $target['bankname'] . ' › ' . $target['categoryname'],
                'bank' => $target['bankname'],
                'category' => $target['categoryname'],
                'questioncount' => (int)$target['questioncount'],
            ];
        }
        $lines[] = '';
        $lines[] = 'Just reply with the name of the category you want and I will create the questions there.';

        return $this->invalid([[
            'severity' => 'needs_clarification',
            'message' => implode("\n", $lines),
            'code' => 'GENERATE_QUESTIONS_TARGET_AMBIGUOUS',
            'options' => $options,
        ]]);
    }

    /**
     * Generate the questions and import them into the course question bank, retrying on import errors.
     *
     * @param array $preparedinput
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $sourcetext = (string)($preparedinput['sourcetext'] ?? '');
        if (trim($sourcetext) === '') {
            return $this->build_error_result('No document text was available to generate questions from.');
        }

        $params = [
            'count' => (int)($preparedinput['count'] ?? self::DEFAULT_COUNT),
            'qtypes' => (array)($preparedinput['qtypes'] ?? []),
            'difficulty' => (string)($preparedinput['difficulty'] ?? 'medium'),
            'outputlang' => (string)($preparedinput['outputlang'] ?? 'en'),
        ];

        $store = new conversation_store();
        $threadid = $this->resolve_thread_id($userid, $contextid);

        // Resolve the target question bank. This is the confirmed mutation point. When the user picked
        // a specific category in the clarification, honour it; otherwise get-or-create the course default.
        $targetcategoryid = (int)($preparedinput['target_categoryid'] ?? 0);
        $ambient = context::instance_by_id($contextid, MUST_EXIST);
        try {
            $resolver = new question_bank_target_resolver();
            $target = $targetcategoryid > 0
                ? $resolver->resolve_selected_target($ambient, $targetcategoryid, $userid)
                : $resolver->resolve_for_context($ambient);
        } catch (\Throwable $e) {
            return $this->build_error_result($e->getMessage());
        }

        $generator = new question_generation_service($store);
        $importer = new question_import_service();

        $feedback = '';
        $lasterror = '';
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $generated = $generator->generate_gift($threadid, $contextid, $userid, $sourcetext, $params, $feedback);
            if (empty($generated['success'])) {
                $lasterror = (string)$generated['error'];
                $feedback = $lasterror;
                continue;
            }

            $imported = $importer->import_gift(
                (string)$generated['gift'],
                $target['context'],
                $target['course'],
                $targetcategoryid > 0 ? $targetcategoryid : null
            );
            if (!empty($imported['success'])) {
                // Moodle 4.5 course-context target: cm is null, the bank label is the course name.
                return $this->build_success_result(
                    (int)$imported['imported'],
                    array_map('intval', (array)$imported['questionids']),
                    $target['cm'] !== null ? (int)$target['cm']->id : 0,
                    $target['cm'] !== null
                        ? (string)$target['cm']->get_formatted_name()
                        : (string)$target['course']->fullname,
                    (int)$target['context']->id,
                    $attempt,
                    (array)($preparedinput['sourcefiles'] ?? []),
                    !empty($preparedinput['sourcetruncated']),
                    (int)$target['course']->id
                );
            }

            $lasterror = (string)$imported['errors'];
            $feedback = $lasterror;
        }

        return $this->build_error_result(
            'Could not generate importable questions after ' . self::MAX_RETRIES . ' attempts. '
                . 'Last error: ' . $lasterror
        );
    }

    /**
     * Resolve the text the questions are generated from.
     *
     * Priority: explicit `content` from the chat > PDFs stored in the target course
     * (resourcecmid for one specific resource, usecoursepdfs for all visible ones) > the most
     * recent uploaded-document block in the conversation.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @param int   $courseid Target course id (already resolved from the operating context).
     * @return array text (string|null), files (array of cmid/name/filename per used course PDF),
     *               truncated (bool), issues (array, non-empty when the course-PDF source failed).
     */
    private function resolve_source(array $input, int $contextid, int $userid, int $courseid): array {
        $none = ['text' => null, 'files' => [], 'truncated' => false, 'issues' => []];

        $content = trim((string)($input['content'] ?? ''));
        if ($content !== '') {
            return ['text' => $content] + $none;
        }

        $resourcecmid = (int)($input['resourcecmid'] ?? 0);
        if ($resourcecmid > 0 || preview_support::truthy($input['usecoursepdfs'] ?? null)) {
            return $this->resolve_course_pdf_source($resourcecmid, $courseid, $userid, $this->get_output_language($input));
        }

        return ['text' => $this->extract_document_text($contextid, $userid)] + $none;
    }

    /**
     * Source the text from PDFs that live in the target course as resource activities.
     *
     * The files are read server-side (never through the LLM) for the ACTING user only —
     * course_pdf_resolver lists nothing the user cannot see. Every failure is returned as a
     * localized needs_clarification issue, never as a raw exception.
     *
     * @param int    $resourcecmid One specific resource cm id, or 0 for all visible course PDFs.
     * @param int    $courseid Target course id.
     * @param int    $userid Acting user id.
     * @param string $lang Conversation output language.
     * @return array Same shape as resolve_source().
     */
    private function resolve_course_pdf_source(int $resourcecmid, int $courseid, int $userid, string $lang): array {
        // Shared with course.add_quiz / course.update_quiz since wave 30 (UQ-4).
        return (new course_pdf_source())->resolve($resourcecmid, $courseid, $userid, $lang);
    }

    /**
     * The effective character cap for course-PDF source text before the LLM call.
     *
     * The chat-upload path already caps the injected document text at pdf_text_extractor::MAX_CHARS
     * (applied by attachment_processor at upload time), so the course-PDF path reuses exactly that
     * existing cap as its total extraction budget instead of inventing a new one.
     *
     * @return int
     */
    private static function effective_pdf_budget(): int {
        return course_pdf_source::effective_budget();
    }

    /**
     * Find the most recent uploaded-document text in the conversation.
     *
     * @param int $contextid
     * @param int $userid
     * @return string|null
     */
    private function extract_document_text(int $contextid, int $userid): ?string {
        $threadid = $this->resolve_thread_id($userid, $contextid);
        if ($threadid <= 0) {
            return null;
        }

        $store = new conversation_store();
        $messages = $store->get_recent_messages($threadid, 20);
        foreach (array_reverse($messages) as $message) {
            if ((string)($message->role ?? '') !== 'user') {
                continue;
            }
            $document = self::parse_document_block((string)($message->content ?? ''));
            if ($document !== null) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Extract the text from a "--- DOCUMENT: … --- … --- END DOCUMENT ---" block.
     *
     * @param string $content
     * @return string|null
     */
    private static function parse_document_block(string $content): ?string {
        if (preg_match('/---\s*DOCUMENT:.*?---\s*(.*?)\s*---\s*END DOCUMENT\s*---/s', $content, $matches)) {
            $text = trim($matches[1]);
            return $text !== '' ? $text : null;
        }
        return null;
    }

    /**
     * Build the success result payload.
     *
     * @param int    $imported
     * @param int[]  $questionids
     * @param int    $cmid
     * @param string $bankname
     * @param int    $bankcontextid Context id of the question bank module (used by the inline preview).
     * @param int    $attempts
     * @param array  $sourcefiles Course PDFs the source text came from (cmid/name/filename each).
     * @param bool   $sourcetruncated Whether the assembled PDF text hit the extraction budget.
     * @param int    $courseid Course id, used for the bank URL when cmid is 0 (Moodle 4.5 course-context target).
     * @return array
     */
    private function build_success_result(
        int $imported,
        array $questionids,
        int $cmid,
        string $bankname,
        int $bankcontextid,
        int $attempts,
        array $sourcefiles = [],
        bool $sourcetruncated = false,
        int $courseid = 0
    ): array {
        $bankurl = $cmid > 0
            ? (new moodle_url('/question/edit.php', ['cmid' => $cmid]))->out(false)
            : (new moodle_url('/question/edit.php', ['courseid' => $courseid]))->out(false);
        $message = $imported . ' question(s) were created in the course question bank "' . $bankname . '".';

        $lines = [
            'Created ' . $imported . ' question(s) in question bank "' . $bankname . '" (after ' . $attempts . ' attempt(s)).',
            'Question ids: ' . implode(', ', $questionids),
            'Question bank: ' . $bankurl,
        ];

        // Name the course PDFs the questions are based on — each with the real resource link, so
        // the final answer can reference them as clickable entities.
        if (!empty($sourcefiles)) {
            $lines[] = get_string('ai_generatequestions_sourcepdfsheading', 'bookingextension_agent');
            foreach ($sourcefiles as $sourcefile) {
                $resourceurl = (new moodle_url(
                    '/mod/resource/view.php',
                    ['id' => (int)($sourcefile['cmid'] ?? 0)]
                ))->out(false);
                $lines[] = '- ' . (string)($sourcefile['filename'] ?? '')
                    . ' ("' . (string)($sourcefile['name'] ?? '') . '"): ' . $resourceurl;
            }
            if ($sourcetruncated) {
                $lines[] = get_string(
                    'ai_generatequestions_sourcetruncated',
                    'bookingextension_agent',
                    number_format(self::effective_pdf_budget())
                );
            }
        }

        $observation = implode("\n", $lines);

        return [
            'status' => 'executed',
            'detail' => $message,
            'usermessage' => $message . ' You can review them here: ' . $bankurl,
            'resultid' => null,
            'question_count' => $imported,
            'created_question_ids' => $questionids,
            'question_bank_url' => $bankurl,
            'question_bank_contextid' => $bankcontextid,
            'observation_full' => $observation,
        ];
    }

    /**
     * Render the freshly created questions inline for the agent preview pane.
     *
     * The executor calls this on the raw execute() result; the returned block is attached under the
     * result's 'preview' key and surfaced in the preview pane. We render the questions with Moodle's
     * native question rendering (the same machinery the standalone preview page uses), so the teacher
     * sees the real, rendered questions inline instead of having to open the preview page.
     *
     * @param array $resultentry The skill result (carries created_question_ids + question_bank_contextid).
     * @param int   $contextid   Ambient context id of the run (unused: questions render in their bank context).
     * @param int   $userid      Acting user id.
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $questionids = array_values(array_filter(array_map('intval', (array)($resultentry['created_question_ids'] ?? []))));
        $bankcontextid = (int)($resultentry['question_bank_contextid'] ?? 0);
        if (empty($questionids) || $bankcontextid <= 0) {
            return null;
        }

        $bankurl = (string)($resultentry['question_bank_url'] ?? '');
        $rendered = (new question_preview_renderer())->render($questionids, $bankcontextid, $bankurl);
        $html = (string)($rendered['html'] ?? '');
        if (trim($html) === '') {
            return null;
        }

        return [
            'type' => 'generated_questions',
            'html' => $html,
            // Render-time JS (qtype init, filters, MathJax) the client runs via core/templates.
            'js' => (string)($rendered['js'] ?? ''),
            'payload' => [
                'question_ids' => $questionids,
                'question_bank_url' => $bankurl,
            ],
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

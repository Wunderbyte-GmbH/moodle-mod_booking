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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\activities;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\preview_support;
use bookingextension_agent\local\wizard\services\questions\course_pdf_source;
use bookingextension_agent\local\wizard\services\questions\question_bank_target_resolver;
use bookingextension_agent\local\wizard\services\questions\question_generation_service;
use bookingextension_agent\local\wizard\services\questions\question_import_service;
use context;
use stdClass;

/**
 * Skill-layer helper that brings questions into a quiz, shared by course.add_quiz and course.update_quiz.
 *
 * Three sources (all reuse existing pieces — no engine changes):
 *  - generate: question_generation_service -> question_import_service (into the course bank) -> reference,
 *  - ids:      reference specific existing bank questions,
 *  - category: add N random questions from a named category.
 *
 * Source resolution is deliberately "smart about incomplete requests": when questions are wanted but no
 * source is given, it returns a clarify plan carrying the available categories so the skill can ask
 * "generate from a document / make some up / use existing from category A, B, C?".
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_question_service {
    /** Default number of questions when a count is needed but not given. */
    public const DEFAULT_COUNT = 5;

    /** Supported generation question types (mirrors generate_questions). */
    private const ALLOWED_QTYPES = ['multichoice', 'truefalse', 'shortanswer'];

    /**
     * Ensure a quiz $moduleinfo carries well-formed overall-feedback fields for add/update_moduleinfo.
     *
     * quiz_after_add_or_update() reads $moduleinfo->feedbacktext[$i]['text'|'format'|'itemid']; the headless
     * form export can leave these malformed (missing format) → "feedbacktextformat cannot be null". This
     * preserves any existing band text while guaranteeing format/itemid, and defaults to one empty band.
     *
     * @param stdClass $moduleinfo
     * @return void
     */
    public static function ensure_quiz_feedback(stdClass $moduleinfo): void {
        $bands = (array)($moduleinfo->feedbacktext ?? []);
        $normalized = [];
        foreach ($bands as $band) {
            if (is_string($band)) {
                $normalized[] = ['text' => $band, 'format' => FORMAT_HTML, 'itemid' => 0];
                continue;
            }
            $band = (array)$band;
            $normalized[] = [
                'text' => (string)($band['text'] ?? ''),
                'format' => isset($band['format']) ? (int)$band['format'] : FORMAT_HTML,
                'itemid' => isset($band['itemid']) ? (int)$band['itemid'] : 0,
            ];
        }
        if (empty($normalized)) {
            $normalized[] = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        }
        $moduleinfo->feedbacktext = $normalized;
        if (!isset($moduleinfo->feedbackboundaries) || !is_array($moduleinfo->feedbackboundaries)) {
            $moduleinfo->feedbackboundaries = [];
        }
    }

    /**
     * Decide how to source questions from the input.
     *
     * @param array $input
     * @param context $coursecontext
     * @param int $userid
     * @return array{mode:string,...} mode = generate|ids|category|clarify|none (+ params / categories).
     */
    public function resolve_source(array $input, context $coursecontext, int $userid): array {
        $content = trim((string)($input['content'] ?? ''));
        $questionids = array_values(array_filter(array_map('intval', (array)($input['questionids'] ?? []))));
        $category = trim((string)($input['category'] ?? ''));
        $randomcount = (int)($input['randomcount'] ?? 0);
        $count = (int)($input['count'] ?? 0);
        $qtypes = array_values(array_intersect(
            array_map('strval', (array)($input['qtypes'] ?? [])),
            self::ALLOWED_QTYPES
        ));

        if ($content !== '') {
            return [
                'mode' => 'generate',
                'content' => $content,
                'count' => $count > 0 ? $count : self::DEFAULT_COUNT,
                'qtypes' => $qtypes,
                'difficulty' => (string)($input['difficulty'] ?? 'medium'),
                'outputlang' => trim((string)($input['outputlang'] ?? '')),
            ];
        }
        // The PDFs stored in the course are a source of their own (wave 30, UQ-4): read server-side at execution
        // through the same course_pdf_source as question.generate_questions.
        $resourcecmid = (int)($input['resourcecmid'] ?? 0);
        if ($resourcecmid > 0 || preview_support::truthy($input['usecoursepdfs'] ?? null)) {
            return [
                'mode' => 'generate',
                'content' => '',
                'pdfsource' => ['resourcecmid' => max(0, $resourcecmid)],
                'count' => $count > 0 ? $count : self::DEFAULT_COUNT,
                'qtypes' => $qtypes,
                'difficulty' => (string)($input['difficulty'] ?? 'medium'),
                'outputlang' => trim((string)($input['outputlang'] ?? '')),
            ];
        }
        if (!empty($questionids)) {
            return ['mode' => 'ids', 'questionids' => $questionids];
        }
        if ($category !== '') {
            return [
                'mode' => 'category',
                'category' => $category,
                'count' => $randomcount > 0 ? $randomcount : ($count > 0 ? $count : self::DEFAULT_COUNT),
            ];
        }

        // Wanted questions but gave no source → ask, listing the available categories.
        $wants = (bool)($input['addquestions'] ?? false)
            || $count > 0 || $randomcount > 0 || !empty($qtypes);
        if ($wants) {
            return ['mode' => 'clarify', 'categories' => $this->list_available_categories($coursecontext, $userid)];
        }
        return ['mode' => 'none'];
    }

    /**
     * List the writable question categories available here (with question counts).
     *
     * @param context $coursecontext
     * @param int $userid
     * @return array[]
     */
    public function list_available_categories(context $coursecontext, int $userid): array {
        return (new question_bank_target_resolver())->list_writable_targets($coursecontext, $userid);
    }

    /**
     * Build the "which question source?" clarification content (message lines + selectable options).
     *
     * Shared by the add/update quiz skills, which supply their own lead sentence and issue code.
     *
     * @param array[] $categories
     * @param string $lead Already-resolved lead sentence.
     * @return array{message:string, options:array[]}
     */
    public static function build_source_clarification(array $categories, string $lead): array {
        $lines = [$lead, ''];
        $lines[] = '- Generate new questions from a document/PDF or material you give me';
        $lines[] = '- Let me make up questions on a topic you name';
        $lines[] = '- Use existing questions from a question category';

        $options = [];
        if (!empty($categories)) {
            $lines[] = '';
            $lines[] = 'Available categories:';
            foreach ($categories as $cat) {
                $lines[] = sprintf(
                    '  - %s › %s (%d question(s))',
                    (string)$cat['bankname'],
                    (string)$cat['categoryname'],
                    (int)$cat['questioncount']
                );
                $options[] = [
                    'categoryid' => (int)$cat['categoryid'],
                    'category' => (string)$cat['categoryname'],
                    'bank' => (string)$cat['bankname'],
                    'questioncount' => (int)$cat['questioncount'],
                ];
            }
        }
        $lines[] = '';
        $lines[] = 'Tell me which option (and the topic/PDF or the category).';

        return ['message' => implode("\n", $lines), 'options' => $options];
    }

    /**
     * Add questions to a quiz according to a resolved (non-clarify) plan.
     *
     * @param stdClass $course
     * @param context $coursecontext
     * @param int $quizinstanceid The quiz instance id.
     * @param array $plan A plan from resolve_source() with mode generate|ids|category.
     * @param int $userid
     * @param int $ambientcontextid Context the chat lives in (for the generation LLM call).
     * @return array{added:int,questionids:int[],mode:string,error:?string}
     */
    public function add_questions_to_quiz(
        stdClass $course,
        context $coursecontext,
        int $quizinstanceid,
        array $plan,
        int $userid,
        int $ambientcontextid
    ): array {
        global $DB;
        $mode = (string)($plan['mode'] ?? 'none');
        $quiz = $DB->get_record('quiz', ['id' => $quizinstanceid], '*', MUST_EXIST);
        $quiz->cmid = (int)get_coursemodule_from_instance('quiz', $quizinstanceid, (int)$course->id, false, MUST_EXIST)->id;

        try {
            if ($mode === 'generate') {
                $gen = $this->generate_into_bank($course, $coursecontext, $plan, $userid, $ambientcontextid);
                if ($gen['error'] !== null) {
                    return ['added' => 0, 'questionids' => [], 'mode' => 'generate', 'error' => $gen['error']];
                }
                $added = $this->reference_ids_into_quiz($quiz, $gen['questionids']);
                return ['added' => $added, 'questionids' => $gen['questionids'], 'mode' => 'generate', 'error' => null];
            }
            if ($mode === 'ids') {
                return $this->add_by_ids($quiz, (array)($plan['questionids'] ?? []));
            }
            if ($mode === 'category') {
                return $this->add_random_from_category(
                    $coursecontext,
                    $quiz,
                    (string)$plan['category'],
                    (int)$plan['count'],
                    $userid
                );
            }
        } catch (\Throwable $e) {
            return ['added' => 0, 'questionids' => [], 'mode' => $mode, 'error' => $e->getMessage()];
        }
        return ['added' => 0, 'questionids' => [], 'mode' => $mode, 'error' => null];
    }

    /**
     * Generate questions into the course question bank (durable, independent of any quiz).
     *
     * Done as its own step so a caller can persist questions BEFORE creating a quiz ("generation-first"):
     * if quiz creation later fails, the questions are still safely in the bank.
     *
     * @param stdClass $course
     * @param context $coursecontext
     * @param array $plan A generate-mode plan (content/count/qtypes/...).
     * @param int $userid
     * @param int $ambientcontextid
     * @return array{questionids:int[],error:?string}
     */
    public function generate_into_bank(
        stdClass $course,
        context $coursecontext,
        array $plan,
        int $userid,
        int $ambientcontextid
    ): array {
        $resolver = new question_bank_target_resolver();
        $target = $resolver->resolve_for_context($coursecontext);

        $store = new conversation_store();
        $thread = $store->get_active_thread($userid, $ambientcontextid);
        $threadid = $thread ? (int)$thread->id : 0;

        $params = [
            'count' => (int)($plan['count'] ?? self::DEFAULT_COUNT),
            'qtypes' => (array)($plan['qtypes'] ?? []),
            'difficulty' => (string)($plan['difficulty'] ?? 'medium'),
            'outputlang' => (string)($plan['outputlang'] ?? ''),
        ];
        $generator = new question_generation_service($store);
        $importer = new question_import_service();

        $sourcetext = (string)($plan['content'] ?? '');
        if ($sourcetext === '' && isset($plan['pdfsource'])) {
            $pdf = (new course_pdf_source())->resolve(
                (int)($plan['pdfsource']['resourcecmid'] ?? 0),
                (int)$course->id,
                $userid,
                (string)($plan['outputlang'] ?? '')
            );
            if (!empty($pdf['issues']) || $pdf['text'] === null) {
                return ['questionids' => [], 'error' => (string)($pdf['issues'][0]['message'] ?? 'no source text')];
            }
            $sourcetext = (string)$pdf['text'];
        }

        $feedback = '';
        $lasterror = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $generated = $generator->generate_gift(
                $threadid,
                $ambientcontextid,
                $userid,
                $sourcetext,
                $params,
                $feedback
            );
            if (empty($generated['success'])) {
                $lasterror = (string)($generated['error'] ?? 'generation failed');
                $feedback = $lasterror;
                continue;
            }
            $imported = $importer->import_gift((string)$generated['gift'], $target['context'], $target['course'], null);
            if (empty($imported['success'])) {
                $lasterror = (string)($imported['errors'] ?? 'import failed');
                $feedback = $lasterror;
                continue;
            }
            return ['questionids' => array_map('intval', (array)$imported['questionids']), 'error' => null];
        }
        return ['questionids' => [], 'error' => $lasterror];
    }

    /**
     * Reference an already-generated/known set of question ids into a quiz (post-creation step).
     *
     * @param stdClass $course
     * @param int $quizinstanceid
     * @param int[] $questionids
     * @return array{added:int,questionids:int[],mode:string,error:?string}
     */
    public function reference_existing(stdClass $course, int $quizinstanceid, array $questionids): array {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $quizinstanceid], '*', MUST_EXIST);
        $quiz->cmid = (int)get_coursemodule_from_instance('quiz', $quizinstanceid, (int)$course->id, false, MUST_EXIST)->id;
        return $this->add_by_ids($quiz, $questionids);
    }

    /**
     * Reference specific existing questions into the quiz.
     *
     * @param stdClass $quiz
     * @param int[] $questionids
     * @return array{added:int,questionids:int[],mode:string,error:?string}
     */
    private function add_by_ids(stdClass $quiz, array $questionids): array {
        global $DB;
        $valid = [];
        foreach (array_values(array_filter(array_map('intval', $questionids))) as $qid) {
            $qtype = $DB->get_field('question', 'qtype', ['id' => $qid]);
            if ($qtype !== false && $qtype !== 'random') {
                $valid[] = $qid;
            }
        }
        if (empty($valid)) {
            return [
                'added' => 0,
                'questionids' => [],
                'mode' => 'ids',
                'error' => 'No valid (non-random) questions found for the given ids.',
            ];
        }
        $added = $this->reference_ids_into_quiz($quiz, $valid);
        return ['added' => $added, 'questionids' => $valid, 'mode' => 'ids', 'error' => null];
    }

    /**
     * Add N random questions from a named category to the quiz.
     *
     * @param context $coursecontext
     * @param stdClass $quiz
     * @param string $categoryname
     * @param int $count
     * @param int $userid
     * @return array{added:int,questionids:int[],mode:string,error:?string}
     */
    private function add_random_from_category(
        context $coursecontext,
        stdClass $quiz,
        string $categoryname,
        int $count,
        int $userid
    ): array {
        $categoryid = $this->match_category($coursecontext, $categoryname, $userid);
        if ($categoryid <= 0) {
            return [
                'added' => 0,
                'questionids' => [],
                'mode' => 'category',
                'error' => 'No question category matched "' . $categoryname . '".',
            ];
        }
        $filtercondition = [
            'filter' => [
                'category' => [
                    'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                    'values' => [$categoryid],
                    'filteroptions' => ['includesubcategories' => false],
                ],
            ],
        ];
        $quizobj = \mod_quiz\quiz_settings::create((int)$quiz->id);
        $quizobj->get_structure()->add_random_questions(0, max(1, $count), $filtercondition);
        $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
        return ['added' => max(1, $count), 'questionids' => [], 'mode' => 'category', 'error' => null];
    }

    /**
     * Reference a list of question ids into a quiz and recompute its sum grades.
     *
     * @param stdClass $quiz
     * @param int[] $questionids
     * @return int Number added.
     */
    private function reference_ids_into_quiz(stdClass $quiz, array $questionids): int {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $added = 0;
        foreach ($questionids as $qid) {
            quiz_add_quiz_question((int)$qid, $quiz, 0);
            $added++;
        }
        if ($added > 0) {
            \mod_quiz\quiz_settings::create((int)$quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        }
        return $added;
    }

    /**
     * Resolve a category name to an id among the writable categories.
     *
     * @param context $coursecontext
     * @param string $name
     * @param int $userid
     * @return int Category id, or 0.
     */
    private function match_category(context $coursecontext, string $name, int $userid): int {
        $needle = \core_text::strtolower(trim($name));
        if ($needle === '') {
            return 0;
        }
        $partial = 0;
        foreach ($this->list_available_categories($coursecontext, $userid) as $cat) {
            $catname = \core_text::strtolower((string)$cat['categoryname']);
            if ($catname === $needle) {
                return (int)$cat['categoryid'];
            }
            if ($partial === 0 && str_contains($catname, $needle)) {
                $partial = (int)$cat['categoryid'];
            }
        }
        return $partial;
    }
}

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

namespace bookingextension_agent\local\wizard\services\questions;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;

/**
 * Generates Moodle questions in GIFT format from source text via the LLM.
 *
 * The deterministic parts (prompt construction, extracting GIFT from a model reply) are
 * static so they can be unit-tested without a live model. The actual generation uses
 * llm_call_service::invoke_for_context(), so it works at any context level.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_generation_service {
    /** Maximum questions per generation request. */
    public const MAX_COUNT = 50;

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Generate a GIFT document from the source text.
     *
     * @param int    $threadid   Thread id (for debug logging; 0 when unknown).
     * @param int    $contextid  Context id the LLM call runs against.
     * @param int    $userid
     * @param string $sourcetext The extracted document text to base questions on.
     * @param array  $params     count, qtypes, difficulty, outputlang.
     * @param string $feedback   Import errors from a previous attempt (empty on the first try).
     * @return array{success:bool,gift:string,error:string}
     */
    public function generate_gift(
        int $threadid,
        int $contextid,
        int $userid,
        string $sourcetext,
        array $params,
        string $feedback = ''
    ): array {
        $prompt = self::build_prompt($sourcetext, $params, $feedback);

        $call = (new llm_call_service($this->store))->invoke_for_context(
            $threadid,
            $contextid,
            $userid,
            'generate_questions',
            $prompt
        );

        if (empty($call['success'])) {
            return ['success' => false, 'gift' => '', 'error' => (string)($call['errormessage'] ?? 'The model call failed.')];
        }

        $gift = self::extract_gift((string)($call['rawcontent'] ?? ''));
        if ($gift === '') {
            return ['success' => false, 'gift' => '', 'error' => 'The model did not return any GIFT content.'];
        }
        // A reply with more questions than requested (reasoning drafts, a second version) is a failed attempt the
        // caller retries with this feedback - never a silent import of whatever came back (#2493).
        $counterror = self::count_error($gift, $params);
        return [
            'success' => $counterror === '',
            'gift' => $counterror === '' ? $gift : '',
            'error' => $counterror,
        ];
    }

    /**
     * The number of questions the prompt asks for (the same clamp build_prompt applies).
     *
     * @param array $params
     * @return int
     */
    public static function requested_count(array $params): int {
        return max(1, min(self::MAX_COUNT, (int)($params['count'] ?? 5)));
    }

    /**
     * Feedback when the GIFT holds no question or more questions than requested; '' otherwise.
     *
     * More than requested is the reasoning/draft shape of #2493 and fails the attempt. Fewer is imported and
     * reported honestly with the real count (thread 587) - the model is never pushed to pad the set.
     *
     * @param string $gift Extracted GIFT.
     * @param array $params
     * @return string
     */
    public static function count_error(string $gift, array $params): string {
        $expected = self::requested_count($params);
        $found = count(self::question_blocks($gift));
        if ($found > 0 && $found <= $expected) {
            return '';
        }
        return 'Return exactly ' . $expected . ' questions as GIFT only; your reply contained ' . $found
            . '. No reasoning, drafts or second versions.';
    }

    /**
     * Split GIFT into its question blocks (deterministic, GIFT syntax only).
     *
     * A question block opens with a ::name:: (the prompt demands one on every question) and carries an answer
     * block in braces. Lines before the ::name:: inside a block (a numbered draft glued to the question) are
     * dropped; paragraphs without both markers - reasoning, commentary, headings - are not questions.
     *
     * @param string $gift
     * @return string[]
     */
    public static function question_blocks(string $gift): array {
        $blocks = [];
        foreach (preg_split('/\R\s*\R/', trim($gift)) as $paragraph) {
            $lines = preg_split('/\R/', trim((string)$paragraph));
            $start = null;
            foreach ($lines as $index => $line) {
                if (strpos(ltrim((string)$line), '::') === 0) {
                    $start = $index;
                    break;
                }
            }
            if ($start === null) {
                continue;
            }
            $block = trim(implode("\n", array_slice($lines, $start)));
            if (strpos($block, '{') !== false && strpos($block, '}') !== false) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    /**
     * Build the generation prompt (deterministic).
     *
     * @param string $sourcetext
     * @param array  $params
     * @param string $feedback
     * @return string
     */
    public static function build_prompt(string $sourcetext, array $params, string $feedback = ''): string {
        $count = self::requested_count($params);
        $qtypes = array_values(array_filter(array_map('strval', (array)($params['qtypes'] ?? []))));
        if (empty($qtypes)) {
            $qtypes = ['multichoice', 'truefalse', 'shortanswer'];
        }
        $difficulty = (string)($params['difficulty'] ?? 'medium');
        $lang = (string)($params['outputlang'] ?? 'en');

        $lines = [
            'You are an expert assessment author. Create exactly ' . $count . ' Moodle questions in GIFT format',
            'based ONLY on the SOURCE DOCUMENT below.',
            'Allowed question types: ' . implode(', ', $qtypes) . '.',
            'Target difficulty: ' . $difficulty . '. Write the questions in the language with ISO code "' . $lang . '".',
            '',
            'Output rules (critical):',
            '- Return ONLY valid GIFT. No explanations, no commentary, no Markdown, no code fences.',
            '- Give every question a unique ::name:: prefix. Unless the user explicitly asked for a different '
                . 'naming scheme, derive the name from the question itself: a short, meaningful summary of the '
                . 'question text (its key concept or first few words), never a generic label like "Q1" or "Question 7".',
            '- multichoice: exactly one correct answer with "=", the others as distractors with "~".',
            '- truefalse: end with {TRUE} or {FALSE}.',
            '- shortanswer: one or more accepted answers, each prefixed with "=".',
            '- Separate questions with a blank line.',
        ];

        if (trim($feedback) !== '') {
            $lines[] = '';
            $lines[] = 'Your previous attempt could not be imported. Fix exactly these errors and return corrected GIFT:';
            $lines[] = trim($feedback);
        }

        $lines[] = '';
        $lines[] = '--- SOURCE DOCUMENT ---';
        $lines[] = trim($sourcetext);
        $lines[] = '--- END SOURCE DOCUMENT ---';

        return implode("\n", $lines);
    }

    /**
     * Extract the GIFT questions from a model reply, stripping code fences and everything that is not a question
     * block - reasoning or commentary around the GIFT never becomes a question (deterministic, #2493).
     *
     * @param string $raw
     * @return string
     */
    public static function extract_gift(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        // Prefer the first fenced code block (a gift-labelled or unlabelled triple-backtick block), if any.
        // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Literal backticks in a Markdown code-fence regex, not shell execution.
        if (preg_match('/```[a-zA-Z0-9_-]*\s*\n(.*?)```/s', $raw, $m)) {
            $raw = trim($m[1]);
        }
        return implode("\n\n", self::question_blocks($raw));
    }
}

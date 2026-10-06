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

namespace bookingextension_agent\local\wizard\wizard\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\doc_markdown_preview_renderer;
use bookingextension_agent\local\wizard\services\lookup\docs_lookup_service;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_readiness_service;

/**
 * Core skill: explain documentation topics (wizard.explain_docs).
 *
 * Searches registered documentation corpora and returns a windowed excerpt
 * from the most relevant document. Supports any query language — the embedding-
 * based primary search path is language-agnostic by design.
 *
 * Retrieval cascade:
 *  1. Planner direct path (doc_path) — highest priority, deterministic.
 *  2. Planner candidate paths (doc_path_candidates) — ranked direct reads.
 *  3. Semantic search via embeddings index (primary, language-agnostic).
 *  4. Lexical multi-query fallback (when embeddings index not yet ready).
 *  5. Root README fallback (last resort).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explain_docs_skill extends core_skill_base implements
    skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.explain_docs';

    /** Default lines per read window. */
    private const DEFAULT_LINE_COUNT = 80;

    /** First-read window (smaller to reduce initial context cost). */
    private const FIRST_STEP_LINE_COUNT = 40;

    /**
     * Continuation window: reading on takes the largest window, so the longest page of the corpus (429 lines) is
     * complete after four reads, inside the loop budget (L43 ED-1, thread 13343).
     */
    private const CONTINUATION_LINE_COUNT = 160;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
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
     * Read-only, but NOT auto-enabled: documentation search only becomes useful once an admin has
     * configured a docs corpus and built the embeddings index. Enabling it by default would leave
     * it running in a degraded (lexical-only) state, so it requires explicit activation — which is
     * also what arms the docs embeddings gate.
     *
     * @return bool
     */
    public function requires_explicit_activation(): bool {
        return true;
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Search the plugin documentation for how something works or what a term means. Returns the excerpt '
                . 'that answers the question. Works in any language — queries are matched against the documentation corpus '
                . 'language-agnostically. Use this skill whenever the user asks how something works, how to configure a feature, '
                . 'or what a term means in the context of this plugin. Documentation answers are strictly grounded: only the '
                . 'returned excerpt counts — never answer such questions from general knowledge.',
            // Real-LLM re-check 2026-09-26: the selector refused "read the whole file" because the card promised only
            // "the excerpt that answers the question"; whole_page was invisible to it (a construction field).
            'is' => 'The written documentation: the passage that answers a question, or a whole page when the user asks '
                . 'for it.',
            'not' => 'Field lists derived from code (mod_booking.list_option_properties, '
                . 'local_taskflow.list_rule_properties); capability questions (search_skills).',
            'readonly' => $this->is_read_only(),
            'fallback_skillcall_string_key' => 'ai_action_core_explain_docs',
            'example_utterances' => [
                'how do I set up a booking rule',
                'how does the waiting list work',
                'what does the placeholder feature mean',
                'where do I configure booking conditions',
                'explain how option dates work',
            ],
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The user\'s question or topic to look up, verbatim.',
                    'required' => true,
                    'from_user_message' => true,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'ISO 639-1 language code for the user-facing summary (e.g. "de", "en"). '
                        . 'The documentation corpus may be in a different language — the '
                        . 'summary is always generated in outputlang regardless.',
                    'required' => false,
                ],
                'search_queries' => [
                    'type' => 'array',
                    'description' => 'Optional additional English search phrases to improve recall '
                        . 'when the user\'s question is not in English. Up to 2 variants.',
                    'items' => ['type' => 'string'],
                    'maxItems' => 2,
                    'required' => false,
                ],
                'corpus_id' => [
                    'type' => 'string',
                    'description' => 'Corpus of doc_path (e.g. "mod_booking"), as given under "To read on" in a '
                        . 'previous result. Leave it out otherwise; search finds the corpus itself.',
                    'required' => false,
                ],
                'doc_path' => [
                    'type' => 'string',
                    'description' => 'Path of one documentation file, e.g. the doc_path under "To read on" in a '
                        . 'previous result. Leave it out to search by the question.',
                    'required' => false,
                ],
                'doc_path_candidates' => [
                    'type' => 'array',
                    'description' => 'Up to 3 documentation paths to try in order when the exact file is not certain.',
                    'items' => ['type' => 'string'],
                    'maxItems' => 3,
                    'required' => false,
                ],
                'whole_page' => [
                    'type' => 'boolean',
                    'description' => 'true when the user asks for the whole page or document: it is returned complete in '
                        . 'one result.',
                    'required' => false,
                ],
                'line_start' => [
                    'type' => 'integer',
                    'description' => 'Where to continue a page: the line of a listed section, or the line_start under '
                        . '"To read on", in a previous result (with its doc_path).',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['question'],
                'anchor_fields' => ['question'],
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
            'question' => 'How do I create a booking option?',
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
                'id' => 'wizard.explain_docs_request',
                'description' => 'User asks how something works, asks for documentation, or wants '
                    . 'to understand a feature (booking rules, conditions, placeholders, etc.).',
            ],
            [
                'id' => 'wizard.explain_docs_read_more',
                'description' => 'User asks to read more or continue reading a previously returned '
                    . 'documentation page.',
            ],
        ];
    }

    /**
     * Return contextual guidance packs for the planner.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'wizard.explain_docs',
                'triggers' => [
                    'how does', 'how do i', 'explain', 'documentation', 'what is',
                    'how does it work', 'explain', 'documentation', 'what is',
                ],
                'guidance' => [
                    '- Use wizard.explain_docs whenever the user asks how a feature works or wants documentation.',
                    '- Always set input.question to the user\'s actual question verbatim.',
                    '- If the user\'s question is not in English, add up to 2 English paraphrases to '
                    . 'input.search_queries for better recall (keep domain terms like "booking rules" unchanged).',
                    '- To read further in a page, call this skill again with doc_path and line_start: the line of the '
                    . 'listed section the answer needs, or the line_start under "To read on" to read on in order.',
                    '- If you already know the exact doc path from context, set input.doc_path to skip search.',
                ],
            ],
        ];
    }

    /**
     * Structural validation — checks that question is present.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];

        $question = trim((string)($input['question'] ?? ''));
        if ($question === '') {
            $errors[] = get_string('ai_docs_explain_required_question', 'bookingextension_agent');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute the skill.
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $question = trim((string)($input['question'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $docpath = trim((string)($input['doc_path'] ?? ''));
        $corpusid = trim((string)($input['corpus_id'] ?? ''));
        $candidates = array_values(array_filter(
            array_map('strval', (array)($input['doc_path_candidates'] ?? [])),
            static fn(string $v): bool => trim($v) !== ''
        ));
        // Accept either an array or a comma-separated string — the engine no longer splits this
        // field, domain handling stays in the skill (audit 05-F01).
        $rawqueries = $input['search_queries'] ?? [];
        if (is_string($rawqueries)) {
            $rawqueries = explode(',', $rawqueries);
        }
        $searchqueries = array_values(array_filter(
            array_map(static fn($v): string => trim((string)$v), (array)$rawqueries),
            static fn(string $v): bool => $v !== ''
        ));
        $linestart = max(1, (int)($input['line_start'] ?? 1));
        $defaultcount = $linestart > 1 ? self::CONTINUATION_LINE_COUNT : self::FIRST_STEP_LINE_COUNT;
        // The window size is the skill's decision, not a model value: in L43 re-checks the construction chose 80 lines
        // for a continuation and the longest page no longer fitted the loop budget.
        $linecount = $defaultcount;
        // George 2026-09-26: a long page must be readable as a whole. whole_page returns it in one result, so no
        // chain of calls can stop half-way (real-LLM re-check: the model stopped after 40, 220 of 430 lines).
        if (!empty($input['whole_page'])) {
            $linecount = docs_lookup_service::WHOLE_PAGE;
        }
        // A first read (no line_start, no whole_page) plans its own window: the whole page up to the budget (plan B).
        $firstread = $linestart === 1 && empty($input['whole_page']);

        if ($question === '') {
            return $this->error_result(
                get_string('ai_docs_explain_required_question', 'bookingextension_agent'),
                $input
            );
        }

        $svc = $this->create_docs_lookup_service();
        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $input);

        // 1. Planner-supplied direct path (with explicit corpus when given, else search all corpora).
        if ($docpath !== '') {
            $doc = $this->read_window($svc, $corpusid, $docpath, $linestart, $linecount, $firstread);
            if ($doc !== null) {
                return $this->build_doc_result($doc, $svc, $outputlang, $question, $debugbase . "\nmode=direct_path");
            }
        }

        // 2. Planner-supplied candidate paths.
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $doc = $this->read_window($svc, $corpusid, $candidate, $linestart, $linecount, $firstread);
            if ($doc !== null) {
                return $this->build_doc_result(
                    $doc,
                    $svc,
                    $outputlang,
                    $question,
                    $debugbase . "\nmode=candidate_path candidate=" . $candidate
                );
            }
        }

        // 3. Semantic search (primary, language-agnostic).
        $allqueries = array_merge([$question], $searchqueries);
        $semanticresults = $svc->search_semantic($question, $contextid, $userid, 3);

        if (!empty($semanticresults)) {
            $best = $semanticresults[0];
            $score = (int)($best['score'] ?? 0);
            $doc = $this->read_window(
                $svc,
                (string)($best['corpus_id'] ?? ''),
                (string)($best['path'] ?? ''),
                $linestart,
                $linecount,
                $firstread
            );
            if ($doc !== null) {
                return $this->build_doc_result(
                    $doc,
                    $svc,
                    $outputlang,
                    $question,
                    $debugbase . "\nmode=semantic score=" . $score
                );
            }
        }

        // Trigger an async rebuild when the index is missing, corrupt, or does not yet cover every
        // resolvable corpus (e.g. a freshly added one). Cheap coverage check; the expensive per-file
        // diff/prune runs in the task.
        $readiness = new docs_embeddings_readiness_service();
        if (!$readiness->is_index_covered()) {
            $readiness->ensure_rebuild_scheduled_if_needed();
        }

        // 4. Lexical fallback.
        $lexicalresults = $svc->search_multi($allqueries, 3);

        if (!empty($lexicalresults)) {
            $best = $lexicalresults[0];
            $doc = $this->read_window(
                $svc,
                (string)($best['corpus_id'] ?? ''),
                (string)($best['path'] ?? ''),
                $linestart,
                $linecount,
                $firstread
            );
            if ($doc !== null) {
                return $this->build_doc_result(
                    $doc,
                    $svc,
                    $outputlang,
                    $question,
                    $debugbase . "\nmode=lexical score=" . (int)($best['score'] ?? 0)
                );
            }
        }

        // 5. Root README fallback.
        $rootdoc = $svc->read_root_doc($linestart, $linecount);
        if ($rootdoc !== null) {
            return $this->build_doc_result(
                $rootdoc,
                $svc,
                $outputlang,
                $question,
                $debugbase . "\nmode=root_fallback"
            );
        }

        // Nothing found.
        $usermessage = $this->localized_string('ai_docs_no_results', null, $outputlang);
        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => null,
            'observation_full' => 'DOCUMENTATION GROUNDING CONTRACT (non-negotiable): the documentation lookup '
                . 'found NOTHING for this question. Tell the user that this is not covered by the documentation. '
                . 'Do NOT answer the documentation question from outside knowledge and NEVER invent parameters, '
                . 'options or features. ' . $usermessage,
            // Instructional engine text — exempt from privacy anonymization.
            'observation_engine_static' => true,
            'debugmessage' => $debugbase . "\nmode=no_results",
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // Separator.

    /**
     * Build a structured result payload from a doc read result.
     *
     * @param array $doc
     * @param docs_lookup_service $svc
     * @param string              $outputlang
     * @param string              $question
     * @param string              $debugsuffix
     * @return array
     */
    private function build_doc_result(
        array $doc,
        docs_lookup_service $svc,
        string $outputlang,
        string $question,
        string $debugsuffix
    ): array {
        $path = (string)($doc['path'] ?? '');
        $corpusid = (string)($doc['corpus_id'] ?? '');
        $title = (string)($doc['title'] ?? $path);
        $content = (string)($doc['content'] ?? '');
        $summary = $svc->build_summary($doc, $outputlang, $question);

        $hasmore = (bool)($doc['has_more'] ?? false);
        $nextlinestart = $hasmore ? (int)($doc['next_line_start'] ?? null) : null;
        $totallines = (int)($doc['total_lines'] ?? 0);
        $linestart = (int)($doc['line_start'] ?? 1);

        $docurl = $this->build_doc_url($corpusid, $path);

        $observation = $this->build_observation_full(
            $path,
            $corpusid,
            $title,
            $content,
            $docurl,
            $linestart,
            $totallines,
            $hasmore ? $nextlinestart : null,
            (array)($doc['outline'] ?? [])
        );

        $usermessage = $summary !== '' ? $summary : $title;

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => null,
            'doc_corpus' => $corpusid,
            'doc_path' => $path,
            'doc_title' => $title,
            'doc_url' => $docurl,
            'line_start' => $linestart,
            'line_count' => (int)($doc['line_count'] ?? 0),
            'next_line_start' => $nextlinestart,
            'has_more' => $hasmore,
            'total_lines' => $totallines,
            'observation_full' => $observation,
            // Shipped documentation + grounding instructions = engine text; exempt
            // from privacy anonymization (masking would corrupt doc content, and the
            // user question is NOT part of this observation).
            'observation_engine_static' => true,
            'debugmessage' => $debugsuffix,
        ];
    }

    /**
     * Read one window of a page. A continuation or a whole-page request reads as asked; a first read reads the whole
     * page up to docs_lookup_service::FIRST_READ_CHAR_BUDGET, a longer page from its start up to the budget (L43
     * ED-1: the 40-line window ended one line before the section the question needed).
     *
     * @param docs_lookup_service $svc
     * @param string $corpusid Empty: try every corpus.
     * @param string $path
     * @param int $linestart
     * @param int $linecount
     * @param bool $firstread
     * @return array|null
     */
    private function read_window(
        docs_lookup_service $svc,
        string $corpusid,
        string $path,
        int $linestart,
        int $linecount,
        bool $firstread
    ): ?array {
        $read = static function (int $start, int $count) use ($svc, $corpusid, $path): ?array {
            return $corpusid !== ''
                ? $svc->read_doc_by_path($corpusid, $path, $start, $count)
                : $svc->read_doc_any_corpus($path, $start, $count);
        };
        if (!$firstread) {
            return $read($linestart, $linecount);
        }
        $whole = $read(1, docs_lookup_service::WHOLE_PAGE);
        if ($whole === null) {
            return null;
        }
        [$start, $count] = docs_lookup_service::first_window($whole, docs_lookup_service::FIRST_READ_CHAR_BUDGET);
        return $count === docs_lookup_service::WHOLE_PAGE ? $whole : $read($start, $count);
    }

    /**
     * Build the observation string returned to the orchestrator/synchronizer.
     *
     * @param string   $path
     * @param string   $corpusid
     * @param string   $title
     * @param string   $content
     * @param string   $docurl
     * @param int      $linestart
     * @param int      $totallines
     * @param int|null $nextlinestart Where to read on, null when the page is complete.
     * @param array    $outline Headings of the whole page with their lines (docs_lookup_service).
     * @return string
     */
    private function build_observation_full(
        string $path,
        string $corpusid,
        string $title,
        string $content,
        string $docurl,
        int $linestart,
        int $totallines,
        ?int $nextlinestart,
        array $outline
    ): string {
        $lines = [];
        // Grounding contract: documentation answers must come from the excerpt and
        // nothing else. Thread 321: the planner confidently invented a shortcode
        // parameter (pastcourses=1) on top of a correct excerpt — the contract
        // travels with the observation so every consumer (next planner turn AND
        // synchronizer) sees it right next to the content it constrains.
        $lines[] = 'DOCUMENTATION GROUNDING CONTRACT (non-negotiable):';
        // Plan D (2026-09-26): the rules speak of the page as read so far - an excerpt of a longer page does not prove
        // that something is undocumented, and further reads of the same page are grounded too.
        $lines[] = '- The documentation read from this page is the ONLY authoritative source for the answer.';
        $lines[] = '- Answer EXCLUSIVELY from it. Do NOT add parameters, options, attributes, features or behaviour from '
            . 'outside knowledge, and NEVER invent identifiers.';
        $lines[] = '- Something is NOT documented only if it is missing from the whole page - an excerpt that lists '
            . 'sections under "Not in this excerpt" is not the whole page.';
        // L43 ED-1, thread 13343: one rule for reading on, matching the field descriptions and the guidance. The
        // values to continue with stand below as facts (the same hand-over as CHOICES), never as a second rule.
        $lines[] = '- If the answer is in a section listed under "Not in this excerpt", call ' . self::SKILL_NAME
            . ' again with doc_path and that section\'s line as line_start before answering. To read the page on in '
            . 'order, use doc_path and line_start from "To read on".';
        // Moved from the skill guidance (read by selection and construction, never by the reply writer).
        $lines[] = '- Shortcodes in square brackets (e.g. [bookingoptions ...]) are shown literally: quote them verbatim, '
            . 'never HTML-escaped.';
        $lines[] = '';
        $lines[] = 'Doc: ' . $path;
        if ($title !== '' && $title !== $path) {
            $lines[] = 'Title: ' . $title;
        }
        if ($docurl !== '') {
            $lines[] = 'Links: ' . $docurl;
        }
        $lineend = $linestart + substr_count($content, "\n");
        $lines[] = 'Lines ' . $linestart . '–' . $lineend . ($totallines > 0 ? ' of ' . $totallines : '') . '.';
        $before = [];
        $after = [];
        foreach ($outline as $entry) {
            $line = (int)($entry['line'] ?? 0);
            $label = '"' . (string)($entry['heading'] ?? '') . '" (line ' . $line . ')';
            if ($line < $linestart) {
                $before[] = $label;
            } else if ($line > $lineend) {
                $after[] = $label;
            }
        }
        if (!empty($before)) {
            $lines[] = 'Before this excerpt: ' . implode(', ', $before) . '.';
        }
        if (!empty($after)) {
            $lines[] = 'Not in this excerpt: ' . implode(', ', $after) . '.';
        }
        if ($nextlinestart !== null) {
            $lines[] = 'To read on: doc_path=' . $path . ', line_start=' . $nextlinestart
                . ($corpusid !== '' ? ', corpus_id=' . $corpusid : '');
        }
        $lines[] = '';
        $lines[] = $content;

        return implode("\n", $lines);
    }

    /**
     * Build a public Moodle URL for a doc path.
     *
     * Only the mod_booking corpus has a public web route (/mod/booking/docs/...). Other corpora
     * have no clickable source URL (v1); their content is still fully shown in the side preview.
     *
     * @param string $corpusid
     * @param string $relpath
     * @return string
     */
    private function build_doc_url(string $corpusid, string $relpath): string {
        // Only mod_booking exposes a docs web route today.
        if ($relpath === '' || $corpusid !== 'mod_booking') {
            return '';
        }

        $modbookingdir = \core_component::get_component_directory('mod_booking');
        if ($modbookingdir === null) {
            return '';
        }

        // Expose the URL only if the file lives within mod_booking/docs.
        $abspath = rtrim($modbookingdir, '/') . '/docs/' . ltrim($relpath, '/');
        if (!is_readable($abspath)) {
            return '';
        }

        try {
            $encodedpath = rawurlencode($relpath);
            return (new \moodle_url('/mod/booking/docs/' . $encodedpath))->out(false);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Build an error result payload.
     *
     * @param string $message
     * @param array  $input
     * @return array
     */
    private function error_result(string $message, array $input): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'resultid' => null,
            'debugmessage' => $this->build_skill_debug_message(self::SKILL_NAME, $input, [$message]),
        ];
    }

    /**
     * Instantiate the registry-backed docs lookup service (searches across all corpora).
     *
     * @return docs_lookup_service
     */
    private function create_docs_lookup_service(): docs_lookup_service {
        return new docs_lookup_service();
    }

    /**
     * Return the preview descriptor for this skill.
     *
     * @return array
     */
    /**
     * Provide the documentation preview as ready-to-insert server-rendered HTML data.
     *
     * @param array $resultentry One executed skill result entry.
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $path = trim((string)($resultentry['doc_path'] ?? $resultentry['path'] ?? ''));
        $corpusid = trim((string)($resultentry['doc_corpus'] ?? $resultentry['corpus_id'] ?? ''));
        if ($path === '' || $corpusid === '') {
            return null;
        }

        $html = (new doc_markdown_preview_renderer())->render(
            ['corpus_id' => $corpusid, 'path' => $path],
            $contextid,
            $userid
        );
        if (trim($html) === '') {
            return null;
        }

        return [
            'type' => 'doc_markdown',
            'html' => $html,
            'payload' => ['corpus_id' => $corpusid, 'path' => $path],
        ];
    }
}

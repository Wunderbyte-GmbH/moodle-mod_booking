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

namespace bookingextension_agent\local\wizard\core\skills;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service;

/**
 * Readonly semantic site-content search skill (core.find_content).
 *
 * Thin skill layer over {@see site_content_search_service}: takes a natural-language query and
 * returns access-gated hits from the admin-enabled site-content index (blueprint §6, Phase 3).
 * The service enforces the two-gate access model itself (engine-free context prefilter +
 * authoritative per-hit check_access), so this skill is safe to run as any user — it adds no
 * access decisions of its own.
 *
 * Stateless, deterministic and cheap by design: calling it repeatedly with refined inputs
 * (different query, areas, courseid, includecontent) is the intended usage pattern — search broad
 * first, then re-run narrower with includecontent=true for the hits to work with downstream.
 *
 * Observation contract: every hit line carries its deep link AND its course link verbatim
 * (real moodle_url output — the synthesizer passes URLs through, never invents them), plus the
 * machine-usable fields (areaid, docid, courseid, contextid) for follow-up skill calls.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_content_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'core.find_content';

    /** Default number of hits. */
    private const DEFAULT_LIMIT = 5;

    /** Lower default when full content is included, to keep the observation bounded. */
    private const DEFAULT_LIMIT_WITH_CONTENT = 3;

    /** Hard cap on the number of hits. */
    private const MAX_LIMIT = 10;

    /** @var site_content_search_service Access-gated retrieval backend. */
    private site_content_search_service $searchservice;

    /**
     * Constructor. Read-only lookup (R0); the search service is injectable for provider-free tests.
     *
     * @param site_content_search_service|null $searchservice Defaults to the real service.
     */
    public function __construct(?site_content_search_service $searchservice = null) {
        parent::__construct(true, skill_risk_class::R0);
        $this->searchservice = $searchservice ?? new site_content_search_service();
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
     * Works from any course/module context; the search itself is site-wide (access-gated).
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
            'description' => 'Find content by MEANING across the site\'s indexed content: pages, forum posts, course summaries, '
                . 'glossaries — which course or page deals with a topic. Returns per hit the title, a deep link, the course link, '
                . 'ids and a snippet; results are access-checked (users only find what they may see). Stateless and cheap — '
                . 'iterate freely: search BROAD first, then re-run with a refined query, areas or courseid, and set '
                . 'includecontent=true (fewer hits, full text) for the hits you want to work with.',
            'is' => 'Content by topic.',
            'not' => 'A course-name lookup (course.search_courses), users or booking options.',
            'readonly' => true,
            'example_utterances' => [
                'find the page about assessment criteria',
                'in welchem Kurs geht es um Photosynthese',
                'such den Forumsbeitrag über die Exkursion',
                'finde Inhalte zu Datenschutz',
                'where on this site is the enrolment procedure explained',
                'which course covers statistics basics',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'What to find, in natural language (meaning matters more than exact words).',
                    'required' => true,
                ],
                'areas' => [
                    'type' => 'array',
                    'description' => 'OPTIONAL content-type hints as free-form strings, e.g. "page", "forum post", '
                        . '"mod_glossary". Matched leniently; unknown values are ignored, and if nothing matches, '
                        . 'all enabled areas are searched.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'OPTIONAL numeric course id to restrict the search to one course. Never guess.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of hits (default 5, or 3 with includecontent=true; max 10).',
                    'required' => false,
                ],
                'includecontent' => [
                    'type' => 'boolean',
                    'description' => 'When true, each hit additionally carries its document text (capped) so you can '
                        . 'work with the content (summarize, build a quiz, ...). Default false = snippets only; '
                        . 'search broad first, then re-run narrower with includecontent=true.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for skill-authored wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['query'],
                'anchor_fields' => ['query'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['query' => 'enrolment procedure for the summer seminar', 'areas' => ['page'], 'limit' => 5];
    }

    /**
     * Discovery triggers (lexical — carry the typical phrasings so discovery finds the skill
     * before the next embeddings rebuild).
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.find_content_request',
                'description' => 'The user wants content on the site or in a course found by topic or meaning: a page, forum'
                    . ' post, glossary entry or course summary about a subject.',
                'examples' => [
                    'Find the page about assessment criteria.',
                    'In welchem Kurs geht es um Photosynthese?',
                    'Such den Forumsbeitrag über die Exkursion.',
                    'Finde Inhalte zu Datenschutz auf dieser Plattform.',
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
                'id' => 'core.find_content',
                'triggers' => [
                    'find content', 'find the page', 'which course covers', 'where is explained',
                    'search the site', 'forum post about', 'inhalte zu', 'finde die seite',
                    'in welchem kurs', 'such den beitrag', 'wo steht',
                ],
                'guidance' => [
                    '- core.find_content searches the indexed site content semantically (access-gated). It is',
                    '  stateless and cheap: calling it several times with refined inputs is the NORMAL pattern.',
                    '- Search BROAD first (query only). Then re-run with a tighter query, areas (content-type',
                    '  hints like "page"/"forum") or courseid — and includecontent=true when you need the found',
                    '  text for a follow-up step (e.g. building a quiz from it).',
                    '- Every hit carries url= (deep link) and courseurl=. Pass these URLs through VERBATIM when',
                    '  mentioning a hit; never invent or alter links.',
                    '- The per-hit fields areaid/docid/courseid/contextid are for follow-up skill calls.',
                    '- Do NOT use it to find users, courses by name, or booking options.',
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
        if (trim((string)($input['query'] ?? '')) === '') {
            $errors[] = $this->localized_string('agent_find_content_queryrequired', null, $this->get_output_language($input));
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Run the search (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $outputlang = $this->get_output_language($input);
        $query = trim((string)($input['query'] ?? ''));
        if ($query === '') {
            $message = $this->localized_string('agent_find_content_queryrequired', null, $outputlang);
            return [
                'status' => 'error',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
                'observation_full' => get_string('agent_find_content_queryrequired', 'bookingextension_agent'),
            ];
        }

        $registry = new site_content_area_registry();
        $enabledareas = $registry->enabled_area_keys();

        // Graceful degradation, never an exception: without the DB embeddings store, the
        // embeddings provider, or at least one enabled area there is simply nothing to search.
        if (!$this->searchservice->is_ready() || $enabledareas === []) {
            $message = $this->localized_string('agent_find_content_notready', null, $outputlang);
            return [
                'status' => 'executed',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
                'results' => [],
                'observation_full' => get_string('agent_find_content_notready', 'bookingextension_agent'),
                'debugmessage' => $this->build_skill_debug_message(self::SKILL_NAME, $input, ['Not ready / no areas enabled']),
            ];
        }

        // Lenient area normalization: unknown hints are dropped; nothing matched = no restriction.
        $arearefs = [];
        foreach ((array)($input['areas'] ?? []) as $ref) {
            if (is_scalar($ref) && trim((string)$ref) !== '') {
                $arearefs[] = trim((string)$ref);
            }
        }
        $areaids = ($arearefs === []) ? null : $registry->normalize_area_refs($arearefs);
        $areasunmatched = ($arearefs !== [] && $areaids === null);

        $courseid = (int)($input['courseid'] ?? 0);
        $courseid = ($courseid > 0) ? $courseid : null;

        $includecontent = !empty($input['includecontent']);
        $limit = (int)($input['limit'] ?? 0);
        if ($limit <= 0) {
            $limit = $includecontent ? self::DEFAULT_LIMIT_WITH_CONTENT : self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);

        // The service runs as the current user and enforces access itself (two-gate model).
        $hits = $this->searchservice->search($query, $contextid, $limit, $areaids, $courseid, $includecontent);

        $searchedareaids = ($areaids === null) ? $enabledareas : array_values(array_intersect($areaids, $enabledareas));
        $searchedlabels = array_map(
            static function (string $areaid) use ($registry): string {
                $areaobj = $registry->area_instance($areaid);
                try {
                    return $areaobj !== null ? (string)$areaobj->get_visible_name() : $areaid;
                } catch (\Throwable $e) {
                    return $areaid;
                }
            },
            $searchedareaids
        );

        $count = count($hits);
        $usermessage = $this->localized_string(
            'agent_find_content_summary',
            (object)['count' => $count, 'query' => $query],
            $outputlang
        );

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => ($count > 0) ? (int)$hits[0]['docid'] : null,
            'results' => $hits,
            'observation_full' => $this->build_observation($query, $hits, $searchedlabels, $areasunmatched, $includecontent),
            'debugmessage' => $this->build_skill_debug_message(self::SKILL_NAME, $input, [
                'Hits: ' . $count,
                'Searched areas: ' . implode(', ', $searchedareaids),
            ]),
        ];
    }

    /**
     * Build the observation: header, one machine-parsable line per hit (ALWAYS carrying the deep
     * link and the course link verbatim), snippet/content blocks, and footer notes.
     *
     * @param string $query
     * @param array[] $hits Service result rows.
     * @param string[] $searchedlabels Visible names of the areas that were actually searched.
     * @param bool $areasunmatched The caller gave area hints, but none matched (all-areas fallback).
     * @param bool $includecontent Content mode: append the document text per hit.
     * @return string
     */
    private function build_observation(
        string $query,
        array $hits,
        array $searchedlabels,
        bool $areasunmatched,
        bool $includecontent
    ): string {
        $lines = [];
        if ($areasunmatched) {
            $lines[] = get_string('agent_find_content_areasunmatched', 'bookingextension_agent');
        }

        if ($hits === []) {
            $lines[] = get_string('agent_find_content_nohits', 'bookingextension_agent', (object)[
                'query' => $query,
                'areas' => implode(', ', $searchedlabels),
            ]);
            return implode("\n", $lines);
        }

        $lines[] = get_string('agent_find_content_found', 'bookingextension_agent', (object)[
            'count' => count($hits),
            'query' => $query,
        ]);
        foreach ($hits as $hit) {
            $courseurl = ((int)$hit['courseid'] > 0)
                ? (new \moodle_url('/course/view.php', ['id' => (int)$hit['courseid']]))->out(false)
                : '';
            $lines[] = '- title="' . (string)$hit['title'] . '"'
                . ' area="' . $this->area_label((string)$hit['area']) . '"'
                . ' areaid=' . (string)$hit['area']
                . ' docid=' . (int)$hit['docid']
                . ' courseid=' . (int)$hit['courseid']
                . ' contextid=' . (int)$hit['contextid']
                . ' score=' . (int)$hit['score']
                . ' url=' . $this->format_observation_scalar($hit['url'])
                . ' courseurl=' . $this->format_observation_scalar($courseurl);
            // Chunk text may span lines (title + content) — keep the snippet on one line.
            $snippet = trim((string)preg_replace('/\s+/u', ' ', (string)$hit['snippet']));
            if ($snippet !== '') {
                $stalenote = !empty($hit['stale'])
                    ? ' ' . get_string('agent_find_content_stalenote', 'bookingextension_agent')
                    : '';
                $lines[] = '  snippet: ' . $snippet . $stalenote;
            }
            if ($includecontent) {
                // Full content block (fallback: the matched chunk) for downstream steps.
                $content = trim((string)$hit['documenttext']) !== ''
                    ? trim((string)$hit['documenttext'])
                    : trim((string)$hit['chunktext']);
                if ($content !== '') {
                    $lines[] = '  content: ' . $content;
                }
            }
        }
        $lines[] = get_string('agent_find_content_linknote', 'bookingextension_agent');

        return implode("\n", $lines);
    }

    /**
     * The visible label of an area id, falling back to the id itself.
     *
     * @param string $areaid
     * @return string
     */
    private function area_label(string $areaid): string {
        $areaobj = (new site_content_area_registry())->area_instance($areaid);
        try {
            return $areaobj !== null ? (string)$areaobj->get_visible_name() : $areaid;
        } catch (\Throwable $e) {
            return $areaid;
        }
    }

    /**
     * Structured self-contained preview for the search hits (#2350).
     *
     * Escaped list of title-link, course and snippet per hit; file-backed hits (mod_resource)
     * carry a click-to-embed button the panel turns into an in-pane document iframe.
     *
     * @param array $resultentry One executed skill result entry.
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $hits = (array)($resultentry['results'] ?? []);
        if (empty($hits)) {
            return null;
        }

        $items = '';
        $seenurls = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $title = trim((string)($hit['title'] ?? ''));
            $url = trim((string)($hit['url'] ?? ''));
            if ($title === '' || $url === '') {
                continue;
            }
            // One entry per document: chunk hits of the same target collapse (best hit came first).
            if (isset($seenurls[$url])) {
                continue;
            }
            $seenurls[$url] = true;
            $coursename = '';
            $courseid = (int)($hit['courseid'] ?? 0);
            if ($courseid > 0) {
                try {
                    $coursename = format_string((string)get_course($courseid)->fullname);
                } catch (\Throwable $e) {
                    $coursename = '';
                }
            }
            $snippet = trim((string)($hit['snippet'] ?? ''));
            // The chunk text starts with its own heading — no point echoing the title line.
            foreach ([$title, 'FILE:'] as $prefix) {
                while (str_starts_with(ltrim($snippet), $prefix)) {
                    $snippet = trim(\core_text::substr(ltrim($snippet), \core_text::strlen($prefix)));
                }
            }
            if (\core_text::strlen($snippet) > 220) {
                $snippet = \core_text::substr($snippet, 0, 220) . '…';
            }
            // Module type is engine data: only real file modules offer the in-pane document view.
            $isfile = strpos($url, '/mod/resource/view.php') !== false;

            $items .= \html_writer::start_div('mb-2 pb-2 border-bottom');
            $items .= \html_writer::link($url, s($title), ['target' => '_blank', 'class' => 'fw-bold']);
            if ($coursename !== '' && $coursename !== $title) {
                $items .= \html_writer::div(s($coursename), 'small text-muted');
            }
            if ($snippet !== '') {
                $items .= \html_writer::div(s($snippet), 'small');
            }
            if ($isfile) {
                $items .= \html_writer::tag('button', get_string('agent_find_content_embedpreview', 'bookingextension_agent'), [
                    'class' => 'btn btn-sm btn-outline-secondary mt-1',
                    'data-embed-url' => $url,
                    'data-embed-title' => s($title),
                ]);
            }
            $items .= \html_writer::end_div();
        }
        if ($items === '') {
            return null;
        }

        return [
            'type' => 'find_content_results',
            'html' => \html_writer::div($items, 'booking-ai-findcontent-preview'),
            'payload' => [],
        ];
    }
}

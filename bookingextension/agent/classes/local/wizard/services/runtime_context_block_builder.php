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

namespace bookingextension_agent\local\wizard\services;

use core\context;
use context_module;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use core_text;
use bookingextension_agent\local\wizard\skill_registry_factory;
use bookingextension_agent\local\wizard\dto\agent_context;

/**
 * Builds the [SYSTEM_RUNTIME] / [SYSTEM_RUNTIME_STATE] context blocks for the planner/synchronizer.
 *
 * Extracted verbatim from orchestrator::build_runtime_context_block (orchestrator split, runtime-context
 * seam). Splits per-thread-stable facts (cache-friendly prefix) from volatile per-request state. The
 * orchestrator keeps a thin delegating method so its 4 call sites are unchanged. Collaborators are
 * injected: conversation_store, completed_command_history_service and planner_catalog_service (catalog
 * text rendering). Behaviour-preserving.
 *
 * Note: the small JSON helpers (json_encode_or_empty / append_json_list_section /
 * normalize_for_observation_dedup) are duplicated here because the orchestrator still uses its own copies
 * for other call sites; they are trivial and pure.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class runtime_context_block_builder {
    /** @var conversation_store */
    private conversation_store $store;

    /** @var completed_command_history_service */
    private completed_command_history_service $completedhistorysvc;

    /** @var planner_catalog_service */
    private planner_catalog_service $catalogsvc;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     * @param completed_command_history_service $completedhistorysvc
     * @param planner_catalog_service $catalogsvc
     */
    public function __construct(
        conversation_store $store,
        completed_command_history_service $completedhistorysvc,
        planner_catalog_service $catalogsvc
    ) {
        $this->store = $store;
        $this->completedhistorysvc = $completedhistorysvc;
        $this->catalogsvc = $catalogsvc;
    }

    /**
     * Build the runtime context block (stable + volatile halves).
     *
     * @param int $threadid
     * @param int $contextid
     * @param string $phase
     * @param bool $isfirstassistantturn
     * @param bool $hasobservations
     * @param array $skillcatalog
     * @param array $unavailableskillcatalog
     * @param array $messages
     * @param string $memorychannel
     * @param array $liveobservations
     * @param bool $catalogisstatic
     * @return array{stable: string, volatile: string}
     */
    public function build(
        int $threadid,
        int $contextid,
        string $phase = orchestrator::PHASE_DISCOVERY,
        bool $isfirstassistantturn = false,
        bool $hasobservations = false,
        array $skillcatalog = [],
        array $unavailableskillcatalog = [],
        array $messages = [],
        string $memorychannel = '',
        array $liveobservations = [],
        bool $catalogisstatic = false
    ): array {
        $timezonename = (string)(get_config('core', 'timezone') ?? '');
        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = date_default_timezone_get();
        }

        try {
            $tz = new \DateTimeZone($timezonename);
        } catch (\Throwable $e) {
            $timezonename = date_default_timezone_get();
            $tz = new \DateTimeZone($timezonename);
        }

        $blockcontext = context::instance_by_id($contextid, IGNORE_MISSING);
        $cm = ($blockcontext instanceof context_module)
            ? get_coursemodule_from_id('booking', (int)$blockcontext->instanceid, 0, false, IGNORE_MISSING)
            : false;
        // The agent is site-wide, so the context line is generic (never a booking-specific
        // "booking_name"): a booking module yields the booking instance name, any other context level
        // its Moodle context name (course, user, system, ...).
        $contextname = $cm
            ? format_string($cm->name)
            : ($blockcontext ? $blockcontext->get_context_name() : 'this context');
        // Minute granularity on purpose: a second-precise timestamp makes every request's
        // prompt unique and is the main breaker for upstream prompt-prefix caching.
        $nowiso = (new \DateTime('now', $tz))->format('Y-m-d\TH:iP');

        // Split for prompt-prefix caching: $lines holds per-thread-stable facts emitted right after
        // the static [SYSTEM] block; $statelines holds volatile per-request state (execution ledgers,
        // an adaptive catalog, and finally now_iso) emitted below the history as [SYSTEM_RUNTIME_STATE].
        // A STATIC catalog (see $catalogisstatic) instead joins $lines so it lands in the cached prefix,
        // and now_iso is appended LAST so it never fronts the cacheable catalog/ledger lines above it.
        $lines = [
            'timezone: ' . $timezonename,
        ];
        // Context_name is per-context (volatile): keep it OUT of the cached [SYSTEM_RUNTIME] prefix so
        // the large skill catalog that follows caches across contexts in the slim_all path. It is
        // emitted in the volatile [SYSTEM_RUNTIME_STATE] block instead (which sits past the cache
        // boundary anyway, below the user message).
        $statelines = [
            'context_name: ' . $contextname,
        ];

        // Rich context awareness: a structured moodle_context block (course/activity ids + names).
        // Construction (the constructor needs real ids to fill parameters without clarification
        // round-trips) and the synchronizer (the final reply references the user's environment) get it
        // in the per-thread-stable half. Selection ALSO gets it — but in the VOLATILE half — so the
        // [SYSTEM] CONTEXT-AWARE PLANNING rule ("skip a search/resolution step when the target IS the
        // current context") is actually backed by data at the phase that routes, instead of referencing
        // an absent field. Volatile placement keeps it out of the cached skill-catalog prefix.
        // Data sources are cache-backed only: agent_context (static context cache) and
        // get_fast_modinfo (MUC) — no extra DB load per request. Never breaks the prompt.
        $fullcontextblock = ($phase === orchestrator::PHASE_PARAMETER_CONSTRUCTION)
            || ($memorychannel === user_memory_service::SCOPE_SYNCHRONIZATION);
        if ($blockcontext) {
            if ($fullcontextblock) {
                $this->append_moodle_context_section($lines, $blockcontext);
            } else if ($phase === orchestrator::PHASE_SELECTION) {
                $this->append_moodle_context_section($statelines, $blockcontext);
            }
        }

        // Current-page hint (VOLATILE): where the user actually is right now — pagetype, course,
        // activity, or a non-course family (dashboard, user profile, admin/report). Sourced from the
        // navbar snapshot via thread metadata, so it changes as the user navigates and therefore lives
        // in [SYSTEM_RUNTIME_STATE], never the cached prefix. Best-effort hint, not authorization.
        if ($fullcontextblock) {
            $this->append_page_context_section($statelines, $threadid);
        }

        // Keep first-turn language enforcement in SYSTEM_RUNTIME so static SYSTEM
        // prompt prefixes remain cache-friendly across requests.
        if ($phase === orchestrator::PHASE_DISCOVERY && $isfirstassistantturn && !$hasobservations) {
            $lines[] = '';
            $lines[] = 'NON-OPTIONAL LANGUAGE POLICY:';
            $lines[] = "- Include valid ISO 639-1 value 'user_lang'.";
        }

        // Inject user-stated memories filtered to the relevant channel. Each memory is tagged
        // (by the LLM at wizard.remember time) with the stage(s) it influences. Channels:
        // - selection: planner skill-selection LLM call (PHASE_SELECTION)
        // - construction: planner parameter-construction LLM call (PHASE_PARAMETER_CONSTRUCTION)
        // - synchronization: synchronizer final reply (process_synchronizer passes it explicitly,
        // because it also builds this block with PHASE_SELECTION and must not pull selection items).
        // Discovery makes no LLM call, so it carries no channel. Budget capped by the service.
        // Emitted in the VOLATILE half: memories are PER-USER, so keeping them out of the cached
        // [SYSTEM_RUNTIME] prefix lets the static skill catalog (slim_all path) cache across users, not
        // just across one user's contexts. They sit closer to the decision (after history) too.
        $channel = $memorychannel !== '' ? $memorychannel : $this->memory_channel_for_phase($phase);
        if ($channel !== '') {
            $this->append_user_memory_section($statelines, $threadid, $channel);
        }

        if (!empty($skillcatalog)) {
            if ($phase === orchestrator::PHASE_PARAMETER_CONSTRUCTION) {
                // Construction phase needs full parameter details — keep JSON so the constructor
                // can read types, descriptions and validation hints for the single selected skill.
                // It is the selected skill's schema (per-turn), so it stays volatile.
                $this->append_json_object_section($statelines, 'SKILL CATALOG:', $skillcatalog);
            } else if ($catalogisstatic) {
                // Static (slim_all / no-embeddings) catalog: identical every turn, so emit it in the
                // per-thread-stable block above the history where it joins the cached prompt prefix.
                $lines[] = '';
                $lines[] = 'SKILL CATALOG:';
                $lines[] = $this->catalogsvc->render_catalog_as_text($skillcatalog);
            } else {
                // Adaptive (embeddings top-K) catalog: changes per query, so it stays in the volatile
                // state — but above the ledgers/now_iso, so it is still cached across a turn's loop.
                $statelines[] = '';
                $statelines[] = 'SKILL CATALOG:';
                $statelines[] = $this->catalogsvc->render_catalog_as_text($skillcatalog);
            }
        }

        if (!empty($unavailableskillcatalog)) {
            // Travels with the catalog: stable when the catalog is static, volatile otherwise.
            if ($catalogisstatic) {
                $lines[] = '';
                $lines[] = 'UNAVAILABLE SKILLS (exist but not currently executable):';
                $lines[] = $this->catalogsvc->render_catalog_as_text($unavailableskillcatalog);
            } else {
                $statelines[] = '';
                $statelines[] = 'UNAVAILABLE SKILLS (exist but not currently executable):';
                $statelines[] = $this->catalogsvc->render_catalog_as_text($unavailableskillcatalog);
            }
        }

        $privacy = new privacy_anonymizer($this->store);

        // Low-confidence anonymization contract (#2226 D2): when the thread's token map holds
        // single-word name hits the user has not decided on, both planner phases get a volatile
        // instruction block. Condition is pure engine state (token-map confidence + stored
        // decisions); the synchronizer channel is excluded — it composes the reply, it does not
        // route. Volatile placement keeps the cached [SYSTEM_RUNTIME] prefix stable.
        $isplannerphase = in_array($phase, [
            orchestrator::PHASE_SELECTION,
            orchestrator::PHASE_PARAMETER_CONSTRUCTION,
        ], true);
        if ($isplannerphase && $channel !== user_memory_service::SCOPE_SYNCHRONIZATION) {
            $this->append_low_confidence_anon_section($statelines, $privacy, $threadid);
        }
        // Requester identity for BOTH planner phases (#2246; selection added 2026-09-19): the model must be
        // able to bind a self-reference to something that exists instead of inventing a name or placeholder.
        // Without it in the selection phase the selector treated "trag mich ein" as missing input and asked the
        // requester for their own name (baseline run 16, BKU-2) — the rule that forbids that sits in the skill
        // guidance and only ever reached the construction phase.
        // Anonymized tokens only — the clear-text identity never reaches the LLM.
        if ($isplannerphase) {
            // Same place as in the A/B (thread 15205/15207/15791): after the catalog, before current_user.
            if ($phase === orchestrator::PHASE_PARAMETER_CONSTRUCTION) {
                $this->append_course_names_section($statelines, $threadid, $skillcatalog);
            }
            $this->append_current_user_section($statelines, $privacy, $threadid);
        }

        // Rows of earlier turns go to their own sections (#2550): the decision order reads "completed_commands
        // of this turn", so listing every turn's work there made a follow-up request look already done. A row
        // belongs to this turn when it was created at or after the thread's latest user message (engine state).
        $turnstart = $this->current_turn_start($threadid, $messages);
        $completedcommands = $this->completedhistorysvc->extract_from_messages($messages, true);
        $completedcommands = $this->completedhistorysvc->merge_from_queue($threadid, $completedcommands, true);
        [$completedcommands, $earliercommands] = $this->split_by_turn($completedcommands, $turnstart);
        $completedcommands = (array)$privacy->anonymize_value_for_llm($threadid, $completedcommands);
        $earliercommands = (array)$privacy->anonymize_value_for_llm($threadid, $earliercommands);
        $this->append_turn_sections($statelines, 'completed_commands:', $completedcommands, $earliercommands);

        $observationledger = new execution_observation_ledger($this->store);
        $completedobservations = $observationledger->get_recent_for_runtime($threadid, 12, true);

        // Dedup haystack: live observations are already part of this prompt as
        // [OBSERVATION n] blocks; a ledger row repeating the same text is compacted
        // to its skill/status stub (the "already done" signal survives, the token
        // duplication does not). Both sides carry the same masking state, so the
        // comparison is reliable.
        $livehaystack = $this->normalize_for_observation_dedup(
            implode("\n", array_map('strval', $liveobservations))
        );

        $rows = [];
        foreach ($completedobservations as $row) {
            if (!is_array($row)) {
                continue;
            }
            $created = (int)($row['_created_at'] ?? 0);
            unset($row['_created_at']);

            $enginestatic = !empty($row['engine_static']);
            unset($row['engine_static']);

            if ($enginestatic) {
                // Engine-generated instructional text (e.g. search_skills catalog
                // descriptions) is never masked — masking corrupts instructions
                // (threads 286/288). Data sub-fields (input values) still go
                // through the anonymizer.
                $observation = (string)($row['observation'] ?? '');
                unset($row['observation']);
                $row = (array)$privacy->anonymize_value_for_llm($threadid, $row);
                $row['observation'] = $observation;
            } else {
                $row = (array)$privacy->anonymize_value_for_llm($threadid, $row);
            }

            $observationtext = $this->normalize_for_observation_dedup((string)($row['observation'] ?? ''));
            if ($observationtext !== '' && $livehaystack !== '' && str_contains($livehaystack, $observationtext)) {
                $row['observation'] = '[shown in the OBSERVATION blocks below]';
            }

            $row['_created_at'] = $created;
            $rows[] = $row;
        }
        [$rows, $earlierrows] = $this->split_by_turn($rows, $turnstart);
        $this->append_turn_sections($statelines, 'completed_observations:', $rows, $earlierrows);

        // The now_iso line is the single most volatile token (changes every request); keep it the LAST
        // state line so it never fronts the cacheable catalog/ledger content above it in the state block.
        $statelines[] = 'now_iso: ' . $nowiso;

        return [
            'stable' => implode("\n", $lines),
            'volatile' => implode("\n", $statelines),
        ];
    }

    /**
     * Append the structured moodle_context block (rich context awareness).
     *
     * @param array $lines runtime block lines, appended in place
     * @param context $blockcontext the resolved request context
     */
    private function append_moodle_context_section(array &$lines, context $blockcontext): void {
        $levelnames = [
            CONTEXT_SYSTEM => 'System',
            CONTEXT_USER => 'User',
            CONTEXT_COURSECAT => 'Course category',
            CONTEXT_COURSE => 'Course',
            CONTEXT_MODULE => 'Module',
        ];
        // Keep YAML values single-line and quote-safe.
        $yamlsafe = static function (string $value): string {
            return '"' . str_replace(['"', "\n", "\r"], ["'", ' ', ''], $value) . '"';
        };

        try {
            $ctx = agent_context::from_context($blockcontext);

            $lines[] = '';
            $lines[] = 'moodle_context:';
            // Spell the level out — the raw Moodle level constant (e.g. 30) means
            // nothing to the model.
            $lines[] = '  context_id: ' . $ctx->id();
            $lines[] = '  context_level: ' . $yamlsafe($levelnames[$ctx->level()] ?? ('Other (level ' . $ctx->level() . ')'));
            $lines[] = '  context_name: ' . $yamlsafe($blockcontext->get_context_name(false));

            $courseid = $ctx->courseid();
            if ($courseid !== null) {
                $modinfo = get_fast_modinfo($courseid);
                $course = $modinfo->get_course();
                $lines[] = '  course:';
                $lines[] = '    id: ' . (int)$courseid;
                $lines[] = '    fullname: ' . $yamlsafe(format_string($course->fullname));
                $lines[] = '    shortname: ' . $yamlsafe(format_string($course->shortname));

                $cmid = $ctx->cmid();
                if ($cmid !== null && isset($modinfo->cms[$cmid])) {
                    $cminfo = $modinfo->cms[$cmid];
                    $lines[] = '  module:';
                    $lines[] = '    cmid: ' . (int)$cmid;
                    $lines[] = '    modname: ' . $yamlsafe((string)$cminfo->modname);
                    $lines[] = '    instance_id: ' . (int)$cminfo->instance;
                    $lines[] = '    name: ' . $yamlsafe(format_string($cminfo->name));
                }
            }
        } catch (\Throwable $e) {
            debugging('moodle_context block skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Append the low-confidence anonymization contract for undecided single-word name hits (#2226 D2).
     *
     * @param array $statelines
     * @param privacy_anonymizer $privacy
     * @param int $threadid
     */
    private function append_low_confidence_anon_section(
        array &$statelines,
        privacy_anonymizer $privacy,
        int $threadid
    ): void {
        $thread = $this->store->get_thread($threadid);
        $userid = (int)($thread->userid ?? 0);
        $suspects = $privacy->get_low_confidence_suspects($threadid, $userid);
        if (empty($suspects)) {
            return;
        }

        $statelines[] = '';
        $statelines[] = 'LOW-CONFIDENCE ANONYMIZATION NOTICE:';
        $statelines[] = '- The following masked tokens come from a SINGLE-word name match and may be '
            . 'ordinary words, not persons: ' . implode(', ', array_keys($suspects)) . '.';
        $statelines[] = '- Do not select person-centric skills solely because such a token is present.';
        $statelines[] = '- In non-person parameters (course, option, search text, titles) pass the '
            . 'token through unchanged and treat it as an ordinary word.';
    }

    /**
     * Append the anonymized identity of the requester (thread owner) for the construction phase (#2246).
     *
     * @param array $statelines
     * @param privacy_anonymizer $privacy
     * @param int $threadid
     * @return void
     */
    private function append_current_user_section(array &$statelines, privacy_anonymizer $privacy, int $threadid): void {
        $tokens = self::current_user_identity_tokens($privacy, $threadid, $this->store);
        if (empty($tokens)) {
            return;
        }
        $statelines[] = 'current_user: ' . implode(' / ', $tokens)
            . ' (the requester; a person parameter carrying this identity means the requester -> omit it)';
    }

    /**
     * Anonymized identity tokens (name, e-mail) of a thread's owner, as the LLM sees them.
     *
     * Shared with the decision service, which strips person parameters bound to these tokens
     * (structural self-reference resolution). Empty when the thread or user is unknown or when
     * anonymization is off (the identity is then not shown to the LLM at all).
     *
     * @param privacy_anonymizer $privacy
     * @param int $threadid
     * @param conversation_store $store
     * @return string[]
     */
    public static function current_user_identity_tokens(
        privacy_anonymizer $privacy,
        int $threadid,
        conversation_store $store
    ): array {
        if ($threadid <= 0 || !$privacy->should_anonymize_llm_backend_data()) {
            return [];
        }
        $thread = $store->get_thread($threadid);
        $userid = (int)($thread->userid ?? 0);
        $user = $userid > 0 ? \core_user::get_user($userid, '*', IGNORE_MISSING) : false;
        if (!$user) {
            return [];
        }
        $tokens = [];
        foreach ([fullname($user), (string)($user->email ?? '')] as $identity) {
            $identity = trim($identity);
            if ($identity === '') {
                continue;
            }
            $masked = trim((string)$privacy->anonymize_value_for_llm($threadid, $identity));
            if ($masked !== '' && $masked !== $identity && !in_array($masked, $tokens, true)) {
                $tokens[] = $masked;
            }
        }
        return $tokens;
    }

    /**
     * Append the current-page hint from the navbar snapshot in thread metadata.
     *
     * @param array $statelines
     * @param int $threadid
     * @return void
     */
    private function append_page_context_section(array &$statelines, int $threadid): void {
        try {
            $pc = $this->store->get_thread_metadata_value($threadid, '_page_context');
            if (!is_array($pc) || empty($pc)) {
                return;
            }
            $yamlsafe = static fn($v): string =>
                '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', ' ', ' '], (string)$v) . '"';

            $pagetype = (string)($pc['pagetype'] ?? '');
            $statelines[] = 'current_page:';
            $statelines[] = '  page: ' . $yamlsafe($this->describe_page_family($pagetype, (int)($pc['contextlevel'] ?? 0)));
            if ($pagetype !== '') {
                $statelines[] = '  pagetype: ' . $yamlsafe($pagetype);
            }
            if (!empty($pc['url'])) {
                $statelines[] = '  url: ' . $yamlsafe($pc['url']);
            }
            if (!empty($pc['courseid'])) {
                $coursename = trim((string)($pc['coursename'] ?? ''));
                $statelines[] = '  course: '
                    . $yamlsafe(($coursename !== '' ? $coursename . ' ' : '') . '(id ' . (int)$pc['courseid'] . ')');
            }
            if (!empty($pc['cmid'])) {
                $activity = trim((string)($pc['modname'] ?? '') . ' ' . (string)($pc['activityname'] ?? ''));
                $statelines[] = '  activity: '
                    . $yamlsafe(($activity !== '' ? $activity . ' ' : '') . '(cmid ' . (int)$pc['cmid'] . ')');
            }
            if (!empty($pc['heading'])) {
                $statelines[] = '  heading: ' . $yamlsafe($pc['heading']);
            }
        } catch (\Throwable $e) {
            debugging('current_page block skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Map a Moodle pagetype to a human-readable page family so the agent can say where the user is.
     *
     * @param string $pagetype
     * @param int $contextlevel
     * @return string
     */
    private function describe_page_family(string $pagetype, int $contextlevel): string {
        $pt = strtolower(trim($pagetype));
        $exact = [
            'my-index' => 'Dashboard',
            'site-index' => 'Site front page',
            'user-profile' => 'User profile page',
            'course-index' => 'Course list',
        ];
        if (isset($exact[$pt])) {
            return $exact[$pt];
        }
        if (str_starts_with($pt, 'course-view')) {
            return 'Course page';
        }
        if (str_starts_with($pt, 'course-edit') || str_starts_with($pt, 'course-management')) {
            return 'Course management page';
        }
        if (preg_match('/^mod-[a-z0-9]+-index$/', $pt)) {
            return 'Activity index (list)';
        }
        if (str_starts_with($pt, 'mod-')) {
            return 'Activity page';
        }
        if (str_starts_with($pt, 'grade-')) {
            return 'Gradebook page';
        }
        if (str_starts_with($pt, 'user-')) {
            return 'User page';
        }
        if (str_starts_with($pt, 'admin-') || str_starts_with($pt, 'report-') || $contextlevel === CONTEXT_SYSTEM) {
            return 'Admin/report page';
        }
        return $pagetype !== '' ? $pagetype : 'Unknown page';
    }

    /**
     * Resolve the memory injection channel for a planner phase.
     *
     * @param string $phase
     * @return string '' when the phase carries no memory channel (e.g. discovery)
     */
    private function memory_channel_for_phase(string $phase): string {
        switch ($phase) {
            case orchestrator::PHASE_SELECTION:
                return user_memory_service::SCOPE_SELECTION;
            case orchestrator::PHASE_PARAMETER_CONSTRUCTION:
                return user_memory_service::SCOPE_CONSTRUCTION;
            default:
                return '';
        }
    }

    /**
     * Append the USER MEMORY block (user-stated facts) for the thread owner, filtered to one channel.
     *
     * @param string[] $lines
     * @param int $threadid
     * @param string $channel One of user_memory_service::SCOPE_*
     * @return void
     */
    private function append_user_memory_section(array &$lines, int $threadid, string $channel): void {
        $thread = $this->store->get_thread($threadid);
        $userid = (int)($thread->userid ?? 0);
        if ($userid <= 0) {
            return;
        }

        $records = (new user_memory_service())->get_for_scope($userid, $channel);
        if (empty($records)) {
            return;
        }

        $lines[] = '';
        $lines[] = 'USER MEMORY (facts the user asked you to remember; respect these):';
        foreach ($records as $record) {
            $memory = trim((string)$record->memory);
            if ($memory !== '') {
                $lines[] = '- "' . $memory . '"';
            }
        }
    }

    /** @var int Shortest word stem compared against course names (letters and digits). */
    private const COURSE_NAME_MIN_STEM = 5;

    /** @var int A match may be this many letters shorter than the best one and still be listed. */
    private const COURSE_NAME_SCORE_MARGIN = 2;

    /** @var int Most course names listed. */
    private const COURSE_NAME_LIMIT = 10;

    /**
     * Append the names of the courses the request's words point to - construction of a course skill only.
     *
     * Welle 33/34 (AQ-1, AQ-3, SCC-1): a course named by its subject ("vom Brandschutzkurs") never reached the course
     * field, the constructor read it as the content's topic. A/B: with the matching course names as a fact 20/20 instead
     * of 2/20. The list is engine state: courses the user can access whose name shares a word stem (at least
     * COURSE_NAME_MIN_STEM letters or digits, in either direction) with the user's latest message - a comparison against
     * real course names like the resolvers, no word list. Only when the one selected skill targets a course
     * (get_target_context_level(), a declarative hook). Every name passes the anonymizer.
     *
     * @param string[] $lines
     * @param int $threadid
     * @param array $skillcatalog The construction phase's catalog: the selected skill.
     * @return void
     */
    private function append_course_names_section(array &$lines, int $threadid, array $skillcatalog): void {
        global $DB;
        if (count($skillcatalog) !== 1) {
            return;
        }
        $entry = reset($skillcatalog);
        $skill = skill_registry_factory::get_default()->get_skill((string)($entry['skill'] ?? ''));
        $targetscourse = $skill && method_exists($skill, 'get_target_context_level')
            && (int)$skill->get_target_context_level() === CONTEXT_COURSE;
        if (!$targetscourse) {
            return;
        }
        $thread = $this->store->get_thread($threadid);
        $userid = (int)($thread->userid ?? 0);
        $message = '';
        $messages = $this->store->get_messages($threadid);
        usort($messages, static fn($a, $b): int => (int)$a->id <=> (int)$b->id);
        foreach ($messages as $record) {
            if ((string)($record->role ?? '') === 'user') {
                $message = (string)$record->content;
            }
        }
        preg_match_all('/[\p{L}\p{N}]{' . self::COURSE_NAME_MIN_STEM . ',}/u', core_text::strtolower($message), $found);
        $stems = array_values(array_unique($found[0] ?? []));
        if ($userid <= 0 || empty($stems)) {
            return;
        }
        // Candidates: a course name containing the first letters of a request word (covers both directions).
        $conditions = [];
        $params = [];
        foreach ($stems as $i => $stem) {
            $conditions[] = $DB->sql_like('fullname', ':s' . $i, false);
            $params['s' . $i] = '%' . $DB->sql_like_escape(core_text::substr($stem, 0, self::COURSE_NAME_MIN_STEM)) . '%';
        }
        $candidates = $DB->get_records_select(
            'course',
            'id <> :site AND (' . implode(' OR ', $conditions) . ')',
            array_merge($params, ['site' => SITEID]),
            'fullname',
            '*',
            0,
            200
        );
        $user = \core_user::get_user($userid);
        $scored = [];
        foreach ($candidates as $course) {
            $pattern = '/[\p{L}\p{N}]{' . self::COURSE_NAME_MIN_STEM . ',}/u';
            preg_match_all($pattern, core_text::strtolower($course->fullname), $cf);
            $score = 0;
            foreach ((array)($cf[0] ?? []) as $coursestem) {
                foreach ($stems as $stem) {
                    if (str_contains($stem, $coursestem) || str_contains($coursestem, $stem)) {
                        $score = max($score, min(core_text::strlen($stem), core_text::strlen($coursestem)));
                    }
                }
            }
            if ($score > 0 && $user && can_access_course($course, $user, '', true)) {
                $scored[] = [$score, (string)$course->fullname];
            }
        }
        if (empty($scored)) {
            return;
        }
        usort($scored, static fn(array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        // Several courses fit the request equally well: no list (L48, AA-2 thread 16747). With "Winter School 2026" and
        // "2027" in front of it, the constructor picked one of them silently in 4 of 20 A/B runs; without the list it
        // hands the user's words to the course resolution, which asks with the candidates to choose from.
        if (count($scored) > 1 && (int)$scored[1][0] === (int)$scored[0][0]) {
            return;
        }
        // Only matches close to the best one (live check 16568: "cours" inside "course" listed unrelated courses next to
        // "Biologie"); two courses the request really names keep similar lengths and both stay.
        $best = (int)$scored[0][0];
        $scored = array_values(array_filter(
            $scored,
            static fn(array $row): bool => (int)$row[0] >= $best - self::COURSE_NAME_SCORE_MARGIN
        ));
        $privacy = new privacy_anonymizer($this->store);
        $lines[] = '';
        $lines[] = 'COURSE NAMES matching words of the request (courses the user can access):';
        foreach (array_slice($scored, 0, self::COURSE_NAME_LIMIT) as [, $name]) {
            $lines[] = '- ' . (string)$privacy->anonymize_value_for_llm($threadid, format_string($name));
        }
    }

    /**
     * Append a JSON-encoded object section to runtime context lines.
     *
     * @param string[] $lines
     * @param string $heading
     * @param mixed $value
     * @return void
     */
    private function append_json_object_section(array &$lines, string $heading, $value): void {
        $json = $this->json_encode_or_empty($value, JSON_UNESCAPED_UNICODE);
        if ($json === '') {
            return;
        }

        $lines[] = '';
        $lines[] = $heading;
        $lines[] = $json;
    }

    /**
     * Start of the current turn: creation time of the thread's latest user message (0 when there is none).
     *
     * @param int $threadid
     * @param array $messages Messages of the thread when the caller already has them.
     * @return int
     */
    private function current_turn_start(int $threadid, array $messages): int {
        if (empty($messages) && $threadid > 0) {
            $messages = $this->store->get_messages($threadid);
        }
        $start = 0;
        foreach ($messages as $message) {
            if ((string)($message->role ?? '') === 'user') {
                $start = max($start, (int)($message->timecreated ?? 0));
            }
        }
        return $start;
    }

    /**
     * Append this turn's rows and, below, earlier turns' rows under earlier_turns_<heading>.
     *
     * While earlier rows exist, this turn's heading is emitted even when empty: the explicit empty list is
     * what tells the planner that nothing has been done for the current request yet (#2550 A/B: without
     * the empty heading the selector still read the earlier rows as this turn's).
     *
     * @param array $lines
     * @param string $heading
     * @param array[] $current
     * @param array[] $earlier
     * @return void
     */
    private function append_turn_sections(array &$lines, string $heading, array $current, array $earlier): void {
        if (empty($current) && !empty($earlier)) {
            $lines[] = '';
            $lines[] = $heading;
        } else {
            $this->append_json_list_section($lines, $heading, $current);
        }
        $this->append_json_list_section($lines, 'earlier_turns_' . $heading, $earlier);
    }

    /**
     * Split rows carrying _created_at into this turn's and earlier turns' rows, dropping the key.
     *
     * A row without a creation time counts as this turn's, as all rows did before the split.
     *
     * @param array[] $rows
     * @param int $turnstart
     * @return array Two lists: this turn's rows and earlier turns' rows.
     */
    private function split_by_turn(array $rows, int $turnstart): array {
        $current = [];
        $earlier = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $created = (int)($row['_created_at'] ?? 0);
            unset($row['_created_at']);
            if ($turnstart > 0 && $created > 0 && $created < $turnstart) {
                $earlier[] = $row;
            } else {
                $current[] = $row;
            }
        }
        return [$current, $earlier];
    }

    /**
     * Append a bullet-style JSON list section to runtime context lines.
     *
     * @param string[] $lines
     * @param string $heading
     * @param mixed[] $items
     * @return void
     */
    private function append_json_list_section(array &$lines, string $heading, array $items): void {
        if (empty($items)) {
            return;
        }

        $lines[] = '';
        $lines[] = $heading;
        foreach ($items as $item) {
            $json = $this->json_encode_or_empty($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === '') {
                continue;
            }
            $lines[] = '  - ' . $json;
        }
    }

    /**
     * JSON encode helper that always returns a string.
     *
     * @param mixed $value
     * @param int $flags
     * @return string
     */
    private function json_encode_or_empty($value, int $flags): string {
        $json = json_encode($value, $flags);
        if (!is_string($json)) {
            return '';
        }

        return $json;
    }

    /**
     * Whitespace-normalize observation text for the ledger-vs-live dedup check.
     *
     * @param string $text
     * @return string
     */
    private function normalize_for_observation_dedup(string $text): string {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}

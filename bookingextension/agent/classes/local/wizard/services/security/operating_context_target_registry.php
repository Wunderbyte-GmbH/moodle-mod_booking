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

namespace bookingextension_agent\local\wizard\services\security;

use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\dto\context_target_resolution;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\operating_context_target_provider_interface;
use context_course;
use core_course_category;
use core_text;

/**
 * Resolves a {@see target_selector} to a concrete operating context.
 *
 * Generic by design: the target's context LEVEL decides the strategy.
 *  - CONTEXT_COURSE is resolved here, in the engine core, using only Moodle core course APIs
 *    (courses are a core concept, so this introduces no domain dependency).
 *  - Any other level is delegated to a domain provider implementing
 *    {@see operating_context_target_provider_interface}, discovered duck-typed so the engine
 *    keeps no compile-time link to a concrete domain.
 *
 * Resolving a context is NOT a permission grant — Gate 2 (require_capability at the resolved
 * operating context) is enforced separately by the caller.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class operating_context_target_registry {
    /** @var operating_context_target_provider_interface[] Providers for non-core levels. */
    private array $providers;

    /** @var module_target_resolver Generic CONTEXT_MODULE resolver (keyed by modname). */
    private module_target_resolver $moduleresolver;

    /**
     * Constructor.
     *
     * @param operating_context_target_provider_interface[] $providers Optional explicit
     *        providers for non-core levels other than module (mainly for tests / third-party
     *        overrides). When omitted, none are active.
     * @param module_target_resolver|null $moduleresolver Injectable for tests.
     */
    public function __construct(array $providers = [], ?module_target_resolver $moduleresolver = null) {
        $this->providers = $providers;
        $this->moduleresolver = $moduleresolver ?? new module_target_resolver();
    }

    /**
     * Resolve a target selector to a concrete operating context.
     *
     * @param target_selector    $target
     * @param int                $userid  Acting user id (for visibility-aware resolution).
     * @param agent_context|null $ambient The chat/thread ambient context (drives module scope cascade).
     * @return context_target_resolution
     */
    public function resolve(
        target_selector $target,
        int $userid = 0,
        ?agent_context $ambient = null
    ): context_target_resolution {
        if ($target->level() === CONTEXT_MODULE) {
            // Module targets resolve generically by modname — even when "empty", the modname alone
            // lets the resolver auto-pick the unique instance in scope (course-first → site-wide).
            return $this->moduleresolver->resolve($target, $ambient, $userid);
        }

        if ($target->is_empty()) {
            return context_target_resolution::not_found();
        }

        if ($target->level() === CONTEXT_COURSE) {
            return $this->resolve_course($target);
        }

        foreach ($this->providers as $provider) {
            if ($provider->supports_level($target->level())) {
                return $provider->resolve_target($target, $userid);
            }
        }

        return context_target_resolution::unsupported();
    }

    /**
     * Resolve a course-level target using core course APIs only.
     *
     * @param target_selector $target
     * @return context_target_resolution
     */
    private function resolve_course(target_selector $target): context_target_resolution {
        // An explicit, valid course id wins.
        $id = $target->id();
        if ($id !== null) {
            $context = context_course::instance($id, IGNORE_MISSING);
            return $context ? context_target_resolution::resolved($context) : context_target_resolution::not_found();
        }

        $query = (string)$target->query();

        // A purely numeric query is treated as a course id.
        if (preg_match('/^\d+$/', $query)) {
            $context = context_course::instance((int)$query, IGNORE_MISSING);
            return $context ? context_target_resolution::resolved($context) : context_target_resolution::not_found();
        }

        // The site course (front page, id 1) is a legitimate target — Moodle lets activities and
        // resources live on the front page — but the course catalog search below never returns it.
        // Resolve it explicitly when the query names the site (its full/short name) or refers to the
        // current front-page context (its context name, e.g. "Site home"). Resolving a context is not
        // a permission grant: the caller still enforces the capability at this context (Gate 2).
        if ($this->query_denotes_site_course($query)) {
            $context = context_course::instance(SITEID, IGNORE_MISSING);
            return $context ? context_target_resolution::resolved($context) : context_target_resolution::not_found();
        }

        // Free-text: match against the visible course list (respects the acting user's visibility).
        $candidates = $this->course_candidates($query, 25);
        if (empty($candidates)) {
            // A query such as "Buchdruck-Kurs" finds nothing as one string although "Buchdruck (Kursrohling)" is the only
            // course with that token (baseline runs 28-37, SCC-1): the tokens that match must agree on one
            // course; a token nobody matches ("Kurs") carries no meaning (wave 28, same helper as for persons).
            $candidates = \bookingextension_agent\local\wizard\services\target_query_normalizer::narrow_by_tokens(
                $query,
                fn(string $token, int $limit): array => $this->course_candidates($token, $limit)
            );
        }
        if (empty($candidates)) {
            // A compound request word ("Brandschutzkurs", AQ-1 L49) that a course's name word opens - the comparison the
            // constructor's course-names block makes; the resolver must find what the block showed (wave 35).
            $candidates = \bookingextension_agent\local\wizard\services\target_query_normalizer::narrow_by_stems(
                $query,
                fn(string $prefix, int $limit): array => $this->course_candidates($prefix, $limit)
            );
        }

        if (empty($candidates)) {
            return context_target_resolution::not_found();
        }

        // Exact (case-insensitive) matches on fullname or shortname win over substring
        // siblings — mirroring the module path's filter_by_name(): "booking" resolves the
        // course literally named "booking" instead of going ambiguous against "slotbooking".
        $needle = core_text::strtolower(trim($query));
        $exact = array_values(array_filter($candidates, static function (array $candidate) use ($needle): bool {
            return core_text::strtolower((string)$candidate['name']) === $needle
                || core_text::strtolower((string)$candidate['shortname']) === $needle;
        }));
        if (!empty($exact)) {
            $candidates = $exact;
        }

        if (count($candidates) === 1) {
            $context = context_course::instance($candidates[0]['id'], IGNORE_MISSING);
            return $context ? context_target_resolution::resolved($context) : context_target_resolution::not_found();
        }

        return context_target_resolution::ambiguous($candidates);
    }

    /**
     * Courses whose name matches the query, as the course search sees it (site course excluded).
     *
     * @param string $query
     * @param int $limit
     * @return array<int,array{id:int,name:string,shortname:string}>
     */
    private function course_candidates(string $query, int $limit): array {
        $courses = core_course_category::search_courses(
            ['search' => $query],
            ['limit' => $limit, 'sort' => ['fullname' => 1]]
        );
        $candidates = [];
        foreach ($courses as $course) {
            $courseid = (int)($course->id ?? 0);
            if ($courseid <= 1) {
                continue;
            }
            $candidates[] = [
                'id' => $courseid,
                'name' => (string)($course->fullname ?? $course->shortname ?? ('#' . $courseid)),
                'shortname' => (string)($course->shortname ?? ''),
            ];
        }
        return $candidates;
    }

    /**
     * Whether a free-text query denotes the site course (front page, id 1).
     *
     * Matches, case-insensitively, the site's full or short name, or the site course context name
     * (e.g. the localized "Site home") — the latter is what the runtime context block shows the
     * planner as the current context on the front page, so "add a label here" resolves correctly.
     *
     * @param string $query
     * @return bool
     */
    private function query_denotes_site_course(string $query): bool {
        $needle = core_text::strtolower(trim($query));
        if ($needle === '') {
            return false;
        }
        $site = get_site();
        $names = [
            core_text::strtolower(format_string($site->fullname)),
            core_text::strtolower((string)$site->shortname),
            core_text::strtolower(context_course::instance(SITEID)->get_context_name(false)),
        ];
        return in_array($needle, array_filter($names), true);
    }
}

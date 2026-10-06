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

use stdClass;

/**
 * Lists a course's existing sections and resolves a placement query ("ganz unten", "top", a section name)
 * to a concrete section number — without creating any section (read-only, preflight-safe).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_resolver_service {
    /** Resolution sentinel: the query named the first section (top). */
    public const PLACE_TOP = 'top';

    /** Resolution sentinel: the query named the last section (bottom). */
    public const PLACE_BOTTOM = 'bottom';

    /** The only usable section on the site front page (section 0 is not rendered there). */
    public const SITE_FRONT_PAGE_SECTION = 1;

    /** Language-agnostic-ish keyword sets for top/bottom placement. */
    private const TOP_WORDS = ['top', 'oben', 'ganz oben', 'anfang', 'beginning', 'start', 'first', 'erste'];

    /** @var string[] */
    private const BOTTOM_WORDS = ['bottom', 'unten', 'ganz unten', 'ende', 'end', 'last', 'letzte', 'final'];

    /**
     * Return the course's existing sections with clear, human-readable names.
     *
     * @param stdClass $course
     * @return array[] Ordered by section number.
     */
    public function list_sections(stdClass $course): array {
        $modinfo = get_fast_modinfo($course);
        $sections = [];
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            $sectionnum = (int)$sectioninfo->section;
            $sections[] = [
                'sectionnum' => $sectionnum,
                'name' => (string)get_section_name($course, $sectioninfo),
                // The name a teacher gave the section; '' for the format's generated default name.
                'customname' => trim((string)($sectioninfo->name ?? '')),
            ];
        }

        usort($sections, static fn(array $a, array $b): int => $a['sectionnum'] <=> $b['sectionnum']);
        return $sections;
    }

    /**
     * Highest existing section number of a course.
     *
     * Used to clamp a relative move ("one section down") to what the course actually has.
     *
     * @param stdClass $course
     * @return int
     */
    public function last_section_number(stdClass $course): int {
        $sections = $this->list_sections($course);
        if (empty($sections)) {
            return 0;
        }

        return (int)end($sections)['sectionnum'];
    }

    /**
     * Resolve a placement query to a concrete section number.
     *
     * Returns:
     *  - an int section number when the query unambiguously names one (top/bottom or a single name match),
     *  - the matching subset (>1 entries) when a name query is ambiguous,
     *  - null when the query is empty or matches nothing (caller asks "where?").
     *
     * @param stdClass $course
     * @param string $query
     * @return int|array[]|null
     */
    public function resolve_placement(stdClass $course, string $query) {
        $needle = \core_text::strtolower(trim($query));
        if ($needle === '') {
            return null;
        }

        $sections = $this->list_sections($course);
        if (empty($sections)) {
            return null;
        }

        if (in_array($needle, self::TOP_WORDS, true)) {
            return (int)$sections[0]['sectionnum'];
        }
        if (in_array($needle, self::BOTTOM_WORDS, true)) {
            return (int)$sections[count($sections) - 1]['sectionnum'];
        }

        // A numeric section number the user gave directly.
        if (ctype_digit($needle)) {
            $wanted = (int)$needle;
            foreach ($sections as $section) {
                if ($section['sectionnum'] === $wanted) {
                    return $wanted;
                }
            }
            return null;
        }

        // Match by section name: exact (case-insensitive) first, then substring.
        $exact = [];
        foreach ($sections as $section) {
            if (\core_text::strtolower($section['name']) === $needle) {
                $exact[] = $section;
            }
        }
        if (count($exact) === 1) {
            return (int)$exact[0]['sectionnum'];
        }
        if (count($exact) > 1) {
            return $exact;
        }

        $partial = [];
        foreach ($sections as $section) {
            if ($section['name'] !== '' && str_contains(\core_text::strtolower($section['name']), $needle)) {
                $partial[] = $section;
            }
        }
        if (count($partial) === 1) {
            return (int)$partial[0]['sectionnum'];
        }
        if (count($partial) > 1) {
            return $partial;
        }

        // Wave 32 (AA-4, nine of ten runs): "im Einführungsabschnitt" reached this resolver verbatim as
        // "Einführungsabschnitt" and never matched the section "Einführung" - the user's words carry the name,
        // not the other way round. A section whose OWN name (not the format's generated default) stands inside
        // the query, compared by shape, is the one meant. No word is read; one match resolves, several are
        // returned for the caller's question.
        $querykey = \bookingextension_agent\local\wizard\services\target_query_normalizer::name_key($query);
        $carried = [];
        foreach ($sections as $section) {
            $namekey = \bookingextension_agent\local\wizard\services\target_query_normalizer::name_key(
                (string)($section['customname'] ?? '')
            );
            if ($namekey !== '' && $querykey !== '' && str_contains($querykey, $namekey)) {
                $carried[] = $section;
            }
        }
        if (count($carried) === 1) {
            return (int)$carried[0]['sectionnum'];
        }
        if (count($carried) > 1) {
            return $carried;
        }

        return null;
    }

    /**
     * Whether the course is the site front page (course format 'site').
     *
     * The front page has no selectable topic sections: its section 0 is not rendered there, so every
     * activity must live in section 1. Callers use this to hard-map any requested placement to
     * {@see self::SITE_FRONT_PAGE_SECTION} instead of asking the user "which section?".
     *
     * @param stdClass $course
     * @return bool
     */
    public static function is_site_front_page(stdClass $course): bool {
        return (string)($course->format ?? '') === 'site' || (int)($course->id ?? 0) === (int)SITEID;
    }

    /**
     * Whether the given section number currently exists in the course.
     *
     * @param stdClass $course
     * @param int $sectionnum
     * @return bool
     */
    public function section_exists(stdClass $course, int $sectionnum): bool {
        foreach ($this->list_sections($course) as $section) {
            if ($section['sectionnum'] === $sectionnum) {
                return true;
            }
        }
        return false;
    }
}

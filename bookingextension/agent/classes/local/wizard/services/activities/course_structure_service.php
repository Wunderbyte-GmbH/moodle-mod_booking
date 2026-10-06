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

use context_course;

/**
 * Builds a per-user-correct tree of a course's sections and activities.
 *
 * The ONLY visibility filter is Moodle's own engine: get_fast_modinfo($course, $userid) computes
 * $section->uservisible / $cm->uservisible for exactly that user, already folding in viewhiddensections,
 * viewhiddenactivities, ignoreavailabilityrestrictions, all availability conditions and group/mod:view
 * restrictions. We never re-implement has_capability — so a caller can only ever surface what the user
 * would normally see. Two non-accessible states are intentionally still reported (matching how Moodle
 * greys them out on the course page): an element the user may SEE but not ENTER (restriction reason shown)
 * is included with accessible=false; an element the user cannot see at all is omitted entirely.
 *
 * Pure read-only skill-layer helper — no engine coupling, easily unit-tested with two users.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_structure_service {
    /** Max characters kept for a section summary / activity intro (keeps observations bounded). */
    private const TEXT_LIMIT = 600;

    /**
     * Build the visible course structure for a given user.
     *
     * @param \stdClass $course               The course record.
     * @param int       $userid               The user whose visibility scopes the result.
     * @param bool      $includedescriptions  Whether to load section summaries + activity intros (1 DB read per activity).
     * @return array Normalised structure (see the skill's data contract).
     */
    public function analyze(\stdClass $course, int $userid, bool $includedescriptions = true): array {
        $courseid = (int)$course->id;
        $coursecontext = context_course::instance($courseid);

        // Visibility is computed for THIS user. This is the single, capability-safe filter.
        $modinfo = get_fast_modinfo($course, $userid);
        $sectionscmids = $modinfo->get_sections(); // Map [sectionnumber => [cmid, ...]] in display order.

        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $sectionnode = $this->build_section_node(
                $course,
                $coursecontext,
                $modinfo,
                $section,
                $sectionscmids[$section->section] ?? [],
                $includedescriptions
            );
            if ($sectionnode !== null) {
                $sections[] = $sectionnode;
            }
        }

        return [
            'courseid' => $courseid,
            'coursename' => format_string($course->fullname),
            'courseshortname' => format_string($course->shortname),
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            'viewer_can_edit' => has_capability('moodle/course:manageactivities', $coursecontext, $userid),
            'descriptions_included' => $includedescriptions,
            'sections' => $sections,
        ];
    }

    /**
     * Build one section node, or null when the user may not even see the section.
     *
     * @param \stdClass $course
     * @param \context_course $coursecontext
     * @param \course_modinfo $modinfo
     * @param \section_info $section
     * @param int[] $cmids Course-module ids of this section, in display order.
     * @param bool $includedescriptions
     * @return array|null
     */
    private function build_section_node(
        \stdClass $course,
        \context_course $coursecontext,
        \course_modinfo $modinfo,
        \section_info $section,
        array $cmids,
        bool $includedescriptions
    ): ?array {
        $restrictinfo = $this->restriction_text($section->availableinfo, $course);

        // Fully invisible (e.g. teacher-hidden section the user can't view, or a restriction set to "hide
        // entirely" → no reason shown): omit it completely. Only the "shown but greyed with a reason" case
        // (uservisible=false AND a restriction reason exists) is surfaced as not-accessible.
        if (!$section->uservisible && $restrictinfo === '') {
            return null;
        }

        $accessible = (bool)$section->uservisible;

        // Activities: only list them for a section the user can actually enter. A locked (greyed) section is
        // reported as a header with its reason, but we never enumerate its contents.
        $activities = [];
        if ($accessible) {
            foreach ($cmids as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                $activitynode = $this->build_activity_node($course, $cm, count($activities), $includedescriptions);
                if ($activitynode !== null) {
                    $activities[] = $activitynode;
                }
            }
        }

        return [
            'id' => (int)$section->id,
            'number' => (int)$section->section,
            'name' => get_section_name($course, $section),
            'summary_text' => ($includedescriptions && $accessible)
                ? $this->format_to_text($section->summary, (int)$section->summaryformat, $coursecontext)
                : '',
            'hidden' => ((int)$section->visible === 0),
            'restricted' => !$section->available,
            'restrictinfo' => $restrictinfo,
            'accessible' => $accessible,
            'activities' => $activities,
        ];
    }

    /**
     * Build one activity node, or null when the user may not see it at all.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @param int $position
     * @param bool $includedescriptions
     * @return array|null
     */
    private function build_activity_node(\stdClass $course, \cm_info $cm, int $position, bool $includedescriptions): ?array {
        if (!empty($cm->deletioninprogress)) {
            return null;
        }

        // Accessible → normal listing. Not accessible but shown greyed on the course page with a reason
        // (is_visible_on_course_page) → list it as locked. Otherwise the user cannot see it → omit.
        $accessible = (bool)$cm->uservisible;
        if (!$accessible && !$cm->is_visible_on_course_page()) {
            return null;
        }

        return [
            'cmid' => (int)$cm->id,
            'modname' => (string)$cm->modname,
            'name' => $cm->get_formatted_name(),
            'intro_text' => $includedescriptions ? $this->activity_intro_text($cm) : '',
            'url' => ($cm->url instanceof \moodle_url) ? $cm->url->out(false) : null,
            'hidden' => ((int)$cm->visible === 0),
            'restricted' => !$cm->available,
            'restrictinfo' => $this->restriction_text($cm->availableinfo, $course),
            'accessible' => $accessible,
            'groupmode' => $this->group_mode_label((int)$cm->effectivegroupmode),
            'position' => $position,
        ];
    }

    /**
     * Load and flatten an activity's intro/description (1 DB read; only when descriptions are requested).
     *
     * @param \cm_info $cm
     * @return string
     */
    private function activity_intro_text(\cm_info $cm): string {
        global $DB;
        try {
            $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
            if (!$instance || !isset($instance->intro) || trim((string)$instance->intro) === '') {
                return '';
            }
            $html = format_module_intro($cm->modname, $instance, (int)$cm->id, false);
            return $this->flatten($html);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Resolve a restriction (availability) reason to plain text, rendering Moodle's placeholder tags.
     *
     * @param string|null $availableinfo
     * @param \stdClass $course
     * @return string
     */
    private function restriction_text(?string $availableinfo, \stdClass $course): string {
        $raw = trim((string)$availableinfo);
        if ($raw === '') {
            return '';
        }
        try {
            return $this->flatten(\core_availability\info::format_info($raw, $course));
        } catch (\Throwable $e) {
            return $this->flatten($raw);
        }
    }

    /**
     * Format Moodle text and reduce it to bounded plain text.
     *
     * @param string|null $text
     * @param int $format
     * @param \context_course $context
     * @return string
     */
    private function format_to_text(?string $text, int $format, \context_course $context): string {
        $raw = trim((string)$text);
        if ($raw === '') {
            return '';
        }
        return $this->flatten(format_text($raw, $format, ['context' => $context, 'filter' => true]));
    }

    /**
     * Strip HTML, collapse whitespace and bound the length.
     *
     * @param string $html
     * @return string
     */
    private function flatten(string $html): string {
        $text = trim(preg_replace('/\s+/u', ' ', (string)html_to_text($html, 0, false)));
        if (\core_text::strlen($text) > self::TEXT_LIMIT) {
            $text = \core_text::substr($text, 0, self::TEXT_LIMIT) . '…';
        }
        return $text;
    }

    /**
     * Map an effective group mode to a stable label.
     *
     * @param int $groupmode
     * @return string
     */
    private function group_mode_label(int $groupmode): string {
        if ($groupmode === SEPARATEGROUPS) {
            return 'separate';
        }
        if ($groupmode === VISIBLEGROUPS) {
            return 'visible';
        }
        return 'none';
    }
}

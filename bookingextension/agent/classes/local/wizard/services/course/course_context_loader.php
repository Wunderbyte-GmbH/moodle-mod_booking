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

namespace bookingextension_agent\local\wizard\services\course;

/**
 * Shared course-context loader for readonly course skills.
 *
 * One place that turns a resolved course id into the full activity inventory and resolves an
 * activity/item reference — the foundation for the course diagnose/overview consolidation
 * (docs/Blueprints/COURSE_DIAGNOSE_SKILL_CONSOLIDATION.md).
 *
 * Resolution policy is "enumerate-then-reason": the loader does NOT guess ordinals or module-type
 * taxonomies ("the 2nd activity", "the 3rd quiz" with mixed quiz modules). It produces a rich,
 * compact inventory (id · modname · name · section · position · visible) and resolves only an exact,
 * unique name/id match. Everything else is handed back as the inventory so the LLM can pick the
 * concrete activity (or ask) — the code enumerates, the model decides.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_context_loader {
    /**
     * Build the per-user-visibility-filtered activity inventory for a course.
     *
     * @param \stdClass $course
     * @param int $foruserid the user whose visibility is applied (acting or target user)
     * @return array[] rows: cmid, modname, name, sectionnum, sectionname, position, visible, uservisible
     */
    public function build_inventory(\stdClass $course, int $foruserid): array {
        $modinfo = get_fast_modinfo($course, $foruserid);
        $inventory = [];
        $position = 0;
        foreach ($modinfo->get_cms() as $cm) {
            $sectionnum = (int)$cm->sectionnum;
            $inventory[] = [
                'cmid' => (int)$cm->id,
                'modname' => (string)$cm->modname,
                'name' => (string)$cm->name,
                'sectionnum' => $sectionnum,
                'sectionname' => (string)get_section_name($course, $sectionnum),
                'position' => ++$position,
                'visible' => (int)$cm->visible === 1,
                'uservisible' => (bool)$cm->uservisible,
            ];
        }
        return $inventory;
    }

    /**
     * Resolve an activity reference against the inventory — exact id, else exact-unique name match.
     *
     * Deliberately conservative: only a single unambiguous match resolves. No ordinal/type guessing —
     * a non-unique reference returns 'unresolved' and the caller hands the inventory to the LLM.
     *
     * @param array[] $inventory
     * @param string $query free-text activity name (may be empty)
     * @param int $activityid resolved cmid when the LLM already picked one (takes precedence)
     * @param string $modnamefilter optional module-name filter (e.g. 'quiz'); '' = any
     * @return array{status:string,row?:array,candidates:array[]}
     *         status: 'resolved' | 'unresolved' | 'none'
     */
    public function resolve_activity(
        array $inventory,
        string $query,
        int $activityid = 0,
        string $modnamefilter = ''
    ): array {
        $pool = $modnamefilter === ''
            ? $inventory
            : array_values(array_filter($inventory, static fn($r): bool => $r['modname'] === $modnamefilter));

        if ($activityid > 0) {
            foreach ($inventory as $row) {
                if ((int)$row['cmid'] === $activityid) {
                    return ['status' => 'resolved', 'row' => $row, 'candidates' => []];
                }
            }
        }

        if (empty($pool)) {
            return ['status' => 'none', 'candidates' => []];
        }

        $query = trim($query);
        if ($query !== '') {
            $needle = \core_text::strtolower($query);
            $exact = array_values(array_filter(
                $pool,
                static fn($r): bool => \core_text::strtolower((string)$r['name']) === $needle
            ));
            if (count($exact) === 1) {
                return ['status' => 'resolved', 'row' => $exact[0], 'candidates' => []];
            }
            $contains = array_values(array_filter(
                $pool,
                static fn($r): bool => str_contains(\core_text::strtolower((string)$r['name']), $needle)
            ));
            if (count($contains) === 1) {
                return ['status' => 'resolved', 'row' => $contains[0], 'candidates' => []];
            }
        }

        return ['status' => 'unresolved', 'candidates' => $pool];
    }

    /**
     * Format an inventory (or candidate subset) as a compact, LLM-facing list — one line per activity.
     *
     * @param array[] $rows
     * @return string
     */
    public function format_inventory(array $rows): string {
        if (empty($rows)) {
            return '(no activities)';
        }
        $lines = [];
        foreach ($rows as $r) {
            // Label the id with the ACTUAL skill parameter name (activityid), so the LLM re-calls with
            // activityid=<n> instead of inventing a new activityquery.
            $lines[] = '- activityid=' . (int)$r['cmid'] . ' — "' . (string)$r['name'] . '"'
                . ' (type=' . (string)$r['modname']
                . ', section=' . (int)$r['sectionnum'] . ' "' . (string)$r['sectionname'] . '"'
                . ', position=' . (int)$r['position']
                . ', ' . (((bool)$r['uservisible']) ? 'visible' : 'hidden') . ')';
        }
        return implode("\n", $lines);
    }

    /**
     * Build the engine-static observation that hands the inventory to the LLM for resolution.
     *
     * Carries DATA + NEXT ACTION so the planner continues (re-call with a concrete activityid) instead
     * of giving up — and never asks the user to perform a lookup the agent already did.
     *
     * @param string $reference the user's activity reference (e.g. "the second quiz")
     * @param array[] $candidates
     * @param string $skillname the skill to re-call with a resolved activityid
     * @return string
     */
    public function build_resolution_observation(string $reference, array $candidates, string $skillname): string {
        $ref = trim($reference);
        $header = $ref !== ''
            ? 'The activity reference "' . $ref . '" did not match exactly one activity.'
            : 'No specific activity was named.';
        $exampleid = !empty($candidates) ? (int)($candidates[0]['cmid'] ?? 0) : 0;
        $example = $exampleid > 0 ? ' (for example activityid=' . $exampleid . ')' : '';
        // Unambiguous, self-correcting instruction — NOT a user clarification (which only confuses here).
        // Force the next call onto the exact id parameter so it converges instead of looping on activityquery.
        return $header . ' To fix this, re-call ' . $skillname . ' with the "activityid" parameter set to '
            . 'EXACTLY ONE of the activityid values listed below' . $example . '. Both activityid and '
            . 'activityquery are accepted, but to resolve this ambiguity you MUST use activityid — do NOT '
            . 'repeat the same activityquery, and do NOT conclude the activity does not exist. The diagnosis '
            . "will then report that single activity's status for the user.\nActivities in the course:\n"
            . $this->format_inventory($candidates);
    }
}

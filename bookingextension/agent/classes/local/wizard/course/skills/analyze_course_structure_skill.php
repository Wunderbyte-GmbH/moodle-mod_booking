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

namespace bookingextension_agent\local\wizard\course\skills;

use bookingextension_agent\local\wizard\course_targeted_skill;
use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\dto\target_selector;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\activities\course_structure_preview;
use bookingextension_agent\local\wizard\services\activities\course_structure_service;
use context_course;

/**
 * Readonly analysis skill: list a course's structure (sections + activities) exactly as the acting user
 * may see it, including hidden / availability-restricted / group / locked markers.
 *
 * The precondition for later "create a booking option in section X / behind heading Y" interactions: it
 * surfaces the stable targeting anchors (section id/number/name, activity cmid + position). It NEVER writes.
 *
 * Capability safety: the skill operates as the real $userid and lets Moodle's own engine
 * (get_fast_modinfo($course, $userid) + uservisible) decide visibility — it adds NO bypassing capability
 * logic. A user therefore only ever sees what they would normally see; items they may view but not enter are
 * reported as locked (with the restriction reason), items they cannot see at all are omitted.
 *
 * R0/readonly: the engine skips preflight for readonly skills, so course resolution AND the access gate live
 * in execute(). Cross-context resolution (any course by id/name) is engine-provided (Path A).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analyze_course_structure_skill extends core_skill_base implements skill_trigger_provider_interface {
    use course_targeted_skill;

    /** Skill name. */
    public const SKILL_NAME = 'course.analyze_course_structure';

    /**
     * Constructor. Read-only analysis (R0).
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
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
     * Operates on a course (the operating context is resolved to course level).
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
            // Wave 32 (ACS-2/ACS-4, L30/L33/L38/L40): four detours through course.search_courses before this
            // skill ran. The sibling skills that work on a named course (add_activity, add_quiz) say inside the
            // window that no lookup is needed; this one now says it too. (The old coursequery sentence about
            // search_courses stood after the 160-character field window and never reached a model - L43 check;
            // it is removed only so the contract does not contradict the card.)
            'description' => 'Analyse / list the STRUCTURE of a Moodle course: its sections and the activities & resources in '
                . 'them, with names, types, links and whether each is hidden, restricted or locked. A named course goes '
                . 'into coursequery, no lookup needed. Use for "what is in this course", "show me the sections of course '
                . 'X". It is the prerequisite for later placing something into a section.',
            'is' => 'Reading what a Moodle course contains, including what is hidden.',
            'not' => 'Creating or changing anything; the details of a booking option.',
            'readonly' => true,
            'example_utterances' => [
                'what is in this course',
                'show me the sections and activities of this course',
                'give me an overview of the course structure',
                'list the topics in the Biology course',
                'which sections or activities of this course are hidden or restricted',
                'which activities does this course contain',
            ],
            'properties' => [
                'coursequery' => [
                    'type' => 'string',
                    // Review: within the 159-character field window (skill_input_schema_projection).
                    'description' => 'Name or idnumber of a course the user names, as written; a named course is never '
                        . 'the current one. The system resolves it. Leave empty for the current course.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id when known. Takes precedence over coursequery. Leave empty '
                        . 'for the current course; never guess.',
                    'required' => false,
                ],
                'include_descriptions' => [
                    'type' => 'boolean',
                    'description' => 'Include section summaries and activity descriptions (default true). Set false '
                        . 'for a faster names-only overview of a large course.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['coursequery'],
                'anchor_fields' => ['coursequery'],
                'context_scopes' => ['course'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['coursequery' => 'Mathematics 101'];
    }

    /**
     * Discovery triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.analyze_course_structure_request',
                'description' => 'The user wants an overview of a course\'s sections and the activities in them, for instance'
                    . ' before placing something into a section.',
                'examples' => [
                    'What is in the course "Mathematics 101"?',
                    'Show me the sections and activities of this course.',
                    'Which sections does the current course have?',
                    'What activities are in the course Biology?',
                    'Give me the structure of this course.',
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
                'id' => 'course.analyze_course_structure',
                'triggers' => [
                    'structure', 'layout', 'sections', 'section', 'heading', 'what is in the course',
                    'which activities', 'course content',
                    'structure', 'sections', 'what is in the course', 'what activities', 'course content', 'overview of the course',
                ],
                'guidance' => [
                    '- course.analyze_course_structure lists a course\'s sections + activities (read-only) as the',
                    '  asking user may see them. Use it before "create/place something in section X".',
                    '- Name the course via coursequery/courseid only when it is NOT the current course; leave empty',
                    '  for the current course. A named course is never the current course.',
                    '- Answer strictly from the returned structure. Items marked LOCKED are visible to the user but',
                    '  they may not open them; items not listed are not visible to the user — never invent any.',
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
        return ['valid' => true, 'errors' => [], 'ambiguities' => []];
    }

    /**
     * Run the analysis (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid Operating context (resolved to the target course by the engine).
     * @param int   $userid    Acting user.
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        // R0/readonly skips preflight, so the skill self-resolves its course — eager and safe via the
        // shared readonly resolver: explicit id > unique name > ambient (when no course named or the
        // named course IS the current one). 0 = a named-but-different course that could not be resolved.
        $courseid = $this->resolve_readonly_course_context_id($input, $contextid);
        if ($courseid <= 0) {
            return $this->error_result(
                'I could not find a single course matching that name. Please check the course name.',
                'course_not_found'
            );
        }
        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            return $this->error_result('That course could not be found.', 'course_not_found');
        }
        $coursecontext = context_course::instance($courseid);

        // 2) Access gate: the acting user must be able to access this course at all. This is the real guard
        // for an explicit courseid (the resolver does not check access); visibility of the contents below is
        // then handled per-user by get_fast_modinfo inside the service.
        $accessuser = \core_user::get_user($userid, '*', IGNORE_MISSING);
        if (!$accessuser || !can_access_course($course, $accessuser)) {
            return $this->error_result(
                get_string('nopermissions', 'error', 'moodle/course:view'),
                'permission_denied'
            );
        }

        // 3) Build the visibility-filtered structure for this user.
        $includedescriptions = !array_key_exists('include_descriptions', $input)
            || !empty($input['include_descriptions']);
        $structure = (new course_structure_service())->analyze($course, $userid, $includedescriptions);

        $coursename = (string)$structure['coursename'];
        $sectioncount = count((array)$structure['sections']);
        $usermessage = 'Analysed the structure of "' . $coursename . '" (' . $sectioncount . ' visible section(s)).';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => $courseid,
            'structure' => $structure,
            'observation_full' => $this->build_observation($structure),
        ];
    }

    /**
     * Build the deterministic, LLM-facing structure text.
     *
     * @param array $structure
     * @return string
     */
    private function build_observation(array $structure): string {
        $lines = [];
        $lines[] = 'Course structure of "' . (string)$structure['coursename'] . '" (id=' . (int)$structure['courseid']
            . '), as visible to the acting user:';

        $sections = (array)$structure['sections'];
        if (empty($sections)) {
            $lines[] = '(No sections are visible to this user.)';
        }

        foreach ($sections as $section) {
            $flags = $this->node_flags($section, true);
            $lines[] = '[Section ' . (int)$section['number'] . '] ' . (string)$section['name']
                . ($flags !== '' ? ' ' . $flags : '');
            $summary = trim((string)($section['summary_text'] ?? ''));
            if ($summary !== '') {
                $lines[] = '    summary: ' . $summary;
            }
            $activities = (array)($section['activities'] ?? []);
            if (empty($activities) && !empty($section['accessible'])) {
                $lines[] = '    (no activities visible)';
            }
            foreach ($activities as $activity) {
                $aflags = $this->node_flags($activity, false);
                $line = '    - ' . (string)$activity['modname'] . ' "' . (string)$activity['name'] . '" (cmid '
                    . (int)$activity['cmid'] . ')';
                if ($aflags !== '') {
                    $line .= ' ' . $aflags;
                }
                $intro = trim((string)($activity['intro_text'] ?? ''));
                if ($intro !== '') {
                    $line .= ' — ' . $intro;
                }
                if (!empty($activity['url'])) {
                    $line .= ' (' . (string)$activity['url'] . ')';
                }
                $lines[] = $line;
            }
        }

        $lines[] = 'Note: items marked [LOCKED] are shown to this user with a restriction reason but they may NOT '
            . 'open them. [HIDDEN] = hidden from students but visible to this user. Items not listed are not visible '
            . 'to this user. State only the structure above; do not invent sections or activities.';

        return implode("\n", $lines);
    }

    /**
     * Build the bracketed flag suffix for a section/activity node.
     *
     * @param array $node
     * @param bool $issection
     * @return string
     */
    private function node_flags(array $node, bool $issection): string {
        $flags = [];
        if (!empty($node['hidden'])) {
            $flags[] = 'HIDDEN';
        }
        if (empty($node['accessible'])) {
            $reason = trim((string)($node['restrictinfo'] ?? ''));
            $flags[] = 'LOCKED' . ($reason !== '' ? ': ' . $reason : '');
        } else if (!empty($node['restricted'])) {
            $reason = trim((string)($node['restrictinfo'] ?? ''));
            $flags[] = 'RESTRICTED' . ($reason !== '' ? ': ' . $reason : '');
        }
        if (!$issection) {
            $groupmode = (string)($node['groupmode'] ?? 'none');
            if ($groupmode !== 'none') {
                $flags[] = 'GROUPS=' . $groupmode;
            }
        }
        return empty($flags) ? '' : '[' . implode('] [', $flags) . ']';
    }

    /**
     * Render the dedicated course-structure preview.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $structure = (array)($resultentry['structure'] ?? []);
        if (empty($structure['sections'])) {
            return null;
        }
        return (new course_structure_preview())->render($structure);
    }

    /**
     * Build an error result (error-messaging contract: carries an error_class for the synchronizer).
     *
     * @param string $message
     * @param string $errorclass
     * @return array
     */
    private function error_result(string $message, string $errorclass): array {
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'error_class' => $errorclass,
            'observation_full' => 'Course-structure analysis could not run: ' . $message,
        ];
    }
}

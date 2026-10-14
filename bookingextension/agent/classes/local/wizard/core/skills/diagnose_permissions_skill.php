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

use bookingextension_agent\local\wizard\diagnostics\diagnostic_result_builder;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_checklist_preview;
use bookingextension_agent\local\wizard\diagnostics\diagnostic_link_builder;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use context;
use context_course;

/**
 * Readonly diagnosis skill: roles and capabilities of a user across the context chain.
 *
 * Two bounded question types (v1, to avoid data floods):
 *  - "What roles does user X have (for course Y)?" → role assignments per context System→Cat→Course→Module.
 *  - "May user X do capability Z (at course Y)?" → has_capability() at the target context plus the
 *    ALLOW/PREVENT/PROHIBIT overrides that exist for the user's roles along that chain.
 *
 * Deliberately NOT v1: "who has capability Z" (get_users_by_capability — expensive) and full capability
 * matrices. R0/readonly → course/user resolution and the cross-user gate live in execute().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_permissions_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'core.diagnose_permissions';

    /** How many contexts outside the chain are named before the rest is counted. */
    private const ELSEWHERE_MAX = 8;

    /** Cap on suggested capability names when the given one is unknown. */
    private const MAX_SUGGESTIONS = 8;

    /** Cap on the whole candidate list for an unknown capability (rule "Auswahl statt Error": at most 50). */
    private const MAX_CANDIDATES = 50;

    /**
     * Constructor. Read-only diagnosis (R0).
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
     * Typically course-scoped; also works at system level (resolved in execute).
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
            'description' => 'Inspect a person\'s ROLES and CAPABILITIES (permissions) across the context chain (system → category '
                . '→ course → activity). Answers "what roles does X have here", and "may X do <capability> at course Y" including '
                . 'the ALLOW/PREVENT/PROHIBIT overrides on the chain. For a capability question, pass the technical capability '
                . 'name in "capability" (e.g. mod/booking:addoption).',
            'is' => 'One named person\'s roles and capabilities.',
            'not' => 'Who all holds a right; course access, enrolment or grades (course.diagnose_user_in_course); taskflow '
                . 'rights and tabs (local_taskflow.diagnose_permissions).',
            'readonly' => true,
            'example_utterances' => [
                'why can\'t this teacher edit the activity',
                'what roles does this user have in the course',
                'is she allowed to add booking options here',
                'why is this user missing the permission to grade',
                'which role is preventing him from doing this',
                'does this person have the capability to manage the course',
            ],
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    // Wave 32 (A3): the old text 'Name ... "me" or empty = the current user' contradicted F81
                    // (e08b63b): resolve_userid no longer knows the word "me", only an EMPTY field means the requester.
                    'description' => 'Name, e-mail or id of the person asked about, as the user wrote it. Leave it out '
                        . 'when the requester asks about themself; never a placeholder or a pronoun.',
                    'required' => false,
                ],
                'userid' => [
                    'type' => 'integer',
                    'description' => 'Numeric user id when known. Takes precedence over userquery.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Course whose context to inspect, when not the current one. Leave empty for the '
                        . 'current context.',
                    'required' => false,
                ],
                'courseid' => [
                    'type' => 'integer',
                    'description' => 'Numeric course id when known. Leave empty for the current context; never guess.',
                    'required' => false,
                ],
                'capability' => [
                    'type' => 'string',
                    'description' => 'OPTIONAL technical capability name to check, e.g. "mod/booking:addoption", '
                        . '"moodle/question:add".',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['userquery', 'coursequery', 'capability'],
                'anchor_fields' => ['userquery', 'coursequery', 'capability'],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['userquery' => 'Maria Jones', 'capability' => 'mod/booking:addoption'];
    }

    /**
     * Discovery triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.diagnose_permissions_request',
                'description' => 'The user asks which roles a person holds and where, or whether and why a person may or may not'
                    . ' do a specific thing in a course or context.',
                'examples' => [
                    'Which roles does Maria have in the course "Mathematics"?',
                    'Is Tom allowed to create booking options in this course?',
                    'Why can this teacher not grade — what permission is missing?',
                    'Which permissions does Billy have at course level?',
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
                'id' => 'core.diagnose_permissions',
                'triggers' => [
                    'role in course', 'permission', 'permissions', 'allowed',
                    'capability', 'what role', 'which roles', 'is allowed to', 'not allowed to',
                    'recht', 'rechte', 'override', 'prohibit',
                ],
                'guidance' => [
                    '- core.diagnose_permissions reports a person\'s roles along the context chain, or whether they hold',
                    '  a specific capability (with the ALLOW/PREVENT/PROHIBIT overrides). Read-only.',
                    '- For a capability question, put the TECHNICAL capability name into input.capability (translate the',
                    '  user\'s everyday wording yourself, e.g. "add questions" → moodle/question:add). If unknown, the',
                    '  skill returns the closest capability names to choose from.',
                    '- Do NOT use it for "who all can do Z", access/visibility, enrolment, or grades. Answer strictly',
                    '  from the returned findings.',
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
     * Run the permissions diagnosis (all guards here — R0 skips preflight).
     *
     * @param array $input
     * @param int   $contextid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        // 1) Resolve the target context: an explicit course wins, else the ambient context, else system.
        $courseid = (int)($input['courseid'] ?? 0);
        if ($courseid <= 0) {
            $courseid = $this->resolve_courseid($input);
            // A NAMED course that cannot be resolved must never silently become a
            // System-scope answer (#2337) - same honesty class as #2325.
            $coursequery = trim((string)($input['coursequery'] ?? ''));
            if ($courseid <= 0 && $coursequery !== '') {
                return $this->error_result(
                    'No unique course matched "' . s($coursequery) . '". '
                        . 'Give the exact course name or its id.',
                    'course_not_found'
                );
            }
        }
        if ($courseid > 0) {
            try {
                $targetcontext = context_course::instance($courseid);
            } catch (\Throwable $e) {
                return $this->error_result('That course could not be found.', 'course_not_found');
            }
        } else {
            $targetcontext = context::instance_by_id($contextid, IGNORE_MISSING) ?: \context_system::instance();
        }

        // 2) Resolve the target user (default: self).
        $targetuserid = (int)($input['userid'] ?? 0);
        if ($targetuserid <= 0) {
            $targetuserid = $this->resolve_userid($input, $userid);
        }
        if ($targetuserid <= 0) {
            return $this->error_result(
                'I could not identify the person. Give a full name, e-mail address or numeric user id.',
                'user_unresolved'
            );
        }
        $isself = ($targetuserid === $userid);

        // 3) Cross-user gate (R0 → here): reviewing another person's roles/permissions needs role:review.
        if (!$isself && !has_capability('moodle/role:review', $targetcontext, $userid)) {
            return $this->error_result(get_string('nopermissions', 'error', 'moodle/role:review'), 'permission_denied');
        }

        $targetuser = \core_user::get_user($targetuserid, '*', IGNORE_MISSING);
        if (!$targetuser || !empty($targetuser->deleted)) {
            return $this->error_result('That user no longer exists.', 'user_not_found');
        }

        $links = new diagnostic_link_builder();
        $capability = trim((string)($input['capability'] ?? ''));

        if ($capability !== '') {
            return $this->diagnose_capability($targetcontext, $targetuser, $isself, $capability, $links, $userid);
        }
        return $this->diagnose_roles($targetcontext, $targetuser, $isself, $links, $userid);
    }

    /**
     * Capability mode: does the user hold the capability, and what overrides exist on the chain?
     *
     * @param context $targetcontext
     * @param \stdClass $targetuser
     * @param bool $isself
     * @param string $capability
     * @param diagnostic_link_builder $links
     * @param int $actinguserid
     * @return array
     */
    private function diagnose_capability(
        context $targetcontext,
        \stdClass $targetuser,
        bool $isself,
        string $capability,
        diagnostic_link_builder $links,
        int $actinguserid
    ): array {
        global $DB;

        $allcaps = get_all_capabilities();
        if (!isset($allcaps[$capability])) {
            // The planner maps everyday wording to a technical name itself, so an unknown name is its
            // guess, not a finding. Reporting it as an executed check (2026-09-23: "moodle/activity:manage")
            // told the planner the check was done and put a developer card into the preview. The read-only
            // chat path has no preflight, so the correction travels like every other recoverable read-only
            // lookup: an error row flagged RECOVERABLE_INPUT_ERROR whose observation offers the real names,
            // after which the loop re-plans and the honest end of the turn stays 'sufficient'.
            $candidates = $this->capability_candidates($capability, array_keys($allcaps));
            if (empty($candidates)) {
                // Nothing resembles it, so there is nothing to retry with: finish with the role picture
                // (a completed result the planner answers from) and say why the check did not run.
                $result = $this->diagnose_roles($targetcontext, $targetuser, $isself, $links, $actinguserid);
                $result['observation_full'] = 'Capability check did NOT run: "' . $capability . '" is not a capability'
                    . ' on this site and no similar capability exists. Do not call this skill again for it;'
                    . ' answer from the role assignments below.' . "\n" . $result['observation_full'];
                return $result;
            }
            return $this->unknown_capability_result($capability, $candidates);
        }

        $rows = [];
        $can = has_capability($capability, $targetcontext, $targetuser->id);
        // Person-correct verb: "You HAVE / do NOT have", "<Name> HAS / does NOT have".
        $subject = $isself ? 'You' : fullname($targetuser);
        $verb = $can
            ? ($isself ? 'HAVE' : 'HAS')
            : ($isself ? 'do NOT have' : 'does NOT have');
        $rows[] = diagnostic_result_builder::row(
            $can ? 'ok' : 'fail',
            $subject . ' ' . $verb . ' ' . $capability,
            'Checked at ' . $targetcontext->get_context_name(),
            $links->if_capable(
                $links->check_permissions((int)$targetcontext->id),
                'moodle/role:review',
                $targetcontext,
                $actinguserid
            )
        );

        // Overrides for this capability along the chain, limited to the user's roles.
        $chainids = $targetcontext->get_parent_context_ids(true);
        $userroles = get_user_roles($targetcontext, (int)$targetuser->id, true);
        $roleids = array_values(array_unique(array_map(static fn($r): int => (int)$r->roleid, $userroles)));

        if (!empty($roleids) && !empty($chainids)) {
            [$insqlc, $paramsc] = $DB->get_in_or_equal($chainids, SQL_PARAMS_NAMED, 'ctx');
            [$insqlr, $paramsr] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
            $sql = "SELECT rc.id, rc.contextid, rc.roleid, rc.permission
                      FROM {role_capabilities} rc
                     WHERE rc.capability = :cap
                       AND rc.contextid $insqlc
                       AND rc.roleid $insqlr
                       AND rc.permission <> 0";
            $overrides = $DB->get_records_sql($sql, ['cap' => $capability] + $paramsc + $paramsr);
            foreach ($overrides as $ov) {
                $octx = context::instance_by_id((int)$ov->contextid, IGNORE_MISSING);
                $role = $DB->get_record('role', ['id' => (int)$ov->roleid], 'id, shortname', IGNORE_MISSING);
                $perm = $this->permission_label((int)$ov->permission);
                $rows[] = diagnostic_result_builder::row(
                    $ov->permission > 0 ? 'ok' : 'warn',
                    'Override: role "' . ($role ? $role->shortname : $ov->roleid) . '" → ' . $perm,
                    'at ' . ($octx ? $octx->get_context_name() : ('context ' . $ov->contextid))
                );
            }
        }

        return $this->build_result($targetcontext, $targetuser, $isself, $rows, 'Capability check', 'capability');
    }

    /**
     * Role mode: list the user's role assignments per context along the chain.
     *
     * @param context $targetcontext
     * @param \stdClass $targetuser
     * @param bool $isself
     * @param diagnostic_link_builder $links
     * @param int $actinguserid
     * @return array
     */
    private function diagnose_roles(
        context $targetcontext,
        \stdClass $targetuser,
        bool $isself,
        diagnostic_link_builder $links,
        int $actinguserid
    ): array {
        $rows = [];
        $chainids = array_reverse($targetcontext->get_parent_context_ids(true)); // System → … → target.
        $chainnames = [];
        $anyrole = false;
        foreach ($chainids as $ctxid) {
            $ctx = context::instance_by_id((int)$ctxid, IGNORE_MISSING);
            if (!$ctx) {
                continue;
            }
            $chainnames[] = $ctx->get_context_name();
            $roles = get_user_roles($ctx, (int)$targetuser->id, false); // Assigned at THIS context.
            if (empty($roles)) {
                continue;
            }
            $anyrole = true;
            $names = array_values(array_unique(array_map(static fn($r): string => (string)$r->shortname, $roles)));
            $rows[] = diagnostic_result_builder::row(
                'ok',
                $ctx->get_context_name(),
                'Roles: ' . implode(', ', $names),
                $links->if_capable($links->check_permissions((int)$ctx->id), 'moodle/role:review', $ctx, $actinguserid)
            );
        }
        if (!$anyrole) {
            // The chain is named so that "nothing here" is never read as "nothing anywhere".
            $rows[] = diagnostic_result_builder::row(
                'warn',
                'No role assignments along this chain',
                'Checked: ' . implode(', ', $chainnames) . '. Other courses and activities are not part of this chain.',
                $links->if_capable(
                    $links->assign_roles((int)$targetcontext->id),
                    'moodle/role:assign',
                    $targetcontext,
                    $actinguserid
                )
            );
        }
        foreach ($this->roles_elsewhere($chainids, $targetuser, $isself, $actinguserid) as $row) {
            $rows[] = $row;
        }
        return $this->build_result($targetcontext, $targetuser, $isself, $rows, 'Role assignments', 'roles');
    }

    /**
     * The person's role assignments outside the context chain, one row per context.
     *
     * A context is named only where the requester may review roles (or asks about themselves);
     * the others are counted. At most self::ELSEWHERE_MAX contexts are named.
     *
     * @param int[] $chainids
     * @param \stdClass $targetuser
     * @param bool $isself
     * @param int $actinguserid
     * @return array[]
     */
    private function roles_elsewhere(array $chainids, \stdClass $targetuser, bool $isself, int $actinguserid): array {
        global $DB;

        [$notinsql, $params] = $DB->get_in_or_equal(array_map('intval', $chainids), SQL_PARAMS_NAMED, 'chain', false);
        $params['userid'] = (int)$targetuser->id;
        $assignments = $DB->get_records_sql(
            "SELECT ra.id, ra.contextid, r.shortname
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
              WHERE ra.userid = :userid AND ra.contextid $notinsql
           ORDER BY ra.contextid, r.shortname",
            $params
        );
        if (empty($assignments)) {
            return [];
        }

        $bycontext = [];
        foreach ($assignments as $ra) {
            $bycontext[(int)$ra->contextid][] = (string)$ra->shortname;
        }
        $named = [];
        $hidden = 0;
        foreach ($bycontext as $ctxid => $names) {
            $ctx = context::instance_by_id($ctxid, IGNORE_MISSING);
            if (!$ctx) {
                continue;
            }
            if (!$isself && !has_capability('moodle/role:review', $ctx, $actinguserid)) {
                $hidden += count($names);
                continue;
            }
            // A user context would carry a third person's name; the anonymizer does not know it.
            $name = (int)$ctx->contextlevel === CONTEXT_USER ? 'a user profile' : $ctx->get_context_name();
            $named[] = ['level' => (int)$ctx->contextlevel, 'name' => $name, 'roles' => array_unique($names)];
        }
        usort($named, static fn(array $a, array $b): int => [$a['level'], $a['name']] <=> [$b['level'], $b['name']]);

        $rows = [];
        foreach (array_slice($named, 0, self::ELSEWHERE_MAX) as $entry) {
            $rows[] = diagnostic_result_builder::row(
                'ok',
                'Elsewhere on this site: ' . $entry['name'],
                'Roles: ' . implode(', ', $entry['roles'])
            );
        }
        $more = count($named) - count($rows);
        if ($more > 0 || $hidden > 0) {
            $parts = [];
            if ($more > 0) {
                $parts[] = $more . ' more context' . ($more === 1 ? '' : 's') . ' with role assignments not listed';
            }
            if ($hidden > 0) {
                $parts[] = $hidden . ' more role assignment' . ($hidden === 1 ? '' : 's') . ' in '
                    . ($hidden === 1 ? 'a context' : 'contexts') . ' you may not review';
            }
            $rows[] = diagnostic_result_builder::row('ok', 'Elsewhere on this site', ucfirst(implode('; ', $parts)) . '.');
        }
        return $rows;
    }

    /**
     * The recoverable result for a capability name that does not exist: not a finding, no checklist
     * (so nothing reaches the preview), the real look-alikes on the observation for one corrected call.
     *
     * @param string $capability
     * @param string[] $candidates
     * @return array
     */
    private function unknown_capability_result(string $capability, array $candidates): array {
        $message = 'Capability "' . $capability . '" is not defined on this site.';
        $observation = 'Capability check did NOT run: "' . $capability . '" is not a capability on this site.'
            . ' Existing capabilities that resemble it or belong to the same component: ' . implode(', ', $candidates) . '.'
            . ' Re-run this skill ONCE with input.capability set to exactly one of these names'
            . ' (same person, same course). If none of them means what the user asked, do not call it'
            . ' again — tell the user which permission could not be identified.';
        return [
            'status' => 'error',
            'detail' => $message,
            'usermessage' => $message,
            'resultid' => null,
            'error_class' => 'unknown_capability',
            'issue_codes' => ['RECOVERABLE_INPUT_ERROR'],
            'capability_candidates' => array_values($candidates),
            'observation_full' => $observation,
        ];
    }

    /**
     * The capabilities offered for an unknown name, capped at MAX_CANDIDATES: every look-alike first (at most
     * MAX_SUGGESTIONS, those inside the same component ahead of the others), then - only when at least one
     * look-alike lies inside the component the planner chose - the rest of that component (alphabetical) in the
     * remaining slots.
     *
     * Wave 32 (A3, DP-3 in 10 of 10 runs L30-L41): the planner turns "manage the course" into moodle/course:manage,
     * which does not exist. The look-alike ranking only knows identifier tokens, so it offered managefiles,
     * manageactivities, managegroups, ... but never moodle/course:update - the capability that actually means it -
     * and in 7 of 10 runs the planner then asked the user instead of re-running the check (threads 9213 ... 12680).
     * The component the planner chose (the identifier part before the colon) is structural context: when the
     * ranking itself finds look-alikes inside it, its family holds the right name whatever word the planner used
     * for the action, so the family is offered as well. moodle/course:update then sits at slot 43 of 50 (35th
     * family name after the four in-family look-alikes).
     * L43 re-check (DP-2 thread 13239): the planner guessed mod/booking:grades; no mod/booking capability resembles
     * it, the right names live in OTHER components (mod/assign:grade, moodle/grade:*), and mod/booking has 98
     * capabilities. There the chosen component is not grounded, so its family is not offered, and the look-alikes
     * always keep their slots ahead of any family. Identifier structure only, no language.
     *
     * @param string $query
     * @param string[] $allnames
     * @return string[]
     */
    private function capability_candidates(string $query, array $allnames): array {
        $lookalikes = $this->suggest_capabilities($query, $allnames);

        $needle = \core_text::strtolower(trim($query));
        $colon = strrpos($needle, ':');
        $prefix = $colon === false ? '' : substr($needle, 0, $colon + 1);
        if ($prefix === '' || $prefix === ':') {
            return $lookalikes;
        }
        $infamily = static fn(string $name): bool => strpos(\core_text::strtolower($name), $prefix) === 0;
        $familylookalikes = array_values(array_filter($lookalikes, $infamily));
        if (empty($familylookalikes)) {
            return $lookalikes;
        }
        $family = array_values(array_filter($allnames, $infamily));
        sort($family);

        $ordered = array_merge(
            $familylookalikes,
            array_values(array_filter($lookalikes, static fn(string $name): bool => !$infamily($name))),
            $family
        );
        return array_slice(array_values(array_unique($ordered)), 0, self::MAX_CANDIDATES);
    }

    /**
     * Rank the site's capability identifiers by resemblance to an unknown one.
     *
     * Identifier similarity only (no language). A candidate qualifies when the part after its colon
     * resembles the query's — by token containment, tolerant of an inflected tail ("activity" ~
     * "manageactivities"), or by a short edit distance for a typo ("managactivities"). The tokens of the
     * query's component name (after the "type/" prefix, i.e. "activity" in "moodle/activity:manage")
     * count as much as the name tokens, because they are what the planner meant. The old plain substring
     * count had no such anchor and never listed moodle/course:manageactivities for moodle/activity:manage
     * ("activity" is not a substring of "manageactivities"), so eight unrelated "manage" capabilities
     * outranked it.
     *
     * @param string $query
     * @param string[] $allnames
     * @return string[]
     */
    private function suggest_capabilities(string $query, array $allnames): array {
        $needle = \core_text::strtolower(trim($query));
        $colon = strrpos($needle, ':');
        $querycomponent = $colon === false ? '' : substr($needle, 0, $colon);
        $queryname = $colon === false ? $needle : substr($needle, $colon + 1);
        $slash = strrpos($querycomponent, '/');
        $querycomponentname = $slash === false ? $querycomponent : substr($querycomponent, $slash + 1);
        $nametokens = self::identifier_tokens($queryname);
        $componenttokens = self::identifier_tokens($querycomponentname);
        if (empty($nametokens)) {
            return [];
        }
        $typothreshold = max(2, intdiv(strlen($queryname), 4));

        $scored = [];
        foreach ($allnames as $name) {
            $lname = \core_text::strtolower($name);
            $lcolon = strrpos($lname, ':');
            $candidatename = $lcolon === false ? $lname : substr($lname, $lcolon + 1);

            $score = 0;
            foreach ($nametokens as $token) {
                if (self::identifier_contains($candidatename, $token)) {
                    $score += 2;
                }
            }
            $distance = levenshtein($queryname, $candidatename);
            if ($distance > 0 && $distance <= $typothreshold) {
                $score += 3;
            }
            if ($score === 0) {
                continue;
            }
            foreach ($componenttokens as $token) {
                if (self::identifier_contains($lname, $token)) {
                    $score += 2;
                }
            }
            $scored[$name] = $score;
        }
        arsort($scored);
        return array_slice(array_keys($scored), 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Split an identifier fragment into its lowercase alphanumeric tokens.
     *
     * @param string $fragment
     * @return string[]
     */
    private static function identifier_tokens(string $fragment): array {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $fragment) ?: [],
            static fn(string $token): bool => $token !== ''
        ));
    }

    /**
     * Does an identifier contain a token — or, for longer tokens, the token without its last two
     * characters, so an inflected tail does not hide the match?
     *
     * @param string $identifier
     * @param string $token
     * @return bool
     */
    private static function identifier_contains(string $identifier, string $token): bool {
        if (strpos($identifier, $token) !== false) {
            return true;
        }
        return strlen($token) >= 6 && strpos($identifier, substr($token, 0, -2)) !== false;
    }

    /**
     * Map a role_capabilities permission value to a label.
     *
     * @param int $permission
     * @return string
     */
    private function permission_label(int $permission): string {
        if ($permission == CAP_PROHIBIT) {
            return 'PROHIBIT';
        }
        if ($permission == CAP_PREVENT) {
            return 'PREVENT';
        }
        if ($permission == CAP_ALLOW) {
            return 'ALLOW';
        }
        return 'INHERIT';
    }

    /**
     * Assemble the result + observation.
     *
     * @param context $targetcontext
     * @param \stdClass $targetuser
     * @param bool $isself
     * @param array[] $rows
     * @param string $titleprefix
     * @param string $mode
     * @return array
     */
    private function build_result(
        context $targetcontext,
        \stdClass $targetuser,
        bool $isself,
        array $rows,
        string $titleprefix,
        string $mode
    ): array {
        $subject = $isself ? 'you' : fullname($targetuser);
        $ctxname = $targetcontext->get_context_name();

        $lines = [$titleprefix . ' for ' . $subject . ' at ' . $ctxname . ':'];
        foreach ($rows as $r) {
            $glyph = diagnostic_result_builder::glyph((string)$r['status']);
            $line = $glyph . ' ' . $r['check'];
            if (trim((string)$r['finding']) !== '') {
                $line .= ' — ' . $r['finding'];
            }
            if (!empty($r['url'])) {
                $line .= ' (' . $r['url'] . ')';
            }
            $lines[] = $line;
        }
        $lines[] = 'Note: automated roles/permissions check. State only the findings above.';

        $usermessage = $titleprefix . ' for ' . $subject . ' at ' . $ctxname . ' completed.';

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$targetuser->id,
            'diagnosis' => [
                'contextid' => (int)$targetcontext->id,
                'targetuserid' => (int)$targetuser->id,
                'mode' => $mode,
                'checklist' => $rows,
            ],
            'checklist_rows' => $rows,
            'checklist_title' => $titleprefix . ': ' . $subject . ' · ' . $ctxname,
            'observation_full' => implode("\n", $lines),
        ];
    }

    /**
     * Render the checklist preview.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string,payload:array}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $rows = (array)($resultentry['checklist_rows'] ?? []);
        if (empty($rows)) {
            return null;
        }
        return (new diagnostic_checklist_preview())->render(
            $rows,
            (string)($resultentry['checklist_title'] ?? ''),
            ['contextid' => (int)($resultentry['diagnosis']['contextid'] ?? 0)]
        );
    }


    /**
     * Build an error result.
     *
     * @param string $message
     * @param string $errorclass
     * @return array
     */
    private function error_result(string $message, string $errorclass): array {
        return diagnostic_result_builder::error_result($message, $errorclass, 'Permissions diagnosis could not run: ');
    }
}

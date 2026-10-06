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

/**
 * Skill definition for core.search_users.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users_skill extends core_skill_base implements
    skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'core.search_users';

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
     * Input fields whose value is resolved to persons (#2363, F23): the free-text query IS the
     * person lookup, so a low-confidence anonymizer token there must pass the collision gate.
     *
     * @return string[]
     */
    public function get_person_reference_fields(): array {
        return ['query'];
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Search users and return resolved candidates with profile data, '
                . 'enrolled courses, roles, and profile URL.',
            'is' => 'Other people: find a user and see their profile, roles and enrolled courses.',
            'not' => 'The requester themselves (get_current_user).',
            'readonly' => $this->is_read_only(),
            'fallback_skillcall_string_key' => 'ai_status_skillcall_booking_search_users',
            'example_utterances' => [
                'find the user named John Smith',
                'look up a user by their email address',
                'search for a person in the system',
                'which user has the id 42',
                'find another teacher account',
                'which courses is this user enrolled in',
                'list the courses that person is enrolled in',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text for first name, last name, email or user id.',
                    'required' => true,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of users to return (default 10).',
                    'required' => false,
                ],
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
            'query' => 'john.smith',
            'limit' => 5,
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'core.search_users_request',
                'description' => 'User asks to find users by name, email or id.',
                'examples' => [
                    'Find users called John',
                    'Search users by email',
                    'Find user with id 42',
                ],
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'core.search_users',
                'triggers' => [
                    'find user', 'search user',
                    'find users', 'search users', 'user lookup',
                ],
                'guidance' => [
                    '- Use core.search_users as a FIRST STEP whenever you need to resolve a person by name,',
                    '  email fragment, or partial id before calling a mutating skill (e.g. booking.book_users).',
                    '- This skill already returns the matched user\'s enrolled courses and assigned roles,',
                    '  so use it before asking for course participation or permission context about a user.',
                    '- Execute this skill and wait for the observation before proceeding to the next step.',
                    '- Return a short preview list of matching users including user id, full name, profile URL,',
                    '  enrolled courses, and roles when available.',
                    '- If more than one user matches, ask the user to clarify which one they mean.',
                ],
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $input = self::normalize_query_input($input);
        $errors = [];
        $lang = $this->get_output_language($input);
        if (empty($input['query']) || !is_string($input['query'])) {
            $errors[] = $this->localized_string('agent_booking_search_users_required_query', null, $lang);
            $errors[] = $this->build_query_retry_hint();
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute skill.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $input = self::normalize_query_input($input);
        $query = trim((string)($input['query'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

        if ($query === '') {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_search_users_required_query', null, $outputlang)
                    . ' ' . $this->build_query_retry_hint(),
                'resultid' => null,
            ];
        }

        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $input);

        // Per-target visibility gate (audit 12-F01 / CAP-01): core.search_users resolves arbitrary users
        // site-wide, so without a gate a teacher could enumerate any user's contact PII. user_can_view_profile()
        // is NOT sufficient — it returns true for everyone when $CFG->forceloginforprofiles is off, and only
        // authorises profile-page visibility, not identity fields. So: (1) drop any candidate the actor has no
        // legitimate relationship with — keep only self, users sharing a course, or (for holders of a site-level
        // user:viewdetails/viewalldetails or admins) anyone; (2) strip identity/contact fields unless the actor
        // holds moodle/site:viewuseridentity at the system context OR in a shared course (so an in-course teacher
        // still sees their own student's email). The skill runs in the actor's session ($USER == actor).
        $systemctx = \context_system::instance();
        $canviewallusers = is_siteadmin()
            || has_capability('moodle/user:viewdetails', $systemctx)
            || has_capability('moodle/user:viewalldetails', $systemctx);
        $canviewidentitysystem = has_capability('moodle/site:viewuseridentity', $systemctx);

        $candidates = $this->search_user_candidates_for_preview($query, $limit);
        if (empty($candidates)) {
            // Wave 37 (DUC-3, L50 thread 18996, an asset): "Madame <name>" as one string found nobody, although the name
            // alone is unique - the same narrowing the person resolver got in wave 26 ("Mr Okafor"): the tokens that
            // match someone must agree, a token that matches nobody (a salutation, a title) carries no meaning.
            $candidates = \bookingextension_agent\local\wizard\services\target_query_normalizer::narrow_by_tokens(
                $query,
                fn(string $token, int $tokenlimit): array => $this->search_user_candidates_for_preview($token, $tokenlimit),
                $limit
            );
        }
        $payloadusers = [];
        $hiddencount = 0;
        foreach ($candidates as $candidate) {
            $candidateid = (int)($candidate['userid'] ?? 0);
            if ($candidateid <= 0) {
                continue;
            }

            $user = \core_user::get_user($candidateid, '*', MUST_EXIST);
            [$related, $canviewidentity] = self::resolve_actor_visibility(
                $user,
                $userid,
                $canviewallusers,
                $canviewidentitysystem
            );
            if (!$related) {
                $hiddencount++;
                continue;
            }

            $payload = $this->build_user_payload($user);
            if (!$canviewidentity) {
                $payload = self::strip_user_identity_fields($payload);
            }
            $payloadusers[] = $payload;
        }

        if (empty($payloadusers)) {
            // F59: matches removed by the visibility gate must not read as non-existence.
            // The count may travel, identities of hidden users never do.
            $hiddenonly = $hiddencount > 0;
            $usermessage = $this->localized_string(
                $hiddenonly ? 'agent_booking_search_users_hidden_matches' : 'agent_booking_search_users_no_results',
                null,
                $outputlang
            );
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'resultid' => null,
                'users' => [],
                'observation_full' => $hiddenonly
                    ? 'Found 0 visible user(s). ' . $hiddencount
                        . ' matching account(s) exist but are not visible to the acting user.'
                    : 'Found 0 user(s).',
                'debugmessage' => $debugbase . "\nResults: 0"
                    . ($hiddencount > 0 ? "\nHidden (not visible to actor): " . $hiddencount : ''),
            ];
        }

        $count = count($payloadusers);
        $usermessage = $this->localized_string(
            'agent_booking_search_users_found',
            $count,
            $outputlang
        );
        $previewids = array_values(array_map(static fn(array $u): int => (int)($u['userid'] ?? 0), $payloadusers));
        $debugextra = [
            'Results: ' . $count,
            'Top user: ' . ((string)($payloadusers[0]['fullname'] ?? '')
                ?: (string)($payloadusers[0]['username'] ?? '')) . ' ',
            'Preview user ids: ' . implode(', ', $previewids),
        ];
        if ($hiddencount > 0) {
            $debugextra[] = 'Hidden (not visible to actor): ' . $hiddencount;
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)($payloadusers[0]['userid'] ?? 0),
            'users' => $payloadusers,
            'user' => $payloadusers[0] ?? [],
            'observation_full' => $this->build_user_observation_full($payloadusers)
                . ($hiddencount > 0
                    ? "\n" . $hiddencount . ' additional matching account(s) are not visible to the acting user.'
                    : ''),
            'debugmessage' => $debugbase . "\n" . implode("\n", $debugextra),
        ];
    }

    /**
     * Identity/contact fields removed from a user payload when the actor may not view user identity.
     */
    private const IDENTITY_FIELDS = [
        'username', 'email', 'idnumber', 'institution', 'department',
        'city', 'country', 'address', 'phone1', 'phone2',
        'description', 'descriptionformat', 'customprofilefields',
    ];

    /**
     * Decide whether a candidate is visible to the actor at all, and whether their identity (contact)
     * fields may be shown. Closes audit CAP-01 (site-wide PII enumeration).
     *
     * Relationship (visible at all): the actor themselves, a user sharing a course with the actor, or —
     * for holders of a site-level user:viewdetails/viewalldetails or admins — anyone. Otherwise the
     * candidate is dropped (the "fall back to users in my own courses" scoping).
     *
     * Identity fields: only when the actor holds moodle/site:viewuseridentity at the system context, or
     * in one of the shared courses (so an in-course teacher still sees their student's email).
     *
     * @param \stdClass $user
     * @param int $actorid
     * @param bool $canviewallusers
     * @param bool $canviewidentitysystem
     * @return array{0:bool,1:bool} [related, canviewidentity]
     */
    private static function resolve_actor_visibility(
        \stdClass $user,
        int $actorid,
        bool $canviewallusers,
        bool $canviewidentitysystem
    ): array {
        global $CFG;

        if ((int)$user->id === $actorid) {
            return [true, true];
        }

        require_once($CFG->libdir . '/enrollib.php');
        $sharedcourses = enrol_get_shared_courses($actorid, (int)$user->id, false, false);
        if (!$canviewallusers && empty($sharedcourses)) {
            return [false, false];
        }

        $canviewidentity = $canviewidentitysystem;
        if (!$canviewidentity && !empty($sharedcourses)) {
            foreach ($sharedcourses as $course) {
                $courseid = (int)($course->id ?? 0);
                if (
                    $courseid > 0
                        && has_capability('moodle/site:viewuseridentity', \context_course::instance($courseid))
                ) {
                    $canviewidentity = true;
                    break;
                }
            }
        }

        return [true, $canviewidentity];
    }

    /**
     * Remove identity/contact PII fields from a user payload.
     *
     * @param array $payload
     * @return array
     */
    private static function strip_user_identity_fields(array $payload): array {
        foreach (self::IDENTITY_FIELDS as $field) {
            unset($payload[$field]);
        }
        return $payload;
    }

    /**
     * Normalize common aliases to canonical query for user search.
     *
     * @param array $input
     * @return array
     */
    private static function normalize_query_input(array $input): array {
        if (!empty($input['query']) && is_scalar($input['query']) && trim((string)$input['query']) !== '') {
            $input['query'] = trim((string)$input['query']);
            return $input;
        }

        $aliases = [
            'userquery',
            'user',
            'username',
            'email',
            'mail',
            'fullname',
            'name',
            'searchterm',
        ];
        foreach ($aliases as $alias) {
            if (!array_key_exists($alias, $input) || !is_scalar($input[$alias])) {
                continue;
            }

            $value = trim((string)$input[$alias]);
            if ($value === '') {
                continue;
            }

            $input['query'] = $value;
            return $input;
        }

        foreach ($input as $key => $value) {
            if (!is_string($key) || in_array($key, ['outputlang', 'limit', 'contextid', 'cmid'], true)) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }

            $text = trim((string)$value);
            if ($text !== '') {
                $input['query'] = $text;
                return $input;
            }
        }

        return $input;
    }

    /**
     * Build a compact retry hint for missing user query input.
     *
     * @return string
     */
    private function build_query_retry_hint(): string {
        return 'Retry core.search_users once with input.query (or alias: userquery, user, username, email, name). '
            . 'Resend exactly one corrected skill_call for the same skill.';
    }

    /**
     * Return the preview descriptor for this skill.
     *
     * @return array
     */
    /**
     * Provide the user-search preview as ready-to-insert server-rendered HTML data.
     *
     * @param array $resultentry One executed skill result entry.
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $users = is_array($resultentry['users'] ?? null) ? (array)$resultentry['users'] : [];
        if (empty($users)) {
            return null;
        }

        $rows = \html_writer::tag(
            'tr',
            \html_writer::tag('th', s('Name')) . \html_writer::tag('th', s('Email')) . \html_writer::tag('th', s('ID'))
        );
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $name = (string)($user['fullname'] ?? $user['username'] ?? '');
            // Entity mentions are always linked (moodle_url-built profileurl from the
            // result payload) — never leave a user as plain text in agent output.
            $profileurl = trim((string)($user['profileurl'] ?? ''));
            $namecell = $profileurl !== ''
                ? \html_writer::link($profileurl, s($name))
                : s($name);
            $rows .= \html_writer::tag(
                'tr',
                \html_writer::tag('td', $namecell)
                . \html_writer::tag('td', s((string)($user['email'] ?? '')))
                . \html_writer::tag('td', s((string)($user['id'] ?? '')))
            );
        }

        $html = \html_writer::tag('table', $rows, ['class' => 'table table-sm booking-ai-preview-user-search']);

        return ['type' => 'user_search', 'html' => $html];
    }
}

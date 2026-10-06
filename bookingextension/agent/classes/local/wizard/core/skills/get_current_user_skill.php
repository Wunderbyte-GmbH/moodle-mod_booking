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
 * Skill definition for core.get_current_user.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_current_user_skill extends core_skill_base implements
    skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'core.get_current_user';

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
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'The requester\'s own account: identity, e-mail, roles and the courses they are enrolled in. '
                . 'Use this when the user asks about themselves - who they are, what their role is, or which courses they '
                . 'are enrolled in.',
            'is' => 'The requester themselves: own profile, roles and enrolled courses.',
            'not' => 'Another person (search_users).',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'who am I',
                'what is my email address',
                'show my own profile',
                'what is my role here',
                'what account am I logged in as',
                'which courses am I enrolled in',
                'where am I enrolled',
                'list my courses',
            ],
            'properties' => [
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for skill-authored wrapper strings, e.g. de or en.',
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
            'outputlang' => 'en',
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        return [
            'valid' => true,
            'errors' => [],
            'ambiguities' => [],
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
                'id' => 'core.get_current_user_request',
                'description' => 'User asks about their own account, their roles, or which courses they are enrolled in.',
                'examples' => [
                    'Who am I?',
                    'Show my profile',
                    'Which courses am I enrolled in?',
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
                'id' => 'core.get_current_user',
                'triggers' => [
                    'who am i', 'show my profile', 'my account',
                ],
                'guidance' => [
                    '- Use core.get_current_user as a FIRST STEP when the request refers to "me",',
                    '  "myself", "me", or "my booking" and you do not yet know the current userid.',
                    '- Execute this skill and wait for the observation; then pass the resolved userid',
                    '  to any follow-up skill that needs it (e.g. booking.book_users for self-booking).',
                    '- Only call this once per conversation turn unless the user explicitly asks again.',
                ],
            ],
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
        global $USER;

        $user = $USER;
        $userdata = $this->build_user_payload($user);
        $fullname = (string)($userdata['fullname'] ?? fullname($user));
        $email = (string)($userdata['email'] ?? $user->email);

        $outputlang = $this->get_output_language($input);
        $usermessage = $this->localized_string(
            'agent_booking_get_current_user_fallback',
            (object)['fullname' => $fullname, 'email' => $email],
            $outputlang
        );

        return [
            'status' => 'executed',
            'detail' => $this->localized_string('agent_booking_get_current_user_identified', null, $outputlang),
            'resultid' => (int)$user->id,
            'userid' => (int)$user->id,
            'email' => $email,
            'fullname' => $fullname,
            'user' => $userdata,
            // The single complete payload lives in 'user'; 'users' deliberately carries only a
            // compact identity entry. Both used to hold the FULL payload (enrolledcourses, roles,
            // custom fields), duplicating an already large result — the consumers of 'users'
            // (result summarizer, preview fallback) only read identity fields.
            'users' => [$this->compact_user_identity($userdata)],
            'observation_full' => $this->build_user_observation_full([$userdata]),
            'usermessage' => $usermessage,
            'debugmessage' => $this->build_skill_debug_message(
                self::SKILL_NAME,
                $input,
                ['Resolved user: ' . $fullname . ' (id=' . $user->id . ')']
            ),
        ];
    }

    /**
     * Reduce a full user payload to the compact identity fields the 'users' consumers read.
     *
     * @param array $userdata Full payload from build_user_payload().
     * @return array
     */
    private function compact_user_identity(array $userdata): array {
        $identity = [];
        foreach (['userid', 'fullname', 'firstname', 'lastname', 'email', 'profileurl'] as $key) {
            if (array_key_exists($key, $userdata)) {
                $identity[$key] = $userdata[$key];
            }
        }
        return $identity;
    }

    /**
     * Provide the user-profile preview as ready-to-insert server-rendered HTML data.
     *
     * @param array $resultentry One executed skill result entry.
     * @param int $contextid
     * @param int $userid
     * @return array{type:string,html:string}|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $user = is_array($resultentry['user'] ?? null) ? (array)$resultentry['user'] : [];
        if (empty($user) && !empty($resultentry['users']) && is_array($resultentry['users'])) {
            $user = (array)($resultentry['users'][0] ?? []);
        }
        if (empty($user)) {
            return null;
        }

        $rows = '';
        foreach (['fullname' => 'Name', 'email' => 'Email', 'id' => 'ID'] as $key => $label) {
            if (!isset($user[$key]) || (string)$user[$key] === '') {
                continue;
            }
            $rows .= \html_writer::tag(
                'tr',
                \html_writer::tag('th', s($label)) . \html_writer::tag('td', s((string)$user[$key]))
            );
        }
        if ($rows === '') {
            return null;
        }

        $html = \html_writer::tag('table', $rows, ['class' => 'table table-sm booking-ai-preview-user-profile']);

        return ['type' => 'user_profile', 'html' => $html];
    }
}

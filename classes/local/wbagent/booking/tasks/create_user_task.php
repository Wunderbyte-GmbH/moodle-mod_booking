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

namespace mod_booking\local\wbagent\booking\tasks;

use context_system;
use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for booking.create_user.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_user_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.create_user';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(false);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [[
            'id' => 'booking.create_user',
            'triggers' => ['create user', 'benutzer erstellen', 'nutzer erstellen', 'user not found'],
            'guidance' => [
                '- Use booking.create_user only when user explicitly allows creating a missing user.',
                '- Prefer preserving the requested full name from userquery.',
                '- Keep create_user and create_option in one confirmation_request when both are needed.',
            ],
        ]];
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Create a Moodle user when an explicitly requested teacher user does not exist.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'userquery' => [
                    'type' => 'string',
                    'description' => 'Requested user display name or query, e.g. "Max Mustermann".',
                    'required' => true,
                ],
                'firstname' => [
                    'type' => 'string',
                    'description' => 'Optional explicit first name override.',
                    'required' => false,
                ],
                'lastname' => [
                    'type' => 'string',
                    'description' => 'Optional explicit last name override.',
                    'required' => false,
                ],
                'username' => [
                    'type' => 'string',
                    'description' => 'Optional explicit username override.',
                    'required' => false,
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'Optional explicit e-mail override.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [[
            'id' => 'booking.create_user_allowed_if_missing',
            'description' => 'User explicitly allows creating a missing teacher user.',
        ]];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        $ambiguities = [];

        $userquery = trim((string)($input['userquery'] ?? ''));
        if ($userquery === '') {
            $errors[] = get_string('agent_booking_create_user_query_required', 'mod_booking');
        }

        if (isset($input['email']) && trim((string)$input['email']) !== '') {
            $email = trim((string)$input['email']);
            if (!validate_email($email)) {
                $errors[] = get_string('agent_booking_create_user_email_invalid', 'mod_booking');
            }
        }

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        global $CFG, $DB;

        if (!has_capability('moodle/user:create', context_system::instance())) {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_create_user_capability_required', 'mod_booking'),
                'resultid' => null,
            ];
        }

        $userquery = trim((string)($input['userquery'] ?? ''));
        if ($userquery === '') {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_create_user_missing_userquery', 'mod_booking'),
                'resultid' => null,
            ];
        }

        $resolved = booking_task_support::resolve_single_user($userquery);
        if (($resolved['status'] ?? '') === 'ok') {
            return [
                'status' => 'executed',
                'detail' => get_string('agent_booking_user_exists', 'mod_booking'),
                'resultid' => (int)($resolved['userid'] ?? 0),
                'userid' => (int)($resolved['userid'] ?? 0),
                'email' => (string)($resolved['email'] ?? ''),
                'created' => false,
            ];
        }

        if (($resolved['status'] ?? '') === 'ambiguity') {
            return [
                'status' => 'error',
                'detail' => (string)($resolved['message'] ?? get_string('agent_booking_create_user_ambiguous', 'mod_booking')),
                'resultid' => null,
            ];
        }

        [$firstname, $lastname] = $this->split_name_from_query($userquery);
        if (trim((string)($input['firstname'] ?? '')) !== '') {
            $firstname = trim((string)$input['firstname']);
        }
        if (trim((string)($input['lastname'] ?? '')) !== '') {
            $lastname = trim((string)$input['lastname']);
        }

        $username = trim((string)($input['username'] ?? ''));
        if ($username === '') {
            $username = $this->generate_username($firstname, $lastname);
        }
        $username = $this->ensure_unique_username($username);

        $email = trim((string)($input['email'] ?? ''));
        if ($email === '') {
            $email = $this->ensure_unique_email($username . '@example.invalid');
        }

        require_once($CFG->dirroot . '/user/lib.php');
        $record = new \stdClass();
        $record->auth = 'manual';
        $record->confirmed = 1;
        $record->mnethostid = (int)$CFG->mnet_localhost_id;
        $record->username = $username;
        $record->password = random_string(32);
        $record->firstname = $firstname;
        $record->lastname = $lastname;
        $record->email = $email;
        $record->maildisplay = 1;
        $record->lang = current_language();

        try {
            $createdid = (int)user_create_user($record, false, false);
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_create_user_failed', 'mod_booking', $e->getMessage()),
                'resultid' => null,
            ];
        }

        $created = $DB->get_record('user', ['id' => $createdid], 'id,username,email,firstname,lastname', MUST_EXIST);

        return [
            'status' => 'executed',
            'detail' => get_string('agent_booking_create_user_created', 'mod_booking'),
            'resultid' => (int)$created->id,
            'userid' => (int)$created->id,
            'username' => (string)$created->username,
            'email' => (string)$created->email,
            'firstname' => (string)$created->firstname,
            'lastname' => (string)$created->lastname,
            'created' => true,
        ];
    }

    /**
     * Split query into first and last name.
     *
     * @param string $query
     * @return array{0:string,1:string}
     */
    private function split_name_from_query(string $query): array {
        $clean = trim(preg_replace('/\s+/', ' ', $query) ?? $query);
        $clean = trim($clean, " \t\n\r\0\x0B\"'.,;:!?");

        if ($clean === '') {
            return ['New', 'User'];
        }

        $parts = array_values(array_filter(array_map('trim', explode(' ', $clean)), static fn($p): bool => $p !== ''));
        if (count($parts) === 1) {
            return [$parts[0], 'User'];
        }

        $firstname = array_shift($parts);
        $lastname = implode(' ', $parts);
        return [$firstname, $lastname === '' ? 'User' : $lastname];
    }

    /**
     * Generate username base from first/last name.
     *
     * @param string $firstname
     * @param string $lastname
     * @return string
     */
    private function generate_username(string $firstname, string $lastname): string {
        $base = \core_text::strtolower(trim($firstname . '.' . $lastname));
        $base = preg_replace('/[^a-z0-9._-]+/', '', $base) ?? '';
        if ($base === '') {
            $base = 'agentuser';
        }
        return $base;
    }

    /**
     * Ensure username uniqueness.
     *
     * @param string $base
     * @return string
     */
    private function ensure_unique_username(string $base): string {
        global $DB;

        $candidate = $base;
        $counter = 1;
        while ($DB->record_exists('user', ['username' => $candidate, 'deleted' => 0])) {
            $candidate = $base . $counter;
            $counter++;
            if ($counter > 9999) {
                $candidate = $base . '_' . time();
                break;
            }
        }

        return $candidate;
    }

    /**
     * Ensure e-mail uniqueness.
     *
     * @param string $baseemail
     * @return string
     */
    private function ensure_unique_email(string $baseemail): string {
        global $DB;

        $candidate = $baseemail;
        $counter = 1;
        while ($DB->record_exists('user', ['email' => $candidate, 'deleted' => 0])) {
            $parts = explode('@', $baseemail, 2);
            $local = $parts[0] ?? 'agentuser';
            $domain = $parts[1] ?? 'example.invalid';
            $candidate = $local . '+' . $counter . '@' . $domain;
            $counter++;
            if ($counter > 9999) {
                $candidate = $local . '+' . time() . '@' . $domain;
                break;
            }
        }

        return $candidate;
    }
}

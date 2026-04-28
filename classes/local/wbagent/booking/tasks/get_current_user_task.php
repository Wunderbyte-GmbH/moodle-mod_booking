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
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;
use mod_booking\local\wbagent\services\answering\get_current_user_answering_service;

/**
 * Task definition for booking.get_current_user.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_current_user_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.get_current_user';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
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
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Get information about the current executor user.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for task-authored wrapper strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        return [
            'valid' => true,
            'errors' => [],
            'ambiguities' => [],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.get_current_user_request',
                'description' => 'User asks about their current account or profile information.',
                'examples' => [
                    'Who am I?',
                    'Show my profile',
                    'Zeige meinen Benutzernamen',
                ],
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'booking.get_current_user',
                'triggers' => [
                    'who am i', 'show my profile', 'wer bin ich', 'zeige mein profil', 'my account',
                ],
                'guidance' => [
                    '- Use booking.get_current_user when the user asks about their own account.',
                    '- Provide a short summary with userid, fullname and email.',
                ],
            ],
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
        global $USER;

        $user = $USER;
        $fullname = trim((string)$user->firstname . ' ' . (string)$user->lastname);
        $userdata = [
            'userid' => (int)$user->id,
            'fullname' => $fullname,
            'email' => (string)$user->email,
        ];

        // LLM-Antwort generieren lassen via Factory.
        $usermessage = '';
        $outputlang = $this->get_output_language($input);
        $answersource = 'none';
        try {
            $llmservice = $this->create_get_current_user_answering_service();
            if ($llmservice !== null) {
                $llmresult = $llmservice->answer_question(
                    (string)($input['question'] ?? ''),
                    $userdata,
                    $outputlang,
                    $cmid,
                    $userid
                );
                $usermessage = trim((string)($llmresult['usermessage'] ?? ''));
                if ($usermessage !== '') {
                    $answersource = 'llm';
                }
            }
        } catch (\Throwable $e) {
            $answersource = 'error';
        }

        if ($usermessage === '') {
            // Fallback: lokale, lokalisierbare Standardantwort.
            $usermessage = $this->localized_string(
                'agent_booking_get_current_user_fallback',
                (object)['fullname' => $fullname, 'email' => $user->email],
                $outputlang
            );
            $answersource = $answersource === 'error' ? 'fallback_after_error' : 'fallback';
        }

        return [
            'status' => 'executed',
            'detail' => $this->localized_string('agent_booking_get_current_user_identified', null, $outputlang),
            'resultid' => (int)$user->id,
            'userid' => (int)$user->id,
            'email' => (string)$user->email,
            'fullname' => $fullname,
            'previewmode' => 'user_profile',
            'previewdata' => $userdata,
            'usermessage' => $usermessage,
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Resolved user: ' . $fullname . ' (id=' . $user->id . ')',
                    'Answer source: ' . $answersource,
                ]
            ),
        ];
    }

    /**
     * Create the answering service for get_current_user.
     *
     * @return get_current_user_answering_service|null
     */
    protected function create_get_current_user_answering_service(): ?get_current_user_answering_service {
        if (class_exists(get_current_user_answering_service::class)) {
            return new get_current_user_answering_service();
        }
        return null;
    }
}

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

/**
 * Task definition for booking.get_current_user.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_current_user_task extends base_booking_task {
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

        // LLM-Antwort generieren lassen.
        $usermessage = '';
        $outputlang = $this->get_output_language($input);
        $answersource = 'none';
        try {
            $llmserviceclass = '\\mod_booking\\local\\wbagent\\services\\get_current_user_answering_service';
            if (class_exists($llmserviceclass)) {
                $llmservice = new $llmserviceclass();
                $llmresult = $llmservice->answer_question(
                    $input['question'] ?? '',
                    $userdata,
                    $outputlang,
                    $cmid,
                    $userid
                );
                if (!empty($llmresult['usermessage'])) {
                    $usermessage = $llmresult['usermessage'];
                    $answersource = 'llm';
                }
            }
        } catch (\Throwable $e) {
            // Preserve task-side debug/fallback behavior. Do not expose LLM errors to users.
            $answersource = 'error';
        }
        if (empty($usermessage)) {
            // Fallback: einfache Standardantwort.
            $usermessage = 'Benutzer: ' . $fullname . ' (' . $user->email . ')';
            $answersource = 'fallback';
        }

        return [
            'status' => 'executed',
            'detail' => 'Current user identified.',
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
}

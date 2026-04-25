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

use mod_booking\local\wbagent\conversation_store;
use mod_booking\local\wbagent\booking\booking_task_mutation_execute_service;
use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;
use mod_booking\local\wbagent\privacy_anonymizer;

/**
 * Task definition for booking.bulk_update_options.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_update_options_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.bulk_update_options';

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
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Update multiple booking options at once. All provided fields are applied to every '
                . 'matched option. Requires optionids, optionquery, or apply_to_all=true to select targets.',
            'readonly' => $this->is_read_only(),
            'properties' => array_merge([
                'optionids' => [
                    'type' => 'array',
                    'description' => 'Array of specific option IDs to update.',
                    'required' => false,
                ],
                'optionquery' => [
                    'type' => 'string',
                    'description' => 'Search query to select multiple options to update '
                        . '(e.g. "yoga" selects all yoga options).',
                    'required' => false,
                ],
                'apply_to_all' => [
                    'type' => 'boolean',
                    'description' => 'Set to true to update ALL options in this booking instance. '
                        . 'Must be set when neither optionids nor optionquery is provided.',
                    'required' => false,
                ],
            ], option_schema_definition::common_properties()),
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
                'id' => 'booking.bulk_update_apply_to_all_confirmed',
                'description' => 'User explicitly confirms applying a bulk update to all booking options.',
            ],
            [
                'id' => 'booking.bulk_update_selection_by_query',
                'description' => 'User specifies that bulk target selection should be based on optionquery.',
            ],
            [
                'id' => 'booking.bulk_update_by_optionids',
                'description' => 'User specifies explicit option ids for bulk update targets.',
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>,issues?:array<int,array<string,mixed>>}
     */
    public function validate(array $input, int $cmid): array {
        global $DB;
        global $USER;

        if (!empty($USER->id) && (int)$USER->id > 0) {
            $anonymizer = new privacy_anonymizer(new conversation_store());
            $input = $anonymizer->deanonymize_command_input_for_active_user($cmid, (int)$USER->id, $input);
        }

        $errors = [];
        $ambiguities = [];
                $issues = [];

        $hasids = !empty($input['optionids']) && is_array($input['optionids'])
            && count($input['optionids']) > 0;
        $hasquery = !empty($input['optionquery']) && is_string($input['optionquery'])
            && trim((string)$input['optionquery']) !== '';
        $applytoall = !empty($input['apply_to_all']);
        $previewfallbackids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute(
            $cmid,
            (int)($USER->id ?? 0)
        );

        if (!$hasids && !$hasquery && !$applytoall && empty($previewfallbackids)) {
            $errors[] = 'Provide optionids (array), optionquery (string), or set apply_to_all=true '
                . 'to specify which options should be updated.';
            $issues[] = [
                'code' => 'MISSING_BULK_TARGET_SELECTION',
                'severity' => 'needs_clarification',
                'user_question' => 'Which options should I update: all options, a query subset, or explicit option ids?',
                'remedy_options' => ['SET_APPLY_TO_ALL', 'PROVIDE_OPTIONQUERY', 'PROVIDE_OPTIONIDS'],
            ];
        }

        if ($hasids) {
            $normalizedoptionids = booking_task_support::resolve_bulk_option_ids_for_execute(
                $cmid,
                ['optionids' => $input['optionids']],
                (int)($USER->id ?? 0)
            );
            $cm = get_coursemodule_from_id('booking', $cmid);
            if ($cm && empty($normalizedoptionids)) {
                foreach ($input['optionids'] as $optid) {
                    if (
                        !$DB->record_exists('booking_options', [
                            'id' => (int)$optid,
                            'bookingid' => (int)$cm->instance,
                        ])
                    ) {
                        $errors[] = 'Option id ' . (int)$optid
                            . ' does not exist in this booking instance.';
                    }
                }
            }
        }

        if (!$hasids && $hasquery && booking_task_support::is_last_preview_selection_reference((string)$input['optionquery'])) {
            $previewids = booking_task_support::resolve_last_preview_option_ids_for_user_for_execute(
                $cmid,
                (int)($USER->id ?? 0)
            );
            if (empty($previewids)) {
                $errors[] = 'No recently previewed booking options are available for this follow-up request.';
            }
        }

        if (!empty($input['bookusersquery'])) {
            $errors[] = 'Field "bookusersquery" is not supported for booking.bulk_update_options. '
                . 'Use booking.update_option for per-option user booking.';
        }

        $preflight = (new booking_task_mutation_execute_service())->preflight_validate(
            self::TASK_NAME,
            $input,
            $cmid,
            (int)($USER->id ?? 0)
        );
        $errors = array_merge($errors, (array)($preflight['errors'] ?? []));
        $ambiguities = array_merge($ambiguities, (array)($preflight['ambiguities'] ?? []));

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issues' => $issues,
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
                'id' => 'booking.bulk_mutation_flow',
                'triggers' => [
                    'alle optionen', 'alle buchungsoptionen', 'bulk update', 'massenaktualisierung',
                    'update all', 'alle aktualisieren', 'alle setzen', 'für alle optionen',
                    'all options', 'all booking options',
                ],
                'guidance' => [
                    '- Use booking.bulk_update_options when the user wants to update multiple options at once.',
                    '- Set apply_to_all=true when the user says "all options" without naming specific ones.',
                    '- Use optionquery to match a subset by title/keyword (e.g. "yoga" selects all yoga options).',
                    '- Use optionids array for an explicit list of known option IDs.',
                    '- All common update fields (maxanswers, maxoverbooking, location, etc.) work the same '
                        . 'as in booking.update_option and are applied to every matched option.',
                    '- Do not use bookusersquery with bulk_update_options.',
                    '- Use confirmation_request for bulk mutations and follow structured validation issues when returned.',
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
        $service = new booking_task_mutation_execute_service();
        $result = $service->execute(self::TASK_NAME, $input, $cmid, $userid, $this->support);
        if (is_array($result)) {
            $result['debugmessage'] = $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                ['Status: ' . ($result['status'] ?? 'unknown')]
            );
            return $result;
        }

        return [
            'status' => 'error',
            'detail' => 'Unknown booking task: ' . self::TASK_NAME,
            'resultid' => null,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Status: error']),
        ];
    }
}

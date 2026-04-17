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

use mod_booking\local\wbagent\booking\support\booking_mutation_validation;

/**
 * Task definition for booking.bulk_update_options.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_update_options_task extends base_booking_task {
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
     * @return array<string,mixed>
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
     * Validate task input.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        global $DB;

        $errors = [];
        $ambiguities = [];

        $hasids = !empty($input['optionids']) && is_array($input['optionids'])
            && count($input['optionids']) > 0;
        $hasquery = !empty($input['optionquery']) && is_string($input['optionquery'])
            && trim((string)$input['optionquery']) !== '';
        $applytoall = !empty($input['apply_to_all']);

        if (!$hasids && !$hasquery && !$applytoall) {
            $errors[] = 'Provide optionids (array), optionquery (string), or set apply_to_all=true '
                . 'to specify which options should be updated.';
        }

        if ($hasids) {
            $cm = get_coursemodule_from_id('booking', $cmid);
            if ($cm) {
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

        if (!empty($input['bookusersquery'])) {
            $errors[] = 'Field "bookusersquery" is not supported for booking.bulk_update_options. '
                . 'Use booking.update_option for per-option user booking.';
        }

        $common = booking_mutation_validation::validate_common($input, $cmid, self::TASK_NAME);
        $errors = array_merge($errors, $common['errors']);
        $ambiguities = array_merge($ambiguities, $common['ambiguities']);

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
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
                ],
            ],
        ];
    }
}

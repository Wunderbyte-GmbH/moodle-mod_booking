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
use mod_booking\local\pricecategories_handler;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for booking.add_price_category.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_price_category_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.add_price_category';

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
        return [
            [
                'id' => 'booking.pricing',
                'triggers' => ['price', 'preise', 'preis', 'cost', 'kosten', 'price category', 'pricecat'],
                'guidance' => [
                    '- Use a "prices" object keyed by price category identifier, e.g. {"default": 10, "student": 20}.',
                    '- If a requested price category is unknown, ask for clarification or add it via booking.add_price_category.',
                    '- For mutating pricing actions, use confirmation_request first and follow structured issues.',
                    '- If duplicate category creation is explicitly confirmed by user,',
                    '  retry with override token duplicate_identifier.',
                ],
            ],
        ];
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Create a booking price category for option pricing.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'identifier' => [
                    'type' => 'string',
                    'description' => 'Unique identifier, e.g. "student" (letters/numbers/_/-).',
                    'required' => true,
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Display name of the category, e.g. "Student".',
                    'required' => false,
                ],
                'defaultvalue' => [
                    'type' => 'number',
                    'description' => 'Default price value for this category.',
                    'required' => false,
                ],
                'pricecatsortorder' => [
                    'type' => 'integer',
                    'description' => 'Optional explicit sort order.',
                    'required' => false,
                ],
                'override' => [
                    'type' => 'array',
                    'description' => 'Optional override tokens for confirmed exceptions (e.g. duplicate_identifier).',
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
        return [
            [
                'id' => 'booking.confirm_duplicate_price_category',
                'description' => 'User explicitly confirms creating/keeping a duplicate price category identifier.',
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
        $errors = [];
        $ambiguities = [];
        $issues = [];

        $lang = $this->get_output_language($input);

        $identifier = trim((string)($input['identifier'] ?? ''));
        if ($identifier === '') {
            $errors[] = $this->localized_string('agent_booking_pricecat_identifier_required', null, $lang);
        } else if (!preg_match('/^[a-z0-9_-]+$/i', $identifier)) {
            $errors[] = $this->localized_string('agent_booking_pricecat_identifier_invalid', null, $lang);
        }

        if (isset($input['defaultvalue']) && !is_numeric($input['defaultvalue'])) {
            $errors[] = $this->localized_string('agent_booking_pricecat_defaultvalue_numeric', null, $lang);
        } else if (isset($input['defaultvalue']) && (float)$input['defaultvalue'] < 0) {
            $errors[] = $this->localized_string('agent_booking_pricecat_defaultvalue_nonnegative', null, $lang);
        }

        $handler = new pricecategories_handler();
        $existing = $handler->get_pricecategories_indexed_by_identifier();
        if (
            $identifier !== ''
            && isset($existing[strtolower($identifier)])
            && (int)$existing[strtolower($identifier)]->disabled === 0
        ) {
            $errors[] = $this->localized_string('agent_booking_pricecat_duplicate_exists', $identifier, $lang);
            $issues[] = [
                'code' => 'DUPLICATE_PRICE_CATEGORY_CONFIRM_REQUIRED',
                'severity' => 'needs_confirmation',
                'user_question' => $this->localized_string('agent_booking_pricecat_duplicate_user_question', $identifier, $lang),
                'remedy_options' => ['CONFIRM_DUPLICATE_IDENTIFIER', 'USE_DIFFERENT_IDENTIFIER'],
            ];
        }

        return [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
            'issues' => $issues,
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
        if (!has_capability('moodle/site:config', context_system::instance())) {
            return [
                'status' => 'error',
                'detail' => get_string('agent_booking_add_pricecat_capability_required', 'mod_booking'),
                'resultid' => null,
            ];
        }

        $identifier = trim((string)($input['identifier'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            $name = ucfirst(str_replace(['_', '-'], ' ', $identifier));
        }
        $defaultvalue = isset($input['defaultvalue']) ? (float)$input['defaultvalue'] : 0.0;

        $handler = new pricecategories_handler();
        $result = $handler->upsert_pricecategory(
            $identifier,
            $name,
            $defaultvalue,
            isset($input['pricecatsortorder']) ? (int)$input['pricecatsortorder'] : null
        );

        $outputlang = $this->get_output_language($input);
        if (is_array($result)) {
            $result['usermessage'] = $this->localized_string(
                'agent_booking_pricecat_created',
                $identifier,
                $outputlang
            );
            $result['outputlang'] = $outputlang;
            $result['debugmessage'] = $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                ['Status: ' . ($result['status'] ?? 'unknown')]
            );
        }

        return $result;
    }
}

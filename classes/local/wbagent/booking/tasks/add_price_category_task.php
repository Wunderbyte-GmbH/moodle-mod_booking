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

/**
 * Task definition for booking.add_price_category.
 *
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_price_category_task extends base_booking_task {
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
                ],
            ],
        ];
    }

    /**
     * Return task schema.
     *
     * @return array<string,mixed>
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
            ],
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
        $errors = [];
        $ambiguities = [];

        $identifier = trim((string)($input['identifier'] ?? ''));
        if ($identifier === '') {
            $errors[] = 'Field "identifier" is required for add_price_category.';
        } else if (!preg_match('/^[a-z0-9_-]+$/i', $identifier)) {
            $errors[] = 'Field "identifier" may only contain letters, numbers, underscore and dash.';
        }

        if (isset($input['defaultvalue']) && !is_numeric($input['defaultvalue'])) {
            $errors[] = 'Field "defaultvalue" must be numeric.';
        } else if (isset($input['defaultvalue']) && (float)$input['defaultvalue'] < 0) {
            $errors[] = 'Field "defaultvalue" must be non-negative.';
        }

        $handler = new pricecategories_handler();
        $existing = $handler->get_pricecategories_indexed_by_identifier();
        if (
            $identifier !== ''
            && isset($existing[strtolower($identifier)])
            && (int)$existing[strtolower($identifier)]->disabled === 0
        ) {
            $ambiguities[] = 'Price category "' . $identifier . '" already exists.';
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
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function execute(array $input, int $cmid, int $userid): array {
        if (!has_capability('moodle/site:config', context_system::instance())) {
            return [
                'status' => 'error',
                'detail' => 'Adding price categories requires moodle/site:config capability.',
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
        return $handler->upsert_pricecategory(
            $identifier,
            $name,
            $defaultvalue,
            isset($input['pricecatsortorder']) ? (int)$input['pricecatsortorder'] : null
        );
    }
}

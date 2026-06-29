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

/**
 * Application service for entity mutations with deduplication.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wizard\services\mutation;

use bookingextension_agent\local\wizard\dto\mutation_result_dto;
use mod_booking\local\wizard\dto\create_entity_input_dto;

/**
 * Handles entity creation with name/shortname deduplication.
 *
 * Dedup is enforced before any write attempt, preventing duplicate entity records
 * even when the same request is replayed without an idempotency key.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class entity_mutation_service {
    /**
     * Create an entity, rejecting requests that would create duplicates.
     *
     * @param create_entity_input_dto $dto
     * @param int                     $userid
     * @return mutation_result_dto
     */
    public function create_entity(create_entity_input_dto $dto, int $userid): mutation_result_dto {
        $name      = trim((string)$dto->get('name', ''));
        $shortname = trim((string)$dto->get('shortname', ''));

        if ($name === '') {
            return mutation_result_dto::error('Entity name must not be empty.');
        }

        if ($this->entity_exists_by_name($name)) {
            return mutation_result_dto::error("Entity with name '{$name}' already exists (deduplicated).");
        }

        if ($shortname !== '' && $this->entity_exists_by_shortname($shortname)) {
            return mutation_result_dto::error("Entity with shortname '{$shortname}' already exists (deduplicated).");
        }

        // Actual creation is delegated to the entities plugin when available.
        return mutation_result_dto::error('Entity creation service not yet available in this context.');
    }

    /**
     * Check whether an entity with the given name already exists.
     *
     * @param string $name
     * @return bool
     */
    public function entity_exists_by_name(string $name): bool {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_wb_entity')) {
            return false;
        }
        return $DB->record_exists('local_wb_entity', ['name' => $name]);
    }

    /**
     * Check whether an entity with the given shortname already exists.
     *
     * @param string $shortname
     * @return bool
     */
    public function entity_exists_by_shortname(string $shortname): bool {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_wb_entity')) {
            return false;
        }
        return $DB->record_exists('local_wb_entity', ['shortname' => $shortname]);
    }
}

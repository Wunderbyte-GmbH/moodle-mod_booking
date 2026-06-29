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
 * Application service for booking option mutations.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wizard\services\mutation;

use bookingextension_agent\local\wizard\booking\booking_task_support;
use bookingextension_agent\local\wizard\booking\tasks\create_option_task;
use bookingextension_agent\local\wizard\booking\tasks\update_option_task;
use bookingextension_agent\local\wizard\booking\tasks\bulk_update_options_task;
use bookingextension_agent\local\wizard\dto\mutation_result_dto;
use mod_booking\local\wizard\dto\create_option_input_dto;
use mod_booking\local\wizard\dto\update_option_input_dto;
use mod_booking\local\wizard\dto\bulk_update_options_input_dto;

/**
 * Centralises booking option mutation logic previously spread across booking_task_support.
 *
 * Tasks orchestrate, services execute.  Both paths call the same underlying logic
 * so architectural tests can verify identical results for identical input.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_mutation_service {
    /**
     * Validate a create-option request without executing it.
     *
     * @param create_option_input_dto $dto
     * @param int                     $cmid
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function validate_create(create_option_input_dto $dto, int $cmid): array {
        return (new booking_task_support())->validate(create_option_task::TASK_NAME, $dto->to_array(), $cmid);
    }

    /**
     * Validate an update-option request without executing it.
     *
     * @param update_option_input_dto $dto
     * @param int                     $cmid
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function validate_update(update_option_input_dto $dto, int $cmid): array {
        return (new booking_task_support())->validate(update_option_task::TASK_NAME, $dto->to_array(), $cmid);
    }

    /**
     * Validate a bulk-update-options request without executing it.
     *
     * @param bulk_update_options_input_dto $dto
     * @param int                           $cmid
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function validate_bulk_update(bulk_update_options_input_dto $dto, int $cmid): array {
        return (new booking_task_support())->validate(bulk_update_options_task::TASK_NAME, $dto->to_array(), $cmid);
    }

    /**
     * Execute a create-option mutation.
     *
     * @param create_option_input_dto $dto
     * @param int                     $cmid
     * @param int                     $userid
     * @return mutation_result_dto
     */
    public function create_option(create_option_input_dto $dto, int $cmid, int $userid): mutation_result_dto {
        $result = (new booking_task_support())->execute(create_option_task::TASK_NAME, $dto->to_array(), $cmid, $userid);
        return $this->map_result($result);
    }

    /**
     * Execute an update-option mutation.
     *
     * @param update_option_input_dto $dto
     * @param int                     $cmid
     * @param int                     $userid
     * @return mutation_result_dto
     */
    public function update_option(update_option_input_dto $dto, int $cmid, int $userid): mutation_result_dto {
        $result = (new booking_task_support())->execute(update_option_task::TASK_NAME, $dto->to_array(), $cmid, $userid);
        return $this->map_result($result);
    }

    /**
     * Execute a bulk-update-options mutation.
     *
     * @param bulk_update_options_input_dto $dto
     * @param int                           $cmid
     * @param int                           $userid
     * @return mutation_result_dto
     */
    public function bulk_update_options(bulk_update_options_input_dto $dto, int $cmid, int $userid): mutation_result_dto {
        $result = (new booking_task_support())->execute(bulk_update_options_task::TASK_NAME, $dto->to_array(), $cmid, $userid);
        return $this->map_result($result);
    }

    /**
     * Map a raw booking_task_support result array to a mutation_result_dto.
     *
     * @param array $result Raw result from booking_task_support::execute().
     * @return mutation_result_dto
     */
    private function map_result(array $result): mutation_result_dto {
        if (($result['status'] ?? '') === 'executed') {
            return mutation_result_dto::success(
                (int)($result['resultid'] ?? 0),
                (string)($result['detail'] ?? ''),
                (array)($result['warnings'] ?? []),
                (array)($result['previewoptionids'] ?? [])
            );
        }
        return mutation_result_dto::error((string)($result['detail'] ?? 'Unknown error.'));
    }
}

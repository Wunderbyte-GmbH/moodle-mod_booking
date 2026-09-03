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
 * Canonical input DTO for the booking.bulk_update_options use-case.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wizard\dto;

/**
 * Value object carrying validated input for a bulk-update-options request.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_update_options_input_dto {
    /** @var array<string,mixed> */
    private array $fields;

    /**
     * Private constructor – use from_array() factory.
     *
     * @param array $fields
     */
    private function __construct(array $fields) {
        $this->fields = $fields;
    }

    /**
     * Create a DTO from a raw input array.
     *
     * @param array $data
     * @return self
     */
    public static function from_array(array $data): self {
        return new self($data);
    }

    /**
     * Return all fields as an associative array.
     *
     * @return array
     */
    public function to_array(): array {
        return $this->fields;
    }

    /**
     * Get a single field value.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed {
        return array_key_exists($key, $this->fields) ? $this->fields[$key] : $default;
    }
}

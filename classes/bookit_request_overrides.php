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
 * One-shot handling of optional webservice override requests for bookit.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\bo_availability\conditions\bookitbutton;

/**
 * Parses and validates optional override ids coming from the bookit webservice payload.
 *
 * The server remains authoritative. Overrides are optional hints and are consumed once.
 */
class bookit_request_overrides {
    /** @var array<int,bool> */
    private $requestedids = [];

    /** @var bool */
    private $consumed = false;

    /**
     * Build override helper from webservice data payload.
     *
     * @param string $data
     * @return self
     */
    public static function from_data(string $data): self {
        $instance = new self();

        if (empty($data)) {
            return $instance;
        }

        $payload = json_decode($data);
        if (!$payload || empty($payload->overrideids)) {
            return $instance;
        }

        $overrideids = $payload->overrideids;

        if (is_string($overrideids)) {
            $decoded = json_decode($overrideids, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $overrideids = $decoded;
            } else {
                $overrideids = explode(',', $overrideids);
            }
        }

        if (!is_array($overrideids)) {
            return $instance;
        }

        foreach ($overrideids as $overrideid) {
            if (!is_numeric($overrideid)) {
                continue;
            }
            $id = (int)$overrideid;
            if ($id <= 0) {
                continue;
            }
            $instance->requestedids[$id] = true;
        }

        return $instance;
    }

    /**
     * Consume option condition ids that may be ignored for this request.
     *
     * This is intentionally narrow: currently only for multiple-bookings scenarios
     * and only for the cancel-myself blocker.
     *
     * @param booking_option_settings $settings
     * @return int[]
     */
    public function consume_option_ignored_condition_ids(booking_option_settings $settings): array {
        if ($this->consumed || empty($this->requestedids)) {
            return [];
        }
        $this->consumed = true;

        if (empty($settings->jsonobject->multiplebookings)) {
            return [];
        }

        $allowed = bookitbutton::get_book_intent_override_condition_ids();
        $allowedkeys = array_flip($allowed);

        $selected = array_keys($this->requestedids);
        $selected = array_values(array_filter($selected, function (int $id) use ($allowedkeys): bool {
            return isset($allowedkeys[$id]);
        }));

        return $selected;
    }
}

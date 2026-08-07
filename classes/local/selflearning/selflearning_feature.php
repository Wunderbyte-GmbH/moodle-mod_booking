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

declare(strict_types=1);

namespace mod_booking\local\selflearning;

use mod_booking\utils\wb_payment;

/**
 * Single source of truth for whether the self-learning course feature is available.
 *
 * Self-learning courses (booking options with a fixed duration instead of dates) require
 * BOTH the PRO licence AND the global admin toggle (booking/selflearningcourseactive,
 * settings.php) to be on. This mirrors {@see \mod_booking\local\slotbooking\slot_feature}
 * so both non-default option types are gated the same way.
 *
 * Unlike slot booking this toggle is default-off: the setting ships with a default of 0,
 * so an unset value means the feature is not available.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selflearning_feature {
    /**
     * Whether the self-learning course feature is available on this site.
     *
     * @return bool True only when PRO is active and the admin toggle is on.
     */
    public static function is_enabled(): bool {
        if (!wb_payment::pro_version_is_activated()) {
            return false;
        }
        return (bool)get_config('booking', 'selflearningcourseactive');
    }
}

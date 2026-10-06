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

namespace bookingextension_agent\local\wizard\dto;

/**
 * Declarative skill risk class contract.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skill_risk_class {
    /** Read-only skills. */
    public const R0 = 'read_only';
    /** Scoped write skills. */
    public const R1 = 'scoped_write';
    /** Broad write skills. */
    public const R2 = 'broad_write';
    /** Irreversible or external-effect skills. */
    public const R3 = 'irreversible_or_external';

    /**
     * Check whether the provided class value is valid.
     *
     * @param string $class
     * @return bool
     */
    public static function is_valid(string $class): bool {
        return in_array(trim($class), [self::R0, self::R1, self::R2, self::R3], true);
    }
}

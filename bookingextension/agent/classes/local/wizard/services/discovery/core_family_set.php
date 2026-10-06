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

namespace bookingextension_agent\local\wizard\services\discovery;

/**
 * Resolves the always-on core family baseline for discovery stage A.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_family_set {
    /** @var int Hard upper bound for default core families. */
    private const MAX_CORE_FAMILIES = 4;

    /**
     * Resolve core families from prompt contracts plus stable defaults.
     *
     * @param array[] $promptcontracts
     * @return string[]
     */
    public function resolve(array $promptcontracts): array {
        $families = ['wizard.general'];

        foreach ($promptcontracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }

            $family = trim((string)($contract['family'] ?? ''));
            if ($family === '' || strpos($family, 'wizard.') !== 0) {
                continue;
            }

            $families[] = $family;
        }

        $families = array_values(array_unique(array_filter(array_map('strval', $families))));
        sort($families, SORT_STRING);

        return array_slice($families, 0, self::MAX_CORE_FAMILIES);
    }
}

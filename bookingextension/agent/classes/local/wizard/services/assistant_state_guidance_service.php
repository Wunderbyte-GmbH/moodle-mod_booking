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

namespace bookingextension_agent\local\wizard\services;

use core_text;

/**
 * Normalizes assistant-state string lists for prompt assembly.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assistant_state_guidance_service {
    /**
     * Normalize an arbitrary list into non-empty trimmed strings.
     *
     * @param mixed[] $values
     * @param int $maxitems
     * @param int $maxchars
     * @return string[]
     */
    public function normalize_nonempty_string_list(array $values, int $maxitems = 0, int $maxchars = 0): array {
        if ($maxitems > 0) {
            $values = array_slice($values, 0, $maxitems);
        }

        $normalized = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            if ($maxchars > 0) {
                $text = (string)core_text::substr($text, 0, $maxchars);
            }
            $normalized[] = $text;
        }

        return array_values($normalized);
    }
}

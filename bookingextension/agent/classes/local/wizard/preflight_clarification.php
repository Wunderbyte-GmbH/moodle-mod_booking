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
 * Shared "needs clarification" preflight result builder for skills.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

/**
 * Builds an invalid preflight result that asks the user a clarifying question. Previously copied
 * verbatim across the add/update activity and add/update quiz skills.
 *
 * DTO-free: returns the primitive result shape via base_skill::invalid(); the using skill's
 * base_skill wraps it into the engine's preflight_result_v2.
 */
trait preflight_clarification {
    /**
     * Build a "needs clarification" preflight result.
     *
     * @param string $message
     * @param string $code
     * @param array[] $options
     * @return array{status:string,prepared_input:array,issues:array}
     */
    private function clarify(string $message, string $code, array $options = []): array {
        $issue = ['severity' => 'needs_clarification', 'message' => $message, 'code' => $code];
        if (!empty($options)) {
            $issue['options'] = $options;
        }
        return $this->invalid([$issue]);
    }
}

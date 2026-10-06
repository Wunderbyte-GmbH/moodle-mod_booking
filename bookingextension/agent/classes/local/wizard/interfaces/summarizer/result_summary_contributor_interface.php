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
 * Result summary contributor interface.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\interfaces\summarizer;

/**
 * Contract for skill/domain specific summary contributors.
 */
interface result_summary_contributor_interface {
    /**
     * Whether this contributor can summarize the given result category.
     *
     * @param string $category
     * @param array $entry
     * @return bool
     */
    public function supports(string $category, array $entry): bool;

    /**
     * Summarize a single result entry.
     *
     * Return empty string when no summary should be emitted.
     *
     * @param array $entry
     * @param int $step
     * @return string
     */
    public function summarize(array $entry, int $step = 0): string;
}

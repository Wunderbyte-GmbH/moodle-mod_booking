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

namespace bookingextension_agent\local\wizard\interfaces;

/**
 * Optional interface for skill-authored result summaries.
 *
 * Implement this on skills that need custom deterministic summary text beyond
 * plugin/general contributors.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface skill_result_summary_provider_interface {
    /**
     * Build a compact deterministic summary for one skill result entry.
     *
     * Return an empty string to fall back to plugin/general contributors.
     *
     * @param array $result
     * @param array $context
     * @return string
     */
    public function summarize_skill_result(array $result, array $context = []): string;
}

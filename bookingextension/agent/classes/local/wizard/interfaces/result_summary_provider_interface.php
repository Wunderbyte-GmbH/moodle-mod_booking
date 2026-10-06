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
 * Optional provider interface for result summary contributors.
 *
 * Components can implement this interface on their skill_provider to register
 * skill/domain-specific summary contributors for result payload summarization.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface result_summary_provider_interface {
    /**
     * Return result summary contributors provided by this component.
     *
     * @return \bookingextension_agent\local\wizard\interfaces\summarizer\result_summary_contributor_interface[]
     */
    public function get_result_summary_contributors(): array;
}

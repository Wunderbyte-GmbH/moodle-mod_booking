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

namespace bookingextension_agent\local\wizard\services\llm;

/**
 * The admin switch for enhanced content generation: switching it on probes the content model once.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_model_switch {
    /**
     * Settings callback: an unreachable content model switches the setting back off and says why.
     *
     * @return void
     */
    public static function on_setting_updated(): void {
        if ((int)get_config('bookingextension_agent', 'contentmodel_enabled') !== 1) {
            return;
        }
        $probe = llm_call_service::probe_content_model();
        if (!empty($probe['success'])) {
            \core\notification::success(
                get_string('contentmodel_probe_ok', 'bookingextension_agent', (string)($probe['model'] ?? ''))
            );
            return;
        }
        set_config('contentmodel_enabled', 0, 'bookingextension_agent');
        \core\notification::warning(
            get_string('contentmodel_probe_failed', 'bookingextension_agent', (string)($probe['error'] ?? ''))
        );
    }
}

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
 * External service: toggle the agent debug mode (site-wide).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Set the site-wide aidebugmode flag (admin quick action from the agent gear).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_debug_mode extends external_api {
    /**
     * Describe input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'enabled' => new external_value(PARAM_BOOL, 'Whether debug mode should be on.'),
        ]);
    }

    /**
     * Toggle the site-wide debug mode.
     *
     * @param bool $enabled
     * @return array
     */
    public static function execute(bool $enabled): array {
        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), ['enabled' => $enabled]);

        // Debug mode is a site-wide setting -> true admin only.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        set_config('aidebugmode', !empty($params['enabled']) ? 1 : 0, 'bookingextension_agent');

        return ['success' => true, 'enabled' => !empty($params['enabled'])];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the setting was saved.'),
            'enabled' => new external_value(PARAM_BOOL, 'The new debug mode state.'),
        ]);
    }
}

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

namespace bookingextension_agent;

use mod_booking\plugininfo\bookingextension;
use mod_booking\plugininfo\bookingextension_interface;

/**
 * Booking extension entrypoint.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agent extends bookingextension implements bookingextension_interface {
    /**
     * Get plugin name.
     *
     * @return string
     */
    public function get_plugin_name(): string {
        return get_string('pluginname', 'bookingextension_agent');
    }

    /**
     * Whether extension contributes option fields.
     *
     * @return bool
     */
    public function contains_option_fields(): bool {
        return false;
    }

    /**
     * Option field metadata.
     *
     * @return array
     */
    public function get_option_fields_info_array(): array {
        return [];
    }

    /**
     * Load settings.
     *
     * @param \part_of_admin_tree $adminroot
     * @param string $parentnodename
     * @param bool $hassiteconfig
     * @return void
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig): void {
        global $CFG;

        try {
            require($CFG->dirroot . '/mod/booking/bookingextension/agent/settings.php');
        } catch (\Throwable $e) {
            // Never let a settings error abort the extension loading loop in mod_booking/settings.php.
            debugging('bookingextension_agent: load_settings failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Load singleton data.
     *
     * @param int $optionid
     * @return object
     */
    public static function load_data_for_settings_singleton(int $optionid): object {
        return (object)[];
    }

    /**
     * Add template data.
     *
     * @param object $settings
     * @return array[]
     */
    public static function set_template_data_for_optionview(object $settings): array {
        return [];
    }

    /**
     * Add options to actions column.
     *
     * @param object $settings
     * @param mixed $context
     * @return string
     */
    public static function add_options_to_col_actions(object $settings, mixed $context): string {
        return '';
    }

    /**
     * Return allowed booking rule event keys.
     *
     * @return array
     */
    public static function get_allowedruleeventkeys(): array {
        return [];
    }

    /**
     * Return booking history description.
     *
     * @param \stdClass $values
     * @param array $info
     * @return string
     */
    public static function get_booking_history_description(\stdClass $values, array $info): string {
        return '';
    }
}

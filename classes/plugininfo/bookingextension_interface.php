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

namespace mod_booking\plugininfo;

/**
 * Interface for a single booking extension.
 *
 * All booking extensions must extend this class.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH
 * @author      Bernhard Fischer-Sengseis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface bookingextension_interface {
    /**
     * Get the plugin name.
     * @return string the plugin name
     */
    public function get_plugin_name(): string;

    /**
     *
     * Check if the booking extension contains new option fields.
     * @return bool True if the booking extension contains new option fields, false otherwise.
     */
    public function contains_option_fields(): bool;

    /**
     * If the extension adds new option fields this array contains the according information.
     * @return array
     */
    public function get_option_fields_info_array(): array;

    /**
     * Loads plugin settings to the settings tree
     *
     * This function usually includes settings.php file in plugins folder.
     * Alternatively it can create a link to some settings page (instance of admin_externalpage)
     *
     * @param \part_of_admin_tree $adminroot
     * @param string $parentnodename
     * @param bool $hassiteconfig whether the current user has moodle/site:config capability
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig): void;

    /**
     * Adds Settings Singleton Service access to Subplugins.
     *
     * @param int $optionid
     *
     * @return object
     *
     */
    public static function load_data_for_settings_singleton(int $optionid): object;

    /**
     * Adds Data to Template for Optionview in Descriptions.
     *
     * @param object $settings
     *
     * @return array[] Array of associative arrays with keys: key, value, label, description.
     *
     */
    public static function set_template_data_for_optionview(object $settings): array;

    /**
     * Add an Option to col_action in the bookingoptions_wbtable.php
     *
     * @param object $settings
     * @param mixed $context
     *
     * @return string
     *
     */
    public static function add_options_to_col_actions(object $settings, mixed $context): string;

    /**
     * Returns array of allowed event keys for booking rule react on event.
     *
     * @return array
     *
     */
    public static function get_allowedruleeventkeys(): array;
}

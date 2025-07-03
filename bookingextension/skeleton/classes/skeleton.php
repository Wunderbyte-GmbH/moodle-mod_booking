<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Class skeleton.
 *
 * @package     bookingextension_skeleton
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_skeleton;

use admin_setting_configcheckbox;
use admin_setting_configpasswordunmask;
use admin_setting_configtext;
use admin_setting_heading;
use admin_settingpage;
use mod_booking\plugininfo\bookingextension;
use mod_booking\plugininfo\bookingextension_interface;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/bookingextension/skeleton/lib.php');

/**
 * Class for the Respond API booking extension.
 */
class skeleton extends bookingextension implements bookingextension_interface {
    /**
     * Get the plugin name.
     * @return string the plugin name
     */
    public function get_plugin_name(): string {
        return get_string('pluginname', 'bookingextension_skeleton');
    }

    /**
     * Check if the booking extension contains new option fields.
     * @return bool True if the booking extension contains new option fields, false otherwise.
     */
    public function contains_option_fields(): bool {
        // Yes, this plugin contains new option fields.
        return false;
    }

    /**
     * If the extension adds new option fields this array contains the according information.
     * @return array
     */
    public function get_option_fields_info_array(): array {
        return [
            // phpcs:disable
            // 'skeleton' => [
            //     'name' => 'skeleton',
            //     'class' => 'bookingextension_skeleton\option\fields\skeleton',
            //     'id' => MOD_BOOKING_OPTION_FIELD_RESPONDAPI,
            //  ],
            // phpcs:enable
            // We can add more fields here...
        ];
    }

    /**
     * Loads plugin settings to the settings tree.
     *
     * @param \part_of_admin_tree $adminroot
     * @param string $parentnodename
     * @param bool $hassiteconfig whether the current user has moodle/site:config capability
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig): void {
        $skeletonsettings = new admin_settingpage(
            'bookingextension_skeleton_settings',
            get_string('pluginname', 'bookingextension_skeleton'),
            'moodle/site:config',
            $this->is_enabled() === false
        );

        // Add settings to Booking plugin.
        // Skeleton.
        $skeletonsettings->add(new admin_setting_heading(
            'bookingextension_skeleton',
            get_string('bookingextensionskeleton:heading', 'bookingextension_skeleton'),
            get_string('bookingextensionskeleton:heading_desc', 'bookingextension_skeleton')
        ));
        $skeletonsettings->add(new admin_setting_configcheckbox(
            'bookingextension_skeleton/skeleton_enabled',
            get_string('bookingextensionskeleton:skeletonenabled', 'bookingextension_skeleton'),
            get_string('bookingextensionskeleton:skeletonenabled_desc', 'bookingextension_skeleton'),
            0
        ));

        $adminroot->add('modbookingfolder', $skeletonsettings);
    }

    /**
     * Function for Bookingoption Settings Singleton.
     *
     * @param int $optionid
     *
     * @return object
     *
     */
    public static function load_data_for_settings_singleton(int $optionid): object {
        return (object)[];
    }

    /**
     * Adds Data to Template for Optionview in Descriptions.
     *
     * @param object $settings
     *
     * @return array[] Array of associative arrays with keys: key, value, label, description.
     *
     */
    public static function set_template_data_for_optionview(object $settings): array {
        return [];
    }
}

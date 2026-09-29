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
 * Class confirmation_supervisor.
 *
 * @package     bookingextension_confirmation_supervisor
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_confirmation_supervisor;

use admin_setting_configcheckbox;
use admin_setting_configpasswordunmask;
use admin_setting_configselect;
use admin_setting_configtext;
use admin_setting_heading;
use admin_settingpage;
use mod_booking\plugininfo\bookingextension;
use mod_booking\plugininfo\bookingextension_interface;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/bookingextension/confirmation_supervisor/lib.php');

/**
 * Class for the Respond API booking extension.
 */
class confirmation_supervisor extends bookingextension implements bookingextension_interface {
    /**
     * Get the list of available options for confirmation order.
     *
     * Keys are the stored integer values; values are the translated labels.
     *
     * @return array<int,string>
     */
    public static function get_confirmation_order_options(): array {
        return [
            0 => get_string('noconfirmationneeded', 'bookingextension_confirmation_supervisor'),
            1 => get_string('confirmbysupervisor', 'bookingextension_confirmation_supervisor'),
            2 => get_string('confirmbyhrsupervisor', 'bookingextension_confirmation_supervisor'),
            3 => get_string('confirmbyhr', 'bookingextension_confirmation_supervisor'),
            4 => get_string('confirmbysupervisorhr', 'bookingextension_confirmation_supervisor'),
            5 => get_string('confirmbysupervisororhr', 'bookingextension_confirmation_supervisor'),
        ];
    }

    /**
     * Get the plugin name.
     * @return string the plugin name
     */
    public function get_plugin_name(): string {
        return get_string('pluginname', 'bookingextension_confirmation_supervisor');
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
            // 'confirmation_supervisor' => [
            //     'name' => 'confirmation_supervisor',
            //     'class' => 'bookingextension_confirmation_supervisor\option\fields\confirmation_supervisor',
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
        $settings = new admin_settingpage(
            'bookingextension_confirmation_supervisor_settings',
            get_string('pluginname', 'bookingextension_confirmation_supervisor'),
            'moodle/site:config',
            $this->is_enabled() === false
        );

        // Add settings to Booking plugin.
        // Skeleton.
        $settings->add(new admin_setting_heading(
            'bookingextension_confirmation_supervisor',
            get_string('bookingextensionconfirmationsupervisor:heading', 'bookingextension_confirmation_supervisor'),
            get_string('bookingextensionconfirmationsupervisor:heading_desc', 'bookingextension_confirmation_supervisor')
        ));

        // Checkbox to enable or disable.
        $settings->add(new admin_setting_configcheckbox(
            'bookingextension_confirmation_supervisor/confirmationsupervisorenabled',
            get_string(
                'bookingextensionconfirmationsupervisor:confirmationsupervisorenabled',
                'bookingextension_confirmation_supervisor'
            ),
            get_string(
                'bookingextensionconfirmationsupervisor:confirmationsupervisorenabled_desc',
                'bookingextension_confirmation_supervisor'
            ),
            0
        ));

        // Checkbox to limit the answers a supervisor sees to the answers of their own team.
        $settings->add(new admin_setting_configcheckbox(
            'bookingextension_confirmation_supervisor/restricttrackertomyteam',
            get_string('restricttrackertomyteam', 'bookingextension_confirmation_supervisor'),
            get_string('restricttrackertomyteam_desc', 'bookingextension_confirmation_supervisor'),
            0
        ));

        $settings->add(
            new admin_setting_configselect(
                'bookingextension_confirmation_supervisor/defaultconfirmationorder',
                get_string('defaultconfirmationorder', 'bookingextension_confirmation_supervisor'),
                get_string('defaultconfirmationorder_desc', 'bookingextension_confirmation_supervisor'),
                0,
                self::get_confirmation_order_options()
            )
        );

        $settings->add(new admin_setting_configtext(
            'bookingextension_confirmation_supervisor/confirmation_supervisor_hrusers',
            get_string('hrusers', 'bookingextension_confirmation_supervisor'),
            get_string('hrusers_desc', 'bookingextension_confirmation_supervisor'),
            0
        ));

        // Code snippet to choose user profile fields.
        $userprofilefieldsarray[0] = get_string('choose...', 'mod_booking');
        $userprofilefields = profile_get_custom_fields();
        if (!empty($userprofilefields)) {
            // Create an array of key => value pairs for the dropdown.
            foreach ($userprofilefields as $userprofilefield) {
                $userprofilefieldsarray[$userprofilefield->shortname] = "$userprofilefield->name ($userprofilefield->shortname)";
            }
        }

        $settings->add(
            new admin_setting_configselect(
                'bookingextension_confirmation_supervisor/supervisor',
                get_string('supervisorfield', 'bookingextension_confirmation_supervisor'),
                get_string('supervisorfield_desc', 'bookingextension_confirmation_supervisor'),
                0,
                $userprofilefieldsarray
            )
        );

        $settings->add(
            new admin_setting_configselect(
                'bookingextension_confirmation_supervisor/deputy',
                get_string('deputyfield', 'bookingextension_confirmation_supervisor'),
                get_string('deputyfield_desc', 'bookingextension_confirmation_supervisor'),
                0,
                $userprofilefieldsarray
            )
        );

        $adminroot->add('modbookingfolder', $settings);
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

    /**
     * A subplugin can implement it's own way to add ways to allow supervisors to approve requests on waitinglist.
     * If the first value in the aray is true, this means that the test was successful.
     *
     * @param int $optionid
     * @param int $approverid
     * @param int $userid
     *
     * @return array // Returns [false, 'Reason why you are not allowed to book']
     */
    public static function has_capability_to_confirm_booking(int $optionid, int $approverid, int $userid): array {

        $approved = false;
        $message = get_string('notallowedtoconfirm', 'bookingextension_confirmation_supervisor');
        $reload = false;

        return [$approved, $message, $reload];
    }
}

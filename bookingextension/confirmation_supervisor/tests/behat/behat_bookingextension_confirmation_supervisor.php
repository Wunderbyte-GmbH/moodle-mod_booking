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
 * Defines message providers (types of messages being sent)
 *
 * @package bookingextension_confirmation_supervisor
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Andrii Semenets
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

/**
 * To create booking specific behat scearios.
 */
class behat_bookingextension_confirmation_supervisor extends behat_base {
    /**
     * Create custom role
     * @Given /^I create custom role "(?P<rolename_string>(?:[^"]|\\")*)"$/
     * @param string $rolename
     * @return void
     */
    public function i_create_custom_role($rolename) {
        global $DB;

        $roleid = create_role($rolename, strtolower($rolename), 'Custom role: ' . $rolename);
        if (!$DB->record_exists('role_context_levels', ['roleid' => $roleid, 'contextlevel' => CONTEXT_SYSTEM])) {
            $DB->insert_record('role_context_levels', ['roleid' => $roleid, 'contextlevel' => CONTEXT_SYSTEM]);
        }
    }

    /**
     * Set userids as the config value in the plugin (typically - bookingextension_confirmation_supervisor)
     * // phpcs:ignore
     * @Given /^I set userids "(?P<userids_string>(?:[^"]|\\")*)" as config value "(?P<configname_string>(?:[^"]|\\")*)" in plugin "(?P<pluginname_string>(?:[^"]|\\")*)"$/
     *
     * @param string $userids
     * @param string $configname
     * @param string $pluginname
     * @return void
     */
    public function i_set_userids_as_config_value_in_plugin($userids, $configname, $pluginname) {
        global $DB;

        $usernames = explode(',', $userids);
        $users = [];
        foreach ($usernames as $username) {
            if ($id = $DB->get_field('user', 'id', ['username' => trim($username)])) {
                $users[] = $id;
            }
        }

        if (!empty($users)) {
            set_config($configname, implode(',', $users), $pluginname);
        } else {
            throw new \moodle_exception('No valid Moodle users found to be set into ' . $configname);
        }
    }

    /**
     * Set userids as the profilefield value (typically for bookingextension_confirmation_supervisor)
     * // phpcs:ignore
     * @Given /^I set userids "(?P<userids_string>(?:[^"]|\\")*)" as value of profilefield "(?P<configname_string>(?:[^"]|\\")*)" for user "(?P<targetusername_string>(?:[^"]|\\")*)"$/
     *
     * @param string $userids
     * @param string $profilefield
     * @param string $targetusername
     * @return void
     */
    public function i_set_userids_as_value_of_profilefield_for_user($userids, $profilefield, $targetusername) {
        global $DB;

        $usernames = explode(',', $userids);
        $users = [];
        foreach ($usernames as $username) {
            if ($id = $DB->get_field('user', 'id', ['username' => trim($username)])) {
                $users[] = $id;
            }
        }

        if ($targetuser = $DB->get_record('user', ['username' => trim($targetusername)])) {
            profile_load_custom_fields($targetuser);
            if (isset($targetuser->profile[strtolower($profilefield)])) {
                profile_save_custom_fields(
                    $targetuser->id,
                    [(strtolower($profilefield)) => (implode(',', $users))]
                );
            } else {
                throw new \moodle_exception('No profile field found with name ' . $profilefield);
            }
        } else {
            throw new \moodle_exception('No valid Moodle user found with username ' . $targetusername);
        }
    }
}

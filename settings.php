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
 * Global settings
 *
 * @package mod_booking
 * @copyright 2017 David Bogner, http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
if ($ADMIN->fulltree) {
    $settings->add(
            new admin_setting_configcheckbox('booking/attachical',
                    get_string('attachical', 'mod_booking'),
                    get_string('attachicaldesc', 'mod_booking'), 0));
    $bookingcfg = get_config('mod_booking');
    $number = 1;
    $cfgname = "customfield_$number";
    // Only increase number when value is set
    while (isset($bookingcfg->$cfgname) && !empty($bookingcfg->$cfgname)) {
        $number++;
        $cfgname = "customfield_$number";
    }

    for ($i = 1; $i <= $number; $i++) {
        $settings->add(
                new admin_setting_configtext('booking/customfield_' . $i,
                        get_string('customfield', 'mod_booking'),
                        get_string('customfielddesc', 'mod_booking'), ''));
    }
}
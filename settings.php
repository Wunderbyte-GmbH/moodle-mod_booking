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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Global settings
 *
 * @package mod_booking
 * @copyright 2017 David Bogner, http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$ADMIN->add('modsettings',
        new admin_category('modbookingfolder', new lang_string('pluginname', 'mod_booking'),
                $module->is_enabled() === false));
$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookingcustomfield',
                get_string('customfieldconfigure', 'mod_booking'),
                new moodle_url('/mod/booking/customfieldsettings.php', array('sesskey' => sesskey()))));
$ADMIN->add('modbookingfolder', $settings);

if ($ADMIN->fulltree) {
    $settings->add(
            new admin_setting_configcheckbox('booking/attachical',
                    get_string('attachical', 'mod_booking'),
                    get_string('attachicaldesc', 'mod_booking'), 0));
}
$settings = null;
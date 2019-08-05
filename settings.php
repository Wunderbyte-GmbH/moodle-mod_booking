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
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/profile/lib.php');

$ADMIN->add('modsettings',
        new admin_category('modbookingfolder', new lang_string('pluginname', 'mod_booking'),
                $module->is_enabled() === false));

$ADMIN->add('modbookingfolder', $settings);

$settings->add(
        new admin_setting_heading('mod_booking_icalcfg',
                get_string('icalcfg', 'mod_booking'),
                get_string('icalcfgdesc', 'mod_booking')));
if ($ADMIN->fulltree) {
    $settings->add(
            new admin_setting_configcheckbox('booking/attachical',
                    get_string('attachical', 'mod_booking'),
                    get_string('attachicaldesc', 'mod_booking'), 0));
    $settings->add(
            new admin_setting_configcheckbox('booking/multiicalfiles',
                    get_string('multiicalfiles', 'mod_booking'),
                    get_string('multiicalfilesdesc', 'mod_booking'), 0));
    $settings->add(
            new admin_setting_configcheckbox('booking/attachicalsessions',
                    get_string('attachicalsess', 'mod_booking'),
                    get_string('attachicalsessdesc', 'mod_booking'), 1));
    $settings->add(
            new admin_setting_configcheckbox('booking/icalcancel',
                    get_string('icalcancel', 'mod_booking'),
                    get_string('icalcanceldesc', 'mod_booking'), 1));
    $options = array(1 => get_string('courseurl', 'hub'), 2 => get_string('location', 'mod_booking'),
        3 => get_string('institution', 'mod_booking'), 4 => get_string('address'));
    $settings->add(
            new admin_setting_configselect('booking/icalfieldlocation',
                    get_string('icalfieldlocation', 'mod_booking'),
                    get_string('icalfieldlocationdesc', 'mod_booking'),
                    1, $options));

    $name = 'booking/googleapikey';
    $visiblename = get_string('googleapikey', 'mod_booking');
    $description = get_string('googleapikey_desc', 'mod_booking');
    $setting = new admin_setting_configtext($name, $visiblename, $description, '');
    $settings->add($setting);

    $settings->add(
            new admin_setting_heading('mod_booking_signinsheet',
                    get_string('cfgsignin', 'mod_booking'),
                    get_string('cfgsignin_desc', 'mod_booking')));

    $settings->add(
            new admin_setting_configcheckbox('booking/numberrows',
                    get_string('numberrows', 'mod_booking'),
                    get_string('numberrowsdesc', 'mod_booking'), 0));

    $name = 'booking/signinlogo';
    $title = get_string('signinlogoheader', 'mod_booking');
    $description = $title;
    $fileoptions = array('maxfiles' => 1, 'accepted_types' => array('image'));
    $setting = new admin_setting_configstoredfile($name, $title, $description,
            'mod_booking_signinlogo', 0, $fileoptions);
    $settings->add($setting);

    $name = 'booking/signinlogofooter';
    $title = get_string('signinlogofooter', 'mod_booking');
    $description = $title;
    $fileoptions = array('maxfiles' => 1, 'accepted_types' => array('image'));
    $setting = new admin_setting_configstoredfile($name, $title, $description,
            'mod_booking_signinlogo_footer', 0, $fileoptions);
    $settings->add($setting);

    $name = 'booking/custprofilefields';
    $visiblename = get_string('signincustfields', 'mod_booking');
    $description = get_string('signincustfields_desc', 'mod_booking');
    $profiles = profile_get_custom_fields();
    $choices = array_map(function ($object) {
        return $object->name;
    }, $profiles);
    if (!empty($choices)) {
        $setting = new admin_setting_configmulticheckbox($name, $visiblename, $description, array(),
                $choices);
        $settings->add($setting);
    }

    $name = 'booking/showcustfields';
    $visiblename = get_string('showcustomfields', 'mod_booking');
    $description = get_string('showcustomfields_desc', 'mod_booking');
    $customfields = \mod_booking\booking_option::get_customfield_settings();
    $choices = array();
    if (!empty($customfields)) {
        foreach ($customfields as $cfgname => $value) {
            $choices[$cfgname] = $value['value'];
        }
        $setting = new admin_setting_configmulticheckbox($name, $visiblename, $description, array(),
                $choices);
        $settings->add($setting);
    }

    $settings->add(
            new admin_setting_heading('mod_booking_signinheading',
                    get_string('signinextracols_heading', 'mod_booking'), ''));

    for ($i = 1; $i < 4; $i++) {
        $name = 'booking/signinextracols' . $i;
        $visiblename = get_string('signinextracols', 'mod_booking') . " $i";
        $description = get_string('signinextracols_desc', 'mod_booking') . " $i";
        $setting = new admin_setting_configtext($name, $visiblename, $description, '');
        $settings->add($setting);
    }

    $settings->add(
        new admin_setting_heading('optiontemplatessettings_heading',
                get_string('optiontemplatessettings', 'mod_booking'), ''));

        $alltemplates = array('' => get_string('dontuse', 'booking'));
        $alloptiontemplates = $DB->get_records('booking_options', array('bookingid' => 0), '', $fields = 'id, text', 0, 0);

    foreach ($alloptiontemplates as $key => $value) {
            $alltemplates[$value->id] = $value->text;
    }

    $settings->add(
             new admin_setting_configselect('booking/defaulttemplate',
                        get_string('defaulttemplate', 'mod_booking'),
                        get_string('defaulttemplatedesc', 'mod_booking'),
                        1, $alltemplates));
}
$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookingcustomfield',
                get_string('customfieldconfigure', 'mod_booking'),
                new moodle_url('/mod/booking/customfieldsettings.php')));
$settings = null;
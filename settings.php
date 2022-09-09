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

global $CFG, $ADMIN, $DB;

require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

use \mod_booking\utils\wb_payment;

$ADMIN->add('modsettings',
        new admin_category('modbookingfolder', new lang_string('pluginname', 'mod_booking'),
                $module->is_enabled() === false));

$ADMIN->add('modbookingfolder', $settings);

if ($ADMIN->fulltree) {
    $settings->add(
        new admin_setting_heading('licensekeycfgheading',
            get_string('licensekeycfg', 'mod_booking'),
            get_string('licensekeycfgdesc', 'mod_booking')));

    // Dynamically change the license info text.
    $licensekeydesc = get_string('licensekeydesc', 'mod_booking');

    // Get license key which has been set in text field.
    $pluginconfig = get_config('booking');
    if (!empty($pluginconfig->licensekey)) {
        $licensekey = $pluginconfig->licensekey;

        $expirationdate = wb_payment::decryptlicensekey($licensekey);
        if (!empty($expirationdate)) {
            $licensekeydesc = "<p style='color: green; font-weight: bold'>"
                .get_string('license_activated', 'mod_booking')
                .$expirationdate
                .")</p>";
        } else {
            $licensekeydesc = "<p style='color: red; font-weight: bold'>"
                .get_string('license_invalid', 'mod_booking')
                ."</p>";
        }
    }

    $settings->add(
        new admin_setting_configtext('booking/licensekey',
            get_string('licensekey', 'mod_booking'),
            $licensekeydesc, ''));

    $settings->add(
            new admin_setting_heading('mod_booking_icalcfg',
                get_string('icalcfg', 'mod_booking'),
                get_string('icalcfgdesc', 'mod_booking')));
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
    $options = array(1 => get_string('courseurl', 'mod_booking'), 2 => get_string('location', 'mod_booking'),
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

    $settings->add(
        new admin_setting_heading('availabilityinfotexts_heading',
            get_string('availabilityinfotexts_heading', 'mod_booking'),
            get_string('availabilityinfotexts_desc', 'mod_booking')));

    // PRO feature.
    if (wb_payment::is_currently_valid_licensekey()) {

        $settings->add(
            new admin_setting_configcheckbox('booking/bookingplacesinfotexts',
                get_string('bookingplacesinfotexts', 'mod_booking'),
                get_string('bookingplacesinfotexts_info', 'booking'), 0));

        $bookingplaceslowpercentages = [
            5 => ' 5%',
            10 => '10%',
            15 => '15%',
            20 => '20%',
            30 => '30%',
            40 => '40%',
            50 => '50%',
            60 => '60%',
            70 => '70%',
            80 => '80%',
            90 => '90%',
            100 => '100%'
        ];

        $settings->add(
            new admin_setting_configselect('booking/bookingplaceslowpercentage',
                get_string('bookingplaceslowpercentage', 'booking'),
                get_string('bookingplaceslowpercentagedesc', 'booking'),
                20, $bookingplaceslowpercentages));

        $settings->add(
            new admin_setting_configcheckbox('booking/waitinglistinfotexts',
                get_string('waitinglistinfotexts', 'mod_booking'),
                get_string('waitinglistinfotexts_info', 'booking'), 0));

        $waitinglistlowpercentages = [
            5 => ' 5%',
            10 => '10%',
            15 => '15%',
            20 => '20%',
            30 => '30%',
            40 => '40%',
            50 => '50%',
            60 => '60%',
            70 => '70%',
            80 => '80%',
            90 => '90%',
            100 => '100%'
        ];

        $settings->add(
            new admin_setting_configselect('booking/waitinglistlowpercentage',
                get_string('waitinglistlowpercentage', 'booking'),
                get_string('waitinglistlowpercentagedesc', 'booking'),
                20, $waitinglistlowpercentages));
    }

    $settings->add(
        new admin_setting_heading('globalmailtemplates_heading',
            get_string('globalmailtemplates', 'mod_booking'),
            get_string('globalmailtemplates_desc', 'mod_booking')));

    // PRO feature.
    if (wb_payment::is_currently_valid_licensekey()) {
        $settings->add(new admin_setting_confightmleditor('booking/globalbookedtext',
            get_string('globalbookedtext', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globalwaitingtext',
            get_string('globalwaitingtext', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globalnotifyemail',
            get_string('globalnotifyemail', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globalnotifyemailteachers',
            get_string('globalnotifyemailteachers', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globalstatuschangetext',
            get_string('globalstatuschangetext', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globaluserleave',
            get_string('globaluserleave', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globaldeletedtext',
            get_string('globaldeletedtext', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globalbookingchangedtext',
            get_string('globalbookingchangedtext', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globalpollurltext',
            get_string('globalpollurltext', 'booking'), '', ''));

        $settings->add(new admin_setting_confightmleditor('booking/globalpollurlteacherstext',
            get_string('globalpollurlteacherstext', 'booking'), '', ''));

        // TODO: globalactivitycompletiontext is currently not implemented because activitycompletiontext isn't either.
    }
}

$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookingcustomfield',
                get_string('customfieldconfigure', 'mod_booking'),
                new moodle_url('/mod/booking/customfieldsettings.php')));
$settings = null;

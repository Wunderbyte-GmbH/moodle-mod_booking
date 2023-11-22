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

global $CFG, $DB, $ADMIN, $DB;

require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

use mod_booking\price;
use mod_booking\utils\wb_payment;

$ADMIN->add('modsettings',
        new admin_category('modbookingfolder', new lang_string('pluginname', 'mod_booking'),
                $module->is_enabled() === false));

$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookinginstancetemplatessettings',
                get_string('bookinginstancetemplatessettings', 'mod_booking'),
                new moodle_url('/mod/booking/instancetemplatessettings.php')));

$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookingoptionformconfig',
                get_string('optionformconfig', 'mod_booking'),
                new moodle_url('/mod/booking/optionformconfig.php')));

$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookingpricecategories',
                get_string('pricecategories', 'mod_booking'),
                new moodle_url('/mod/booking/pricecategories.php')));

$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookingsemesters',
                get_string('booking:semesters', 'mod_booking'),
                new moodle_url('/mod/booking/semesters.php')));

$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookingcustomfield',
                get_string('customfieldconfigure', 'mod_booking'),
                new moodle_url('/mod/booking/customfield.php')));

$ADMIN->add('modbookingfolder',
        new admin_externalpage('modbookingeditrules',
                get_string('bookingrules', 'mod_booking'),
                new moodle_url('/mod/booking/edit_rules.php')));

$ADMIN->add('modbookingfolder',
new admin_externalpage('modbookingeditcampaigns',
        get_string('bookingcampaigns', 'mod_booking'),
        new moodle_url('/mod/booking/edit_campaigns.php')));

$ADMIN->add('modbookingfolder', $settings);

if ($ADMIN->fulltree) {

    // Has PRO version been activated?
    $proversion = wb_payment::pro_version_is_activated();

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

    // PRO feature: Appearance settings.
    if ($proversion) {

        $settings->add(
            new admin_setting_heading('appearancesettings',
                get_string('appearancesettings', 'mod_booking'),
                get_string('appearancesettings_desc', 'mod_booking')));

        // Turn off wunderbyte branding.
        $settings->add(
            new admin_setting_configcheckbox('booking/turnoffwunderbytelogo',
                    get_string('turnoffwunderbytelogo', 'mod_booking'),
                    get_string('turnoffwunderbytelogo_desc', 'mod_booking'), 0));

        $options = [
            1 => "1",
            2 => "2",
            3 => "3",
            4 => "4",
            5 => "5",
            6 => "6",
            7 => "7",
            8 => "8",
            9 => "9",
            10 => "10",
        ];

        $settings->add(
            new admin_setting_configselect('booking/collapseshowsettings',
                get_string('collapseshowsettings', 'mod_booking'),
                get_string('collapseshowsettings_desc', 'mod_booking'),
                2, $options));

        // Turn off wunderbyte branding.
        $settings->add(
            new admin_setting_configcheckbox('booking/turnoffmodals',
                    get_string('turnoffmodals', 'mod_booking'),
                    get_string('turnoffmodals_desc', 'mod_booking'), 0));

    } else {
        $settings->add(
            new admin_setting_heading('appearancesettings',
                get_string('appearancesettings', 'mod_booking'),
                get_string('infotext:prolicensenecessary', 'mod_booking')));
    }

    // PRO feature: Teacher settings.
    if ($proversion) {

        $settings->add(
            new admin_setting_heading('teachersettings',
                get_string('teachersettings', 'mod_booking'),
                get_string('teachersettings_desc', 'mod_booking')));

        $settings->add(
            new admin_setting_configcheckbox('booking/teacherslinkonteacher',
                get_string('teacherslinkonteacher', 'mod_booking'),
                get_string('teacherslinkonteacher_desc', 'mod_booking'), 1));

        $settings->add(
            new admin_setting_configcheckbox('booking/teachersnologinrequired',
                get_string('teachersnologinrequired', 'mod_booking'),
                get_string('teachersnologinrequired_desc', 'mod_booking'), 0));

        $settings->add(
            new admin_setting_configcheckbox('booking/teachersshowemails',
                get_string('teachersshowemails', 'mod_booking'),
                get_string('teachersshowemails_desc', 'mod_booking'), 0));

        $settings->add(
            new admin_setting_configcheckbox('booking/teachersallowmailtobookedusers',
                get_string('teachersallowmailtobookedusers', 'mod_booking'),
                get_string('teachersallowmailtobookedusers_desc', 'mod_booking'), 0));
    } else {
        $settings->add(
            new admin_setting_heading('teachersettings',
                get_string('teachersettings', 'mod_booking'),
                get_string('infotext:prolicensenecessary', 'mod_booking')));
    }

    // PRO feature: Cancellation settings.
    if ($proversion) {

        $settings->add(
            new admin_setting_heading('cancellationsettings',
                get_string('cancellationsettings', 'mod_booking'), ''));

        // Calculate canceluntil from semester start instead of booking options tart (coursestarttime).
        $settings->add(
            new admin_setting_configcheckbox('booking/cancelfromsemesterstart',
                    get_string('cancelfromsemesterstart', 'mod_booking'),
                    get_string('cancelfromsemesterstart_desc', 'mod_booking'), 0));

    } else {
        $settings->add(
            new admin_setting_heading('cancellationsettings',
                get_string('cancellationsettings', 'mod_booking'),
                get_string('infotext:prolicensenecessary', 'mod_booking')));
    }

    // Will be needed more than once. So initialize here.
    $customfieldsarray["-1"] = get_string('choose...', 'mod_booking');

    // PRO feature.
    if ($proversion) {

        // Global setting to allow overbooking.
        $settings->add(
            new admin_setting_heading('allowoverbookingheader',
                get_string('allowoverbookingheader', 'mod_booking'),
                get_string('allowoverbookingheader_desc', 'mod_booking')));

        $settings->add(
            new admin_setting_configcheckbox('booking/allowoverbooking',
                    get_string('allowoverbooking', 'mod_booking'), '', 0));

        /* Booking option custom field to be used as course category
        for automatically created courses. */
        $settings->add(
            new admin_setting_heading('newcoursecategorycfieldheading',
                get_string('automaticcoursecreation', 'mod_booking'),
                ''));

        $sql = "SELECT cff.name, cff.shortname FROM {customfield_category} cfc
        JOIN {customfield_field} cff ON cfc.id = cff.categoryid
        WHERE cfc.component = 'mod_booking'";

        $records = $DB->get_records_sql($sql);
        foreach ($records as $record) {
            $customfieldsarray[$record->shortname] = "$record->name ($record->shortname)";
        }
        $settings->add(
            new admin_setting_configselect('booking/newcoursecategorycfield',
                    get_string('newcoursecategorycfield', 'mod_booking'),
                    get_string('newcoursecategorycfielddesc', 'mod_booking'),
                    "-1", $customfieldsarray));
    } else {
        $settings->add(
            new admin_setting_heading('newcoursecategorycfieldheading',
                get_string('automaticcoursecreation', 'mod_booking'),
                get_string('infotext:prolicensenecessary', 'mod_booking')));
    }

    // Waiting list settings.
    $settings->add(
        new admin_setting_heading('waitinglistheader',
            get_string('waitinglistheader', 'mod_booking'),
            get_string('waitinglistheader_desc', 'mod_booking')));

    $settings->add(
        new admin_setting_configcheckbox('booking/turnoffwaitinglist',
                get_string('turnoffwaitinglist', 'mod_booking'),
                get_string('turnoffwaitinglist_desc', 'mod_booking'), 0));

    $settings->add(
        new admin_setting_configcheckbox('booking/turnoffwaitinglistaftercoursestart',
                get_string('turnoffwaitinglistaftercoursestart', 'mod_booking'), '', 0));

    // Notification list settings.
    $settings->add(
        new admin_setting_heading('notificationlist',
            get_string('notificationlist', 'mod_booking'),
            get_string('notificationlistdesc', 'mod_booking')));

    $settings->add(
        new admin_setting_configcheckbox('booking/usenotificationlist',
                get_string('usenotificationlist', 'mod_booking'), '', 0));

    $settings->add(
        new admin_setting_heading('educationalunitinminutes',
            get_string('educationalunitinminutes', 'mod_booking'), ''));

    $allowedlengthsofunit = [
        '60' => '60 min', // Default value.
        '55' => '55 min',
        '50' => '50 min',
        '45' => '45 min',
        '40' => '40 min',
    ];
    $settings->add(
        new admin_setting_configselect('booking/educationalunitinminutes',
            get_string('educationalunitinminutes', 'mod_booking'),
            get_string('educationalunitinminutes_desc', 'mod_booking'),
            '60', $allowedlengthsofunit));

    $settings->add(
        new admin_setting_heading('bookingpricesettings_heading',
            get_string('bookingpricesettings', 'mod_booking'),
            get_string('bookingpricesettings_desc', 'mod_booking')));

    $settings->add(
        new admin_setting_configcheckbox('booking/priceisalwayson',
                get_string('priceisalwayson', 'mod_booking'),
                get_string('priceisalwayson_desc', 'mod_booking'), 0));

    // Choose the user profile field which is used to store each user's price category.
    $userprofilefieldsarray[0] = get_string('userprofilefieldoff', 'mod_booking');
    $userprofilefields = profile_get_custom_fields();
    if (!empty($userprofilefields)) {
        $userprofilefieldsarray = [];
        // Create an array of key => value pairs for the dropdown.
        foreach ($userprofilefields as $userprofilefield) {
            $userprofilefieldsarray[$userprofilefield->shortname] = $userprofilefield->name;
        }
    }

    $settings->add(
        new admin_setting_configselect('booking/pricecategoryfield',
            get_string('pricecategoryfield', 'mod_booking'),
            get_string('pricecategoryfielddesc', 'mod_booking'),
            0, $userprofilefieldsarray));

    // Currency dropdown.
    $currenciesobjects = price::get_possible_currencies();

    $currencies['EUR'] = 'Euro (EUR)';
    foreach ($currenciesobjects as $currenciesobject) {
        $currencyidentifier = $currenciesobject->get_identifier();
        $currencies[$currencyidentifier] = $currenciesobject->out(current_language()) . ' (' . $currencyidentifier . ')';
    }

    $settings->add(
        new admin_setting_configselect('booking/globalcurrency',
            get_string('globalcurrency', 'booking'),
            get_string('globalcurrencydesc', 'booking'),
            'EUR', $currencies));

    $settings->add(
        new admin_setting_configcheckbox('booking/bookwithcreditsactive',
                get_string('bookwithcreditsactive', 'mod_booking'),
                get_string('bookwithcreditsactive_desc', 'mod_booking'), 0));

    $settings->add(
        new admin_setting_configselect('booking/bookwithcreditsprofilefield',
            get_string('bookwithcreditsprofilefield', 'mod_booking'),
            get_string('bookwithcreditsprofilefield_desc', 'mod_booking'),
            0, $userprofilefieldsarray));

    $settings->add(
        new admin_setting_configselect('booking/cfcostcenter',
            get_string('cfcostcenter', 'mod_booking'),
            get_string('cfcostcenter_desc', 'mod_booking'),
            "-1", $customfieldsarray));


    // PRO feature: Progress bars.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading('priceformulaheader',
                get_string('priceformulaheader', 'mod_booking'),
                get_string('priceformulaheader_desc', 'mod_booking')));

        $settings->add(
            new admin_setting_configtextarea('booking/defaultpriceformula',
                get_string('defaultpriceformula', 'booking'),
                get_string('defaultpriceformuladesc', 'booking'), '', PARAM_TEXT, 60, 10));

        $settings->add(
            new admin_setting_configcheckbox('booking/applyunitfactor',
                    get_string('applyunitfactor', 'mod_booking'),
                    get_string('applyunitfactor_desc', 'mod_booking'), 1));

        $settings->add(
            new admin_setting_configcheckbox('booking/roundpricesafterformula',
                    get_string('roundpricesafterformula', 'mod_booking'),
                    get_string('roundpricesafterformula_desc', 'mod_booking'), 1));
    } else {
        $settings->add(
            new admin_setting_heading('priceformulaheader',
                get_string('priceformulaheader', 'mod_booking'),
                get_string('infotext:prolicensenecessary', 'mod_booking')));
    }

    $settings->add(
        new admin_setting_heading('duplicationrestore',
            get_string('duplicationrestore', 'mod_booking'),
            get_string('duplicationrestoredesc', 'mod_booking')));

    $settings->add(
        new admin_setting_configcheckbox('booking/duplicationrestoreteachers',
                get_string('duplicationrestoreteachers', 'mod_booking'), '', 1));

    $settings->add(
        new admin_setting_configcheckbox('booking/duplicationrestoreprices',
                get_string('duplicationrestoreprices', 'mod_booking'), '', 1));

    $settings->add(
        new admin_setting_configcheckbox('booking/duplicationrestoreentities',
                get_string('duplicationrestoreentities', 'mod_booking'), '', 1));

    if (wb_payment::pro_version_is_activated()) {
        $settings->add(
            new admin_setting_configcheckbox('booking/duplicationrestoresubbookings',
                    get_string('duplicationrestoresubbookings', 'mod_booking'), '', 1));
    }

    $settings->add(
        new admin_setting_heading('optiontemplatessettings_heading',
                get_string('optiontemplatessettings', 'mod_booking'), ''));

    $alltemplates = ['0' => get_string('dontuse', 'booking')];
    $alloptiontemplates = $DB->get_records('booking_options', ['bookingid' => 0], '', $fields = 'id, text', 0, 0);

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
            ''));

    // PRO feature.
    if ($proversion) {

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
            100 => '100%',
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
            100 => '100%',
        ];

        $settings->add(
            new admin_setting_configselect('booking/waitinglistlowpercentage',
                get_string('waitinglistlowpercentage', 'booking'),
                get_string('waitinglistlowpercentagedesc', 'booking'),
                20, $waitinglistlowpercentages));
    }

    // PRO feature: Subbookings.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading('subbookings',
                get_string('subbookings', 'mod_booking'),
                get_string('subbookings_desc', 'mod_booking')));

        $settings->add(
            new admin_setting_configcheckbox('booking/showsubbookings',
                    get_string('showsubbookings', 'mod_booking'), '', 0));
    } else {
        $settings->add(
            new admin_setting_heading('subbookings',
                get_string('subbookings', 'mod_booking'),
                get_string('infotext:prolicensenecessary', 'mod_booking')));
    }

    // PRO feature: Booking actions.
    // Booking actions are not yet finished, so we do not show them yet.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading('boactions',
                get_string('boactions', 'mod_booking'),
                get_string('boactions_desc', 'mod_booking')));

        $settings->add(
            new admin_setting_configcheckbox('booking/showboactions',
                    get_string('showboactions', 'mod_booking'), '', 0));
    } else {
        $settings->add(
            new admin_setting_heading('boactions',
                get_string('boactions', 'mod_booking'),
                get_string('infotext:prolicensenecessary', 'mod_booking')));
    }

    // PRO feature: Progress bars.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading('progressbars',
                get_string('progressbars', 'mod_booking'),
                get_string('progressbars_desc', 'mod_booking')));

        $settings->add(
            new admin_setting_configcheckbox('booking/showprogressbars',
                    get_string('showprogressbars', 'mod_booking'), '', 0));

        $settings->add(
            new admin_setting_configcheckbox('booking/progressbarscollapsible',
                    get_string('progressbarscollapsible', 'mod_booking'), '', 1));
    } else {
        $settings->add(
            new admin_setting_heading('progressbars',
                get_string('progressbars', 'mod_booking'),
                get_string('infotext:prolicensenecessary', 'mod_booking')));
    }

    $settings->add(
        new admin_setting_heading('mod_booking_icalcfg',
            get_string('icalcfg', 'mod_booking'),
            get_string('icalcfgdesc', 'mod_booking')));

    $settings->add(
        new admin_setting_configcheckbox('booking/dontaddpersonalevents',
                get_string('dontaddpersonalevents', 'mod_booking'),
                get_string('dontaddpersonaleventsdesc', 'mod_booking'), 0));
                $settings->add(
            new admin_setting_configcheckbox('booking/attachical',
                    get_string('attachical', 'mod_booking'),
                    get_string('attachicaldesc', 'mod_booking'), 0));
    $settings->add(
            new admin_setting_configcheckbox('booking/attachicalsessions',
                    get_string('attachicalsess', 'mod_booking'),
                    get_string('attachicalsessdesc', 'mod_booking'), 1));
    $settings->add(
            new admin_setting_configcheckbox('booking/icalcancel',
                    get_string('icalcancel', 'mod_booking'),
                    get_string('icalcanceldesc', 'mod_booking'), 1));
    $options = [1 => get_string('courseurl', 'mod_booking'),
                2 => get_string('location', 'mod_booking'),
                3 => get_string('institution', 'mod_booking'), 4 => get_string('address'),
            ];
    $settings->add(
            new admin_setting_configselect('booking/icalfieldlocation',
                    get_string('icalfieldlocation', 'mod_booking'),
                    get_string('icalfieldlocationdesc', 'mod_booking'),
                    1, $options));

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
    $fileoptions = ['maxfiles' => 1, 'accepted_types' => ['image']];
    $setting = new admin_setting_configstoredfile($name, $title, $description,
            'mod_booking_signinlogo', 0, $fileoptions);
    $settings->add($setting);

    $name = 'booking/signinlogofooter';
    $title = get_string('signinlogofooter', 'mod_booking');
    $description = $title;
    $fileoptions = ['maxfiles' => 1, 'accepted_types' => ['image']];
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
        $setting = new admin_setting_configmulticheckbox($name, $visiblename, $description, [],
                $choices);
        $settings->add($setting);
    }

    $name = 'booking/showcustfields';
    $visiblename = get_string('showcustomfields', 'mod_booking');
    $description = get_string('showcustomfields_desc', 'mod_booking');
    $customfields = \mod_booking\booking_option::get_customfield_settings();
    $choices = [];
    if (!empty($customfields)) {
        foreach ($customfields as $cfgname => $value) {
            $choices[$cfgname] = $value['value'];
        }
        $setting = new admin_setting_configmulticheckbox($name, $visiblename, $description, [],
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

    // Global mail templates (PRO).
    $settings->add(
        new admin_setting_heading('globalmailtemplates_heading',
            get_string('globalmailtemplates', 'mod_booking'),
            get_string('globalmailtemplates_desc', 'mod_booking')));
    if ($proversion) {
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

$settings = null;

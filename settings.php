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
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\customfield\booking_handler;

defined('MOODLE_INTERNAL') || die();

global $CFG, $DB, $ADMIN, $DB;

require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

use mod_booking\booking;
use mod_booking\plugininfo\bookingextension_interface;
use mod_booking\local\checkanswers\checkanswers;
use mod_booking\price;
use mod_booking\utils\wb_payment;

/** @var \admin_settingpage $settings */
$settings;

$handler = booking_handler::create();
echo $handler->check_for_forbidden_shortnames_and_return_warning();

$ADMIN->add(
    'modsettings',
    new admin_category(
        'modbookingfolder',
        new lang_string(
            'pluginname',
            'mod_booking'
        ),
        $module->is_enabled() === false
    )
);

$ADMIN->add(
    'modbookingfolder',
    new admin_externalpage(
        'modbookinginstancetemplatessettings',
        get_string(
            'bookinginstancetemplatessettings',
            'mod_booking'
        ),
        new moodle_url('/mod/booking/instancetemplatessettings.php')
    )
);

$ADMIN->add(
    'modbookingfolder',
    new admin_externalpage(
        'modbookingoptionformconfig',
        get_string(
            'booking',
            'mod_booking'
        ) . ": " .
        get_string('optionformconfig', 'mod_booking'),
        new moodle_url('/mod/booking/optionformconfig.php', [
            'cmid' => 0,
        ])
    )
);

$ADMIN->add(
    'modbookingfolder',
    new admin_externalpage(
        'modbookingpricecategories',
        get_string('pricecategories', 'mod_booking'),
        new moodle_url('/mod/booking/pricecategories.php')
    )
);

$ADMIN->add(
    'modbookingfolder',
    new admin_externalpage(
        'modbookingsemesters',
        get_string('booking:semesters', 'mod_booking'),
        new moodle_url('/mod/booking/semesters.php')
    )
);

$ADMIN->add(
    'modbookingfolder',
    new admin_externalpage(
        'modbookingcustomfield',
        get_string('customfieldconfigure', 'mod_booking'),
        new moodle_url('/mod/booking/customfield.php')
    )
);

$ADMIN->add(
    'modbookingfolder',
    new admin_externalpage(
        'modbookingeditrules',
        get_string('bookingrules', 'mod_booking'),
        new moodle_url('/mod/booking/edit_rules.php')
    )
);

$ADMIN->add(
    'modbookingfolder',
    new admin_externalpage(
        'modbookingeditcampaigns',
        get_string('bookingcampaigns', 'mod_booking'),
        new moodle_url('/mod/booking/edit_campaigns.php')
    )
);

// Load all settings from booking extensions.
foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
    $fullclassname = "\\bookingextension_{$plugin->name}\\{$plugin->name}";
    if (!class_exists($fullclassname)) {
        continue; // Skip if the class does not exist.
    }
    $plugin = new $fullclassname();
    if (!$plugin instanceof bookingextension_interface) {
        continue; // Skip if the plugin does not implement the interface.
    }
    $plugin->load_settings($ADMIN, 'modbookingfolder', $hassiteconfig);
}

$ADMIN->add('modbookingfolder', $settings);

if ($ADMIN->fulltree) {
    $notsupported = false;
    $version = $CFG->version;
    switch ($version) {
        // Moodle 4.0 - Absolutely not supported. ok.
        case ($version < 2022042000):
            $notsupported = true;
            break;
        // Moodle 4.1 - Not supported without patch. ok.
        case ($version < 2022112900):
            if ($version < 2022112801) {
                $notsupported = true;
            }
            break;
        // Moodle 4.2 - Not supported without patch. ok.
        case ($version < 2023042500):
            if ($version < 2023042401) {
                $notsupported = true;
            }
            break;
        default:
            // Moodle 4.3+ - Fully supported.
            $notsupported = false;
    }

    if ($notsupported) {
        $settings->add(
            new admin_setting_heading(
                'installmoodlebugfix',
                get_string('installmoodlebugfix', 'mod_booking'),
                get_string('infotext:installmoodlebugfix', 'mod_booking')
            )
        );
    }

    // Has PRO version been activated?
    $proversion = wb_payment::pro_version_is_activated();

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
        new admin_setting_heading(
            'licensekeycfgheading',
            get_string('licensekeycfg', 'mod_booking'),
            $proversion ? get_string('licensekeycfgdesc:active', 'mod_booking') :
            get_string('licensekeycfgdesc', 'mod_booking')
        )
    );

    // Dynamically change the license info text.
    $licensekeydesc = get_string('licensekeydesc', 'mod_booking');

    // Get license key which has been set in text field.
    $pluginconfig = get_config('booking');
    if (!empty($pluginconfig->licensekey)) {
        $licensekey = $pluginconfig->licensekey;

        $expirationdate = wb_payment::decryptlicensekey($licensekey);
        if (!empty($expirationdate)) {
            $licensekeydesc = "<p style='color: green; font-weight: bold'>"
                . get_string('licenseactivated', 'mod_booking')
                . $expirationdate
                . ")</p>";
        } else {
            $licensekeydesc = "<p style='color: red; font-weight: bold'>"
                . get_string('licenseinvalid', 'mod_booking')
                . "</p>";
        }
    }

    $settings->add(
        new admin_setting_configtext(
            'booking/licensekey',
            get_string('licensekey', 'mod_booking'),
            $licensekeydesc,
            ''
        )
    );

    // PRO feature: Appearance settings.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'appearancesettings',
                get_string('appearancesettings', 'mod_booking'),
                get_string('appearancesettings_desc', 'mod_booking')
            )
        );

        // Turn off wunderbyte branding.
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/turnoffwunderbytelogo',
                get_string('turnoffwunderbytelogo', 'mod_booking'),
                get_string('turnoffwunderbytelogo_desc', 'mod_booking'),
                0
            )
        );

        // Collapse descriptions.
        $collapsedescriptionoptions = [
            0 => get_string('collapsedescriptionoff', 'mod_booking'),
            100 => "100",
            125 => "125",
            150 => "150",
            175 => "175",
            200 => "200",
            300 => "300",
            400 => "400",
            500 => "500",
            600 => "600",
            700 => "700",
            800 => "800",
            900 => "900",
        ];
        $settings->add(
            new admin_setting_configselect(
                'booking/collapsedescriptionmaxlength',
                get_string('collapsedescriptionmaxlength', 'mod_booking'),
                get_string('collapsedescriptionmaxlength_desc', 'mod_booking'),
                300,
                $collapsedescriptionoptions
            )
        );

        $description = $collapsedescriptionoptions;
        $description[0] = get_string('nodescriptionmaxlength', 'mod_booking');
        $settings->add(
            new admin_setting_configselect(
                'booking/descriptionmaxlength',
                get_string('descriptionmaxlength', 'mod_booking'),
                get_string('descriptionmaxlength_desc', 'mod_booking'),
                0,
                $description
            )
        );

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
            new admin_setting_configselect(
                'booking/collapseshowsettings',
                get_string('collapseshowsettings', 'mod_booking'),
                get_string('collapseshowsettings_desc', 'mod_booking'),
                2,
                $options
            )
        );

        // Show extra information (custom fields, comments...) for optiondates in the booking options overview list.
        $showoptiondatesextrainfo = new admin_setting_configcheckbox(
            'booking/showoptiondatesextrainfo',
            get_string('showoptiondatesextrainfo', 'mod_booking'),
            get_string('showoptiondatesextrainfo_desc', 'mod_booking'),
            0
        );
        $showoptiondatesextrainfo->set_updatedcallback(function () {
            cache_helper::purge_by_event('setbackencodedtables');
            cache_helper::purge_by_event('changesinwunderbytetable');
        });
        $settings->add($showoptiondatesextrainfo);

        // Setting to change steps in timeselector to 5 minutes.
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/timeintervalls',
                get_string('timeintervalls', 'mod_booking'),
                get_string('timeintervalls_desc', 'mod_booking'),
                0
            )
        );

        // Turn off modals.
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/turnoffmodals',
                get_string('turnoffmodals', 'mod_booking'),
                get_string('turnoffmodals_desc', 'mod_booking'),
                0
            )
        );

        // Choose which presence options should be vailabile.

        $presenceoptions = [
            5 => get_string('statusunknown', 'booking'),
            6 => get_string('statusattending', 'booking'),
            1 => get_string('statuscomplete', 'booking'),
            2 => get_string('statusincomplete', 'booking'),
            3 => get_string('statusnoshow', 'booking'),
            4 => get_string('statusfailed', 'booking'),
            7 => get_string('statusexcused', 'booking'),
        ];

        $settings->add(
            new admin_setting_configmultiselect(
                'booking/presenceoptions',
                get_string('presenceoptions', 'booking'),
                get_string('presenceoptions_desc', 'booking'),
                [5, 6, 1, 2, 3, 4, 7],
                $presenceoptions
            )
        );

        if (class_exists('local_shopping_cart\shopping_cart')) {
            // Display shoppingcart history.
            $settings->add(
                new admin_setting_configcheckbox(
                    'booking/displayshoppingcarthistory',
                    get_string('displayshoppingcarthistory', 'mod_booking'),
                    get_string('displayshoppingcarthistory_desc', 'mod_booking'),
                    1
                )
            );
        }
    } else {
        $settings->add(
            new admin_setting_heading(
                'appearancesettings',
                get_string('appearancesettings', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:appearance', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // General settings.
    $settings->add(
        new admin_setting_heading(
            'generalsettings',
            get_string('generalsettings', 'mod_booking'),
            ''
        )
    );

    // Custom fields to be shown on detail page (optionview.php).
    $customfields = booking_handler::get_customfields();
    if (!empty($customfields)) {
        $customfieldshortnames = [];
        foreach ($customfields as $cf) {
            $customfieldshortnames[$cf->shortname] = "$cf->name ($cf->shortname)";
        }
        $settings->add(
            new admin_setting_configmultiselect(
                'booking/optionviewcustomfields',
                get_string('optionviewcustomfields', 'mod_booking'),
                get_string('optionviewcustomfieldsdesc', 'mod_booking'),
                [],
                $customfieldshortnames
            )
        );
    }

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/alloptionsinreport',
            get_string('alloptionsinreport', 'mod_booking'),
            get_string('alloptionsinreportdesc', 'mod_booking'),
            0
        )
    );

    // If the user has the pro version, add a normal checkbox.
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    /* if ($proversion) {
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/alloptionsinreport',
                get_string('alloptionsinreport', 'mod_booking'),
                get_string('alloptionsinreportdesc', 'mod_booking'),
                0
            )
        );
    } else {
        For non-pro users, render a disabled checkbox.
        $settings->add(
            new admin_setting_configempty(
                'booking/alloptionsinreport_disabled',
                get_string('alloptionsinreport', 'mod_booking'),
                '<input type="checkbox" disabled="disabled" /> ' . get_string('alloptionsinreportdesc', 'mod_booking')
            )
        );
    } */

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/responsiblecontactcanedit',
            get_string('responsiblecontactcanedit', 'mod_booking'),
            get_string('responsiblecontactcanedit_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/responsiblecontactenroltocourse',
            get_string('responsiblecontactenroltocourse', 'mod_booking'),
            get_string('responsiblecontactenroltocourse_desc', 'mod_booking'),
            0
        )
    );

    $courseroleids = [0 => ''];
    $allrolenames = role_get_names();
    $assignableroles = get_roles_for_contextlevels(CONTEXT_COURSE);
    foreach ($allrolenames as $value) {
        if (in_array($value->id, $assignableroles)) {
            $courseroleids[$value->id] = $value->localname;
        }
    }

    $settings->add(
        new admin_setting_configselect(
            'booking/definedresponsiblecontactrole',
            get_string('definedresponsiblecontactrole', 'mod_booking'),
            get_string('definedresponsiblecontactrole_desc', 'mod_booking'),
            0,
            $courseroleids
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/maxperuserdontcountpassed',
            get_string('maxperuserdontcountpassed', 'mod_booking'),
            get_string('maxperuserdontcountpassed_desc', 'mod_booking'),
            1
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/maxperuserdontcountcompleted',
            get_string('maxperuserdontcountcompleted', 'mod_booking'),
            get_string('maxperuserdontcountcompleted_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/maxperuserdontcountnoshow',
            get_string('maxperuserdontcountnoshow', 'mod_booking'),
            get_string('maxperuserdontcountnoshow_desc', 'mod_booking'),
            1
        )
    );
    $customfields = booking_handler::get_customfields();
    if (!empty($customfields)) {
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/maxoptionsfromcategory',
                get_string('maxoptionsfromcategory', 'mod_booking'),
                get_string('maxoptionsfromcategorydesc', 'mod_booking'),
                0
            )
        );
        $maxoptionsfromcategory = get_config('booking', 'maxoptionsfromcategory') == 1;
        if ($maxoptionsfromcategory) {
            $customfieldshortnames = [];
            foreach ($customfields as $cf) {
                $name = format_string($cf->name);
                $customfieldshortnames[$cf->shortname] = "$name ($cf->shortname)";
            }
            $settings->add(
                new admin_setting_configselect(
                    'booking/maxoptionsfromcategoryfield',
                    get_string('maxoptionsfromcategoryfield', 'mod_booking'),
                    get_string('maxoptionsfromcategoryfielddesc', 'mod_booking'),
                    '',
                    $customfieldshortnames
                )
            );
        }
    }

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/displayloginbuttonforbookingoptions',
            get_string('displayloginbuttonforbookingoptions', 'mod_booking'),
            get_string('displayloginbuttonforbookingoptions_desc', 'mod_booking'),
            1
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/bookonlyondetailspage',
            get_string('bookonlyondetailspage', 'mod_booking'),
            get_string('bookonlyondetailspage_desc', 'mod_booking'),
            0
        )
    );

    if (get_config('booking', 'bookonlyondetailspage')) {
        // Display of detail dots is only enabled for options bookable on detailspage.
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/showdetaildotsnextbookedalert',
                get_string('showdetaildotsnextbookedalert', 'mod_booking'),
                get_string('showdetaildotsnextbookedalert_desc', 'mod_booking'),
                0
            )
        );
    }

    $coloroptions = [
        'primary' => get_string('cdo:buttoncolor:primary', 'mod_booking'),
        'secondary' => get_string('cdo:buttoncolor:secondary', 'mod_booking'),
        'success' => get_string('cdo:buttoncolor:success', 'mod_booking'),
        'warning' => get_string('cdo:buttoncolor:warning', 'mod_booking'),
        'danger' => get_string('cdo:buttoncolor:danger', 'mod_booking'),
    ];

    $settings->add(
        new admin_setting_configselect(
            'booking/loginbuttonforbookingoptionscoloroptions',
            get_string('loginbuttonforbookingoptionscoloroptions', 'mod_booking'),
            get_string('loginbuttonforbookingoptionscoloroptions_desc', 'mod_booking'),
            'primary',
            $coloroptions
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/linktomoodlecourseonbookedbutton',
            get_string('linktomoodlecourseonbookedbutton', 'mod_booking'),
            get_string('linktomoodlecourseonbookedbutton', 'mod_booking'),
            1
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/conditionsoverwritingbillboard',
            get_string('conditionsoverwritingbillboard', 'mod_booking'),
            get_string('conditionsoverwritingbillboard_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/bookonlyondetailspage',
            get_string('bookonlyondetailspage', 'mod_booking'),
            get_string('bookonlyondetailspage_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/openbookingdetailinsametab',
            get_string('openbookingdetailinsametab', 'mod_booking'),
            get_string('openbookingdetailinsametab_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/showbookingdetailstoall',
            get_string('showbookingdetailstoall', 'mod_booking'),
            get_string('showbookingdetailstoall_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/redirectonlogintocourse',
            get_string('redirectonlogintocourse', 'mod_booking'),
            get_string('redirectonlogintocourse_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/automaticbookingoptioncompletion',
            get_string('automaticbookingoptioncompletion', 'mod_booking'),
            get_string('automaticbookingoptioncompletion_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/bookingdebugmode',
            get_string('bookingdebugmode', 'mod_booking'),
            get_string('bookingdebugmode_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/shortcodesoff',
            get_string('shortcodesoff', 'mod_booking'),
            get_string('shortcodesoff_desc', 'mod_booking'),
            0
        )
    );
    if ($proversion) {
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/certificateon',
                get_string('certificateon', 'mod_booking'),
                get_string('certificateon_desc', 'mod_booking'),
                0,
            )
        );
        if (get_config('booking', 'certificateon')) {
            $settings->add(
                new admin_setting_configselect(
                    'booking/presencestatustoissuecertificate',
                    get_string('presencestatustoissuecertificate', 'mod_booking'),
                    get_string('presencestatustoissuecertificate_desc', 'mod_booking'),
                    0,
                    booking::get_possible_presences(true)
                )
            );
        }
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/usecompetencies',
                get_string('usecompetencies', 'mod_booking'),
                get_string('usecompetencies_desc', 'mod_booking'),
                0
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/restrictavailabilityforinstance',
                get_string('restrictavailabilityforinstance', 'mod_booking'),
                get_string('restrictavailabilityforinstance_desc', 'mod_booking'),
                0
            )
        );
        // PRO feature: "What's new" tab.
        $settings->add(
            new admin_setting_heading(
                'tabwhatsnewheading',
                get_string('tabwhatsnew', 'mod_booking') . " " . get_string('badge:pro', 'mod_booking'),
                get_string('tabwhatsnew_desc', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/tabwhatsnew',
                get_string('tabwhatsnew', 'mod_booking'),
                '',
                0
            )
        );
        $tabwhatsnewdaysarr = array_combine(range(0, 365), array_map('strval', range(0, 365)));
        $settings->add(
            new admin_setting_configselect(
                'booking/tabwhatsnewdays',
                get_string('tabwhatsnewdays', 'mod_booking'),
                get_string('tabwhatsnewdays_desc', 'mod_booking'),
                30,
                $tabwhatsnewdaysarr
            )
        );
        // PRO feature: Bookings tracker.
        $settings->add(
            new admin_setting_heading(
                'bookingstrackerheading',
                get_string('bookingstracker', 'mod_booking')
                    . " " . get_string('badge:pro', 'mod_booking'),
                ""
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/bookingstracker',
                get_string('bookingstracker', 'mod_booking'),
                get_string('bookingstracker_desc', 'mod_booking'),
                0
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/bookingstrackerpresencecounter',
                get_string('bookingstrackerpresencecounter', 'mod_booking'),
                get_string('bookingstrackerpresencecounter_desc', 'mod_booking'),
                0
            )
        );
        $settings->add(
            new admin_setting_configselect(
                'booking/bookingstrackerpresencecountervaluetocount',
                get_string('bookingstrackerpresencecountervaluetocount', 'mod_booking'),
                get_string('bookingstrackerpresencecountervaluetocount_desc', 'mod_booking'),
                0,
                booking::get_possible_presences(true)
            )
        );
        // PRO feature: Teacher settings.
        $settings->add(
            new admin_setting_heading(
                'teachersettings',
                get_string('teachersettings', 'mod_booking'),
                get_string('teachersettings_desc', 'mod_booking')
            )
        );
        // Reduce teachers selection to those with a specific user profile field.
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/selectteacherswithprofilefieldonly',
                get_string('selectteacherswithprofilefieldonly', 'mod_booking'),
                get_string('selectteacherswithprofilefieldonlydesc', 'mod_booking'),
                0
            )
        );
        if (get_config('booking', 'selectteacherswithprofilefieldonly')) {
            // Custom user profile field which defines teachers of booking options.
            $settings->add(
                new admin_setting_configselect(
                    'booking/selectteacherswithprofilefieldonlyfield',
                    get_string('selectteacherswithprofilefieldonlyfield', 'mod_booking'),
                    '',
                    0,
                    $userprofilefieldsarray
                )
            );
            // Value of custom user profile field. Can also be a list of comma-separated values.
            $settings->add(
                new admin_setting_configtext(
                    'booking/selectteacherswithprofilefieldonlyvalue',
                    get_string('selectteacherswithprofilefieldonlyvalue', 'mod_booking'),
                    get_string('selectteacherswithprofilefieldonlyvaluedesc', 'mod_booking'),
                    '',
                    PARAM_TEXT
                )
            );
        }

        $settings->add(
            new admin_setting_configcheckbox(
                'booking/teacherslinkonteacher',
                get_string('teacherslinkonteacher', 'mod_booking'),
                get_string('teacherslinkonteacher_desc', 'mod_booking'),
                1
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/teachersnologinrequired',
                get_string('teachersnologinrequired', 'mod_booking'),
                get_string('teachersnologinrequired_desc', 'mod_booking'),
                0
            )
        );
        $records = $DB->get_records_sql("SELECT b.id, b.name FROM {booking} b ORDER BY b.name");
        if (empty($records)) {
            $bookinginstances[0] = get_string('nobookinginstancesexist', 'mod_booking');
        } else {
            $bookinginstances[0] = get_string('noselection', 'mod_booking');
            foreach ($records as $record) {
                $bookinginstances[$record->id] = "$record->name ($record->id)";
            }
        }
        $settings->add(
            new admin_setting_configmultiselect(
                'booking/allteacherspagebookinginstances',
                get_string('allteacherspagebookinginstances', 'mod_booking'),
                '',
                [0],
                $bookinginstances
            )
        );
        $settings->add(
            new admin_setting_configmultiselect(
                'booking/teacherpageshiddenbookingids',
                get_string('teacherpageshiddenbookingids', 'mod_booking'),
                '',
                [0],
                $bookinginstances
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/teachersshowemails',
                get_string('teachersshowemails', 'mod_booking'),
                get_string('teachersshowemails_desc', 'mod_booking'),
                0
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/bookedteachersshowemails',
                get_string('bookedteachersshowemails', 'mod_booking'),
                get_string('bookedteachersshowemails_desc', 'mod_booking'),
                0
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/teachersallowmailtobookedusers',
                get_string('teachersallowmailtobookedusers', 'mod_booking'),
                get_string('teachersallowmailtobookedusers_desc', 'mod_booking'),
                0
            )
        );

        $settings->add(
            new admin_setting_configselect(
                'booking/definedteacherrole',
                get_string('definedteacherrole', 'mod_booking'),
                get_string('definedteacherrole_desc', 'mod_booking'),
                0,
                $courseroleids
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'tabwhatsnew',
                get_string('tabwhatsnew', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:tabwhatsnew', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_heading(
                'bookingstrackerheading',
                get_string('bookingstracker', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:bookingstracker', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_heading(
                'teachersettings',
                get_string('teachersettings', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:teachers', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // PRO feature: Cancellation settings.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'cancellationsettings',
                get_string('cancellationsettings', 'mod_booking'),
                ''
            )
        );

        // Calculate canceluntil from chosen date field.
        $canceldependentonarr = [
            'coursestarttime' => get_string('cdo:coursestarttime', 'mod_booking'),
            'semesterstart' => get_string('cdo:semesterstart', 'mod_booking'),
            'bookingopeningtime' => get_string('cdo:bookingopeningtime', 'mod_booking'),
            'bookingclosingtime' => get_string('cdo:bookingclosingtime', 'mod_booking'),
        ];
        $settings->add(
            new admin_setting_configselect(
                'booking/canceldependenton',
                get_string('canceldependenton', 'mod_booking'),
                get_string('canceldependenton_desc', 'mod_booking'),
                'coursestarttime',
                $canceldependentonarr
            )
        );
        $settings->add(
            new admin_setting_configtext(
                'booking/coolingoffperiod',
                get_string('coolingoffperiod', 'mod_booking'),
                get_string('coolingoffperiod_desc', 'mod_booking'),
                0,
                PARAM_INT
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'cancellationsettings',
                get_string('cancellationsettings', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:cancellationsettings', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // Will be needed more than once. So initialize here.
    $customfieldsarray["-1"] = get_string('choose...', 'mod_booking');

    // Pro feature: Overbooking of booking options.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'allowoverbookingheader',
                get_string('allowoverbookingheader', 'mod_booking'),
                get_string('allowoverbookingheader_desc', 'mod_booking')
            )
        );

        $settings->add(
            new admin_setting_configcheckbox(
                'booking/allowoverbooking',
                get_string('allowoverbooking', 'mod_booking'),
                '',
                0
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'allowoverbookingheader',
                get_string('allowoverbookingheader', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:overbooking', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // PRO feature: Unenrol users without access.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'unenroluserswithoutaccessheader',
                get_string('unenroluserswithoutaccess', 'mod_booking') . " " . get_string('badge:pro', 'mod_booking'),
                get_string('unenroluserswithoutaccessheader_desc', 'mod_booking')
            )
        );
        // Additional safety, only after activation of the first checkbox, the second one will be shown.
        $unenroluserswithoutaccessareyousure = new admin_setting_configcheckbox(
            'booking/unenroluserswithoutaccessareyousure',
            get_string('unenroluserswithoutaccessareyousure', 'mod_booking'),
            get_string('unenroluserswithoutaccessareyousure_desc', 'mod_booking'),
            0
        );
        $settings->add($unenroluserswithoutaccessareyousure);
        if (get_config('booking', 'unenroluserswithoutaccessareyousure')) {
            // Unenrol users without access.
            $unenroluserswithoutaccess = new admin_setting_configcheckbox(
                'booking/unenroluserswithoutaccess',
                get_string('unenroluserswithoutaccess', 'mod_booking'),
                get_string('unenroluserswithoutaccess_desc', 'mod_booking'),
                0
            );
            // Make sure, we immediately start this task when the checkbox is activated.
            $unenroluserswithoutaccess->set_updatedcallback(function () {
                if (
                    // For safety, we check for both settings.
                    get_config('booking', 'unenroluserswithoutaccessareyousure')
                    && get_config('booking', 'unenroluserswithoutaccess')
                ) {
                    // This will create tasks for ALL affected booking answers (system-wide).
                    checkanswers::create_bookinganswers_check_tasks(
                        context_system::instance()->id, // System context, so everywhere.
                        checkanswers::CHECK_ALL,
                        checkanswers::ACTION_DELETE,
                        0 // Do it for all users.
                    );
                }
            });
            $settings->add($unenroluserswithoutaccess);
        }
    } else {
        $settings->add(
            new admin_setting_heading(
                'unenroluserswithoutaccessheader',
                get_string('unenroluserswithoutaccess', 'mod_booking') . " " . get_string('badge:pro', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:unenroluserswithoutaccess', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // PRO feature: Automatic creation of Moodle course.
    if ($proversion) {
        /* Booking option custom field to be used as course category
        for automatically created courses. */
        $settings->add(
            new admin_setting_heading(
                'newcoursecategorycfieldheading',
                get_string('automaticcoursecreation', 'mod_booking'),
                ''
            )
        );
        $records = booking_handler::get_customfields();
        foreach ($records as $record) {
            $customfieldsarray[$record->shortname] = format_string("$record->name ($record->shortname)");
        }
        $settings->add(
            new admin_setting_configselect(
                'booking/newcoursecategorycfield',
                get_string('newcoursecategorycfield', 'mod_booking'),
                get_string('newcoursecategorycfielddesc', 'mod_booking'),
                "-1",
                $customfieldsarray
            )
        );

        $sql = "SELECT DISTINCT t.id, t.name
        FROM {tag} t
        LEFT JOIN {tag_instance} ti ON t.id=ti.tagid
        WHERE ti.component=:component AND ti.itemtype=:itemtype AND t.isstandard=1";

        $params = [
            'component' => 'core',
            'itemtype' => 'course',
        ];

        $records = $DB->get_records_sql($sql, $params);
        $options = [0 => 'notags'];
        foreach ($records as $record) {
            $options[$record->id] = $record->name;
        }
        $settings->add(
            new admin_setting_configmultiselect(
                'booking/templatetags',
                get_string('choosetags', 'mod_booking'),
                get_string('choosetags_desc', 'mod_booking'),
                [],
                $options
            )
        );

        // phpcs:disable
        // $settings->add(
        //     new admin_setting_configcheckbox('booking/usecoursecategorytemplates',
        //             get_string('usecoursecategorytemplates', 'mod_booking'),
        //             get_string('usecoursecategorytemplates_desc', 'mod_booking'), 0));

        // $settings->add(
        //     new admin_setting_configtext('booking/templatecategoryname',
        //         get_string('templatecategoryname', 'mod_booking'),
        //         get_string('templatecategoryname_desc', 'mod_booking'), '', PARAM_TEXT));
        // phpcs:enable
    } else {
        $settings->add(
            new admin_setting_heading(
                'newcoursecategorycfieldheading',
                get_string('automaticcoursecreation', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:automaticcoursecreation', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // PRO-Feature: Self-learning courses - Booking options with fixed duration.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'selflearningcoursesettingsheader',
                get_string('selflearningcoursesettingsheader', 'mod_booking'),
                get_string('selflearningcoursesettingsheaderdesc', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/selflearningcourseactive',
                get_string('selflearningcourseactive', 'mod_booking'),
                '',
                0
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/selflearningcoursehideduration',
                get_string('selflearningcoursehideduration', 'mod_booking'),
                '',
                0
            )
        );
        $settings->add(
            new admin_setting_configtext(
                'booking/selflearningcourselabel',
                get_string('selflearningcourselabel', 'mod_booking'),
                get_string('selflearningcourselabeldesc', 'mod_booking'),
                ''
            )
        );
        $selflearnshortcodeoptions = [
            0 => get_string('selflearncoursesnotdisplayed', 'mod_booking'),
            1 => get_string('selflearncoursessortingdateinfuture', 'mod_booking'),
            2 => get_string('selflearncoursesall', 'mod_booking'),
        ];
        $settings->add(
            new admin_setting_configselect(
                'booking/selflearningcoursedisplayinshortcode',
                get_string('selflearningcoursedisplayinshortcode', 'mod_booking'),
                get_string('selflearningcoursedisplayinshortcodedesc', 'mod_booking'),
                1,
                $selflearnshortcodeoptions
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'selflearningcoursesettingsheader',
                get_string('selflearningcoursesettingsheader', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:selflearningcourse', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // Waiting list settings.
    $settings->add(
        new admin_setting_heading(
            'waitinglistheader',
            get_string('waitinglistheader', 'mod_booking'),
            get_string('waitinglistheader_desc', 'mod_booking')
        )
    );

    $waitinglistshowplaceonwaitinglist = new admin_setting_configcheckbox(
        'booking/waitinglistshowplaceonwaitinglist',
        get_string('waitinglistshowplaceonwaitinglist', 'mod_booking'),
        get_string('waitinglistshowplaceonwaitinglistinfo', 'mod_booking'),
        0
    );
    $waitinglistshowplaceonwaitinglist->set_updatedcallback(function () {
        cache_helper::purge_by_event('setbackencodedtables');
        cache_helper::purge_by_event('changesinwunderbytetable');
    });
    $settings->add($waitinglistshowplaceonwaitinglist);

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/turnoffwaitinglist',
            get_string('turnoffwaitinglist', 'mod_booking'),
            get_string('turnoffwaitinglist_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/turnoffwaitinglistaftercoursestart',
            get_string('turnoffwaitinglistaftercoursestart', 'mod_booking'),
            '',
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/keepusersbookedonreducingmaxanswers',
            get_string('keepusersbookedonreducingmaxanswers', 'mod_booking'),
            get_string('keepusersbookedonreducingmaxanswers_desc', 'mod_booking'),
            0
        )
    );

    // Notification list settings.
    $settings->add(
        new admin_setting_heading(
            'notificationlist',
            get_string('notificationlist', 'mod_booking'),
            get_string('notificationlistdesc', 'mod_booking')
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/usenotificationlist',
            get_string('usenotificationlist', 'mod_booking'),
            '',
            0
        )
    );

    // Rules settings.
    $url = new moodle_url('/mod/booking/edit_rules.php');
    $linktorules = $url->out();
    $settings->add(
        new admin_setting_heading(
            'rulessettings',
            get_string('rulessettings', 'mod_booking'),
            get_string('rulessettingsdesc', 'mod_booking', $linktorules)
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/bookingruletemplatesactive',
            get_string('bookingruletemplatesactive', 'mod_booking'),
            '',
            1
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/displayinfoaboutrules',
            get_string('displayinfoaboutrules', 'mod_booking'),
            '',
            1
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/limitchangestrackinginrules',
            get_string('limitchangestrackinginrules', 'mod_booking'),
            get_string('limitchangestrackinginrulesdesc', 'mod_booking'),
            1
        )
    );

    $limitchangestrackinginrules = get_config('booking', 'limitchangestrackinginrules') == 1;
    if ($limitchangestrackinginrules) {
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/listentotextchange',
                get_string('listentotextchange', 'mod_booking'),
                '',
                1
            )
        );

        $settings->add(
            new admin_setting_configcheckbox(
                'booking/listentotimestampchange',
                get_string('listentotimestampchange', 'mod_booking'),
                '',
                1
            )
        );

        $settings->add(
            new admin_setting_configcheckbox(
                'booking/listentoteacherschange',
                get_string('listentoteacherschange', 'mod_booking'),
                '',
                1
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/listentoresponsiblepersonchange',
                get_string('listentoresponsiblepersonchange', 'mod_booking'),
                '',
                1
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/listentoaddresschange',
                get_string('listentoaddresschange', 'mod_booking'),
                '',
                1
            )
        );
    }

    $settings->add(
        new admin_setting_heading(
            'educationalunitinminutes',
            get_string('educationalunitinminutes', 'mod_booking'),
            ''
        )
    );

    $allowedlengthsofunit = [
        '60' => '60 min', // Default value.
        '55' => '55 min',
        '50' => '50 min',
        '45' => '45 min',
        '40' => '40 min',
    ];
    $settings->add(
        new admin_setting_configselect(
            'booking/educationalunitinminutes',
            get_string('educationalunitinminutes', 'mod_booking'),
            get_string('educationalunitinminutes_desc', 'mod_booking'),
            '60',
            $allowedlengthsofunit
        )
    );
    $settings->add(
        new admin_setting_heading(
            'bookingpricesettings_heading',
            get_string('bookingpricesettings', 'mod_booking'),
            get_string('bookingpricesettings_desc', 'mod_booking')
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/priceisalwayson',
            get_string('priceisalwayson', 'mod_booking'),
            get_string('priceisalwayson_desc', 'mod_booking'),
            0
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/showpriceifnotloggedin',
            get_string('showpriceifnotloggedin', 'mod_booking'),
            '',
            1
        )
    );

    $settings->add(
        new admin_setting_configselect(
            'booking/pricecategoryfield',
            get_string('pricecategoryfield', 'mod_booking'),
            get_string('pricecategoryfielddesc', 'mod_booking'),
            0,
            $userprofilefieldsarray
        )
    );

    $defaultbehaviours = [
        0 => get_string('fallbackonlywhenempty', 'booking'),
        1 => get_string('fallbackonlywhennotmatching', 'booking'),
        2 => get_string('fallbackturnedoff', 'booking'),
    ];

    $settings->add(
        new admin_setting_configselect(
            'booking/pricecategoryfallback',
            get_string('pricecategoryfallback', 'mod_booking'),
            get_string('pricecategoryfallback_desc', 'mod_booking'),
            0,
            $defaultbehaviours
        )
    );

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/pricecategorychoosehighest',
            get_string('pricecategorychoosehighest', 'mod_booking'),
            get_string('pricecategorychoosehighest_desc', 'mod_booking'),
            0
        )
    );

    // Currency dropdown.
    $currenciesobjects = price::get_possible_currencies();

    $currencies['EUR'] = 'Euro (EUR)';
    foreach ($currenciesobjects as $currenciesobject) {
        $currencyidentifier = $currenciesobject->get_identifier();
        $currencies[$currencyidentifier] = $currenciesobject->out(current_language()) . ' (' . $currencyidentifier . ')';
    }

    $settings->add(
        new admin_setting_configselect(
            'booking/globalcurrency',
            get_string('globalcurrency', 'booking'),
            get_string('globalcurrencydesc', 'booking'),
            'EUR',
            $currencies
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/bookwithcreditsactive',
            get_string('bookwithcreditsactive', 'mod_booking'),
            get_string('bookwithcreditsactive_desc', 'mod_booking'),
            0
        )
    );
    $settings->add(
        new admin_setting_configselect(
            'booking/bookwithcreditsprofilefield',
            get_string('bookwithcreditsprofilefield', 'mod_booking'),
            get_string('bookwithcreditsprofilefield_desc', 'mod_booking'),
            0,
            $userprofilefieldsarray
        )
    );
    $settings->add(
        new admin_setting_configselect(
            'booking/cfcostcenter',
            get_string('cfcostcenter', 'mod_booking'),
            get_string('cfcostcenter_desc', 'mod_booking'),
            "-1",
            $customfieldsarray
        )
    );
    $settings->add(
        new admin_setting_configtextarea(
            'booking/sccartdescription',
            get_string('sccartdescription', 'mod_booking'),
            get_string('sccartdescription_desc', 'mod_booking'),
            ''
        )
    );

    if (class_exists('local_shopping_cart\shopping_cart')) {
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/screstoreitemfromreserved',
                get_string('screstoreitemfromreserved', 'mod_booking'),
                get_string('screstoreitemfromreserved_desc', 'mod_booking'),
                0
            )
        );
    }

    $settings->add(
        new admin_setting_configcheckbox(
            'booking/displayemptyprice',
            get_string('displayemptyprice', 'mod_booking'),
            get_string('displayemptyprice_desc', 'mod_booking'),
            1
        )
    );

    // PRO feature: Price forumla.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'priceformulaheader',
                get_string('priceformulaheader', 'mod_booking'),
                get_string('priceformulaheader_desc', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_configtextarea(
                'booking/defaultpriceformula',
                get_string('defaultpriceformula', 'booking'),
                get_string('defaultpriceformuladesc', 'booking'),
                '',
                PARAM_TEXT,
                60,
                10
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/applyunitfactor',
                get_string('applyunitfactor', 'mod_booking'),
                get_string('applyunitfactor_desc', 'mod_booking'),
                1
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/roundpricesafterformula',
                get_string('roundpricesafterformula', 'mod_booking'),
                get_string('roundpricesafterformula_desc', 'mod_booking'),
                1
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'priceformulaheader',
                get_string('priceformulaheader', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:priceformula', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // Booking instances.
    $settings->add(
        new admin_setting_heading(
            'duplicationrestore',
            get_string('duplicationrestore', 'mod_booking'),
            get_string('duplicationrestoredesc', 'mod_booking')
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/duplicationrestoreteachers',
            get_string('duplicationrestoreteachers', 'mod_booking'),
            '',
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/duplicationrestoreprices',
            get_string('duplicationrestoreprices', 'mod_booking'),
            '',
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/duplicationrestoreentities',
            get_string('duplicationrestoreentities', 'mod_booking'),
            '',
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/duplicationrestoresubbookings',
            get_string('duplicationrestoresubbookings', 'mod_booking'),
            '',
            1
        )
    );

    // PRO feature: Duplication settings.
    if ($proversion) {
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/duplicationrestorebookings',
                get_string('duplicationrestorebookings', 'mod_booking'),
                '',
                1
            )
        );
    }

    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'duplicationrestoreoption',
                get_string('duplicationrestoreoption', 'mod_booking'),
                get_string('duplicationrestoreoption_desc', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/duplicatemoodlecourses',
                get_string('duplicatemoodlecourses', 'mod_booking'),
                get_string('duplicatemoodlecourses_desc', 'mod_booking'),
                0
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'duplicationrestoreoption',
                get_string('duplicationrestoreoption', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:duplicationrestoreoption', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'recurringsettingsheader',
                get_string('recurringsettingsheader', 'mod_booking'),
                get_string('recurringsettingsheader_desc', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/recurringmultiparenting',
                get_string('recurringmultiparenting', 'mod_booking'),
                get_string('recurringmultiparenting_desc', 'mod_booking'),
                0
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'recurringsettingsheader',
                get_string('recurringsettingsheader', 'mod_booking'),
                get_string('infotext:prolicensenecessarytextandlink', 'mod_booking')
            )
        );
    }

    $settings->add(
        new admin_setting_heading(
            'optiontemplatessettings_heading',
            get_string('optiontemplatessettings', 'mod_booking'),
            ''
        )
    );

    $alltemplates = ['0' => get_string('dontusetemplate', 'booking')];
    $alloptiontemplates = $DB->get_records('booking_options', ['bookingid' => 0], '', $fields = 'id, text', 0, 0);

    foreach ($alloptiontemplates as $key => $value) {
        $alltemplates[$value->id] = $value->text;
    }

    $settings->add(
        new admin_setting_configselect(
            'booking/defaulttemplate',
            get_string('defaulttemplate', 'mod_booking'),
            get_string('defaulttemplatedesc', 'mod_booking'),
            1,
            $alltemplates
        )
    );

    // PRO feature: Availability info text.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'availabilityinfotexts_heading',
                get_string('availabilityinfotextsheading', 'mod_booking'),
                ''
            )
        );

        $settings->add(
            new admin_setting_configselect(
                'booking/bookingplacesinfotexts',
                get_string('bookingplacesinfotexts', 'mod_booking'),
                get_string('bookingplacesinfotextsinfo', 'mod_booking'),
                0,
                [
                    0 => get_string('placesinfoshowbooked', 'mod_booking'),
                    1 => get_string('placesinfoshowinfotexts', 'mod_booking'),
                    2 => get_string('placesinfoshowfreeonly', 'mod_booking'),
                ]
            )
        );

        $bookingplaceslowpercentages = [
            0 => ' 0%',
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
            new admin_setting_configselect(
                'booking/bookingplaceslowpercentage',
                get_string('bookingplaceslowpercentage', 'booking'),
                get_string('bookingplaceslowpercentagedesc', 'booking'),
                20,
                $bookingplaceslowpercentages
            )
        );

        $settings->add(
            new admin_setting_configselect(
                'booking/waitinglistinfotexts',
                get_string('waitinglistinfotexts', 'mod_booking'),
                get_string('waitinglistinfotextsinfo', 'mod_booking'),
                0,
                [
                    0 => get_string('placesinfoshowbooked', 'mod_booking'),
                    1 => get_string('placesinfoshowinfotexts', 'mod_booking'),
                    2 => get_string('placesinfoshowfreeonly', 'mod_booking'),
                ]
            )
        );

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
            new admin_setting_configselect(
                'booking/waitinglistlowpercentage',
                get_string('waitinglistlowpercentage', 'booking'),
                get_string('waitinglistlowpercentagedesc', 'booking'),
                20,
                $waitinglistlowpercentages
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'availabilityinfotexts_heading',
                get_string('availabilityinfotextsheading', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:availabilityinfotexts', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // PRO feature: Subbookings.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'subbookings',
                get_string('subbookingsheader', 'mod_booking'),
                get_string('subbookings_desc', 'mod_booking')
            )
        );

        $settings->add(
            new admin_setting_configcheckbox(
                'booking/showsubbookings',
                get_string('showsubbookings', 'mod_booking'),
                '',
                0
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'subbookings',
                get_string('subbookingsheader', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:subbookings', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // We currently do not show actions as they do not work yet.
    // PRO feature: Booking actions.
    // Booking actions are not yet finished, so we do not show them yet.
    // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'boactions',
                get_string('boactions', 'mod_booking'),
                get_string('boactions_desc', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/showboactions',
                get_string('showboactions', 'mod_booking'),
                '',
                0
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'boactions',
                get_string('boactions', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:boactions', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // PRO feature: Progress bars.
    if ($proversion) {
        $settings->add(
            new admin_setting_heading(
                'progressbars',
                get_string('progressbars', 'mod_booking'),
                get_string('progressbars_desc', 'mod_booking')
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/showprogressbars',
                get_string('showprogressbars', 'mod_booking'),
                '',
                0
            )
        );
        $settings->add(
            new admin_setting_configcheckbox(
                'booking/progressbarscollapsible',
                get_string('progressbarscollapsible', 'mod_booking'),
                '',
                1
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'progressbars',
                get_string('progressbars', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:progressbars', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    $settings->add(
        new admin_setting_heading(
            'mod_booking_icalcfg',
            get_string('icalcfg', 'mod_booking'),
            get_string('icalcfgdesc', 'mod_booking')
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/dontaddpersonalevents',
            get_string('dontaddpersonalevents', 'mod_booking'),
            get_string('dontaddpersonaleventsdesc', 'mod_booking'),
            0
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/attachical',
            get_string('attachicalfile', 'mod_booking'),
            get_string('attachicalfile_desc', 'mod_booking'),
            1
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/icalcancel',
            get_string('icalcancel', 'mod_booking'),
            get_string('icalcanceldesc', 'mod_booking'),
            1
        )
    );

    $options = [
        1 => get_string('courseurl', 'mod_booking'),
        2 => get_string('location', 'mod_booking'),
        3 => get_string('institution', 'mod_booking'),
        4 => get_string('address'),
    ];
    $settings->add(
        new admin_setting_configselect(
            'booking/icalfieldlocation',
            get_string('icalfieldlocation', 'mod_booking'),
            get_string('icalfieldlocationdesc', 'mod_booking'),
            1,
            $options
        )
    );
    $settings->add(
        new admin_setting_heading(
            'mod_booking_signinsheet',
            get_string('cfgsignin', 'mod_booking'),
            get_string('cfgsignin_desc', 'mod_booking')
        )
    );

    // Classic sign-in sheet mode ("legacy mode").
    $signinsheetmodes = [
        'legacy' => get_string('signinsheet_legacy', 'mod_booking'),
        'htmltemplate' => get_string('signinsheet_htmltemplate', 'mod_booking'),
    ];
    $settings->add(
        new admin_setting_configselect(
            'booking/signinsheetmode',
            get_string('signinsheetmode', 'mod_booking'),
            get_string('signinsheetmode_desc', 'mod_booking'),
            'legacy',
            $signinsheetmodes
        )
    );
    $settings->add(
        new admin_setting_configtextarea(
            'booking/signinsheethtml',
            get_string('signinsheethtml', 'mod_booking'),
            get_string('signinsheethtmldescription', 'mod_booking'),
            '', /* $defaultsigninsheethtml */
            PARAM_RAW
        )
    );
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/numberrows',
            get_string('numberrows', 'mod_booking'),
            get_string('numberrowsdesc', 'mod_booking'),
            0
        )
    );

    $name = 'mod_booking/signinlogo';
    $title = get_string('signinlogoheader', 'mod_booking');
    $description = $title;
    $fileoptions = ['maxfiles' => 1, 'accepted_types' => ['image']];
    $setting = new admin_setting_configstoredfile(
        $name,
        $title,
        $description,
        'mod_booking_signinlogo',
        0,
        $fileoptions
    );
    $settings->add($setting);

    $name = 'booking/signinlogofooter';
    $title = get_string('signinlogofooter', 'mod_booking');
    $description = $title;
    $fileoptions = ['maxfiles' => 1, 'accepted_types' => ['image']];
    $setting = new admin_setting_configstoredfile(
        $name,
        $title,
        $description,
        'mod_booking_signinlogo_footer',
        0,
        $fileoptions
    );
    $settings->add($setting);

    $name = 'booking/showcustfields';
    $visiblename = get_string('showcustomfields', 'mod_booking');
    $description = get_string('showcustomfields_desc', 'mod_booking');
    $customfields = \mod_booking\booking_option::get_customfield_settings();
    $choices = [];
    if (!empty($customfields)) {
        foreach ($customfields as $cfgname => $value) {
            $choices[$cfgname] = $value['value'];
        }
        $setting = new admin_setting_configmulticheckbox(
            $name,
            $visiblename,
            $description,
            [],
            $choices
        );
        $settings->add($setting);
    }

    $settings->add(
        new admin_setting_heading(
            'mod_booking_signinheading',
            get_string('signinextracolsheading', 'mod_booking'),
            ''
        )
    );

    for ($i = 1; $i < 4; $i++) {
        $name = 'booking/signinextracols' . $i;
        $visiblename = get_string('signinextracols', 'mod_booking') . " $i";
        $description = get_string('signinextracols_desc', 'mod_booking') . " $i";
        $setting = new admin_setting_configtext($name, $visiblename, $description, '');
        $settings->add($setting);
    }

    if ($proversion) {
        // Global mail templates (PRO).
        $settings->add(
            new admin_setting_heading(
                'cachesettings_heading',
                get_string('cachesettings', 'mod_booking'),
                get_string('cachesettings_desc', 'mod_booking')
            )
        );

        $settings->add(
            new admin_setting_configcheckbox(
                'booking/cacheturnoffforbookingsettings',
                get_string('cacheturnoffforbookingsettings', 'mod_booking'),
                get_string('cacheturnoffforbookingsettings_desc', 'mod_booking', $linktorules),
                0
            )
        );

        $settings->add(
            new admin_setting_configcheckbox(
                'booking/cacheturnoffforbookinganswers',
                get_string('cacheturnoffforbookinganswers', 'mod_booking'),
                get_string('cacheturnoffforbookinganswers_desc', 'mod_booking', $linktorules),
                0
            )
        );
    } else {
        $settings->add(
            new admin_setting_heading(
                'cachesettings_heading',
                get_string('cachesettings', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:cachesettings', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    if ($proversion) {
        // Mobile settings (PRO).
        $settings->add(
            new admin_setting_heading(
                'mobile_settings',
                get_string('mobilesettings', 'mod_booking'),
                get_string('mobilesettings_desc', 'mod_booking')
            )
        );

        $whichviewopts = [
            'showall' => get_string('showallbookingoptions', 'booking'),
            'mybooking' => get_string('showmybookingsonly', 'booking'),
            'myoptions' => get_string('optionsiteach', 'booking'),
            'optionsiamresponsiblefor' => get_string('optionsiamresponsiblefor', 'mod_booking'),
            'showactive' => get_string('activebookingoptions', 'booking'),
            'myinstitution' => get_string('myinstitution', 'booking'),
            'showvisible' => get_string('visibleoptions', 'booking'),
            'showinvisible' => get_string('invisibleoptions', 'booking'),
        ];
        $settings->add(new admin_setting_configmultiselect(
            'booking/mobileviewoptions',
            get_string('mobileviewoptionstext', 'booking'),
            get_string('mobileviewoptionsdesc', 'booking'),
            [],
            $whichviewopts
        ));
    }

    if ($proversion) {
        // Shortcode settings.
        $settings->add(
            new admin_setting_heading(
                'shortcodesettingsheading',
                get_string('shortcodesettings', 'mod_booking'),
                get_string('shortcodesettings_desc', 'mod_booking')
            )
        );

        $settings->add(
            new admin_setting_configcheckbox(
                'booking/shortcodesoff',
                get_string('shortcodesoff', 'mod_booking'),
                get_string('shortcodesoff_desc', 'mod_booking'),
                0
            )
        );

        $settings->add(new admin_setting_configtext(
            'booking/shortcodespassword',
            get_string('shortcodespassword', 'mod_booking'),
            get_string('shortcodespassword_desc', 'mod_booking'),
            '' // Default is empty.
        ));
    } else {
        $settings->add(
            new admin_setting_heading(
                'tabwhatsnew',
                get_string('tabwhatsnew', 'mod_booking'),
                get_string('prolicensefeatures', 'mod_booking') .
                get_string('profeatures:shortcodes', 'mod_booking') .
                get_string('infotext:prolicensenecessary', 'mod_booking')
            )
        );
    }

    // Global mail templates (PRO).
    $settings->add(
        new admin_setting_heading(
            'globalmailtemplates_heading',
            get_string('globalmailtemplates', 'mod_booking'),
            get_string('globalmailtemplates_desc', 'mod_booking')
        )
    );

    $url = new moodle_url('/mod/booking/edit_rules.php');
    $linktorules = $url->out();
    $settings->add(
        new admin_setting_configcheckbox(
            'booking/uselegacymailtemplates',
            get_string('uselegacymailtemplates', 'mod_booking'),
            get_string('uselegacymailtemplates_desc', 'mod_booking', $linktorules),
            1
        )
    );

    if (!empty(get_config('booking', 'uselegacymailtemplates'))) {
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globalbookedtext',
                get_string('globalbookedtext', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globalwaitingtext',
                get_string('globalwaitingtext', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globalnotifyemail',
                get_string('globalnotifyemail', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globalnotifyemailteachers',
                get_string('globalnotifyemailteachers', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globalstatuschangetext',
                get_string('globalstatuschangetext', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globaluserleave',
                get_string('globaluserleave', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globaldeletedtext',
                get_string('globaldeletedtext', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globalbookingchangedtext',
                get_string('globalbookingchangedtext', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globalpollurltext',
                get_string('globalpollurltext', 'booking'),
                '',
                ''
            )
        );
        $settings->add(
            new admin_setting_confightmleditor(
                'booking/globalpollurlteacherstext',
                get_string('globalpollurlteacherstext', 'booking'),
                '',
                ''
            )
        );
    }
}

$settings = null;

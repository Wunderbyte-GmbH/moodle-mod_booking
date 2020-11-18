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
require_once('../../config.php');
require_once("$CFG->libdir/tablelib.php");
require_once($CFG->libdir . '/adminlib.php');

$donwloadtemplate = optional_param('donwloadtemplate', '0', PARAM_INT);

// No guest autologin.
require_login(0, false);

$pageurl = new moodle_url('/mod/booking/templates.php');
$allapis = new moodle_url('/admin/category.php?category=modbookingfolder');
$PAGE->set_url($pageurl);

admin_externalpage_setup('modbookingtemplates', '', null, '', array('pagelayout' => 'report'));

$PAGE->set_title(format_string($SITE->shortname) . ': ' . get_string('templates', 'booking'));

if ($donwloadtemplate === 1) {

    $xml = new SimpleXMLElement("<BookingTemplates></BookingTemplates>");
    $xmlinstances = $xml->addChild('Instances');
    $xmloptions = $xml->addChild('Options');

    $instances = $DB->get_records('booking_instancetemplate', null, '', 'name, template');
    $options = $DB->get_records('booking_options', ['bookingid' => 0], '', '*');

    if (!empty($instances)) {
        foreach ($instances as $instance) {
            $xmlinstances->addChild('Instance', json_encode($instance));
        }
    }

    if (!empty($options)) {
        foreach ($options as $option) {
            unset($option->id);
            $xmloptions->addChild('Option', json_encode($option));
        }
    }

    header('Content-type: text/xml');
    header('Content-Disposition: attachment; filename="BookingTemplates_' . date('Ymd') . '.xml"');

    echo $xml->asXML();
    exit();
}

$mform = new \mod_booking\form\templates_form();
if ($mform->is_cancelled()) {
    redirect($allapis);
} else if ($fromform = $mform->get_data()) {
    $content = $mform->get_file_content('template');

    $message = get_string('templatesinserted', 'booking');
    $wait = 0;

    try {
        $xml = new SimpleXMLElement($content);

        foreach ($xml->Instances->Instance as $key => $value) {
            $DB->insert_record('booking_instancetemplate', json_decode($value), false, false);
        }

        foreach ($xml->Options->Option as $key => $value) {
            $DB->insert_record('booking_options', json_decode($value), false, false);
        }
    } catch (Exception $e) {
        $message = get_string('templatesinsertederror', 'booking');
        $wait = 5;
    }

    redirect($allapis, $message, $wait);
} else {
    echo $OUTPUT->header();
    echo html_writer::link(new moodle_url('/mod/booking/templates.php', ['donwloadtemplate' => 1]),
                        get_string('donwloadtemplates', 'booking'));
    $defaultvalues = new stdClass();

    // Processed if form is submitted but data not validated & form should be redisplayed OR first display of form.
    $mform->set_data($defaultvalues);
    $mform->display();
    echo $OUTPUT->footer();
}
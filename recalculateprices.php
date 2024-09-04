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
 * Semesters settings
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Thomas Winkler
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking;

use context_system;
use moodle_url;
use stdClass;

require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

global $OUTPUT;

$context = context_system::instance();

$cmid = required_param('id', PARAM_INT);
$submit = optional_param('submit', false, PARAM_BOOL);

list($course, $cm) = get_course_and_cm_from_cmid($cmid);

require_course_login($course, false, $cm);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$pageurl = new \moodle_url('/mod/booking/recalculateprices.php');
$PAGE->set_url($pageurl);

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('recalculateprices', 'mod_booking')
);

$data = new stdClass();
$data->cmid = $cmid;
$url = new \moodle_url('/mod/booking/view.php', ['id' => $cmid]);
$data->back = $url->out(false);
$url = new \moodle_url('/mod/booking/recalculateprices.php', ['id' => $cmid, 'submit' => true]);
$data->continue = $url->out(false);
$data->alertmsg = get_string('alertrecalculate', 'mod_booking');

if ($submit) {
    $price = new price('option');
    $currency = get_config('booking', 'globalcurrency');
    $formulastring = get_config('booking', 'defaultpriceformula');
    if (empty($price->pricecategories)) {
        $data->alertmsg = get_string('nopricecategoriesyet', 'mod_booking');
        $data->alert = 1;
    } else if (empty($formulastring)) {
        $url = new moodle_url("/admin/category.php", ['category' => 'modbookingfolder'], 'admin-defaultpriceformula');
        $a->url = $url->out(false);
        $data->alertmsg = get_string('nopriceformulaset', 'mod_booking', $a);
        $data->alert = 1;
    } else {
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $alloptionids = \mod_booking\booking::get_all_optionids($bookingsettings->id);
        foreach ($alloptionids as $optionid) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

            // If priceformulaoff is set to 1, we're not doing anything!
            if (isset($settings->priceformulaoff) && $settings->priceformulaoff == 1) {
                continue;
            }

            foreach ($price->pricecategories as $pricecategory) {
                price::add_price(
                    'option',
                    $optionid,
                    $pricecategory->identifier,
                    price::calculate_price_with_bookingoptionsettings($settings, $formulastring, $pricecategory->identifier),
                    $currency
                );
            }
        }
        $msg = get_string('successfulcalculation', 'mod_booking');
        redirect($data->back, $msg, 5);
    }
}

// Page output.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('recalculateprices', 'mod_booking'));
echo $OUTPUT->render_from_template('mod_booking/recalculateprices', $data);
echo $OUTPUT->footer();

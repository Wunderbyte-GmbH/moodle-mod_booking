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

use mod_booking\form\dynamicchangesemesterform;
use mod_booking\form\dynamicholidaysform;
use mod_booking\form\dynamicsemestersform;
use mod_booking\output\semesters_holidays;
use stdClass;

require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

global $OUTPUT;



$cmid = required_param('id', PARAM_INT);
$submit = optional_param('submit', false, PARAM_BOOL);


admin_externalpage_setup('modbookingsemesters', '', [],
    new \moodle_url('/mod/booking/semesters.php'));

$settingsurl = new \moodle_url('/admin/category.php', ['category' => 'modbookingfolder']);

$pageurl = new \moodle_url('/mod/booking/recalculateprices.php');
$PAGE->set_url($pageurl);

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('recalculateprices', 'booking')
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('recalculateprices', 'booking'));

$data->cmid = $cmid;
$url = new \moodle_url('/mod/booking/view.php', ['id' => $cmid]);
$data->back = $url->out(false);
$url = new \moodle_url('/mod/booking/recalculateprices.php', ['id' => $cmid, 'submit' => true]);
$data->continue = $url->out(false);
$data->alertmsg = get_string('alertrecalculate', 'booking');

if ($submit) {
    $price = new price();
    if (empty($price->pricecategories)) {
        $data->alertmsg = get_string('nopricecategoriesyet', 'booking');
        $data->alert = 1;
    } else {
        $bosettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $alloptionsid = \mod_booking\booking::get_all_optionids($bosettings->id);
        foreach ($alloptionsid as $optionid) {
            $bo = singleton_service::get_instance_of_booking_option($cmid, $optionid);
            $settings = new stdClass();
            $settings = $bo->settings;
            $currency = get_config('booking', 'globalcurrency');
            $formulastring = get_config('booking', 'defaultpriceformula');
            foreach ($price->pricecategories as $pricecategory) {
                $price->add_price(
                $optionid,
                $price->pricecategories[1]->identifier,
                price::calculate_price_bo($settings, $formulastring, $pricecategory->identifier),
                $currency
                );
            }
        }
        $msg = get_string('successfullcalculation', 'booking');
        redirect($data->back, $msg, 5);
    }
}


echo $OUTPUT->render_from_template('mod_booking/recalculateprices', $data);

echo $OUTPUT->footer();

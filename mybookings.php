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
 * Handling my bookings page
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

// No guest autologin.
require_login(0, false);

use mod_booking\shortcodes;
use mod_booking\singleton_service;

$url = new moodle_url('/mod/booking/mybookings.php');
$userid = optional_param('userid', 0, PARAM_INT);
$completed = optional_param('completed', 0, PARAM_INT);

if (!empty($userid) && has_capability('local/shopping_cart:cashier', context_system::instance())) {
    $user = singleton_service::get_instance_of_user($userid);
} else {
    $user = $USER;
    $userid = $USER->id;
}

$PAGE->set_context(context_user::instance($user->id));
$PAGE->navigation->extend_for_user($user);
$mybookingsurl = new moodle_url('/mod/booking/mybookings.php', ['userid' => $userid]);
$PAGE->set_url($mybookingsurl);

$PAGE->set_pagelayout('base');

if ($userid != $USER->id) {
    $arguments = ['userid' => $userid, 'completed' => $completed];
    $heading = get_string('bookings', 'mod_booking');
} else {
    $arguments = ['userid' => $userid, 'completed' => $completed];
    $heading = get_string('mybookingoptions', 'mod_booking');
}
$PAGE->navbar->add($heading);

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);


$arguments['sort'] = 1;
$arguments['sortby'] = 'coursestarttime';
$arguments['sortorder'] = 'desc';
$arguments['foruserid'] = $userid;

echo shortcodes::mycourselist('', $arguments, '', (object)[], fn($a) => $a);

if (class_exists('local_shopping_cart\shopping_cart') && get_config('booking', 'displayshoppingcarthistory')) {
    echo local_shopping_cart\shortcodes::shoppingcarthistory('', ['foruserid' => $userid], '', (object)[], fn($a) => $a);
}

echo $OUTPUT->footer();

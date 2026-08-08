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
 * Creates the shipped example ticket template in tool_certificate.
 *
 * Triggered by the "Create example ticket template" button in the booking site settings.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_booking\local\ticket\ticket_template_installer;

require_login();
require_sesskey();
require_capability('moodle/site:config', context_system::instance());

$returnurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingbooking']);

if (!class_exists('tool_certificate\\template')) {
    redirect($returnurl, get_string('error'), null, \core\output\notification::NOTIFY_ERROR);
}

$name = ticket_template_installer::get_template_name();

if (ticket_template_installer::is_installed()) {
    redirect(
        $returnurl,
        get_string('bookingticketcreatetemplatedone', 'mod_booking', $name),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

$templateid = ticket_template_installer::ensure_installed();

if (empty($templateid)) {
    redirect($returnurl, get_string('error'), null, \core\output\notification::NOTIFY_ERROR);
}

redirect(
    $returnurl,
    get_string('tickettemplatecreated', 'mod_booking', $name),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);

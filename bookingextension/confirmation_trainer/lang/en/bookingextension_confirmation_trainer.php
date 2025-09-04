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
 * This file contains language strings for the subplugin.
 *
 * @package     bookingextension_confirmation_trainer
 * @copyright   2025 Wunderbyte GmbH
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['bookingextensionconfirmationtrainer:confirmationtrainerenabled'] = "Activate confirmation by trainer";
$string['bookingextensionconfirmationtrainer:confirmationtrainerenabled_desc'] = "Should the possiblity to confirm by a trainer be activated?";
$string['bookingextensionconfirmationtrainer:confirmationtrainerenabledinbookingoption'] = 'Display in booking option settings';
$string['bookingextensionconfirmationtrainer:confirmationtrainerenabledinbookingoption_desc'] = 'Make it visible in booking option settings';
$string['bookingextensionconfirmationtrainer:heading'] = "Confirmation by trainer";
$string['bookingextensionconfirmationtrainer:heading_desc'] = 'Supervisor confirmation allows you to store the Moodle IDs of supervisors in the defined user profile field. These supervisors can then issue approvals. In addition, it is possible to appoint deputies. To do so, enter the user ID(s) of the deputy/deputies into the selected profile field of the supervisor. Deputies are granted the same rights as the supervisor.
This function can also be combined with fixed users ("confirmation_supervisor_hrusers"). If the standard option "confirmation by trainer" is also selected, trainers will additionally be able to approve.
Detailed settings for the confirmation of individual booking options can be configured directly in their respective settings (e.g., defining the sequence of the confirmation process).';
$string['confirmationtrainerenabled'] = 'Allow confirmation by trainer';
$string['notallowedtoconfirm'] = "Not allowed to confirm";
$string['pluginname'] = 'Confirmation workflow by trainer';
$string['workflowdescription'] = 'In the standard workflow, users will just book on the waitinglist, if in a booking option the "Book only after confirmation"-checkbox is set. As a teacher or any person, who has the corresponding capabilities (mod/booking:bookforothers & mod/booking:subscribeusers) you can view the users on the waitinglist and, from there, confirm users.';
$string['workflowname'] = 'Confirmation workflow by trainer';

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
 * @package     bookingextension_confirmation_supervisor
 * @copyright   2025 Wunderbyte GmbH
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['alreadyconfirmed'] = 'You already confirmed';
$string['alreadyconfirmedbyhr'] = 'HR already confirmed';
$string['alreadyconfirmedbysupervisor'] = 'Supervisor already confirmed';
$string['bookingextension_confirmation_supervisor'] = 'Allow confirmation by supervisor';
$string['bookingextensionconfirmationsupervisor:confirmationsupervisorenabled'] = "Activate confirmation by supervisor";
$string['bookingextensionconfirmationsupervisor:confirmationsupervisorenabled_desc'] = "Explanation";
$string['bookingextensionconfirmationsupervisor:heading'] = "Confirmation by supervisor";
$string['bookingextensionconfirmationsupervisor:heading_desc'] = 'Supervisor confirmation allows you to store the Moodle IDs of supervisors in the defined user profile field. These supervisors can then issue approvals. In addition, it is possible to appoint deputies. To do so, enter the user ID(s) of the deputy/deputies into the selected profile field of the supervisor. Deputies are granted the same rights as the supervisor.
This function can also be combined with fixed users ("confirmation_supervisor_hrusers"). If the standard option "confirmation by trainer" is also selected, trainers will additionally be able to approve.
Detailed settings for the confirmation of individual booking options can be configured directly in their respective settings (e.g., defining the sequence of the confirmation process).';
$string['confirmationsupervisor'] = 'Confirmation by supervisor';
$string['confirmationsupervisorenabled'] = 'Allow confirmation by supervisor';
$string['confirmbyhr'] = 'Confirmation by HR';
$string['confirmbyhrsupervisor'] = 'Confirmation first by HR, then supervisor';
$string['confirmbysupervisor'] = 'Confirmation by supervisor';
$string['confirmbysupervisorhr'] = 'Confirmation first by supervisor, then HR';
$string['confirmbysupervisororhr'] = 'Confirmation by supervisor or HR';
$string['defaultconfirmationorder'] = 'Default confirmation order';
$string['defaultconfirmationorder_desc'] = 'Choose a default confirmation order so that it is preselected in the booking option settings when creating a new booking option.';
$string['deputyfield'] = 'Deputy field';
$string['deputyfield_desc'] = 'You can assign a deputy to the supervisor so that when the supervisor is unavailable, the deputy can view and confirm options.';
$string['hrusers'] = "HR userids";
$string['hrusers_desc'] = "Enter the Moodle user ids of HR, comma separated";
$string['needsconfirmationofhr'] = 'HR has to confirm';
$string['needsconfirmationofsupervisor'] = 'Supervisor has to confirm';
$string['noconfirmationneeded'] = 'No confirmation needed';
$string['notallowedtoconfirm'] = "Not allowed to confirm";
$string['pluginname'] = 'Confirmation workflow by supervisor';
$string['restricttrackertomyteam'] = 'Supervisors only see their own team';
$string['restricttrackertomyteam_desc'] = 'If activated, supervisors (and their deputies) only see the booking answers of their own team in the bookings tracker and in all other lists of booked users. Users who may confirm for everybody (capability mod/booking:seealllisttoapprove), HR users and teachers or managers of the booking instance (capability mod/booking:managebookedusers) are not restricted.';
$string['supervisorfield'] = 'Supervisor field';
$string['supervisorfield_desc'] = 'Supervisor field';
$string['workflowdescription'] = 'In the standard workflow, users will just book on the waitinglist, if in a booking option the "Book only after confirmation"-checkbox is set. As a teacher or any person, who has the corresponding capabilities (mod/booking:bookforothers & mod/booking:subscribeusers) you can view the users on the waitinglist and, from there, confirm users.';
$string['workflowname'] = 'Confirmation workflow by supervisor';

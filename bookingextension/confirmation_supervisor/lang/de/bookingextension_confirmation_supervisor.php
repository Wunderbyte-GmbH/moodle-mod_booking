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
$string['alreadyconfirmed'] = 'Sie haben bereits bestätigt';
$string['alreadyconfirmedbyhr'] = 'Personalabteilung hat bereits bestätigt';
$string['alreadyconfirmedbysupervisor'] = 'Vorgesetzte:n hat bereits bestätigt';
$string['bookingextension_confirmation_supervisor'] = 'Bestätigung durch Vorgesetzte';
$string['bookingextensionconfirmationsupervisor:confirmationsupervisorenabled'] = "Bestätigung durch Vorgesetzte aktiviert";
$string['bookingextensionconfirmationsupervisor:confirmationsupervisorenabled_desc'] = "Erläuterung";
$string['bookingextensionconfirmationsupervisor:heading'] = "Bestätigung durch Vorgesetzte";
$string['bookingextensionconfirmationsupervisor:heading_desc'] = 'Die Bestätigung durch Vorgesetzte ermöglicht es, im hier definierten Nutzer/innen-Profilfeld die Moodle-IDs ihrer Vorgesetzten zu hinterlegen, die dann Freigaben erteilen können.
Zusätzlich ist es möglich, Stellvertretungen zu ernennen. Hierfür müssen in das ausgewählte Profilfeld des/der Vorgesetzten die User-IDs der Stellvertretenden eingetragen werden. Diese haben dann alle Rechte, die auch die Vorgesetzten haben.
Diese Funktion kann mit fixen Nutzer/innen kombiniert werden ("confirmation_supervisor_hrusers"). Wird zusätzlich die standardmäßige "Bestätigung durch Trainer:in" ausgewählt, haben Trainer:innen ebenfalls die Möglichkeit zu bestätigen.
Ausführliche Einstellungen zur Bestätigung einzelner Buchungsoptionen lassen sich in den jeweiligen Einstellungen festlegen (z. B. die Reihenfolge des Bestätigungsprozesses).';
$string['confirmationsupervisor'] = 'Bestätigung durch Vorgesetzte';
$string['confirmationsupervisorenabled'] = 'Erlaube Bestätigung durch Vorgesetzte';
$string['confirmbyhr'] = 'Bestätigung durch Personalabteilung';
$string['confirmbyhrsupervisor'] = 'Bestätigung erst durch Personalabteilung, dann Vorgesetzte:n';
$string['confirmbysupervisor'] = 'Bestätigung durch Vorgesetzte:n';
$string['confirmbysupervisorhr'] = 'Bestätigung erst durch Vorgesetzte:n, dann Personalabteilung';
$string['confirmbysupervisororhr'] = 'Bestätigung durch Vorgesetzte:n oder Personalabteilung';
$string['defaultconfirmationorder'] = 'Standard-Bestätigungsreihenfolge';
$string['defaultconfirmationorder_desc'] = 'Wählen Sie eine Standard-Bestätigungsreihenfolge, die in den Buchungsoptionseinstellungen vorausgewählt ist, wenn eine neue Buchungsoption erstellt wird.';
$string['deputyfield'] = 'Stellvertreter/in-Feld';
$string['deputyfield_desc'] = 'Sie können dem Vorgesetzten einen Stellvertreter zuweisen, sodass dieser bei Abwesenheit des Vorgesetzten Optionen einsehen und bestätigen kann.';
$string['hrusers'] = "Userids der Personalabteilung";
$string['hrusers_desc'] = "Userids der Personalabteilung, mit Komma getrennt";
$string['needsconfirmationofhr'] = 'Die Personalabteilung muss bestätigen';
$string['needsconfirmationofsupervisor'] = 'Der/Die Vorgesetzte muss bestätigen';
$string['noconfirmationneeded'] = 'Keine Bestätigung notwendig';
$string['notallowedtoconfirm'] = "Keine Berechtigung zu buchen";
$string['pluginname'] = 'Bestätigungsworkflow durch Vorgesetzte/n';
$string['restricttrackertomyteam'] = 'Vorgesetzte sehen nur ihr eigenes Team';
$string['restricttrackertomyteam_desc'] = 'Wenn aktiviert, sehen Vorgesetzte (und ihre Stellvertretungen) im Buchungsüberblick und in allen anderen Listen gebuchter Nutzer/innen nur die Buchungen ihres eigenen Teams. Nicht eingeschränkt werden Nutzer/innen, die für alle bestätigen dürfen (Recht mod/booking:seealllisttoapprove), Nutzer/innen der Personalabteilung sowie Trainer/innen und Manager/innen der Buchungsinstanz (Recht mod/booking:managebookedusers).';
$string['supervisorfield'] = 'Vorgesetztenfeld';
$string['workflowdescription'] = 'Mit diesem Workflow können Sie Vorgesetzte in einem Profilfeld festlegen. Außerdem können Nutzer:innen der Personalabteilung festgelegt werden. In verschiedenen Kombinationen können diese die Teilnahme auf Antrag erlauben.';
$string['workflowname'] = 'Bestätigungsworkflow durch Vorgesetzte/n';

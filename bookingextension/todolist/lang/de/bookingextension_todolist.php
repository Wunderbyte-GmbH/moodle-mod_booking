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
 * German language strings for the subplugin.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['bookingextension/todolist:checktodolist'] = 'Punkte der Aufgabenliste als erledigt markieren';
$string['bookingextension/todolist:edittodolist'] = 'Aufgabenliste im Buchungsoptionsformular bearbeiten';
$string['bookingextension/todolist:viewtodolist'] = 'Aufgabenliste in der Ansicht der Buchungsoption anzeigen';
$string['enable_todolist'] = 'Aufgabenliste aktivieren';
$string['enable_todolist_desc'] = 'Wenn diese Option aktiviert ist, kann diese Buchungsoption Aufgaben anzeigen und ihren Status verfolgen.';
$string['event:todolist_completed'] = 'Aufgabenliste abgeschlossen';
$string['event:todolist_completed_desc'] = 'Die Aufgabenliste für die Option {$a->optionid} wurde von Benutzer/in {$a->userid} abgeschlossen.';
$string['event:todolist_item_checked'] = 'Status eines Aufgabenlisteneintrags geändert';
$string['event:todolist_item_checked_desc'] = 'Der Aufgabenlisteneintrag "{$a->itemtext}" in Option {$a->optionid} wurde von Benutzer/in {$a->userid} als erledigt markiert.';
$string['event:todolist_item_unchecked'] = 'Markierung eines Aufgabenlisteneintrags entfernt';
$string['event:todolist_item_unchecked_desc'] = 'Die Erledigt-Markierung des Aufgabenlisteneintrags "{$a->itemtext}" in Option {$a->optionid} wurde von Benutzer/in {$a->userid} entfernt.';
$string['notification_item_checked'] = 'Aufgabe als erledigt markiert.';
$string['notification_item_unchecked'] = 'Aufgabe als nicht erledigt markiert.';
$string['notification_todolist_completed'] = 'Glückwunsch! Sie haben alle Aufgaben erledigt.';
$string['pluginname'] = 'Aufgabenliste';
$string['privacy:metadata'] = 'Das Plugin speichert keine personenbezogenen Daten.';
$string['ruledaysbefore_todolist_not_completed'] = 'Vor einem Datum (nur bei unvollständiger Aufgabenliste)';
$string['ruledaysbefore_todolist_not_completed_desc'] = 'Benachrichtigungen eine konfigurierte Anzahl von Tagen vor dem ausgewählten Datum senden, jedoch nur für Buchungsoptionen, deren Aufgabenliste noch nicht vollständig abgeschlossen ist.';
$string['ruledaysbeforetodolistnotcompleted'] = 'Tage vorher senden, aber nur bei unvollständigen Aufgabenlisten';
$string['ruledaysbeforetodoliststatus'] = 'Filter für den Abschlussstatus der Aufgabenliste';
$string['ruledaysbeforetodoliststatus_completed'] = 'Nur Optionen mit abgeschlossener Aufgabenliste';
$string['ruledaysbeforetodoliststatus_ignore'] = 'Status der Aufgabenliste ignorieren';
$string['ruledaysbeforetodoliststatus_not_completed'] = 'Nur Optionen mit unvollständiger Aufgabenliste';
$string['todolist'] = 'Aufgabenliste';
$string['todolist:checktodolist'] = 'Punkte der Aufgabenliste als erledigt markieren';
$string['todolist:edittodolist'] = 'Aufgabenliste im Buchungsoptionsformular bearbeiten';
$string['todolist:enableglobally'] = 'Erweiterung Aufgabenliste aktivieren';
$string['todolist:enableglobally_desc'] = 'Die Erweiterung Aufgabenliste für alle Seitenoptionen ein- oder ausschalten.';
$string['todolist:heading'] = 'Erweiterung Aufgabenliste';
$string['todolist:heading_desc'] = 'Grundlegender Einstellungsbereich für die Erweiterung Aufgabenliste.';
$string['todolist:viewtodolist'] = 'Aufgabenliste in der Ansicht der Buchungsoption anzeigen';
$string['todolist_empty'] = 'Keine Aufgaben konfiguriert.';
$string['todolist_items'] = 'Aufgabenliste';
$string['todolist_items_desc'] = 'Geben Sie pro Zeile eine Aufgabe ein.';
$string['todolist_items_help'] = 'Geben Sie pro Zeile eine Aufgabe ein. Erledigt-Markierungen werden zurückgesetzt, wenn sich der Inhalt der Liste ändert.';
$string['todolist_reset_completed_confirmation'] = 'Diese Liste enthält erledigte Aufgaben. Beim Speichern wird der gesamte Erledigt-Status zurückgesetzt.';

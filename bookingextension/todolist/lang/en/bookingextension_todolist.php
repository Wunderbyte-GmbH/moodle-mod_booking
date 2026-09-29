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
 * Language strings for the subplugin.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


$string['bookingextension/todolist:checktodolist'] = 'Mark todo list items as completed';
$string['bookingextension/todolist:edittodolist'] = 'Edit todo list in booking option form';
$string['bookingextension/todolist:viewtodolist'] = 'View todo list on booking option view';
$string['enable_todolist'] = 'Enable todo list';
$string['enable_todolist_desc'] = 'When enabled, this booking option can show and track todo items.';
$string['event:todolist_completed'] = 'Todo list completed';
$string['event:todolist_completed_desc'] = 'Todo list for option {$a->optionid} was completed by user {$a->userid}.';
$string['event:todolist_item_checked'] = 'Todo list item checked state changed';
$string['event:todolist_item_checked_desc'] = 'The Todolist item "{$a->itemtext}" in option {$a->optionid} was completed by user {$a->userid}.';
$string['event:todolist_item_unchecked'] = 'Todo list item unchecked';
$string['event:todolist_item_unchecked_desc'] = 'The Todolist item "{$a->itemtext}" in option {$a->optionid} was uncompleted by user {$a->userid}.';
$string['notification_item_checked'] = 'Todo item marked as done.';
$string['notification_item_unchecked'] = 'Todo item marked as not done.';
$string['notification_todolist_completed'] = 'Congratulations! You have completed all todo items.';
$string['pluginname'] = 'Todo list';
$string['privacy:metadata'] = 'The plugin does not store any personal data.';
$string['ruledaysbefore_todolist_not_completed'] = 'Before a date (only with incomplete todo list)';
$string['ruledaysbefore_todolist_not_completed_desc'] = 'Send notifications a configured number of days before the selected date, but only for booking options where the todo list is still incomplete.';
$string['ruledaysbeforetodolistnotcompleted'] = 'Send days before but only for incomplete todo lists';
$string['ruledaysbeforetodoliststatus'] = 'Todo list completion filter';
$string['ruledaysbeforetodoliststatus_completed'] = 'Only options with completed todo list';
$string['ruledaysbeforetodoliststatus_ignore'] = 'Ignore todo list status';
$string['ruledaysbeforetodoliststatus_not_completed'] = 'Only options with incomplete todo list';
$string['todolist'] = 'Todo list';
$string['todolist:checktodolist'] = 'Mark todo list items as completed';
$string['todolist:edittodolist'] = 'Edit todo list in booking option form';
$string['todolist:enableglobally'] = 'Enable todo list extension';
$string['todolist:enableglobally_desc'] = 'Turn the todo list booking extension on or off for the whole site.';
$string['todolist:heading'] = 'Todo list extension';
$string['todolist:heading_desc'] = 'Skeleton settings section for the Todo list booking extension.';
$string['todolist:viewtodolist'] = 'View todo list on booking option view';
$string['todolist_empty'] = 'No todo items configured.';
$string['todolist_items'] = 'Todo list';
$string['todolist_items_desc'] = 'Enter one todo item per line.';
$string['todolist_items_help'] = 'Enter one todo item per line. Checked states are reset when the list content changes.';
$string['todolist_reset_completed_confirmation'] = 'This list has completed items. Saving will reset all completion state.';

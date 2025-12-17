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
 * This file contains the definition for the renderable classes for booked users.
 *
 * It is used to display a configurable list of booked users for a given context.
 *
 * @package     mod_booking
 * @copyright   2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use local_wunderbyte_table\filters\types\standardfilter;
use mod_booking\table\scheduledmails_table;
use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes for booked users.
 *
 * It is used to display the list of scheduled mails.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduledmails implements renderable, templatable {
    /** @var string $renderedtable */
    public string $renderedtable = '';

    /**
     * Constructor.
     * @param int $contextid
     * @return void
     */
    public function __construct(int $contextid) {
        $table = new scheduledmails_table('scheduledmails', $contextid);

        $columns = [
            'rulename' => get_string('rulename', 'mod_booking'),
            'name' => get_string('name'),
            'subject' => get_string('subject', 'mod_booking'),
            'message' => get_string('messagetext', 'mod_booking'),
            'optionid' => get_string('bookingoption', 'mod_booking'),
            'cmid' => get_string('booking', 'mod_booking'),
            'nextruntime' => get_string('nextruntime', 'mod_booking'),
            'action' => get_string('actions'),
        ];

        $table->define_columns(array_keys($columns));
        $table->define_headers(array_values($columns));
        unset($columns['actions']);
        $table->define_sortablecolumns(array_keys($columns));
        [$fields, $from, $where, $params] = \mod_booking\local\scheduledmails::get_sql();
        $table->set_sql($fields, $from, $where, $params);
        $sql = "SELECT $fields FROM $from WHERE $where";

        $standardfilter = new standardfilter('rulename', get_string('rulename', 'mod_booking'));
        $table->add_filter($standardfilter);

        $standardfilter = new standardfilter('name', get_string('name'));
        $table->add_filter($standardfilter);

        $standardfilter = new standardfilter('cmid', get_string('booking', 'mod_booking'));
        $table->add_filter($standardfilter);

        $table->pageable(true);
        $table->showrowcountselect = true;
        $table->showcountlabel = true;
        $table->showdownloadbutton = true;
        $table->showfilterontop = true;
        $table->addcheckboxes = true;
        $table->fulltextsearchcolumns = ['rulename', 'name', 'subject', 'message'];

        $table->define_cache('mod_booking', 'scheduledmailscache');

        $table->actionbuttons[] = [
            'label' => get_string('delete'), // Name of your action button.
            'class' => 'btn btn-danger',
            'href' => '#',
            'methodname' => 'deleteitem', // The method needs to be added to your child of wunderbyte_table class.
            'nomodal' => false,
            'selectionmandatory' => true,
            'data' => [ // Will be added eg as data-id = $values->id, so values can be transmitted to the method above.
                'id' => 'id',
                'titlestring' => 'deletedatatitle',
                'bodystring' => 'deletedatabody',
                'submitbuttonstring' => 'deletedatasubmit',
                'component' => 'local_wunderbyte_table',
                'labelcolumn' => 'firstname',
            ],
        ];

        $this->renderedtable = $table->outhtml(5, true);
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'renderedtable' => $this->renderedtable,
        ];
    }
}

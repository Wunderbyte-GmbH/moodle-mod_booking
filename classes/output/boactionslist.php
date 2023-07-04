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
 * This file contains the definition for the renderable classes for the booking instance
 *
 * @package   mod_booking
 * @copyright 2022 Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2023 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class boactionslist implements renderable, templatable {

    /** @var array $actions */
    public $actions = [];

    /**
     * Constructor takes the actions to render and saves them as array.
     *
     * @param array $actions
     */
    public function __construct(array $actions) {

        foreach ($actions as $action) {

            $action->name = $actionobj->name;
            $action->actionname = $actionobj->actionname;
            $action->conditionname = $actionobj->conditionname;
            // Localize the names.
            $action->localizedactionname = get_string($action->actionname, 'mod_booking');
            $action->localizedactionname = get_string($actionobj->actionname, 'mod_booking');
            $action->localizedconditionname = get_string($actionobj->conditionname, 'mod_booking');

            $this->actions[] = (array)$action;
        }
    }

    public function export_for_template(renderer_base $output) {
        return array(
                'actions' => $this->actions
        );
    }
}

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
 * @copyright 2023 Georg Maißer {@link http://www.wunderbyte.at}
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
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class actionslist implements renderable, templatable {
    /** @var int $cmid */
    public $cmid = [];

    /** @var int $optionid */
    public $optionid = [];

    /** @var array $actions */
    public $actions = [];

    /**
     * Constructor takes the actions to render and saves them as array.
     *
     * @param int $cmid
     * @param int $optionid
     * @param array $actions
     *
     */
    public function __construct(int $cmid, int $optionid, $actions = []) {

        $this->optionid = $optionid;
        $this->cmid = $cmid;

        foreach ($actions as $action) {
            $action->name = $action->boactionname ?? $action->action_type;
            // Localize the names.
            $action->localizedactionname = get_string($action->action_type, 'mod_booking');

            $this->actions[] = (array)$action;
        }
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     *
     * @return array
     *
     */
    public function export_for_template(renderer_base $output) {
        return [
                'cmid' => $this->cmid,
                'optionid' => $this->optionid,
                'actions' => $this->actions,
        ];
    }
}

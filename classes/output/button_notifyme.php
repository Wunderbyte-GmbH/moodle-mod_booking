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
 * This file contains the definition for the renderable classes for column 'action'.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying the column 'action'.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class button_notifyme implements renderable, templatable {

    private $userid = 0;

    private $itemid = 0;

    private $onlist = false;

    /**
     * Dummy constructor
     */
    public function __construct(int $userid, int $itemid, bool $onlist = false) {
        $this->userid = $userid;
        $this->itemid = $itemid;
        $this->onlist = $onlist;
    }

    /**
     * Return data as array.
     *
     * @return void
     */
    public function return_as_array() {
        $returnarray = array(
            'userid' => $this->userid,
            'itemid' => $this->itemid,
            'area' => 'option',
        );

        if ($this->onlist) {
            $returnarray['onlist'] = true;
        }

        return $returnarray;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return $this->return_as_array();
    }
}

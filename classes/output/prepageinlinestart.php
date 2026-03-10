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
 * Renderable class for the inline-start pre-page.
 *
 * When a shortcode uses inlinestartpage="<conditionname>", that condition's page is rendered
 * directly on the page (no button click required). A "Continue" button then opens the
 * standard modal or inline collapse for any remaining pre-booking pages.
 *
 * @package   mod_booking
 * @copyright 2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use renderer_base;
use renderable;
use templatable;

/**
 * Prepares data for the inline-start pre-page template.
 *
 * @package     mod_booking
 * @copyright   2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prepageinlinestart implements renderable, templatable {

    /** @var int $optionid booking option id */
    public $optionid = 0;

    /** @var int $userid user id */
    public $userid = 0;

    /** @var string $conditionhtml server-rendered HTML for the inline start condition */
    public $conditionhtml = '';

    /** @var string $skipcondition condition shortname being shown inline (e.g. 'slotbooking') */
    public $skipcondition = '';

    /** @var int $remainingpages number of prepage pages remaining after the skipped condition */
    public $remainingpages = 0;

    /** @var bool $useinline true = remaining pages use inline collapse, false = modal */
    public $useinline = false;

    /**
     * Constructor.
     *
     * @param int $optionid
     * @param int $userid
     * @param string $conditionhtml server-rendered HTML for the condition shown inline
     * @param string $skipcondition condition shortname (used as data attribute for JS)
     * @param int $remainingpages count of remaining prepage pages after removing the skipped one
     * @param bool $useinline whether the remaining pages use inline collapse (true) or modal (false)
     */
    public function __construct(
        int $optionid,
        int $userid,
        string $conditionhtml,
        string $skipcondition,
        int $remainingpages,
        bool $useinline = false
    ) {
        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->conditionhtml = $conditionhtml;
        $this->skipcondition = $skipcondition;
        $this->remainingpages = $remainingpages;
        $this->useinline = $useinline;
    }

    /**
     * Export for template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        $now = time();
        $rand = random_int(1, 1000);

        // Two distinct uniquids: one for the inline-start area, one for the remaining pages container.
        $uniquid = substr(md5($this->optionid . $now . $rand . 'start'), 0, 16);
        $remaininguniqid = substr(md5($this->optionid . $now . $rand . 'remaining'), 0, 16);

        return [
            'uniquid'          => $uniquid,
            'remaininguniqid'  => $remaininguniqid,
            'optionid'         => $this->optionid,
            'userid'           => $this->userid,
            'conditionhtml'    => $this->conditionhtml,
            'skipcondition'    => $this->skipcondition,
            'remainingpages'   => $this->remainingpages,
            'hasremainingpages' => $this->remainingpages > 0,
            'useinline'        => $this->useinline,
        ];
    }
}

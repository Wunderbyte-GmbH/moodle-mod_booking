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
 * This file contains the definition for the renderable classes for column 'price'.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context_module;
use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying the pre page modal.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prepagemodal implements renderable, templatable {
    /** @var int $optionid as modal counter for more than one modals on a page. */
    public $optionid = 0;

    /** @var int $userid */
    public $userid = 0;

    /** @var int $totalnumberofpages int to pass on to js */
    public $totalnumberofpages = 0;

    /** @var string $buttoncondition classname of the condition which renders the button */
    public $buttoncondition = "";

    /** @var string $outsidebuttonhtml  */
    public $buttonhtml = "";

    /** @var string $inmodalbuttonhtml  */
    public $inmodalbuttonhtml = "";

    /** @var string $results  */
    public $results = "";
    /**
     * Constructor
     *
     * @param mixed $settings
     * @param int $totalnumberofpages
     * @param string $buttoncondition
     * @param string $extrabuttoncondition
     * @param int $userid
     */
    public function __construct(
        $settings,
        int $totalnumberofpages,
        string $buttoncondition,
        string $extrabuttoncondition = '',
        int $userid = 0
    ) {

        global $PAGE;

        $context = context_module::instance($settings->cmid);

        // Verification required to avoid error like "unsupported modification of PAGE->context from xx to yy".
        if (!isset($PAGE->context->contextlevel)) {
            $PAGE->set_context($context);
        }

        if (has_capability('mod/booking:bookforothers', $context)) {
            $full = true;
        } else {
            $full = false;
        }

        $this->optionid = $settings->id;
        $this->totalnumberofpages = $totalnumberofpages;
        $this->buttoncondition = $buttoncondition;
        $this->userid = $userid;
        $condition = new $buttoncondition();
        [$template, $data] = $condition->render_button($settings, $userid, $full);

        if (!empty($extrabuttoncondition)) {
            if (method_exists($extrabuttoncondition, 'instance')) {
                $extracondition = $extrabuttoncondition::instance();
            } else {
                $extracondition = new $extrabuttoncondition();
            }
            [$extratemplate, $extradata] = $extracondition->render_button($settings, $userid, $full);
            if (!empty($data['main']) || $full) { // Full means has capability "bookforothers" & therefore 2 areas: top & main.
                $extradata['top'] = $extradata["main"];
                $extradata['main'] = $data['main'] ?? [];
            }
            $data = $extradata;
        }

        $data['nojs'] = true;
        $data = new bookit_button($data);
        $output = $PAGE->get_renderer('mod_booking');
        $this->buttonhtml = $output->render_bookit_button($data, $template);
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        $now = time();
        $rand = rand(1, 1000);

        return [
            'uniquid' => md5($this->optionid . $now . $rand),
            'optionid' => $this->optionid,
            'totalnumberofpages' => $this->totalnumberofpages,
            'buttonhtml' => $this->buttonhtml,
            'inmodalbuttonhtml' => $this->inmodalbuttonhtml,
            'userid' => $this->userid,
        ];
    }
}

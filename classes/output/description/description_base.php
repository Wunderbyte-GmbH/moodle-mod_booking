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

namespace mod_booking\output\description;

use mod_booking\output\bookingoption_description;
use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;

/**
 * Base class to render the full description (including custom fields).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Mahdi Poustini
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class description_base {
    /**
     * optionid
     * @var int
     */
    protected int $optionid;
    /**
     * output
     * @var \mod_booking\output\renderer
     */
    protected \mod_booking\output\renderer $output;

    /**
     * data
     * @var bookingoption_description
     */
    protected bookingoption_description $data;

    /**
     * forbookeduser
     * @var bool
     */
    protected bool $forbookeduser = false;

    /**
     * Template name.
     * Can be varying based on the description param.
     * This shoud be set in the child class.
     * @var int
     */
    protected string $template = 'mod_booking/bookingoption_description';

    /**
     * Description param.
     * This shoud be set in the child class.
     * Possible values are:
     *   MOD_BOOKING_DESCRIPTION_WEBSITE,
     *   MOD_BOOKING_DESCRIPTION_ICAL,
     *   MOD_BOOKING_DESCRIPTION_MAIL,
     *   MOD_BOOKING_DESCRIPTION_CALENDAR,
     *   etc.
     *
     * @var int
     */
    protected int $param = MOD_BOOKING_DESCRIPTION_WEBSITE;

    /**
     * Constructor.
     * @param int $optionid
     * @param bool $forbookeduser
     * return void
     */
    public function __construct(
        int $optionid,
        bool $forbookeduser = false,
    ) {
        global $PAGE;
        $this->optionid = $optionid;
        $this->forbookeduser = $forbookeduser;
        $this->data = new bookingoption_description($optionid, null, $this->param, true, $forbookeduser);
        $this->output = $PAGE->get_renderer('mod_booking');
    }

    /**
     * Renders the description using default template.
     * @return string
     */
    public function render(): string {
        $o = '';
        $data = $this->data->export_for_template($this->output);
        $o .= $this->output->render_from_template($this->template, $data);
        return $o;
    }

    /**
     * Renders the description using custom template if available.
     * @param string $customfieldshortname Short name of the custom field containing the user defined template.
     * @return string
     */
    protected function render_custom_template_from_customfield($customfieldshortname): string {

        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);

        $o = '';
        // If there is a user defined template for iCal description, use it.
        if (!empty($settings->customfields[$customfieldshortname])) {
            $userdefinedtemplate = $settings->customfields[$customfieldshortname];
            // We use the placeholders_info class to render the text with placeholders.
            $o = placeholders_info::render_text(
                $userdefinedtemplate,
                $settings->cmid,
                $this->optionid,
                0,
                0,
                0,
                0,
                $this->param
            );
        }

        return $o;
    }

    /**
     * Sets the description param.
     *
     * The `description` parameter is normally set by child classes.
     * However, it can also be set using this method.
     *
     * @param int $param
     * @return void
     */
    public function set_description_param(int $param): void {
        $this->param = $param;
        $this->data = new bookingoption_description($this->optionid, null, $this->param, true, $this->forbookeduser);
    }
}

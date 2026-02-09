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

use mod_booking\placeholders\placeholders_info;
use mod_booking\singleton_service;

/**
 * Class description_calendarevent
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class description_calendarevent extends description_base {
    /**
     * Template name.
     * @var int
     */
    protected string $template = 'mod_booking/bookingoption_description_event';

    /**
     * Description param.
     * @var int
     */
    protected int $param = MOD_BOOKING_DESCRIPTION_CALENDAR;

    /**
     * Render the description.
     *
     * @return string
     */
    /**
     * Render the description.
     *
     * @return string
     */
    /**
     * Render the description.
     *
     * @return string
     */
    public function render(): string {

        // For description_calendarevent we accept user defined templates if available.
        // So we get the custom field short name for calendar event description.
        $cfshortname = get_config('booking', 'caleventdescriptionfield');

        $custom = parent::render_custom_template_from_customfield($cfshortname);
        if (!empty($custom)) {
            return $custom;
        }

        return parent::render();
    }
}

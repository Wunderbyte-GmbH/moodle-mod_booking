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
 * Helper functions for the Bookings tracker (report2).
 *
 * @package mod_booking
 * @author Bernhard Fischer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\bookingstracker;

use stdClass;
use moodle_url;
use mod_booking\booking;

/**
 * Helper functions for the Bookings tracker (report2).
 *
 * @package mod_booking
 * @author Bernhard Fischer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingstracker_helper {
    /**
     * Link to a specific booking option view.
     * @var moodle_url|null
     */
    protected ?moodle_url $optionviewlink = null;

    /**
     * Report (report2.php) link scoped to a single option.
     * @var moodle_url|null
     */
    protected ?moodle_url $reportoptionlink = null;

    /**
     * Report (report2.php) link scoped to the booking instance (cmid).
     * @var moodle_url|null
     */
    protected ?moodle_url $reportinstancelink = null;

    /**
     * Report (report2.php) link scoped to the course.
     * @var moodle_url|null
     */
    protected ?moodle_url $reportcourselink = null;

    /**
     * Report (report2.php) link scoped to the whole system.
     * @var moodle_url|null
     */
    protected ?moodle_url $reportsystemlink = null;

    /**
     * Raw values provided by caller (cmid, optionid, courseid, text, etc.).
     *
     * @var stdClass
     */
    protected stdClass $values;

    /**
     * HTML as an icon for the text.
     * @var string
     */
    protected string $texticon = '<i class="fa fa-ticket" aria-hidden="true"></i>&nbsp;';

    /**
     * Return option column.
     *
     * @param stdClass $values
     * @return void
     */
    public function __construct(stdClass $values) {
        $this->values = $values;

        // Set default values for each property.
        // You can change each link with setters when you instantiate the class.
        $this->optionviewlink = new moodle_url(
            '/mod/booking/view.php',
            [
                'id' => $this->values->cmid,
                'optionid' => $this->values->optionid,
                'whichview' => 'showonlyone',
            ]
        );

        // Report2option.
        $this->reportoptionlink = new moodle_url(
            '/mod/booking/report2.php',
            [
                'cmid' => $this->values->cmid,
                'optionid' => $this->values->optionid,
            ]
        );

        // Report2instance.
        $this->reportinstancelink = new moodle_url(
            '/mod/booking/report2.php',
            ['cmid' => $this->values->cmid]
        );

        // Report2course.
        $this->reportcourselink = new moodle_url(
            '/mod/booking/report2.php',
            ['courseid' => $this->values->courseid]
        );

        $this->reportsystemlink = new moodle_url(
            '/mod/booking/report2.php'
        );
    }

    /**
     * Return option column.
     *
     * @return string
     */
    public function render_col_text(): string {
        global $OUTPUT, $SITE;

        if (empty($this->values->optionid)) {
            return '';
        }

        $data = [
            'id' => $this->values->id, // Can be optionid or answerid, depending on scope.
            'text' => $this->values->text,
            'texticon' => $this->texticon,
            'optionlink' => $this->optionviewlink->out(false),
            'report2option' => $this->reportoptionlink->out(false),
            'report2instance' => $this->reportinstancelink->out(false),
            'report2course' => $this->reportcourselink->out(false),
            'report2system' => $this->reportsystemlink->out(false),
            'instancename' => !empty($this->values->instancename) ? booking::shorten_text($this->values->instancename) : null,
            'coursename' => !empty($this->values->coursename) ? booking::shorten_text($this->values->coursename) : null,
            'systemname' => $SITE->fullname ? booking::shorten_text($SITE->fullname) : null,
        ];

        $output = $OUTPUT->render_from_template('mod_booking/report/option', $data);
        if (empty($output)) {
            return '';
        }
        return (string) $output;
    }

    /* ====================================================================== *
     * Setters (fluent)                                                       *
     * ====================================================================== */

    /**
     * Set the option view link.
     *
     * @param moodle_url $url
     * @return self
     */
    public function set_optionviewlink(moodle_url $url): self {
        $this->optionviewlink = $url;
        return $this;
    }

    /**
     * Set the report link for a single option.
     *
     * @param moodle_url $url
     * @return self
     */
    public function set_reportoptionlink(moodle_url $url): self {
        $this->reportoptionlink = $url;
        return $this;
    }

    /**
     * Set the report link for the booking instance (cmid).
     *
     * @param moodle_url $url
     * @return self
     */
    public function set_reportinstancelink(moodle_url $url): self {
        $this->reportinstancelink = $url;
        return $this;
    }

    /**
     * Set the report link for the course.
     *
     * @param moodle_url $url
     * @return self
     */
    public function set_reportcourselink(moodle_url $url): self {
        $this->reportcourselink = $url;
        return $this;
    }

    /**
     * Set the system-wide report link.
     *
     * @param moodle_url $url
     * @return self
     */
    public function set_reportsystemlink(moodle_url $url): self {
        $this->reportsystemlink = $url;
        return $this;
    }

    /**
     * Sets the icon of the text.
     * @param string $html
     * @return void
     */
    public function set_texticon(string $html) {
        $this->texticon = $html;
    }

    /* ====================================================================== *
     * Getters                                                                *
     * ====================================================================== */

    /**
     * Get the option view link (lazy).
     *
     * @return moodle_url
     */
    public function get_optionviewlink(): moodle_url {
        return $this->optionviewlink;
    }

    /**
     * Get the report link scoped to a single option (lazy).
     *
     * @return moodle_url
     */
    public function get_reportoptionlink(): moodle_url {
        return $this->reportoptionlink;
    }

    /**
     * Get the report link scoped to the booking instance (cmid) (lazy).
     *
     * @return moodle_url
     */
    public function get_reportinstancelink(): moodle_url {
        return $this->reportinstancelink;
    }

    /**
     * Get the report link scoped to the course (lazy).
     *
     * @return moodle_url
     */
    public function get_reportcourselink(): moodle_url {
        return $this->reportcourselink;
    }

    /**
     * Get the system-wide report link.
     *
     * @return moodle_url
     */
    public function get_reportsystemlink(): moodle_url {
        return $this->reportsystemlink;
    }
}

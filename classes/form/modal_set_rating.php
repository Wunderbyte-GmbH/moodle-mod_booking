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
 * Dynamic form (modal) to rate the checked booked users of a booking option.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");
require_once("$CFG->dirroot/rating/lib.php");
require_once("$CFG->dirroot/mod/booking/lib.php");

use cache_helper;
use context;
use context_system;
use context_module;
use core_form\dynamic_form;
use mod_booking\singleton_service;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Dynamic form (modal) to rate the checked booked users of a booking option.
 *
 * Migrated from the old report.php bulk action postratingsubmit: writes the
 * chosen rating for every checked booking answer through booking_rate() (the
 * standard Moodle rating API, itemid = booking_answers.id). Selecting the
 * "Rate..." entry (RATING_UNSET_RATING) removes an existing rating.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_set_rating extends dynamic_form {
    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;

        $cmid = $this->_ajaxformdata['cmid'] ?? 0;
        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $optionid = $this->_ajaxformdata['optionid'] ?? 0;
        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        // Booking answer IDs of the checked rows.
        $mform->addElement('hidden', 'checkedids', '');
        $mform->setType('checkedids', PARAM_TEXT);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_RAW);

        if (!empty($this->_ajaxformdata['checkedids'])) {
            $mform->addElement(
                'select',
                'rating',
                get_string('rating', 'core_rating'),
                self::rating_choices((int)$cmid)
            );
            $mform->setType('rating', PARAM_INT);
            $mform->setDefault('rating', RATING_UNSET_RATING);
        } else {
            $mform->addElement(
                'html',
                '<div class="alert alert-warning">'
                . get_string('norowsselected', 'mod_booking')
                . '</div>'
            );
        }
    }

    /**
     * The selectable rating values of the booking instance.
     *
     * Numeric scale (scale > 0): the values 0..scale. Custom scale
     * (scale < 0): the items of the referenced {scale} record, keyed 1..n.
     * The RATING_UNSET_RATING entry ("Rate...") removes an existing rating.
     *
     * @param int $cmid
     * @return array
     */
    public static function rating_choices(int $cmid): array {
        global $DB;

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $scaleid = (int)($bookingsettings->scale ?? 0);

        $choices = [RATING_UNSET_RATING => get_string('rate', 'core_rating') . '...'];
        if ($scaleid < 0) {
            if ($scale = $DB->get_record('scale', ['id' => -$scaleid])) {
                $items = explode(',', $scale->scale);
                foreach ($items as $index => $item) {
                    $choices[$index + 1] = format_string(trim($item));
                }
            }
        } else {
            for ($i = 0; $i <= $scaleid; $i++) {
                $choices[$i] = (string)$i;
            }
        }

        return $choices;
    }

    /**
     * Check access for dynamic submission.
     *
     * Mirrors the old report.php gate: teachers of the option or users with
     * moodle/rating:rate may rate; ratings must be enabled on the instance
     * (assessed != RATING_AGGREGATE_NONE). booking_rate() additionally
     * enforces the plugin rating permissions (mod/booking:rate).
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        $cmid = (int)($this->_ajaxformdata['cmid'] ?? 0);
        $optionid = (int)($this->_ajaxformdata['optionid'] ?? 0);
        $context = $this->get_context_for_dynamic_submission();

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        if (empty($bookingsettings->assessed)) {
            throw new moodle_exception('ratepermissiondenied', 'rating');
        }

        if (!booking_check_if_teacher($optionid)) {
            require_capability('moodle/rating:rate', $context);
        }
    }

    /**
     * Process the form submission, used if form was submitted via AJAX.
     *
     * @return mixed
     */
    public function process_dynamic_submission() {
        global $DB, $USER;

        $data = $this->get_data();
        $cmid = (int)$data->cmid;
        $optionid = (int)$data->optionid;

        if (empty($data->checkedids)) {
            $data->checkedids = $data->id;
        }
        $checkedids = explode(',', (string)$data->checkedids);
        $checkedids = array_filter($checkedids, fn($checkedid) => !empty($checkedid));
        if (empty($checkedids)) {
            return $data;
        }

        // A value outside the select options is discarded by the form
        // (exportValue cleaning), so rating is unset then: write nothing.
        if (!isset($data->rating)) {
            return $data;
        }

        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $context = context_module::instance($cmid);

        $ratings = [];
        foreach ($checkedids as $answerid) {
            $answer = $DB->get_record('booking_answers', ['id' => (int)$answerid]);
            if (empty($answer) || (int)$answer->optionid !== $optionid) {
                continue;
            }
            // Users must not rate themselves (booking_rating_validate would
            // reject it) - the old report.php skipped them silently too.
            if ((int)$answer->userid === (int)$USER->id) {
                continue;
            }

            $rating = new stdClass();
            $rating->rateduserid = (int)$answer->userid;
            $rating->itemid = (int)$answer->id;
            $rating->rating = (int)$data->rating;
            $ratings[(int)$answer->id] = $rating;
        }

        if (!empty($ratings)) {
            $params = new stdClass();
            $params->contextid = $context->id;
            $params->scaleid = (int)($bookingsettings->scale ?? 0);
            $params->returnurl = $this->get_page_url_for_dynamic_submission()->out(false);

            booking_rate($ratings, $params);

            cache_helper::purge_by_event('setbackbookedusertable');
        }

        return $data;
    }

    /**
     * Load in existing data as form defaults.
     */
    public function set_data_for_dynamic_submission(): void {
        $data = (object)$this->_ajaxformdata;
        $this->set_data($data);
    }

    /**
     * Returns form context.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = $this->_ajaxformdata['cmid'] ?? 0;
        if (empty($cmid)) {
            $cmid = $this->optional_param('cmid', 0, PARAM_INT);
            if ($cmid == 0) {
                return context_system::instance();
            }
        }
        return context_module::instance($cmid);
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        $optionid = $this->_ajaxformdata['optionid'] ?? 0;
        if (empty($optionid)) {
            $optionid = $this->optional_param('optionid', 0, PARAM_INT);
        }
        if (!empty($optionid)) {
            return new moodle_url('/mod/booking/report2.php', ['optionid' => $optionid]);
        }
        return new moodle_url('/mod/booking/report2.php');
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        return [];
    }
}

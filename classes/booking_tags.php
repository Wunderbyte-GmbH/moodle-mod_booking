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

namespace mod_booking;

/**
 * Tags templates
 *
 * @package mod-booking
 * @copyright 2014 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_tags {

    public $tags;

    public $replaces;

    public $optionschangetext = array('text', 'description', 'location', 'institution', 'address',
            'beforebookedtext', 'beforecompletedtext', 'aftercompletedtext');

    public $bookingchangetext = array('name', 'intro', 'bookingpolicy', 'bookedtext', 'notifyemail',
            'waitingtext', 'statuschangetext', 'deletedtext', 'bookingchangedtext', 'duration', 'organizatorname',
            'pollurltext', 'eventtype', 'notificationtext', 'userleave', 'pollurlteacherstext',
            'beforebookedtext', 'beforecompletedtext', 'aftercompletedtext');

    private $option;

    /**
     * booking_tags constructor.
     *
     * @param integer $courseid
     * @throws \dml_exception
     */
    public function __construct($courseid) {
        global $DB;

        $this->tags = $DB->get_records('booking_tags', array('courseid' => $courseid));
        $this->replaces = $this->prepare_replaces();
    }

    public function get_all_tags() {
        return $this->tags;
    }

    private function prepare_replaces() {
        $keys = array();
        $values = array();

        foreach ($this->tags as $tag) {
            $keys[] = "[{$tag->tag}]";
            $values[] = $tag->text;
        }

        return array('keys' => $keys, 'values' => $values);
    }

    public function get_replaces() {
        return $this->replaces;
    }

    public function tag_replaces($text) {
        return str_replace($this->replaces['keys'], $this->replaces['values'], $text);
    }

    public function booking_replace($bookingtmp = null) {
        $booking = clone $bookingtmp;
        foreach ($booking as $key => $value) {
            if (in_array($key, $this->bookingchangetext)) {
                $booking->{$key} = $this->tag_replaces($booking->{$key});
            }
        }

        return $booking;
    }

    public function option_replace($option = null) {
        $this->option = clone $option;
        foreach ($this->option as $key => $value) {
            if (in_array($key, $this->optionschangetext)) {
                $this->option->{$key} = $this->tag_replaces($this->option->{$key});
            }
        }

        return $this->option;
    }
}

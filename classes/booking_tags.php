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

use stdClass;

/**
 * Tags templates.
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2014 Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_tags {

    public $tags;

    public $replaces;

    public $optiontextfields = ['text', 'description', 'location', 'institution', 'address',
                                'beforebookedtext', 'beforecompletedtext', 'aftercompletedtext',
                                ];

    public $bookingtextfields = ['name', 'intro', 'bookingpolicy', 'bookedtext', 'notifyemail',
                                'waitingtext', 'statuschangetext', 'deletedtext', 'bookingchangedtext', 'duration',
                                'organizatorname', 'pollurltext', 'eventtype', 'notificationtext', 'userleave',
                                'pollurlteacherstext', 'beforebookedtext', 'beforecompletedtext', 'aftercompletedtext',
                                ];

    private $option;

    /**
     * Booking_tags constructor.
     *
     * @param int $courseid
     * @throws \dml_exception
     */
    public function __construct($courseid) {
        global $DB;

        $this->tags = $DB->get_records('booking_tags', ['courseid' => $courseid]);
        $this->replaces = $this->prepare_replaces();
    }

    public function get_all_tags() {
        return $this->tags;
    }

    private function prepare_replaces() {
        $keys = [];
        $values = [];

        foreach ($this->tags as $tag) {
            $keys[] = "[{$tag->tag}]";
            $values[] = $tag->text;
        }

        return ['keys' => $keys, 'values' => $values];
    }

    public function get_replaces() {
        return $this->replaces;
    }

    public function tag_replaces($text) {
        return str_replace($this->replaces['keys'], $this->replaces['values'], $text);
    }

    public function booking_replace(stdClass $settings = null): stdClass {
        $newsettings = clone $settings;
        foreach ($newsettings as $key => $value) {
            if (in_array($key, $this->bookingtextfields) && (!is_null($newsettings->{$key}))) {
                $newsettings->{$key} = $this->tag_replaces($newsettings->{$key});
            }
        }
        return $newsettings;
    }

    public function option_replace(stdClass $optionsettings = null): stdClass {
        $newoptionsettings = clone $optionsettings;
        foreach ($newoptionsettings as $key => $value) {
            if (in_array($key, $this->optiontextfields) && (!is_null($newoptionsettings->{$key}))) {
                $newoptionsettings->{$key} = $this->tag_replaces($newoptionsettings->{$key});
            }
        }
        return $newoptionsettings;
    }
}

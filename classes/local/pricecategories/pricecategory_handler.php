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
 * Price categories handling
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer, Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\pricecategories;
defined('MOODLE_INTERNAL') || die();
use cache_helper;
use context_system;
use moodle_url;

/**
 * Class pricecategory_handler
 * Handles operations related to price categories.
 */
class pricecategory_handler {
    /**
     * Ensure a default price category exists during installation.
     */
    public static function ensure_default_category() {
        global $DB;
        if (!$DB->record_exists('booking_pricecategories', [])) {
            $default = new pricecategory((object) [
                'ordernum' => 1,
                'identifier' => 'default',
                'name' => get_string('defaultpricecategoryname', 'mod_booking'),
                'defaultvalue' => 0.0,
                'pricecatsortorder' => 1,
                'disabled' => false,
            ]);
            $default->save();
        }
    }

    /**
     * Process form data and update price categories.
     *
     * @param \stdClass $data
     */
    public static function process_form_data($data) {
        global $DB, $USER;
        $pricecategory = new pricecategory($data);
        $existingcategories = $pricecategory->get_all();
        $updates = [];
        $inserts = [];

        foreach ($data as $key => $value) {
            if (preg_match('/pricecategoryid(\d+)/', $key, $matches)) {
                $counter = (int)$matches[1];
                $oldcategory = $pricecategory->get_by_id($value);
                $newidentifier = $data->{'pricecategoryidentifier' . $counter};

                $category = new pricecategory((object) [
                    'id' => $value,
                    'ordernum' => $data->{'pricecategoryordernum' . $counter},
                    'identifier' => $newidentifier,
                    'name' => $data->{'pricecategoryname' . $counter},
                    'defaultvalue' => $data->{'defaultvalue' . $counter},
                    'pricecatsortorder' => $data->{'pricecatsortorder' . $counter},
                    'disabled' => !empty($data->{'disablepricecategory' . $counter}),
                ]);

                if ($category->id) {
                    if ($oldcategory && $oldcategory->identifier !== $newidentifier) {
                        $event = \mod_booking\event\pricecategory_changed::create([
                            'objectid' => $category->id,
                            'context' => context_system::instance(),
                            'relateduserid' => $USER->id,
                            'other' => [
                                'oldidentifier' => $oldcategory->identifier,
                                'newidentifier' => $newidentifier,
                            ],
                        ]);
                        $event->trigger();
                    }
                    $updates[] = $category;
                } else {
                    $inserts[] = $category;
                }
            }
        }

        foreach ($updates as $category) {
            $DB->update_record('booking_pricecategories', $category);
        }

        foreach ($inserts as $category) {
            $DB->insert_record('booking_pricecategories', $category);
        }

        // Purge cache after update.
        cache_helper::purge_by_event('setbackpricecategories');
    }

    /**
     * Sets up the admin page for price categories.
     */
    public static function setup_admin_page() {
        global $PAGE, $SITE;

        require_login(0, false);
        admin_externalpage_setup('modbookingpricecategories');

        $PAGE->set_url(new moodle_url('/mod/booking/pricecategories.php'));
        $PAGE->set_title(format_string($SITE->shortname) . ': ' . get_string('pricecategories', 'booking'));
    }
}

// Call setup_admin_page to initialize the admin page.
self::setup_admin_page();

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

namespace mod_booking\local;

use cache_helper;
use mod_booking\event\pricecategory_changed;
use mod_booking\form\pricecategories_form;
use stdClass;
/**
 * Handles price category operations.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH
 * @author     David Ala
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pricecategories_handler {
    /**
     * Process form data and update price categories.
     *
     * @param /stdClass $data
     */
    public function process_pricecategories_form($data) {
        global $DB;
        // Get existing price categories.
        $oldcategories = $DB->get_records('booking_pricecategories');
        $changes = $this->get_pricecategory_changes($oldcategories, $data);

        // Update existing price categories.
        foreach ($changes['updates'] as $record) {
            $DB->update_record('booking_pricecategories', $record);
            $this->trigger_pricecategory_changed_event($oldcategories[$record->id]->identifier, $record->identifier, $record->id);
        }

        // Insert new price categories.
        if (!empty($changes['inserts'])) {
            $DB->insert_records('booking_pricecategories', $changes['inserts']);
        }

        cache_helper::purge_by_event('setbackpricecategories');
    }

    /**
     * Inserts a new price category.
     *
     * @param stdClass $data Form data
     * @param int $index Index of the category
     */
    private function insert_pricecategory($data, $index) {
        global $DB;

        $pricecategory = new stdClass();
        $pricecategory->ordernum = $data->{"pricecategoryordernum$index"};
        $pricecategory->identifier = $data->{"pricecategoryidentifier$index"};
        $pricecategory->name = $data->{"pricecategoryname$index"};
        $pricecategory->defaultvalue = $data->{"defaultvalue$index"};
        $pricecategory->pricecatsortorder = $data->{"pricecatsortorder$index"};
        $pricecategory->disabled = $data->{"disablepricecategory$index"};

        $DB->insert_record('booking_pricecategories', $pricecategory);
    }

    /**
     * Triggers an event when a price category identifier is changed.
     *
     * @param string $oldidentifier
     * @param string $newidentifier
     * @param int $id
     */
    private function trigger_pricecategory_changed_event($oldidentifier, $newidentifier, $id) {
        global $USER;

        if ($oldidentifier !== $newidentifier) {
            $event = pricecategory_changed::create([
                'objectid' => $id,
                'context' => \context_system::instance(),
                'relateduserid' => $USER->id,
                'other' => [
                    'oldidentifier' => $oldidentifier,
                    'newidentifier' => $newidentifier,
                ],
            ]);
            $event->trigger();
        }
    }

    /**
     * Determines changes in price categories (updates & inserts).
     *
     * @param array $oldpricecategories Existing price categories
     * @param \stdClass $data Form data
     * @return array
     */
    private function get_pricecategory_changes($oldpricecategories, $data) {
        $updates = [];
        $inserts = [];

        $existingordernumbers = [];
        foreach ($oldpricecategories as $oldpricecategory) {
            $existingordernumbers[] = $oldpricecategory->ordernum;
            // We need this as fallback.
            $oldpricecategorybackups[$oldpricecategory->ordernum] = $oldpricecategory;
        }

        foreach ($data as $key => $value) {
            if (preg_match('/pricecategoryid[0-9]/', $key)) {
                $counter = (int)substr($key, -1);

                if (in_array($counter, $existingordernumbers)) {
                    $pricecategory = new stdClass();
                    $pricecategory->id = $value;
                    $pricecategory->ordernum = $data->{"pricecategoryordernum$counter"};
                    $pricecategory->identifier = $data->{"pricecategoryidentifier$counter"}
                        ?? $oldpricecategorybackups[$counter]->identifier;
                    $pricecategory->name = $data->{"pricecategoryname$counter"}
                        ?? $oldpricecategorybackups[$counter]->name;
                    $pricecategory->defaultvalue = str_replace(
                        ',',
                        '.',
                        $data->{"defaultvalue$counter"} ?? $oldpricecategorybackups[$counter]->defaultvalue
                    );
                    $pricecategory->pricecatsortorder = $data->{"pricecatsortorder$counter"}
                        ?? $oldpricecategorybackups[$counter]->pricecatsortorder;
                    $pricecategory->disabled = $data->{"disablepricecategory$counter"};

                    $updates[] = $pricecategory;
                } else {
                    if (!empty($data->{"pricecategoryidentifier$counter"})) {
                        $pricecategory = new stdClass();
                        $pricecategory->ordernum = $data->{"pricecategoryordernum$counter"};
                        $pricecategory->identifier = $data->{"pricecategoryidentifier$counter"};
                        $pricecategory->name = $data->{"pricecategoryname$counter"};
                        $pricecategory->defaultvalue = str_replace(',', '.', $data->{"defaultvalue$counter"});
                        $pricecategory->pricecatsortorder = $data->{"pricecatsortorder$counter"};
                        $pricecategory->disabled = $data->{"disablepricecategory$counter"};

                        $inserts[] = $pricecategory;
                    }
                }
            }
        }

        return ['inserts' => $inserts, 'updates' => $updates];
    }

    /**
     * Returns all Pricecategories
     *
     * @return array
     *
     */
    public function get_pricecategories() {
        global $DB;
        return $DB->get_records('booking_pricecategories', null, 'id ASC');
    }
}

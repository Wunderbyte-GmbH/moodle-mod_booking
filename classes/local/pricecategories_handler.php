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

        if (!empty($data->pricecategoryid)) {
            foreach ($data->pricecategoryid as $key => $value) {
                // Key starts from 0, so we need to add 1 to it.
                if (in_array($value, array_keys($oldpricecategories))) {
                    $pricecategory = new stdClass();
                    $pricecategory->id = $value;
                    $pricecategory->identifier = $data->pricecategoryidentifier[$key];
                    $pricecategory->name = $data->pricecategoryname[$key];
                    $pricecategory->defaultvalue = str_replace(
                        ',',
                        '.',
                        $data->defaultvalue[$key]
                    );
                    $pricecategory->pricecatsortorder = $data->pricecatsortorder[$key];
                    // We don't need ordernum anymore, so we just store pricecatsortorder there too.
                    $pricecategory->ordernum = $data->pricecatsortorder[$key];
                    $pricecategory->disabled = $data->disablepricecategory[$key];

                    $updates[] = $pricecategory;
                } else {
                    if (!empty($data->pricecategoryidentifier[$key])) {
                        $pricecategory = new stdClass();
                        $pricecategory->identifier = $data->pricecategoryidentifier[$key];
                        $pricecategory->name = $data->pricecategoryname[$key];
                        $pricecategory->defaultvalue = str_replace(',', '.', $data->defaultvalue[$key]);
                        $pricecategory->pricecatsortorder = $data->pricecatsortorder[$key];
                        // We don't need ordernum anymore, so we just store pricecatsortorder there too.
                        $pricecategory->ordernum = $data->pricecatsortorder[$key];
                        $pricecategory->disabled = $data->disablepricecategory[$key];

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

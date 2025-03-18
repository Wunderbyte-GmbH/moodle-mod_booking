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

namespace mod_booking\local\pricecategories;
/**
 * Class pricecategory
 * Represents a single price category.
 * @package mod_booking
 */
class pricecategory {
    public int $id;
    public int $ordernum;
    public string $identifier;
    public string $name;
    public float $defaultvalue;
    public int $pricecatsortorder;
    public bool $disabled;

    public function __construct($data) {
        $this->id = $data->id;
        $this->ordernum = $data->ordernum;
        $this->identifier = $data->identifier;
        $this->name = $data->name;
        $this->defaultvalue = $data->defaultvalue;
        $this->pricecatsortorder = $data->pricecatsortorder;
        $this->disabled = !empty($data->disabled);
    }

    /**
     * Saves the price category to the database.
     */
    public function save() {
        global $DB;
        $record = (object) [
            'id' => $this->id,
            'ordernum' => $this->ordernum,
            'identifier' => $this->identifier,
            'name' => $this->name,
            'defaultvalue' => $this->defaultvalue,
            'pricecatsortorder' => $this->pricecatsortorder,
            'disabled' => $this->disabled,
        ];

        if ($this->id) {
            $DB->update_record('booking_pricecategories', $record);
        } else {
            $this->id = $DB->insert_record('booking_pricecategories', $record);
        }
    }
    /**
     * Fetch all price categories.
     *
     * @return pricecategory[]
     */
    public static function get_all(): array {
        global $DB;
        $records = $DB->get_records('booking_pricecategories');
        return array_map(fn($record) => new pricecategory($record), $records);
    }

    /**
     * Get a single price category by ID.
     *
     * @param int $id
     * @return pricecategory|null
     */
    public static function get_by_id(int $id): ?pricecategory {
        global $DB;
        if ($record = $DB->get_record('booking_pricecategories', ['id' => $id])) {
            return new pricecategory($record);
        }
        return null;
    }

}

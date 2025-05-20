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
 * Price class.
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author    Mahdi Poustini
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\local\respondapi\providers\interfaces;

/**
 * Interface for response API providers.
 *
 * Defines the contract any external API provider must follow.
 */
interface respondapi_provider_interface {
    /**
     * Sync (create or update) a keyword in the external API.
     *
     * @param string $name The name/title of the keyword (required).
     * @param int|null $id The ID of the keyword to update, or null to create new.
     * @param string|null $comment Optional description.
     * @param int|null $parentid Optional parent ID.
     * @return int|null The returned keyword ID, or null on failure.
     */
    public function sync_keyword(string $name, ?int $id = null, ?string $comment = null, ?int $parentid = null): ?int;

    /**
     * Search the external database for keywords or criteria matching the given filters.
     *
     * This method allows querying the remote API using specific filters such as name or parent ID,
     * and returns a list of matching keyword records.
     *
     * @param string $name Optional name filter to search keywords by name.
     * @param int|null $parentkeywordid Optional parent keyword ID to filter results by hierarchy.
     * @return array List of matching keywords returned from the external system.
     */
    public function get_keywords(string $name = '', ?int $parentkeywordid = null): array;

    /**
     * Sync a person with the external API and return the Respond person ID.
     *
     * @param string $source A label or tag for the data source. If the person already exists,
     *        this source will be appended to the existing record.
     * @param array $person The person data to be sent (e.g., name, email, etc.).
     * @param array|null $addkeywords An array of keyword IDs to be added to the person.
     * @param array|null $removekeywords An array of keyword IDs to be removed from the person.
     * @param string $multiplestrategy Strategy for handling multiple matches in Respond.
     *        Allowed values: 'useFirst', 'createNew'. If 'createNew', a duplicate entry will be created.
     * @param string $key The field used to identify or match the person (e.g., 'person.email').
     * @return null|string|int containing the Respond person ID as the result of the sync.
     */
    public function sync_person(
        string $source,
        array $person,
        ?array $addkeywords = null,
        ?array $removekeywords = null,
        string $multiplestrategy = 'userFirst',
        string $key = 'person.email',
    ): null|string|int;
}

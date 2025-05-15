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
     * @return int|null The returned keyword ID, or null on failure.
     */
    public function sync_keyword(string $name, ?int $id = null, ?string $comment = null): ?int;
}

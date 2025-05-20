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
 * marmaraapi_provider class.
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author    Mahdi Poustini
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\respondapi\entities;

/**
 * Represents a person to be synced with the external API.
 */
class person {
    /** @var string First name */
    public string $vorname;

    /** @var string Last name */
    public string $nachname;

    /** @var string Email address */
    public string $email;

    /**
     * Constructor.
     *
     * @param string $vorname First name.
     * @param string $nachname Last name.
     * @param string $email Email address.
     */
    public function __construct(string $vorname, string $nachname, string $email) {
        $this->vorname = $vorname;
        $this->nachname = $nachname;
        $this->email = $email;
    }

    /**
     * Returns the person data as an associative array.
     *
     * @return array Associative array with keys: vorname, nachname, email.
     */
    public function to_array(): array {
        return [
            'vorname' => $this->vorname,
            'nachname' => $this->nachname,
            'email' => $this->email,
        ];
    }
}

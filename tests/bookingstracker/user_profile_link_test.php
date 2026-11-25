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
 * Tests for the links to the user profile in the Bookings tracker.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\table\manageusers_table;
use moodle_url;

/**
 * First name and last name of a booked user lead to the Moodle profile of the user,
 * like on report.php. Downloads keep the plain names.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\table\manageusers_table::col_firstname
 * @covers \mod_booking\table\manageusers_table::col_lastname
 */
final class user_profile_link_test extends advanced_testcase {
    /**
     * In the displayed table, both names are links to the profile of the user of the row,
     * which opens in a new tab.
     */
    public function test_names_link_to_the_profile(): void {
        $table = new manageusers_table('profilelinkdisplay');
        $values = (object)['userid' => 42, 'firstname' => 'Ada', 'lastname' => 'Lovelace'];
        $profileurl = (new moodle_url('/user/profile.php', ['id' => 42]))->out();

        $links = ['Ada' => $table->col_firstname($values), 'Lovelace' => $table->col_lastname($values)];
        foreach ($links as $name => $link) {
            $this->assertMatchesRegularExpression('~^<a [^>]*>' . $name . '</a>$~', $link);
            $this->assertStringContainsString('href="' . $profileurl . '"', $link);
            // The profile opens in a new tab, without access to the page it was opened from.
            $this->assertStringContainsString('target="_blank"', $link);
            $this->assertStringContainsString('rel="noopener noreferrer"', $link);
        }
    }

    /**
     * Names are user input: special characters are escaped inside the link.
     */
    public function test_names_are_escaped(): void {
        $table = new manageusers_table('profilelinkescape');
        $values = (object)['userid' => 42, 'firstname' => '<b>Ada</b>', 'lastname' => "O'Brien & Co"];

        $this->assertStringContainsString('>&lt;b&gt;Ada&lt;/b&gt;</a>', $table->col_firstname($values));
        $this->assertStringNotContainsString('<b>', $table->col_firstname($values));
        $this->assertStringContainsString('>O&#039;Brien &amp; Co</a>', $table->col_lastname($values));
    }

    /**
     * A download gets the plain names, exactly as they are stored.
     */
    public function test_download_keeps_the_plain_names(): void {
        $table = new manageusers_table('profilelinkdownload');
        // Mark the table as downloading without starting an export.
        $table->download = 'csv';
        $values = (object)['userid' => 42, 'firstname' => 'Ada', 'lastname' => "O'Brien & Co"];

        $this->assertSame('Ada', $table->col_firstname($values));
        $this->assertSame("O'Brien & Co", $table->col_lastname($values));
    }

    /**
     * Without a user id or without a name there is nothing to link.
     */
    public function test_no_link_without_user_or_name(): void {
        $table = new manageusers_table('profilelinknone');

        $nouser = (object)['firstname' => 'Ada', 'lastname' => 'Lovelace'];
        $this->assertSame('Ada', $table->col_firstname($nouser));
        $this->assertSame('Lovelace', $table->col_lastname($nouser));

        $noname = (object)['userid' => 42, 'firstname' => '', 'lastname' => null];
        $this->assertSame('', $table->col_firstname($noname));
        $this->assertSame('', $table->col_lastname($noname));
    }
}

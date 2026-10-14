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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * An unset or unknown privacy mode falls back to strict, as the setting default and the trial consent dialog promise.
 *
 * Wunderbyte-GmbH/Wunderbyte-GmbH#2601: the anonymizer treated a missing aiprivacymode as off, while the consent dialog
 * (aiready) treated it as strict and told the user that names and e-mails are anonymised. On a site whose settings page
 * was never saved nothing was masked. Only an explicit "off" or "soft" may weaken the masking.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer::get_mode
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class privacy_anonymizer_mode_fallback_test extends advanced_testcase {
    /**
     * Mode for a given stored config value (null = setting not stored at all).
     *
     * @param string|null $stored
     * @return string
     */
    private function mode_for(?string $stored): string {
        if ($stored === null) {
            unset_config('aiprivacymode', 'bookingextension_agent');
        } else {
            set_config('aiprivacymode', $stored, 'bookingextension_agent');
        }
        return (new privacy_anonymizer(new conversation_store()))->get_mode();
    }

    /**
     * A setting that was never stored masks strictly.
     */
    public function test_unset_mode_is_strict(): void {
        $this->resetAfterTest();
        $this->assertSame('strict', $this->mode_for(null));
    }

    /**
     * An empty or unknown stored value masks strictly too (fail closed).
     */
    public function test_empty_or_unknown_mode_is_strict(): void {
        $this->resetAfterTest();
        $this->assertSame('strict', $this->mode_for(''));
        $this->assertSame('strict', $this->mode_for('everything'));
    }

    /**
     * Only an explicit choice weakens the masking.
     */
    public function test_explicit_modes_are_kept(): void {
        $this->resetAfterTest();
        $this->assertSame('off', $this->mode_for('off'));
        $this->assertSame('soft', $this->mode_for('soft'));
        $this->assertSame('strict', $this->mode_for('strict'));
    }
}

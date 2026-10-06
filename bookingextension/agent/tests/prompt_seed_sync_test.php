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
 * Tests for carrying a changed default prompt into the stored config.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\prompt_seed_sync;

/**
 * The planner prompt is a setting that is seeded once and read forever after, so a changed
 * default in code did nothing until someone re-seeded by hand. That was not a rare mistake:
 * it faked "the model ignores the rule" twice, and db/upgrade.php grew a list of superseded
 * seeds only to work around it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\prompt_seed_sync
 */
final class prompt_seed_sync_test extends \advanced_testcase {
    /** @var string A setting name of this plugin, used as the subject. */
    private const SETTING = 'aiinitialprompt_selection';

    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * An untouched seed makes room for the new default.
     */
    public function test_a_value_we_seeded_is_replaced_by_the_new_default(): void {
        $this->resetAfterTest();

        set_config(self::SETTING, 'old default', 'bookingextension_agent');
        prompt_seed_sync::remember_seed(self::SETTING, 'old default');

        $this->assertSame('reseeded', prompt_seed_sync::apply_one(self::SETTING, 'new default'));
        $this->assertSame('new default', get_config('bookingextension_agent', self::SETTING));

        // And the new value is now the one we may replace next time.
        $this->assertSame('unchanged', prompt_seed_sync::apply_one(self::SETTING, 'new default'));
    }

    /**
     * An edited prompt is never overwritten, not once and not later.
     */
    public function test_an_admin_edit_is_kept(): void {
        $this->resetAfterTest();

        set_config(self::SETTING, 'old default', 'bookingextension_agent');
        prompt_seed_sync::remember_seed(self::SETTING, 'old default');
        set_config(self::SETTING, 'old default, plus a house rule', 'bookingextension_agent');

        $this->assertSame('kept_admin_edit', prompt_seed_sync::apply_one(self::SETTING, 'new default'));
        $this->assertSame(
            'old default, plus a house rule',
            get_config('bookingextension_agent', self::SETTING)
        );

        // A second default change must not quietly take it either.
        $this->assertSame('kept_admin_edit', prompt_seed_sync::apply_one(self::SETTING, 'newer default'));
        $this->assertSame(
            'old default, plus a house rule',
            get_config('bookingextension_agent', self::SETTING)
        );
    }

    /**
     * Saving the settings page stores the untouched seed with CRLF line ends; it is still our seed.
     *
     * The admin textarea posts CRLF. On the VM both prompts were the previous default byte for byte once the
     * line ends were normalised, yet the raw hash no longer matched, so the upgrade for #2546 kept them as admin
     * edits and no new default ever arrived on a site where someone had pressed "save".
     */
    public function test_a_seed_saved_through_the_settings_page_is_still_replaced(): void {
        $this->resetAfterTest();

        $seed = "old default\nsecond line\n";
        set_config(self::SETTING, $seed, 'bookingextension_agent');
        prompt_seed_sync::remember_seed(self::SETTING, $seed);
        set_config(self::SETTING, "old default\r\nsecond line\r\n", 'bookingextension_agent');

        $this->assertSame('reseeded', prompt_seed_sync::apply_one(self::SETTING, "new default\n"));
        $this->assertSame("new default\n", get_config('bookingextension_agent', self::SETTING));

        // A real edit saved through the same page stays an edit.
        set_config(self::SETTING, "new default\r\nplus a house rule\r\n", 'bookingextension_agent');
        $this->assertSame('kept_admin_edit', prompt_seed_sync::apply_one(self::SETTING, "newer default\n"));
        $this->assertSame("new default\r\nplus a house rule\r\n", get_config('bookingextension_agent', self::SETTING));
    }

    /**
     * An install from before this existed is adopted, not overwritten.
     */
    public function test_an_untracked_install_is_adopted_without_losing_its_value(): void {
        $this->resetAfterTest();

        set_config(self::SETTING, 'whatever this site has', 'bookingextension_agent');
        unset_config(self::SETTING . prompt_seed_sync::HASH_SUFFIX, 'bookingextension_agent');

        $this->assertSame('adopted', prompt_seed_sync::apply_one(self::SETTING, 'new default'));
        $this->assertSame('whatever this site has', get_config('bookingextension_agent', self::SETTING));

        // From here on the site is tracked, so the next default change does arrive.
        $this->assertSame('reseeded', prompt_seed_sync::apply_one(self::SETTING, 'new default'));
        $this->assertSame('new default', get_config('bookingextension_agent', self::SETTING));
    }

    /**
     * The real defaults are non-empty, so apply() has something to carry.
     */
    public function test_the_real_defaults_are_available(): void {
        $defaults = prompt_seed_sync::current_defaults();

        $this->assertArrayHasKey('aiinitialprompt_selection', $defaults);
        $this->assertArrayHasKey('aiinitialprompt_parameter_construction', $defaults);
        foreach ($defaults as $setting => $default) {
            $this->assertNotSame('', trim((string)$default), $setting . ' has no default to carry');
        }
    }
}

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

namespace bookingextension_agent\local;

/**
 * Carries a changed default planner prompt into the stored config that is actually read.
 *
 * The planner prompt is a setting, and settings.php seeds it exactly once. Everything after
 * that reads the stored value, so editing the default in code changes nothing until someone
 * remembers to re-seed. Twice that looked like the model ignoring a rule it had never been
 * given (threads 408/409), and every wave since has paid the same tax by hand - db/upgrade.php
 * carries a list of superseded seeds for that reason alone.
 *
 * The list is what this replaces. The hash of whatever was last seeded is stored next to the
 * value: as long as the value still hashes to it, nobody has edited the prompt and a new
 * default can take its place. Once an admin edits it, the hashes part ways for good and the
 * edit is never overwritten.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt_seed_sync {
    /** @var string Suffix of the setting holding the hash of the last seeded value. */
    public const HASH_SUFFIX = '_seedhash';

    /** @var string Plugin the settings belong to. */
    private const PLUGIN = 'bookingextension_agent';

    /**
     * Record the hash of a value this plugin just seeded, so a later default can replace it.
     *
     * @param string $setting
     * @param string $value
     */
    public static function remember_seed(string $setting, string $value): void {
        set_config($setting . self::HASH_SUFFIX, sha1($value), self::PLUGIN);
    }

    /**
     * Bring one seeded setting up to its current default, unless an admin has edited it.
     *
     * @param string $setting
     * @param string $default Current default; an empty string means "no default available here".
     * @return string One of 'reseeded', 'kept_admin_edit', 'adopted', 'unchanged', 'no_default'.
     */
    public static function apply_one(string $setting, string $default): string {
        if (trim($default) === '') {
            return 'no_default';
        }

        $stored = get_config(self::PLUGIN, $setting);
        if ($stored === false) {
            // Nothing seeded yet; settings.php owns that case and records the hash itself.
            return 'no_default';
        }

        $stored = (string)$stored;
        if ($stored === $default) {
            self::remember_seed($setting, $default);
            return 'unchanged';
        }

        $knownhash = get_config(self::PLUGIN, $setting . self::HASH_SUFFIX);
        if ($knownhash === false) {
            // An install from before this sync existed. We cannot tell a seeded value from an
            // edited one, so we assume the admin's side and only start tracking from here. The
            // next default change is carried automatically.
            self::remember_seed($setting, $stored);
            return 'adopted';
        }

        // The settings page posts its textarea with CRLF line ends, so pressing "save" stores the untouched seed as
        // CRLF. Line ends are no edit: the value still counts as our seed when it matches with LF (#2546).
        $lf = str_replace(["\r\n", "\r"], "\n", $stored);
        if ((string)$knownhash !== sha1($stored) && (string)$knownhash !== sha1($lf)) {
            return 'kept_admin_edit';
        }

        set_config($setting, $default, self::PLUGIN);
        self::remember_seed($setting, $default);

        return 'reseeded';
    }

    /**
     * Bring every seeded prompt setting up to its current default.
     *
     * @return array<string,string> Outcome per setting, as returned by apply_one().
     */
    public static function apply(): array {
        $defaults = self::current_defaults();

        $outcome = [];
        foreach ($defaults as $setting => $default) {
            $outcome[$setting] = self::apply_one($setting, $default);
        }

        return $outcome;
    }

    /**
     * The current default for every prompt setting this class owns.
     *
     * @return array<string,string>
     */
    public static function current_defaults(): array {
        $planner = '';
        $constructor = '';

        if (class_exists(\bookingextension_agent\local\wizard\orchestrator::class)) {
            $orchestrator = \bookingextension_agent\local\wizard\orchestrator::class;
            $planner = (string)$orchestrator::get_default_initial_prompt_template_for_action(
                \core_ai\aiactions\summarise_text::class
            );
            $constructor = (string)$orchestrator::get_default_constructor_prompt_template();
        }

        return [
            'aiinitialprompt_selection' => $planner,
            'aiinitialprompt_parameter_construction' => $constructor,
        ];
    }
}

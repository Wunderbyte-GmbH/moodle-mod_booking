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

namespace mod_booking\local\wbagent\booking\tasks;

use mod_booking\local\wbagent\base_task;
use mod_booking\local\wbagent\booking\booking_task_support;

/**
 * Base task delegating schema, validation and execution to booking support logic.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_booking_task extends base_task {
    /** @var booking_task_support|null */
    private static ?booking_task_support $sharedsupport = null;

    /** @var booking_task_support */
    protected booking_task_support $support;

    /**
     * Constructor.
     *
     * @param bool $readonly
     */
    public function __construct(bool $readonly = false) {
        parent::__construct($readonly);
        if (self::$sharedsupport === null) {
            self::$sharedsupport = new booking_task_support();
        }
        $this->support = self::$sharedsupport;
    }

    /**
     * Return the task name.
     *
     * @return string
     */
    abstract public function get_name(): string;

    /**
     * Return the schema for this task.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => '',
            'readonly' => $this->is_read_only(),
            'properties' => [],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        return [
            'valid' => true,
            'errors' => [],
            'ambiguities' => [],
        ];
    }

    /**
     * Execute the task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        return $this->support->execute($this->get_name(), $input, $cmid, $userid);
    }

    /**
     * Return optional contextual prompt packs for this task.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [];
    }

    /**
     * Verify that requested values are visible in persisted option settings.
     *
     * @param array $input
     * @param object $settings
     * @return array
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return [];
    }

    /**
     * Build a brief technical debug message for a task execution.
     *
     * @param string $taskname
     * @param array $input
     * @param array $extra Optional extra lines (e.g. result summary).
     * @return string
     */
    protected function build_task_debug_message(string $taskname, array $input, array $extra = []): string {
        $parts = [];

        // Recursively flatten complex nested arrays for display.
        $flatten = static function ($item) use (&$flatten) {
            if (is_array($item)) {
                $subsliced = array_slice($item, 0, 5);
                return '[' . implode(', ', array_map($flatten, $subsliced)) . ']';
            }
            return (string)$item;
        };

        foreach ($input as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $sliced = array_slice($value, 0, 5);
                $parts[] = $key . '=' . $flatten($sliced);
            } else {
                $parts[] = $key . '=' . $value;
            }
        }
        $lines = ['Task: ' . $taskname];
        if (!empty($parts)) {
            $lines[] = 'Params: ' . implode(', ', $parts);
        }
        foreach ($extra as $line) {
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Resolve preferred output language from task input.
     *
     * @param array $input
     * @return string
     */
    protected function get_output_language(array $input): string {
        return trim((string)($input['outputlang'] ?? ''));
    }

    /**
     * Read a localized string, optionally forcing a specific output language.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    protected function localized_string(string $identifier, $a = null, string $lang = ''): string {
        $targetlang = trim($lang);
        if ($targetlang === '') {
            return get_string($identifier, 'mod_booking', $a);
        }

        return get_string_manager()->get_string($identifier, 'mod_booking', $a, $targetlang);
    }

    /**
     * Enforce a hard maximum character length on a string.
     *
     * @param string $text
     * @param int $maxchars
     * @return string
     */
    protected function enforce_max_chars(string $text, int $maxchars): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if ($normalized === '' || $maxchars <= 0) {
            return '';
        }

        if (\core_text::strlen($normalized) <= $maxchars) {
            return $normalized;
        }

        $ellipsis = '...';
        $available = max(1, $maxchars - \core_text::strlen($ellipsis));
        $trimmed = trim(\core_text::substr($normalized, 0, $available));
        return $trimmed . $ellipsis;
    }
}

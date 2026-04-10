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
     * @return array<string,mixed>
     */
    public function get_schema(): array {
        $schema = $this->support->get_task_schema($this->get_name());
        $schema['readonly'] = $this->is_read_only();
        return $schema;
    }

    /**
     * Validate task input.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        return $this->support->validate($this->get_name(), $input, $cmid);
    }

    /**
     * Execute the task.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
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
     * @param array<string,mixed> $input
     * @param object $settings
     * @return array<int,string>
     */
    public function verify_persisted_option_state(array $input, object $settings): array {
        return [];
    }
}

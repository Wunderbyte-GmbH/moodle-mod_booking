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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\dto;

use context;
use context_module;

/**
 * Value object describing the Moodle context the agent operates in.
 *
 * This is the single, context-level-agnostic carrier for "where am I running".
 * It replaces the scattered handling of raw contextid / cmid / bookingid ints and
 * the repeated get_coursemodule_from_id('booking', ...) lookups throughout the engine.
 *
 * The Moodle context id is the authoritative scope key. Module-specific details
 * (cmid, course id, module name) are OPTIONAL, lazily resolved attributes — they are
 * null outside of a course-module context, so the engine no longer has to assume a
 * booking module behind every context.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class agent_context {
    /** @var context The underlying Moodle context. */
    private context $context;

    /** @var int|null Lazily resolved course-module id (null outside a module context). */
    private ?int $cmid = null;

    /** @var bool Whether cmid has been resolved. */
    private bool $cmidresolved = false;

    /** @var int|null Lazily resolved course id (null outside a course). */
    private ?int $courseid = null;

    /** @var bool Whether courseid has been resolved. */
    private bool $courseidresolved = false;

    /** @var string|null Lazily resolved module name, e.g. 'booking' (null outside a module). */
    private ?string $modname = null;

    /** @var bool Whether modname has been resolved. */
    private bool $modnameresolved = false;

    /**
     * Constructor.
     *
     * @param context $context
     */
    private function __construct(context $context) {
        $this->context = $context;
    }

    /**
     * Build from a context id.
     *
     * @param int $contextid
     * @return self
     */
    public static function from_contextid(int $contextid): self {
        return new self(context::instance_by_id($contextid, MUST_EXIST));
    }

    /**
     * Build from an already-resolved Moodle context.
     *
     * @param context $context
     * @return self
     */
    public static function from_context(context $context): self {
        return new self($context);
    }

    /**
     * Return the context id (the authoritative scope key).
     *
     * @return int
     */
    public function id(): int {
        return (int)$this->context->id;
    }

    /**
     * Return the Moodle context level (CONTEXT_MODULE, CONTEXT_COURSE, CONTEXT_SYSTEM, ...).
     *
     * @return int
     */
    public function level(): int {
        return (int)$this->context->contextlevel;
    }

    /**
     * Return the underlying Moodle context.
     *
     * @return context
     */
    public function moodle_context(): context {
        return $this->context;
    }

    /**
     * Human-readable name of this context for prompt/UI display.
     *
     * Generic, site-wide context name. For a booking module this yields the booking instance name,
     * for a course the course name, for a user the user context name, etc.
     *
     * @return string
     */
    public function display_name(): string {
        return $this->context->get_context_name();
    }

    /**
     * Whether this context is a course-module context (optionally of a specific module).
     *
     * @param string|null $modname e.g. 'booking'; null = any module.
     * @return bool
     */
    public function is_module(?string $modname = null): bool {
        if (!($this->context instanceof context_module)) {
            return false;
        }
        if ($modname === null) {
            return true;
        }
        return $this->modname() === $modname;
    }

    /**
     * Course-module id, or null when this is not a module context.
     *
     * @return int|null
     */
    public function cmid(): ?int {
        if (!$this->cmidresolved) {
            $this->cmid = ($this->context instanceof context_module)
                ? (int)$this->context->instanceid
                : null;
            $this->cmidresolved = true;
        }
        return $this->cmid;
    }

    /**
     * Course id of the enclosing course, or null when not within a course (e.g. system).
     *
     * @return int|null
     */
    public function courseid(): ?int {
        if (!$this->courseidresolved) {
            $coursecontext = $this->context->get_course_context(false);
            $this->courseid = $coursecontext ? (int)$coursecontext->instanceid : null;
            $this->courseidresolved = true;
        }
        return $this->courseid;
    }

    /**
     * Module name (e.g. 'booking'), or null when this is not a module context.
     *
     * @return string|null
     */
    public function modname(): ?string {
        if (!$this->modnameresolved) {
            $this->modname = null;
            if ($this->context instanceof context_module) {
                $cm = get_coursemodule_from_id('', (int)$this->context->instanceid, 0, false, IGNORE_MISSING);
                $this->modname = $cm ? $cm->modname : null;
            }
            $this->modnameresolved = true;
        }
        return $this->modname;
    }

    /**
     * Return a new agent_context for a (re-resolved) operating context.
     *
     * Used by the runtime context switch: the ambient context stays, while a single
     * operation may run against a related, broader operating context (see context_resolver).
     *
     * @param context $operatingcontext
     * @return self
     */
    public function with_context(context $operatingcontext): self {
        return new self($operatingcontext);
    }
}

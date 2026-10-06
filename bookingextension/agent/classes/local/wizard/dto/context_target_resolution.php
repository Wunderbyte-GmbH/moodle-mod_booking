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

/**
 * Outcome of resolving a {@see target_selector} to a concrete operating context.
 *
 * Carries enough to drive the UX: a unique resolution yields the context; an ambiguous one
 * carries the candidate list so a clarification ("which course?") can be built; not-found and
 * unsupported are distinct so callers can phrase the right message. The resolver never silently
 * falls back to the ambient context — that decision belongs to the caller.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class context_target_resolution {
    /** @var string Uniquely resolved to one context. */
    public const STATUS_RESOLVED = 'resolved';
    /** @var string Several candidates matched — needs clarification. */
    public const STATUS_AMBIGUOUS = 'ambiguous';
    /** @var string No candidate matched. */
    public const STATUS_NOT_FOUND = 'not_found';
    /** @var string No resolver is registered for the requested target level. */
    public const STATUS_UNSUPPORTED = 'unsupported';

    /** @var string One of the STATUS_* constants. */
    private string $status;

    /** @var context|null The resolved context (only when STATUS_RESOLVED). */
    private ?context $context;

    /** @var array[] Candidate list (only when STATUS_AMBIGUOUS). */
    private array $candidates;

    /**
     * Constructor.
     *
     * @param string                                  $status
     * @param context|null                            $context
     * @param array[]    $candidates
     */
    private function __construct(string $status, ?context $context, array $candidates) {
        $this->status = $status;
        $this->context = $context;
        $this->candidates = $candidates;
    }

    /**
     * A unique resolution.
     *
     * @param context $context
     * @return self
     */
    public static function resolved(context $context): self {
        return new self(self::STATUS_RESOLVED, $context, []);
    }

    /**
     * An ambiguous resolution carrying its candidates.
     *
     * @param array[] $candidates
     * @return self
     */
    public static function ambiguous(array $candidates): self {
        return new self(self::STATUS_AMBIGUOUS, null, array_values($candidates));
    }

    /**
     * No candidate matched.
     *
     * @return self
     */
    public static function not_found(): self {
        return new self(self::STATUS_NOT_FOUND, null, []);
    }

    /**
     * No resolver registered for the target level.
     *
     * @return self
     */
    public static function unsupported(): self {
        return new self(self::STATUS_UNSUPPORTED, null, []);
    }

    /**
     * Return the status (one of the STATUS_* constants).
     *
     * @return string
     */
    public function status(): string {
        return $this->status;
    }

    /**
     * Whether the target resolved uniquely.
     *
     * @return bool
     */
    public function is_resolved(): bool {
        return $this->status === self::STATUS_RESOLVED && $this->context !== null;
    }

    /**
     * The resolved context, or null when not uniquely resolved.
     *
     * @return context|null
     */
    public function context(): ?context {
        return $this->context;
    }

    /**
     * Candidate list for an ambiguous resolution.
     *
     * @return array[]
     */
    public function candidates(): array {
        return $this->candidates;
    }
}

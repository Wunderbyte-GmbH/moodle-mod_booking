#!/usr/bin/env bash
# One-way vendoring: private agent repo (single source of truth) -> mod_booking
# 'agent-bundle' branch, checked out as a git worktree.
#
# Exports the COMMITTED state of the given ref via git archive - no .git, no
# .gitignore/.github, no untracked or ignored files can ever ship. Deletions
# propagate because the destination is wiped before the export.
#
# Usage:
#   release/sync_to_booking.sh [ref]          # default ref: SOFABOOKING
#   BUNDLE_WORKTREE=/path release/sync_to_booking.sh
#
# The script only STAGES the result and prints the commit command - review
# 'git status' in the worktree, then commit. Never edit the bundled copy
# directly; changes go into this repo and get re-vendored.

set -euo pipefail

AGENT_REPO="$(cd "$(dirname "$0")/.." && pwd)"
BUNDLE_WORKTREE="${BUNDLE_WORKTREE:-$HOME/Code/booking-bundle}"
REF="${1:-SOFABOOKING}"
DEST="$BUNDLE_WORKTREE/bookingextension/agent"

if [ ! -d "$BUNDLE_WORKTREE/.git" ] && [ ! -f "$BUNDLE_WORKTREE/.git" ]; then
    echo "ERROR: $BUNDLE_WORKTREE is not a git worktree/checkout." >&2
    exit 1
fi
if [ "$(git -C "$BUNDLE_WORKTREE" branch --show-current)" != "agent-bundle" ]; then
    echo "ERROR: $BUNDLE_WORKTREE is not on the agent-bundle branch." >&2
    exit 1
fi

SHA="$(git -C "$AGENT_REPO" rev-parse --short "$REF")"

rm -rf "$DEST"
mkdir -p "$DEST"
git -C "$AGENT_REPO" archive "$REF" | tar -x -C "$DEST"
# Strip repo infrastructure and internal dev tooling from the shipped copy:
# release/ (this vendoring machinery) and tools/ (wizard_sync generator) are
# development-internal and must not reach the public mod_booking tree.
rm -rf "$DEST/.gitignore" "$DEST/.github" "$DEST/release" "$DEST/tools"

VER="$(sed -n 's/^\$plugin->version[[:space:]]*=[[:space:]]*\([0-9]*\);.*/\1/p' "$DEST/version.php")"

git -C "$BUNDLE_WORKTREE" add bookingextension/agent

# Guard: every staged entry must be a plain blob. A 160000 gitlink would mean a
# .git directory slipped into the export and the subplugin would ship broken.
if git -C "$BUNDLE_WORKTREE" ls-files -s bookingextension/agent | awk '{print $1}' | grep -qv '^100'; then
    echo "ERROR: non-blob entry staged under bookingextension/agent - aborting." >&2
    exit 1
fi

echo "Staged bookingextension_agent ${VER} (private: ${SHA}) in ${BUNDLE_WORKTREE}."
echo "Review:  git -C '${BUNDLE_WORKTREE}' status"
echo "Commit:  git -C '${BUNDLE_WORKTREE}' commit -m 'Bundle bookingextension_agent ${VER} (private: ${SHA})'"

#!/usr/bin/env bash
#
# AstrX repository cleanup.
#
# Removes build artifacts, editor/backup cruft, and leftover scaffolding so the
# working tree can be committed clean, then writes a .gitignore that keeps the
# regenerable tooling (phpstan.phar, build/) out of version control while
# leaving it on disk for local use.
#
# Run once from the repository root:  ./cleanup.sh
#
set -euo pipefail
cd "$(dirname "$0")"

echo "AstrX cleanup — removing superfluous files…"

# --- backups / stray archives / leftover diffs ------------------------------
rm -f  src/AstrX/error.zip                          # accidental source backup
rm -f  src/AstrX/Template/TemplateEngine.php.orig   # pre-hardening (vulnerable) copy
rm -f  diff                                         # stray diff dump

# --- brace-expansion accidents (empty dir trees from a bad shell glob) ------
rm -rf 'src/AstrX/{Controller,User,Auth'
rm -rf 'src/{src'

# --- empty / leftover scaffolding -------------------------------------------
rm -rf setup                       # DB init now lives in src/setup (see docker-compose.yml)
rm -rf xdebug-profiles             # profiler output
rm -rf docker/mysql/config docker/mysql/init docker/mysql/setup docker/setup  # dead mount points

# --- one-off dev/build helper scripts ---------------------------------------
rm -f apply_cleanup.sh clean.sh fix-compiled-bundle.sh xdebug-profile-once.sh

# --- keep tooling out of git (NOT deleted: you still run these locally) ------
cat > .gitignore <<'GITIGNORE'
# Tooling / generated artifacts — kept locally, never committed
/phpstan.phar
/build/
/xdebug-profiles/
/diff

# Editor & OS cruft
*.orig
*.bak
*.rej
*~
.DS_Store
Thumbs.db
GITIGNORE

echo "Done."
echo "  • .gitignore written (phpstan.phar and build/ are now ignored, not deleted)."
echo "  • 'git status' should show only your intended changes."
echo "You can now delete this script and the delivery archive, then commit."

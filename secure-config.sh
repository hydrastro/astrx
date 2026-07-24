#!/usr/bin/env bash
#
# secure-config.sh — stop tracking AstrX secrets and generated state.
#
# Idempotent; run once from the repository root. It NEVER deletes your local
# config — it only removes files from git's index and blanks the committed
# session secret (with a backup). Rotation is left to you (see the end).
#
#   1. Adds cache/secret entries to .gitignore (if missing).
#   2. Removes PDO.config.php, the two config dotfiles, the template cache, and
#      tracked tooling artifacts (phpstan.phar, build/, xdebug-profiles/, diff)
#      from git's index (local copies kept).
#   3. Blanks `server_secret` in the tracked Session.config.php (backup made).
#   4. Writes resources/config/PDO.config.php.example if absent.
#   5. Prints the manual git + rotation steps.
#
set -euo pipefail
cd "$(dirname "$0")"
CFG="resources/config"

have_git=1
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || have_git=0

echo "1) Ensuring .gitignore entries…"
touch .gitignore
add_ignore() { grep -qxF -- "$1" .gitignore || printf '%s\n' "$1" >> .gitignore; }
add_ignore '/resources/template/cache/'
add_ignore '/resources/config/PDO.config.php'
add_ignore '/resources/config/.server_secret_generated'
add_ignore '/resources/config/.setup_complete'
add_ignore '*.bak'

echo "2) Untracking secrets/generated files from git (local copies kept)…"
if [ "$have_git" = 1 ]; then
    git rm -r --cached --ignore-unmatch \
        resources/template/cache build xdebug-profiles >/dev/null 2>&1 || true
    git rm --cached --ignore-unmatch \
        "$CFG/PDO.config.php" \
        "$CFG/.server_secret_generated" \
        "$CFG/.setup_complete" \
        phpstan.phar diff >/dev/null 2>&1 || true
else
    echo "   (not inside a git repo — skipped; run the git rm lines by hand)"
fi

echo "3) Blanking server_secret in Session.config.php…"
if [ -f "$CFG/Session.config.php" ]; then
    cp -- "$CFG/Session.config.php" "$CFG/Session.config.php.bak"
    sed -i -E "s/('server_secret'[[:space:]]*=>[[:space:]]*)'[^']*'/\\1''/" "$CFG/Session.config.php"
    echo "   done (backup: $CFG/Session.config.php.bak, gitignored)"
else
    echo "   Session.config.php not found — skipped"
fi

echo "4) Writing PDO.config.php.example (if missing)…"
if [ ! -f "$CFG/PDO.config.php.example" ]; then
    cat > "$CFG/PDO.config.php.example" <<'PHP'
<?php
declare(strict_types=1);

/**
 * Database connection — TEMPLATE. Copy to PDO.config.php and fill in, or let
 * public/setup.php generate it. PDO.config.php is gitignored (live credentials).
 */
return [
    'PDO' => [
        'db_type'             => 'mysql',
        'db_host'             => '127.0.0.1',   // under Docker: the mariadb service host
        'db_name'             => 'content_manager',
        'db_port'             => 3306,
        'db_username'         => 'user',
        'db_password'         => '',            // set locally — never commit
        'emulate_prepares'    => false,
        'errmode_exception'   => true,
        'default_fetch_assoc' => true,
    ],
];
PHP
    echo "   written"
else
    echo "   already present — left as-is"
fi

cat <<'NEXT'

────────────────────────────────────────────────────────────────────
Commit the change:

  git add .gitignore resources/config/Session.config.php \
          resources/config/PDO.config.php.example
  git commit -m "Ignore cache + secrets; blank server_secret; add PDO template"

ROTATE — the old values live in git history forever:

  • Session key: it is now blank, so the framework generates a fresh
    per-install key into resources/config/.server_secret_generated
    (gitignored) on next boot. Existing sessions are invalidated — expected.
  • Database: change the DB user's password on the server, then update the
    local (gitignored) resources/config/PDO.config.php to match.

Once verified, you can delete resources/config/Session.config.php.bak
────────────────────────────────────────────────────────────────────
NEXT

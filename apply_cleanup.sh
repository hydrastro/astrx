#!/usr/bin/env sh
# Optional helper: remove the files this overlay cannot delete on its own.
# Run from the repository root:  sh apply_cleanup.sh
set -e
rm -rf setup/fix124
rm -f  src/AstrX/Controller/CaptchaTestController.php
rm -f  public/print.php
rm -f  resources/lang/it/Mail.it.php
echo "Removed: setup/fix124/, CaptchaTestController.php, public/print.php, it/Mail.it.php"
echo
echo "Next: run the SQL migrations on your database —"
echo "  src/setup/migrate_add_login_lockout.sql"
echo "  src/setup/migrate_remove_captcha_test.sql"
echo "and review the config notes in ASTRX_SECURITY_FIXES.md."

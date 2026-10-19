## What and why

<!-- What does this change do for users, operators or contributors, and why is it needed? -->

## How it was checked

<!-- Tests added or run; for a change to what pages render, how you compared before and after. -->

- [ ] PHPStan level 10 and the integrity gates pass (`php tools/packages.php`, `check_architecture`, `check_modules`, `check_config`, `check_lang_parity`, `check_diagnostics`)
- [ ] New strings exist in both `en` and `it`
- [ ] Moved or added code: `php tools/packages.php --write` was run
- [ ] No shipped `migrate_*.sql` was edited

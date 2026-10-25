# Repository direction

The current tree still contains the PHP application and Rust workspace together. The PHP code is split into Composer packages inside this repository (step 4 below); publishing them separately remains a proposal.

## Goal

Make the PHP core useful to other applications without requiring the author's CMS modules, database schema or Rust services. Keep the complete application as a consumer of that core. Keep the Rust engines together in one Cargo workspace, with a defined HTTP contract for PHP integrations.

The important first boundary is core versus application. Moving `astrx-suite/` to another Git repository would not make the current PHP bootstrap reusable: it still constructs `Prelude` and starts `ContentManager`.

## Proposed ownership

| Part | Responsibility |
|---|---|
| PHP core | Results/diagnostics, injection/configuration primitives, HTTP and routing abstractions, templates, translation and module contracts |
| Bundled application | SQL-backed content/pages, account implementation, admin UI, personal modules, migrations, templates and service adapters |
| Rust suite | Engine implementations, storage, binary/image releases and their documented network APIs |

These are dependency boundaries before they are repository boundaries. Start by extracting a core package inside the existing repository and proving it with a small independent application.

## Steps

1. **Done:** `src/autoload.php` loads classes without starting a session, database or response; `examples/components.php` exercises Results and injection independently.
1. **Done:** the namespace graph is layered and acyclic (foundation → component → library → feature → application; see [architecture](architecture.md#layers)), and `tools/check_architecture.php` keeps it that way in CI. Features own their controllers, policies and manifests, and pages resolve through an explicit controller map. The layers are the intended package boundaries.
2. **Done:** an application provides its configuration, paths, routes and optional adapters. The framework package has no CMS page rows, and no package reads a path constant: the installation's directories are an `AstrX\Support\Paths` the application builds and the injector hands to every class that needs one (the site builds it from `src/paths.php` in `Prelude`; `examples/minimal` builds its own and defines no constant). `tests/paths_test.php` covers an application with no files of its own.
3. **Started:** `examples/minimal` is a second application built from the framework layers alone (HTML, form with POST/redirect/GET, JSON, 404). `tests/framework_isolation_test.php` serves it from a directory containing only the framework package. Next: have the CMS consume the same core API.
4. **Done in this repository:** the code is Composer packages in `packages/` — `astrx/framework`, `astrx/library`, `astrx/cms` and one `astrx/module-<key>` per module — each requiring exactly the packages it uses (`tools/packages.php` checks it in CI), reached from the root `composer.json` through path repositories. Optional modules reach core only through declared extension points ([modules](MODULES.md#extension-points-optional)), and the site runs with no module package present. Still to do before advertising a reusable distribution: choose explicit PHP licensing and make tagged releases. No third-party Composer runtime packages are required.
5. Specify compatibility for the PHP/Rust HTTP APIs and test the PHP clients against actual pinned Rust binaries, alongside existing mock tests.
6. **Ready:** the application and the Rust workspace can each move into a repository of their own (step 7 extracts them with the packages).
7. **Ready:** every part can become a repository of its own. `php tools/split.php` lists the parts: each package, the Rust suite (`astrx-suite/`) and the site (everything else). `--run --repo-url=https://github.com/<org>/{name}` extracts each one onto a local `split/<name>` branch, and `--push=git@github.com:<org>/{name}.git` also pushes it to that repository's `main`.
   - **History.** A part keeps the full history of its files: each file is followed back through its renames (`tools/lib/history.php`), so a package's history reaches back to when its code lived under `src/AstrX/`. Commits that change nothing in a part are dropped. Every extracted tree is identical to its directory here.
   - **Adaptation.** On top of each branch, one commit makes the part work alone (`tools/lib/split_adapt.php`):
     - a package gets its homepage, the repositories of the packages it requires, and a CI workflow that installs it alone and loads every class;
     - the suite gets its workflow at its own root;
     - the site gets the packages from Composer (`vendor/astrx/…`, which the class loader, the tools and the tests already accept) and the suite's compose file from a checkout next to it (`../astrx-suite`).
   - **Signatures.** Extraction rewrites commits, so their signatures cannot carry over. `--sign` signs every commit of every branch with this repository's signing key, re-creating each with `git commit-tree -S`: trees, authors, committers, dates, messages, merges and roots stay exactly as they were. (`git rebase --exec 'git commit --amend -S'` does not: the site's history has two roots and four merges.)
   - **Rehearsed end to end** with local repositories:
     - the site installed its 21 packages from their repositories, passed every check, PHPStan and the whole test suite, and rendered the same 232 pages as this repository;
     - each package installed alone and loaded every class;
     - the suite built and passed its tests at its own root.
   Every package has its own `README.md`, and `tests/package_isolation_test.php` proves each one loads with only the packages its `composer.json` declares.

Do not split every module into a repository immediately. A separate consumer or release cadence is a better reason to create a package than the existence of a module manifest.

## How consumers obtain each part

The intended PHP consumer installs a versioned package. A service operator runs a versioned Rust binary or image. The complete application records compatible versions of both. Local development can override those versions with local builds.

Submodules are optional source-management machinery, not a solution to API compatibility. They introduce another checkout/pointer workflow without replacing contract tests or migrations. Prefer package and image versions for normal consumption.

An organization can group projects and maintainer access when helpful. It does not need to precede the package work. Preserve existing links and avoid disruptive repository moves until the replacement onboarding path works.

## Checks before a split

- The minimal PHP consumer works without the CMS database or personal modules.
- The CMS passes against the packaged core rather than special internal paths.
- The PHP bridge tests exercise real released Rust services.
- The production install, upgrade and restore procedures work on a clean host.
- Public examples run and describe the same release users obtain.

Keeping one repository can remain a valid maintenance choice after these boundaries exist. Separating repositories becomes a choice about ownership and release cadence rather than a prerequisite for modular code.

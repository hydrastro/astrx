# Repository direction

The current tree still contains the PHP application and Rust workspace together. Inert autoloading and a component example are implemented; the package extraction below remains a proposal.

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
2. Let an application provide configuration, paths, routes and optional adapters. Remove mandatory dependencies on CMS page rows and installation-global constants from the reusable surface.
3. Have the CMS consume the same core API as a second small example application.
4. Composer metadata now describes the application checkout. Choose explicit PHP licensing, finish package boundaries and make tagged releases before advertising a reusable core distribution. No third-party Composer runtime packages are required.
5. Specify compatibility for the PHP/Rust HTTP APIs and test the PHP clients against actual pinned Rust binaries, alongside existing mock tests.
6. If independent maintenance is useful, move the application and Rust workspace into their own repositories using reviewed history extraction in disposable clones.

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

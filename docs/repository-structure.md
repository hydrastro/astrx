# Should `astrx-suite` be its own repository?

**Short answer: no. Keep the monorepo — but fix the two things that made the
question feel urgent.**

This document records the reasoning, because "why is it laid out like this" is a
question that gets asked once a year and answered from memory badly.

---

## What the coupling actually is

The instinct that the two halves "kinda work together through the dockerfile" is
right, but the coupling is broader and more specific than that:

| Coupling | Where |
|---|---|
| Eight CMS modules are HTTP clients for five engines | `src/AstrX/{GitBrowse,SuiteDash,WebSearch,OnionSearch,TorrentSearch,FederatedSearch,SuiteAdmin,Blocklist}/` |
| The bridges parse each engine's JSON API shape | `*Client.php` — a field rename in Rust is a silent breakage in PHP |
| The deployment is one network, one `.env`, one `docker compose up` | `docker-compose.yml` + `astrx-suite/docker-compose.yml` |
| The docs are one document covering both sides | `docs/suite-search-modules.md` |
| The install SQL for each bridge page lives with the CMS | `src/setup/modules/*.down.sql` |

That is not a library-and-consumer relationship. It is **one product with two
implementation languages**, and the seam between them — the JSON APIs — is
private, unversioned, and changes whenever a feature needs it to.

## What a split would cost

A submodule does not remove that coupling; it makes it harder to see and
harder to change.

1. **Atomic change across the seam becomes impossible.** Adding a field to
   gitweb's `/api/commit` and rendering it in `git_browse.html` is one commit
   today. Split, it is: commit to the suite repo, push, bump the submodule
   pointer in the app repo, commit again — and in between, `main` of the app
   repo is pointing at a suite that does not have the field. Every reviewer
   now reviews half a change.
2. **CI can no longer prove the pair works.** The bridge tests
   (`tests/suite-bridge/`, 293 assertions) boot a mock engine and check the PHP
   client against it. Those only mean something if the mock matches the real
   engine in the same commit.
3. **`git submodule update` is a step everyone forgets**, and the failure mode
   is silent: you build against a stale engine and the symptom appears
   somewhere else entirely.
4. **You have already been bitten by exactly this class of problem.** The tree
   spent several commits in a state where a zip extracted over a checkout left
   the two halves inconsistent and nobody noticed. Submodules make that failure
   mode routine rather than exceptional.

## What a split would buy — and how to get it without splitting

The honest arguments for a separate repo are:

- *"The Rust suite is useful on its own."* True — and already served: the suite
  has its own `Cargo.toml` workspace, its own `docker-compose.yml` that runs
  standalone, its own README, and zero dependency on anything in the PHP tree.
  Anyone can `git clone` and use `astrx-suite/` alone today. Nothing about the
  monorepo prevents that.
- *"Rust people don't want to clone PHP."* The whole repository is ~11 MB.
  This is not a real cost.
- *"Releases should be independent."* This is the only genuine one, and it is
  solved by **tags**, not by repositories: `suite-v0.4.0` and `astrx-v2.1.0` can
  live in one history. Independent release cadence does not require independent
  repositories; it requires independent version numbers, which are free.

**Recommendation: keep the monorepo.** Revisit only if the suite grows a
consumer that is not AstrX — at that point the seam becomes a real public API,
and a public API deserves its own repo, its own semver and its own CI. Until
then, splitting adds ceremony and removes safety.

---

## The two things that made this question feel urgent

The question probably arose because two things were genuinely wrong. Both are
now fixed, and both were symptoms of the boundary being *unmarked*, not of it
being in the wrong place.

### 1. The PHP application had no deployment at all

`astrx-suite/docker-compose.yml` had been extracted to the repository root as
well as its own directory, overwriting the application's nginx + php-fpm +
mariadb stack. From then on `docker compose up -d` in a fresh clone brought up
five Rust engines and no website, and `docker/nginx/Dockerfile`,
`docker/php/Dockerfile` and `docker/mysql/Dockerfile` had nothing referencing
them anywhere in the repo.

Nothing caught it because nothing tests the deployment, and both files are
individually plausible — only diffing them reveals that one *is* the other.

Fixed: the root compose is the application stack again, and it `include:`s the
suite's rather than duplicating it, so there is one definition of those ten
services and no copy that can drift. The suite's compose still runs standalone.

**This is the real lesson.** The problem was never that the two halves live in
one repository — it was that nothing marked where one ended and the other began,
so a careless extraction silently merged them. A submodule would have prevented
*this particular* accident while making a dozen others easier.

### 2. MariaDB had no volume

Its data directory was commented out, so the database lived in the container
layer and `docker compose down -v` destroyed every board, page and account. It
was the only stateful service in the project without a volume — sitting next to
five Rust engines that all had one, which is exactly the kind of asymmetry a
monorepo makes visible and a split would have hidden.

---

## How the boundary is marked now

Since the boundary is staying, it is worth stating what it *is*, so the next
careless extraction has something to violate:

```
/                       the AstrX CMS — PHP, nginx, MariaDB
├── docker-compose.yml  the whole product: app + engines (include:s the suite)
├── src/, resources/,   the CMS. Never contains Rust.
│   public/, tools/
├── docs/               documentation for both halves
└── astrx-suite/        the Rust engines — a self-contained Cargo workspace
    ├── docker-compose.yml   the engines alone; must stay runnable standalone
    ├── crates/              no crate here may know the CMS exists
    └── deploy/              engine-only deployment assets
```

Three rules:

1. **Nothing under `astrx-suite/` may depend on anything outside it.** The
   engines are consumed over HTTP by the CMS and by anyone else; they must never
   read a PHP config, a CMS database, or a path above their own root. (Verified:
   the workspace has no such reference today.)
2. **`astrx-suite/docker-compose.yml` must keep working on its own.** It is the
   contract that the suite is independently useful, and it is what makes the
   "should we split?" question answerable with "you already can".
3. **The root `docker-compose.yml` composes; it never copies.** If those two
   files are ever byte-identical again, something has gone wrong in exactly the
   way it went wrong before.

## If you do split later

The migration is mechanical, and worth writing down while it is fresh:

```sh
# History-preserving extraction (git-filter-repo, not the old filter-branch)
git clone astrx astrx-suite-split && cd astrx-suite-split
git filter-repo --subdirectory-filter astrx-suite
# then, in the app repo:
git rm -r astrx-suite
git submodule add https://github.com/hydrastro/astrx-suite astrx-suite
```

What must be in place **before** that is worth doing:

- The JSON APIs versioned (`/api/v1/…`) and their shapes pinned by tests on both
  sides, since they stop being private the moment the repos separate.
- CI in the app repo that checks out a specific suite tag and runs the bridge
  tests against a real engine, not a mock.
- A release process that bumps the submodule pointer deliberately rather than
  by accident.

Without those three, a split converts a visible coupling into an invisible one.

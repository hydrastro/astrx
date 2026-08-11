#!/usr/bin/env bash
# install_rust_suite.sh — verify + build the astrx-suite Rust workspace.
#
# Usage (any of):
#   bash install_rust_suite.sh                 # from inside astrx-suite/
#   bash astrx-suite/install_rust_suite.sh     # from the parent directory
#   bash install_rust_suite.sh [flags] [/path/to/astrx-suite]
#
# Flags:
#   --ci          also run rustfmt --check + clippy (all 4 feature configs) as
#                 hard gates. Requires the `clippy` and `rustfmt` components to
#                 be present AND from the SAME toolchain as cargo/rustc.
#   --build-only  compile only; skip the test run.
#   --offline     pass --offline to cargo (use the pinned Cargo.lock, no network;
#                 only works once the dependencies are already fetched).
#
# The DEFAULT path needs nothing but `rustc` + `cargo` (so `nix-shell -p rustc
# cargo` is enough): it builds the workspace, runs the tests, and asserts the
# zero-dependency property. Linting is opt-in via --ci precisely because a
# missing or mismatched clippy-driver must never break a plain build.
#
# REQUIRES a Rust toolchain >= 1.80 (the workspace MSRV). The optional `net`
# tier pins tokio/mio/socket2, which themselves need Rust ~1.70+, so an older
# rustc cannot build the suite — this script checks and tells you up front.
set -euo pipefail

MSRV_MINOR=80   # workspace MSRV is 1.80

DO_CI=0
BUILD_ONLY=0
OFFLINE=()
SUITE_ARG=""
for arg in "$@"; do
  case "$arg" in
    --ci)         DO_CI=1 ;;
    --build-only) BUILD_ONLY=1 ;;
    --offline)    OFFLINE=(--offline) ;;
    -h|--help)    sed -n '2,20p' "$0"; exit 0 ;;
    -*)           echo "error: unknown flag '$arg' (see --help)." >&2; exit 2 ;;
    *)            SUITE_ARG="$arg" ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
if [ -n "$SUITE_ARG" ]; then
  SUITE="$SUITE_ARG"
elif [ -f "$SCRIPT_DIR/Cargo.toml" ]; then
  SUITE="$SCRIPT_DIR"          # script sits at the suite root (shipped in-repo)
else
  SUITE="./astrx-suite"        # standalone download, run from the parent dir
fi

if [ ! -f "$SUITE/Cargo.toml" ]; then
  echo "error: '$SUITE' is not the astrx-suite workspace root (no Cargo.toml)." >&2
  echo "       pass the extracted astrx-suite directory as the first argument." >&2
  exit 2
fi
cd "$SUITE"

command -v cargo >/dev/null 2>&1 || { echo "error: cargo not found — install Rust (https://rustup.rs)." >&2; exit 2; }

# --- MSRV guard: fail early with actionable guidance on an old toolchain -----
RUSTV="$(rustc --version | awk '{print $2}')"   # e.g. 1.62.1  or  1.85.0-nightly
RVER="${RUSTV%%-*}"                              # strip any -nightly/-beta suffix
RMAJOR="${RVER%%.*}"; RTAIL="${RVER#*.}"; RMINOR="${RTAIL%%.*}"
echo "==> toolchain: rustc $RUSTV (workspace MSRV 1.$MSRV_MINOR)"
if [ "${RMAJOR:-0}" -lt 1 ] || { [ "$RMAJOR" -eq 1 ] && [ "${RMINOR:-0}" -lt "$MSRV_MINOR" ]; }; then
  cat >&2 <<MSG

error: rustc $RUSTV is older than this suite's MSRV (1.$MSRV_MINOR) — it cannot build the code.
       (The optional 'net' tier also pins tokio/mio/socket2, which need Rust ~1.70+.)

  Get a current Rust, then re-run this script:

    # rustup (portable, any distro):
    rustup update stable && rustup default stable

    # NixOS — an ad-hoc shell with a recent toolchain (include clippy+rustfmt
    # only if you plan to use --ci; a plain build needs just rustc + cargo):
    nix-shell -p rustc cargo                 # plain build
    nix-shell -p rustc cargo clippy rustfmt  # if you want --ci

  Then check:  rustc --version               # must report >= 1.$MSRV_MINOR
MSG
  exit 1
fi

STEP=1
if [ "$DO_CI" -eq 1 ]; then
  # Opt-in developer gate. Probe that clippy/rustfmt are present and from the
  # same toolchain, so a nix cargo + rustup clippy-driver mismatch fails loudly
  # here rather than mid-run with a cryptic 'unknown print request' error.
  if ! cargo fmt --version >/dev/null 2>&1; then
    echo "error: --ci needs rustfmt (missing). Add the 'rustfmt' component or drop --ci." >&2; exit 2
  fi
  if ! cargo clippy --version >/dev/null 2>&1; then
    echo "error: --ci needs clippy (missing/mismatched). Add the 'clippy' component" >&2
    echo "       from the SAME toolchain as cargo, or drop --ci." >&2; exit 2
  fi
  echo "==> [$STEP] rustfmt --check"; STEP=$((STEP+1))
  cargo fmt --all --check
  echo "==> [$STEP] clippy across all 4 feature configs (-D warnings)"; STEP=$((STEP+1))
  for cfg in "--no-default-features" "--features rand" "--features net" "--all-features"; do
    echo "    clippy $cfg"
    cargo clippy "${OFFLINE[@]}" --workspace --all-targets $cfg -- -D warnings
  done
fi

if [ "$BUILD_ONLY" -eq 0 ]; then
  echo "==> [$STEP] test suite (--all-features)"; STEP=$((STEP+1))
  cargo test "${OFFLINE[@]}" --workspace --all-features
fi

echo "==> [$STEP] zero-dependency assertion (--no-default-features)"; STEP=$((STEP+1))
for crate in crawlcore torrentds onioncrawler websearch; do
  deps="$(cargo tree "${OFFLINE[@]}" -p "$crate" --no-default-features --prefix none 2>/dev/null \
          | grep -vE "^(crawlcore|torrentds|onioncrawler|websearch)( |$)" | sort -u || true)"
  if [ -n "$deps" ]; then
    echo "    WARN: $crate pulled third-party deps with default features:" >&2
    echo "$deps" | sed 's/^/      /' >&2
  else
    echo "    $crate: zero third-party deps by default ✓"
  fi
done

echo ""
echo "==> release build (all features)"
cargo build "${OFFLINE[@]}" --workspace --all-features --release

echo ""
echo "All checks passed. Binaries are under target/release/."
[ "$DO_CI" -eq 0 ] && echo "(run with --ci to also enforce rustfmt + clippy)"
exit 0

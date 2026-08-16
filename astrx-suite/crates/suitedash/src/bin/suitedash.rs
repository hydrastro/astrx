//! The `suitedash` binary — a thin wrapper around [`suitedash::cli::run`].
//!
//! The command line itself lives in the library (`src/cli.rs`) so the `astrx`
//! multiplexer can invoke exactly the same parser, help text and exit codes via
//! `astrx suitedash …`. Keeping this standalone binary means every unit file,
//! container `CMD`, cron entry and runbook that calls `suitedash` directly keeps
//! working untouched; deleting it would break all of them for no gain.
#![forbid(unsafe_code)]

use std::process::ExitCode;

fn main() -> ExitCode {
    suitedash::cli::run(std::env::args().skip(1))
}

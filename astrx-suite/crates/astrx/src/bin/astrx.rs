//! The `astrx` binary — a thin wrapper around [`astrx::dispatch::run`].
//!
//! All the logic (and all the tests) live in the library so the dispatcher's
//! pass-through contract can be asserted without spawning a process.
#![forbid(unsafe_code)]

use std::process::ExitCode;

fn main() -> ExitCode {
    let argv: Vec<String> = std::env::args().skip(1).collect();
    astrx::dispatch::run(&argv)
}

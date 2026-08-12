# astrx-suite — one multi-stage build for every Rust engine.
#
#   docker build -f deploy/Dockerfile --target websearch -t astrx/websearch .
#
# (docker-compose.yml sets `context: .` + `target: <service>` per service, so a
# single `docker compose build` compiles the workspace ONCE and then cuts five
# images out of the same builder layer.)
#
# WHY THE IMAGES ARE EMPTY
#   The suite has zero third-party dependencies by default and its only
#   networking deps (tokio, getrandom) are pure Rust, so every engine links to a
#   single statically-linked musl binary with no libc, no shell and no package
#   manager underneath it. Four of the five run `FROM scratch`: the image is one
#   executable, /etc/passwd, and a data directory. There is nothing to CVE-scan,
#   nothing to shell into, and nothing to escalate with.
#
#   `gitweb` is the documented exception: it is a front-end for the real `git`
#   binary (which it execs argv-only, never through a shell), so its image is
#   alpine + git and nothing else.

# ---------------------------------------------------------------------------
# Builder — compiles the whole workspace once, statically, against musl.
# ---------------------------------------------------------------------------
FROM rust:1.83-alpine AS builder

# musl-dev provides the C runtime bits the linker needs; git lets the build
# metadata resolve if a crate ever asks for it. Neither ships in the images.
RUN apk add --no-cache musl-dev git

WORKDIR /src

# Copy the workspace. `.dockerignore` keeps target/, .git/ and legacy-python/
# out of the context, so this stays a small, cache-friendly copy.
COPY Cargo.toml Cargo.lock rust-toolchain.toml ./
COPY crates ./crates

# Fully static: no dynamic loader, so the binaries run in an empty image.
# `--features net` turns on the serving/crawling tiers (tokio + getrandom); the
# default build is a pure library and would produce no binaries at all.
ENV RUSTFLAGS="-C target-feature=+crt-static"
RUN cargo build --release --locked --features net \
        --target x86_64-unknown-linux-musl \
        -p websearch -p torrentds -p onioncrawler -p suitedash -p gitweb \
    && mkdir -p /out \
    && cp target/x86_64-unknown-linux-musl/release/websearch \
          target/x86_64-unknown-linux-musl/release/torrentds \
          target/x86_64-unknown-linux-musl/release/onioncrawler \
          target/x86_64-unknown-linux-musl/release/suitedash \
          target/x86_64-unknown-linux-musl/release/gitweb /out/ \
    && /out/websearch --help >/dev/null

# A passwd/group pair so the scratch images can run as a non-root, non-existent
# user (nobody:65534). There is no shell to log into either way, but a numeric
# UID with no matching entry breaks tools that call getpwuid().
RUN printf 'nobody:x:65534:65534:nobody:/:/sbin/nologin\n' > /out/passwd \
    && printf 'nobody:x:65534:\n' > /out/group \
    && mkdir -p /emptydir && chown 65534:65534 /emptydir

# ---------------------------------------------------------------------------
# websearch — clearnet crawler + search UI/API
# ---------------------------------------------------------------------------
FROM scratch AS websearch
COPY --from=builder /out/passwd /out/group /etc/
COPY --from=builder --chown=65534:65534 /out/websearch /websearch
# The data directory must exist in the image; a scratch image has no mkdir, so
# it is COPYed as an empty, correctly-owned tree from the builder. A fresh named
# volume inherits this ownership, which is what lets the unprivileged user write.
COPY --from=builder --chown=65534:65534 /emptydir /data
USER 65534:65534
VOLUME ["/data"]
EXPOSE 8803
ENTRYPOINT ["/websearch"]
CMD ["serve", "--db", "/data/web.db", "--host", "0.0.0.0", "--port", "8803"]

# ---------------------------------------------------------------------------
# onioncrawler — darknet crawler + search UI/API (reaches Tor's SOCKS port)
# ---------------------------------------------------------------------------
FROM scratch AS onioncrawler
COPY --from=builder /out/passwd /out/group /etc/
COPY --from=builder --chown=65534:65534 /out/onioncrawler /onioncrawler
COPY --from=builder --chown=65534:65534 /emptydir /data
USER 65534:65534
VOLUME ["/data"]
EXPOSE 8802
ENTRYPOINT ["/onioncrawler"]
CMD ["search", "--db", "/data/crawl.db", "--host", "0.0.0.0", "--port", "8802"]

# ---------------------------------------------------------------------------
# torrentds — DHT indexer, search UI/API and BitTorrent trackers
# ---------------------------------------------------------------------------
FROM scratch AS torrentds
COPY --from=builder /out/passwd /out/group /etc/
COPY --from=builder --chown=65534:65534 /out/torrentds /torrentds
COPY --from=builder --chown=65534:65534 /emptydir /data
USER 65534:65534
VOLUME ["/data"]
EXPOSE 8804 8805
ENTRYPOINT ["/torrentds"]
CMD ["search", "--db", "/data/torrentds.db", "--host", "0.0.0.0", "--port", "8804"]

# ---------------------------------------------------------------------------
# suitedash — the no-JS status dashboard for the rest of the suite
# ---------------------------------------------------------------------------
FROM scratch AS suitedash
COPY --from=builder /out/passwd /out/group /etc/
COPY --from=builder --chown=65534:65534 /out/suitedash /suitedash
COPY --from=builder --chown=65534:65534 /emptydir /data
USER 65534:65534
VOLUME ["/data"]
EXPOSE 8806
# suitedash is the one image that can health-check itself without a shell: its
# own `--check` probes every configured service and exits non-zero if any is
# DOWN. The scratch engines below it are covered BY this dashboard.
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD ["/suitedash", "--check", "--quiet"]
ENTRYPOINT ["/suitedash"]
CMD ["--host", "0.0.0.0", "--port", "8806"]

# ---------------------------------------------------------------------------
# gitweb — read-only git frontend. The documented non-scratch image: it is a
# front-end for the real `git` binary, which it execs argv-only (never a shell).
# ---------------------------------------------------------------------------
FROM alpine:3.20 AS gitweb
RUN apk add --no-cache git \
    && adduser -S -u 65533 -H -s /sbin/nologin gitweb \
    && mkdir -p /repos && chown 65533 /repos
COPY --from=builder /out/gitweb /usr/local/bin/gitweb
USER 65533
VOLUME ["/repos"]
EXPOSE 8801
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["/usr/local/bin/gitweb", "--version"]
ENTRYPOINT ["/usr/local/bin/gitweb"]
CMD ["--root", "/repos", "--host", "0.0.0.0", "--port", "8801", "--quiet"]

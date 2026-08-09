# onioncrawler container: bundles the crawler + no-JS search server and a Tor
# daemon so the whole thing can run as (and reach) v3 hidden services.
#
# IMPORTANT: build from the SUITE ROOT so the bundled `crawlcore` sibling
# package is in the build context:
#     docker build -f onioncrawler/Dockerfile -t onioncrawler .
# (docker-compose sets context:. and dockerfile:onioncrawler/Dockerfile.)
# Crawl:  docker run --rm -v oc-data:/data onioncrawler \
#             crawl --db /data/crawl.db --seeds /data/seeds.txt -v
# Search: docker run --rm -p 8802:8802 -v oc-data:/data onioncrawler \
#             search --db /data/crawl.db --host 0.0.0.0 --port 8802
#
# For a real crawl a Tor daemon must be reachable at --tor-host/--tor-port
# (install `tor` in the image below, or point at a sidecar/host Tor).

FROM python:3.11-slim AS base

# Tor is only required for real crawling; the search server does not need it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends tor \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
# The shared crawlcore library is a sibling package; install it into the image
# so `import crawlcore` resolves directly (the source-tree sibling shim finds it
# only in the unpacked zip, not in a per-tool container).
COPY crawlcore /app/crawlcore
COPY onioncrawler /app/onioncrawler

# stdlib-only packages; this installs crawlcore then the console entry point.
RUN pip install --no-cache-dir /app/crawlcore /app/onioncrawler

# Run unprivileged with a dedicated data volume.
RUN useradd --create-home --uid 10001 crawler \
    && mkdir -p /data && chown crawler:crawler /data
USER crawler
VOLUME ["/data"]
EXPOSE 8802

ENTRYPOINT ["onioncrawler"]
# Default: print help. Override with `crawl ...` or `search ...`.
CMD ["--help"]

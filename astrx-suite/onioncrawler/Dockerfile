# onioncrawler container: bundles the crawler + no-JS search server and a Tor
# daemon so the whole thing can run as (and reach) v3 hidden services.
#
# Build:  docker build -t onioncrawler .
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
COPY . /app

# stdlib-only package; this just installs the console entry point.
RUN pip install --no-cache-dir .

# Run unprivileged with a dedicated data volume.
RUN useradd --create-home --uid 10001 crawler \
    && mkdir -p /data && chown crawler:crawler /data
USER crawler
VOLUME ["/data"]
EXPOSE 8802

ENTRYPOINT ["onioncrawler"]
# Default: print help. Override with `crawl ...` or `search ...`.
CMD ["--help"]

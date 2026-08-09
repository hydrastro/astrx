# torrentds -- zero-dependency DHT metadata indexer + tracker.
# No pip install step is needed: the whole thing is Python 3.11 stdlib.
FROM python:3.11-slim

LABEL org.opencontainers.image.title="torrentds" \
      org.opencontainers.image.description="DHT torrent-metadata search + tracker" \
      org.opencontainers.image.licenses="MIT"

WORKDIR /app
COPY pyproject.toml README.md ./
COPY torrentds ./torrentds
RUN pip install --no-cache-dir . && \
    useradd --system --create-home --uid 10001 torrentds && \
    mkdir -p /data && chown torrentds:torrentds /data
USER torrentds

VOLUME ["/data"]
ENV TORRENTDS_DB=/data/torrentds.db

# DHT (UDP), search UI (HTTP), tracker HTTP, tracker UDP.
EXPOSE 6881/udp 8804/tcp 8805/tcp 6969/udp

# Default: run the search UI.  Override the command to `index` or `tracker`.
ENTRYPOINT ["torrentds"]
CMD ["search", "--db", "/data/torrentds.db", "--host", "0.0.0.0"]

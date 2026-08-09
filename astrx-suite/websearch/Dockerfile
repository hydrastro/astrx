# astrx-websearch: zero-dependency clearnet search engine.
# Stdlib-only, so the image needs nothing but CPython.
FROM python:3.11-slim

# Run as an unprivileged user.
RUN useradd --system --create-home --home-dir /var/lib/websearch websearch \
    && mkdir -p /var/lib/websearch && chown websearch:websearch /var/lib/websearch

WORKDIR /app
COPY pyproject.toml README.md ./
COPY websearch ./websearch

# Installs the `websearch` console entry point (no third-party deps to fetch).
RUN pip install --no-cache-dir .

USER websearch
WORKDIR /var/lib/websearch
VOLUME ["/var/lib/websearch"]
EXPOSE 8803

# Default: serve the index. Override the command to crawl, e.g.:
#   docker run ... astrx-websearch crawl --seeds /var/lib/websearch/seeds \
#       --db /var/lib/websearch/web.db --scope-domain example.com
ENTRYPOINT ["websearch"]
CMD ["serve", "--db", "/var/lib/websearch/web.db", \
     "--host", "0.0.0.0", "--port", "8803"]

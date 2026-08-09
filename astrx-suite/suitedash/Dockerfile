# suitedash — zero-dependency, no-JavaScript status dashboard for the astrx-suite.
#
# The image ships only Python; there are no Python dependencies to install.
# The dashboard polls the other suite services over the container network, so
# run it on the same Docker network (or host) as them and put Tor / a reverse
# proxy in front. Provide a config file to point it at the right hosts.
FROM python:3.11-slim

WORKDIR /app
COPY . /app
RUN pip install --no-cache-dir . \
    && useradd --system --uid 10001 --create-home suitedash

USER suitedash
EXPOSE 8805

# Bind to all interfaces *inside the container* — the container network and the
# Tor / reverse-proxy front are the trust boundary. Mount a config read-only to
# target the real service hosts, e.g.:
#   docker run --rm -p 127.0.0.1:8805:8805 \
#     -v /etc/suitedash/suitedash.toml:/etc/suitedash/suitedash.toml:ro \
#     suitedash --config /etc/suitedash/suitedash.toml --host 0.0.0.0
ENTRYPOINT ["suitedash"]
CMD ["--host", "0.0.0.0", "--port", "8805", "--quiet"]

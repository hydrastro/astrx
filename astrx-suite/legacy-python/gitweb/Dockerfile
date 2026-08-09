# gitweb — read-only, no-JavaScript, standard-library-only git web viewer.
#
# The image ships only Python + git; there are no Python dependencies to
# install.  Mount your repository root read-only at /srv/git and put Tor or a
# reverse proxy in front of the container.
FROM python:3.11-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
RUN pip install --no-cache-dir . \
    && useradd --system --uid 10001 --create-home gitweb

USER gitweb
EXPOSE 8801

# Bind to all interfaces *inside the container* — the container network and the
# Tor/reverse-proxy front are the trust boundary.  Mount repos read-only:
#   docker run --rm -p 127.0.0.1:8801:8801 -v /srv/git:/srv/git:ro gitweb
ENTRYPOINT ["gitweb"]
CMD ["--root", "/srv/git", "--host", "0.0.0.0", "--port", "8801", "--quiet"]

# Cursor Cloud Agent base image with gstack baked into the snapshot.
# gstack lives under /opt (not $HOME) so it survives across agent sessions.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    BUN_INSTALL=/home/ubuntu/.bun \
    PATH=/home/ubuntu/.bun/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        bash \
    && rm -rf /var/lib/apt/lists/* \
    && (id -u ubuntu >/dev/null 2>&1 || useradd -m -s /bin/bash ubuntu) \
    && mkdir -p /home/ubuntu \
    && chown -R ubuntu:ubuntu /home/ubuntu

# Install bun for the runtime user and keep ~/.bun/bin on PATH.
USER ubuntu
WORKDIR /home/ubuntu
RUN curl -fsSL https://bun.sh/install | bash

USER root
RUN git clone --single-branch --depth 1 https://github.com/garrytan/gstack.git /opt/gstack \
    && chown -R ubuntu:ubuntu /opt/gstack

USER ubuntu
WORKDIR /opt/gstack
RUN bun install \
    && bun run build

# Playwright system deps need root; browsers stay usable by ubuntu afterwards.
USER root
WORKDIR /opt/gstack
RUN bunx playwright install --with-deps chromium \
    && chown -R ubuntu:ubuntu /opt/gstack /home/ubuntu/.bun

USER ubuntu
WORKDIR /workspace
ENV PATH=/home/ubuntu/.bun/bin:${PATH}

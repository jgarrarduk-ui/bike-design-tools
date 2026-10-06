# Creature Cycles tools-api
#
# Build context is this directory (server/):
#   docker build -f server/Dockerfile server
#
# On Railway, set the service Root Directory to `server` and the config-as-code
# path to `/server/railway.toml`. Attach a volume mounted at /data.

FROM node:20-bookworm-slim AS deps

# better-sqlite3 ships prebuilds and falls back to node-gyp. Keep a compiler
# toolchain in this stage so the native module can be built when needed.
RUN apt-get update \
  && apt-get install -y --no-install-recommends python3 make g++ \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

FROM node:20-bookworm-slim

# gosu drops root to the node user after fixing the volume mount's ownership.
# Railway volumes at /data are root-owned; the API process itself is non-root.
RUN apt-get update \
  && apt-get install -y --no-install-recommends gosu \
  && rm -rf /var/lib/apt/lists/* \
  && mkdir -p /data \
  && chown node:node /data

WORKDIR /app

COPY --from=deps /app/node_modules ./node_modules
COPY . .
RUN chmod +x docker-entrypoint.sh \
  && chown -R node:node /app

ENV NODE_ENV=production \
    DB_PATH=/data/designs.db \
    PORT=3001

EXPOSE 3001

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["node", "index.js"]

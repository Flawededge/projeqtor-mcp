FROM node:22-alpine@sha256:0a7108bf6c7bf5de370ffb1a3ed6be93d405b43ff159f681a8d18c0e2bc2e402

ARG VERSION=dev
LABEL org.opencontainers.image.title="ProjeQtOr MCP server" \
      org.opencontainers.image.description="Per-user Model Context Protocol server for ProjeQtOr" \
      org.opencontainers.image.source="https://github.com/Flawededge/projeqtor-mcp" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

ENV NODE_ENV=production
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts \
 && npm cache clean --force

COPY --chown=node:node src ./src

USER node
EXPOSE 3000
CMD ["node", "src/main.mjs"]

FROM node:22-bookworm-slim AS build

WORKDIR /app/server
COPY server/package.json server/package-lock.json ./
RUN npm ci
COPY server/tsconfig.json ./
COPY server/src ./src
RUN npm run build && npm prune --omit=dev

FROM node:22-bookworm-slim

ENV NODE_ENV=production \
    BITRIX_MCP_INDEX=/data/index.sqlite \
    BITRIX_CORE_ROOT=/data/core \
    BITRIX_MCP_HTTP_HOST=0.0.0.0 \
    BITRIX_MCP_HTTP_PORT=3000

WORKDIR /app/server
COPY --from=build /app/server/node_modules ./node_modules
COPY --from=build /app/server/dist ./dist

USER node
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:3000/mcp',{method:'POST',headers:{'content-type':'application/json'},body:'{}'}).then(r=>process.exit(r.status===401?0:1)).catch(()=>process.exit(1))"

CMD ["node", "dist/src/http.js"]

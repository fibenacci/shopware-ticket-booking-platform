# Scanner app production image — static SPA behind nginx with a same-origin
# /api proxy to the shop's `web` service (required by the app's CSP).
# Built + pushed by .github/workflows/docker-publish.yml; routed by Traefik
# in compose.prod.yaml (Host: scanner.${SHOP_DOMAIN}).

FROM node:22-alpine AS build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

FROM nginx:alpine

COPY --from=build /app/dist /usr/share/nginx/html
COPY nginx.prod.conf /etc/nginx/conf.d/default.conf

#!/usr/bin/env sh
# Local HTTPS certs for *.booking.docker — needed for camera access in the
# scanner app (getUserMedia requires a secure context) and a warning-free
# green lock on all local https domains.
#
# Writes the cert pair the dinghy proxy expects (~/.dinghy/certs is mounted
# into the proxy; nginx-proxy wildcard convention: booking.docker.crt covers
# *.booking.docker).
#
# Strategy:
#   1. ensure mkcert exists (auto-install via Homebrew when available)
#   2. mkcert -install — registers the local CA in the system/browser trust
#      stores (asks for your password ONCE; no-op afterwards)
#   3. (re)issue the cert when missing or not mkcert-trusted yet
#   4. fallback without mkcert/brew: self-signed (one-time browser warning)
set -eu

DOMAIN="${DOMAIN:-booking.docker}"
CERT_DIR="${CERT_DIR:-$HOME/.dinghy/certs}"
CRT="$CERT_DIR/$DOMAIN.crt"
KEY="$CERT_DIR/$DOMAIN.key"

have_mkcert() { command -v mkcert >/dev/null 2>&1; }

ensure_mkcert() {
    have_mkcert && return 0
    if command -v brew >/dev/null 2>&1; then
        echo "▶ Installing mkcert via Homebrew (one-time, for locally trusted certs)…"
        brew install mkcert nss || true
    fi
    have_mkcert
}

cert_is_mkcert() {
    [ -s "$CRT" ] && openssl x509 -in "$CRT" -noout -issuer 2>/dev/null | grep -qi mkcert
}

mkdir -p "$CERT_DIR"

if ensure_mkcert; then
    # Register the local CA in the trust stores — needs your password once
    # (interactive). Non-interactive runs must not break `make up`.
    if ! mkcert -install 2>/dev/null; then
        echo "⚠  Local CA not trusted yet — run 'mkcert -install' once (asks for your password)"
        echo "   to turn the browser warning into a green lock."
    fi
    if cert_is_mkcert; then
        echo "✅ trusted dev certs present: $CRT"
        exit 0
    fi
    echo "▶ Issuing locally-trusted cert for $DOMAIN + *.$DOMAIN …"
    mkcert -cert-file "$CRT" -key-file "$KEY" "$DOMAIN" "*.$DOMAIN"
else
    if [ -s "$CRT" ] && [ -s "$KEY" ]; then
        echo "✅ dev certs present (self-signed): $CRT"
        exit 0
    fi
    echo "▶ mkcert/brew not found — generating self-signed cert (one-time browser warning)."
    openssl req -x509 -newkey rsa:2048 -sha256 -days 825 -nodes \
        -keyout "$KEY" -out "$CRT" -subj "/CN=$DOMAIN" \
        -addext "subjectAltName=DNS:$DOMAIN,DNS:*.$DOMAIN"
fi

# The proxy reads certs at start — restart it if it is already running.
docker restart http-proxy >/dev/null 2>&1 || true
echo "✅ dev certs ready — https://$DOMAIN · https://scanner.$DOMAIN (🔒)"

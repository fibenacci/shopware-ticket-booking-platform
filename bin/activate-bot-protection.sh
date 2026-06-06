#!/usr/bin/env sh
# Activate honeypot + ALTCHA captchas (layers 2+3 in docs/Bot-Protection.md).
#
# Shopware's static system config only accepts scalar values, so the
# activeCaptchasV2 object must be written to the database instead. The
# deployment helper runs this script on every deploy (.shopware-project.yml,
# hooks.post), re-enforcing the captcha setup even if it was changed in the
# admin UI in the meantime.
#
# ALTCHA_SECRET_KEY: required in production (compose.prod.yaml refuses to
# start without it). Outside production a random per-run secret is generated —
# fine for dev/CI, where challenge continuity across restarts does not matter.
set -eu

ALTCHA_SECRET_KEY="${ALTCHA_SECRET_KEY:-$(php -r 'echo bin2hex(random_bytes(16));')}"

php bin/console system:config:set core.basicInformation.activeCaptchasV2 "$(cat <<JSON
{
    "honeypot": {"name": "honeypot", "isActive": true},
    "altchaCaptcha": {
        "name": "altchaCaptcha",
        "isActive": true,
        "config": {
            "secretKey": "${ALTCHA_SECRET_KEY}",
            "whitelistCustomers": true,
            "hideLogo": false,
            "hideFooter": false,
            "autoMode": "onsubmit",
            "invisible": true
        }
    }
}
JSON
)" --json

echo "Bot protection captchas activated (honeypot + altchaCaptcha)."

#!/usr/bin/env sh
# "Stack ready" banner — called at the end of `make up` (make/stack.mk).
# Ticket-stub motif (it's a booking/ticketing system) + gradient logo.
DOMAIN="${DOMAIN:-booking.docker}"

# 256-color helpers; chrome is dim so the content pops.
c() { printf '\033[38;5;%sm' "$1"; }
B='\033[1m'; D='\033[2m'; R='\033[0m'

printf '\n'
printf "$(c 51)  ███████╗██╗██████╗     ██████╗  ██████╗  ██████╗ ██╗  ██╗██╗███╗   ██╗ ██████╗ ${R}\n"
printf "$(c 45)  ██╔════╝██║██╔══██╗    ██╔══██╗██╔═══██╗██╔═══██╗██║ ██╔╝██║████╗  ██║██╔════╝ ${R}\n"
printf "$(c 39)  █████╗  ██║██████╔╝    ██████╔╝██║   ██║██║   ██║█████╔╝ ██║██╔██╗ ██║██║  ███╗${R}\n"
printf "$(c 99)  ██╔══╝  ██║██╔══██╗    ██╔══██╗██║   ██║██║   ██║██╔═██╗ ██║██║╚██╗██║██║   ██║${R}\n"
printf "$(c 135)  ██║     ██║██████╔╝    ██████╔╝╚██████╔╝╚██████╔╝██║  ██╗██║██║ ╚████║╚██████╔╝${R}\n"
printf "$(c 201)  ╚═╝     ╚═╝╚═════╝     ╚═════╝  ╚═════╝  ╚═════╝ ╚═╝  ╚═╝╚═╝╚═╝  ╚═══╝ ╚═════╝ ${R}\n"
printf '\n'
printf "  ${D}┌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌┄┄ ✂${R}\n"
printf "  ${D}│${R}  ${B}🎟  ADMIT ONE${R} ${D}·${R} $(c 84)DEV STACK READY${R}\n"
printf "  ${D}├╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌${R}\n"
printf "  ${D}│${R}  🌐 ${B}%-12s${R} $(c 45)http://%-32s${R} ${D}%s${R}\n"  "Storefront"  "$DOMAIN"          "(direct: 127.0.0.1:8090)"
printf "  ${D}│${R}  🔐 ${B}%-12s${R} $(c 45)http://%-32s${R} ${D}%s${R}\n"  "Admin"       "$DOMAIN/admin"    "admin / shopware"
printf "  ${D}│${R}  📷 ${B}%-12s${R} $(c 84)https://%-31s${R} ${D}%s${R}\n" "Scanner"     "scanner.$DOMAIN"  "🔒 secure context — camera ON"
printf "  ${D}│${R}  📧 ${B}%-12s${R} $(c 45)http://%-32s${R}\n"             "Mailpit"     "mail.$DOMAIN"
printf "  ${D}│${R}  🗄  ${B}%-12s${R} $(c 45)http://%-32s${R}\n"            "Adminer"     "adminer.$DOMAIN"
printf "  ${D}├╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌${R}\n"
printf "  ${D}│${R}  ${D}SEAT${R} ${B}DEV${R}  ${D}·  ROW${R} ${B}LOCAL${R}  ${D}·  GATE${R} ${B}make help${R}  ${D}·  No. ${R}$(c 220)%s${R}\n" "$(date +%H%M%S)"
printf "  ${D}└╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌╌┄┄ ✂${R}\n"
printf '\n'

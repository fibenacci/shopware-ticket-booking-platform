#!/usr/bin/env sh
# Production backup: database dump + user-content volumes (files, media).
# Run on the HOST next to compose.prod.yaml (cron example in docs/Backups.md).
#
#   BACKUP_DIR=/backups ./bin/backup-prod.sh
#
# Optional:
#   BACKUP_AGE_RECIPIENT  age public key — when set, artifacts are encrypted
#                         (.age) and the plaintext is removed
#   BACKUP_KEEP_DAYS      local retention in days (default 14)
#   COMPOSE_FILE          compose file to use (default compose.prod.yaml)
#   ENV_FILE              env file to use (default .env.prod)
set -eu

BACKUP_DIR="${BACKUP_DIR:?BACKUP_DIR is required, e.g. /backups}"
BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.prod.yaml}"
ENV_FILE="${ENV_FILE:-.env.prod}"
STAMP="$(date +%Y%m%d-%H%M%S)"

compose() {
    docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

mkdir -p "$BACKUP_DIR"

echo "▶ Dumping database …"
compose exec -T db sh -c 'mariadb-dump --single-transaction --quick -ushopware -p"$MARIADB_PASSWORD" shopware' \
    | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"

echo "▶ Archiving volumes (files, media) …"
for vol in files media; do
    compose run --rm --no-deps --entrypoint tar web \
        -czf - -C "/var/www/html/$([ "$vol" = files ] && echo files || echo public/media)" . \
        > "$BACKUP_DIR/$vol-$STAMP.tar.gz"
done

if [ -n "${BACKUP_AGE_RECIPIENT:-}" ]; then
    echo "▶ Encrypting artifacts (age) …"
    for f in "$BACKUP_DIR/db-$STAMP.sql.gz" "$BACKUP_DIR/files-$STAMP.tar.gz" "$BACKUP_DIR/media-$STAMP.tar.gz"; do
        age -r "$BACKUP_AGE_RECIPIENT" -o "$f.age" "$f" && rm "$f"
    done
fi

echo "▶ Pruning backups older than $BACKUP_KEEP_DAYS days …"
find "$BACKUP_DIR" -maxdepth 1 -type f \
    \( -name 'db-*' -o -name 'files-*' -o -name 'media-*' \) \
    -mtime "+$BACKUP_KEEP_DAYS" -delete

echo "✅ Backup complete: $BACKUP_DIR ($STAMP)"

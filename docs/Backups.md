# Backups

Resilience is part of defense in depth: ransomware on the host, a broken
deploy or a disk failure must never be a total loss.

## What to back up

| Data | Where | Why |
|---|---|---|
| Database | volume `db_data` (dumped, not copied) | orders, customers, bookings, tickets |
| `files` | volume `files` | invoices/documents |
| `media` | volume `media` | uploaded images |
| `traefik_certs` | volume | optional — Let's Encrypt re-issues automatically |

Theme, thumbnails and sitemap are derived data — regenerated on deploy, not
worth backing up.

## How

`bin/backup-prod.sh` (run on the host, next to `compose.prod.yaml`):

- consistent `mariadb-dump --single-transaction` (no downtime)
- tars the `files` + `media` volumes
- optional **age encryption** (`BACKUP_AGE_RECIPIENT=age1…`) — do this
  before shipping anything offsite
- local retention via `BACKUP_KEEP_DAYS` (default 14)

Cron example (daily, 04:00, encrypted):

```cron
0 4 * * * cd /opt/booking && BACKUP_DIR=/backups BACKUP_AGE_RECIPIENT=age1… ./bin/backup-prod.sh >> /var/log/booking-backup.log 2>&1
```

## Offsite

A local backup dies with the host. Sync `/backups` to independent storage —
e.g. `rclone sync /backups remote:bucket` (S3/B2/Hetzner Storage Box) as a
second cron entry after the backup. Keep the age **private** key off the
host; otherwise encryption is theatre.

## Restore (test this twice a year!)

```bash
# database
gunzip < db-<stamp>.sql.gz | docker compose -f compose.prod.yaml --env-file .env.prod \
    exec -T db sh -c 'mariadb -ushopware -p"$MARIADB_PASSWORD" shopware'

# volumes (example: media)
docker compose -f compose.prod.yaml --env-file .env.prod \
    run --rm --no-deps --entrypoint sh web -c 'tar -xzf - -C /var/www/html/public/media' < media-<stamp>.tar.gz

# encrypted artifacts first: age -d -i key.txt backup.age > backup
```

A backup that has never been restored is a hope, not a backup.

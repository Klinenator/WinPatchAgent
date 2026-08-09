# MySQL Storage Cutover Runbook

Use this when you want to move the PatchAgent PHP API off `storage/runtime` files and into MySQL-backed storage for steadier production persistence and easier audit evidence.

## What the cutover does

- Backs up the current `storage/runtime/` directory
- Imports existing runtime files into MySQL typed tables
- Enables `PATCH_API_DB_DRIVER=mysql` in nginx FastCGI params
- Reloads PHP-FPM and nginx
- Verifies `/healthz` reports `storage_driver=mysql`

## Prerequisites

- The API repo is deployed at `/var/www/WinPatchAgent`
- nginx already includes `/etc/winpatchagent/patchapi-secrets.conf`
- A MySQL database and application user already exist
- The current secrets file already contains the non-database settings you want to keep

## Recommended command

```bash
cd /var/www/WinPatchAgent
sudo bash backend/php-api/scripts/enable_mysql_storage.sh \
  --app-root /var/www/WinPatchAgent \
  --db-host 127.0.0.1 \
  --db-port 3306 \
  --db-name winpatchagent \
  --db-user winpatch_app
```

The script prompts for the MySQL password if you do not pass `--db-password` or set `PATCH_API_DB_PASSWORD`.

## What to verify after cutover

```bash
curl -s http://127.0.0.1/healthz
```

Expected result includes:

```json
{"status":"ok","time":"...","storage_driver":"mysql"}
```

Admin verification is also available from:

- `GET /v1/admin/storage/status`

That endpoint shows the active driver plus the effective MySQL host, port, database, and table prefix source.

## Rollback

If the cutover fails after the secrets file is updated:

1. Restore the backup secrets file created by the script.
2. Reload PHP-FPM and nginx.
3. Keep using file mode until the MySQL issue is resolved.

The script creates:

- A secrets backup at `/etc/winpatchagent/patchapi-secrets.conf.bak.<timestamp>`
- A runtime backup under `/var/backups/winpatchagent/runtime-<timestamp>/`

## Notes

- `storage/runtime/` is still left in place after cutover so rollback stays simple.
- MySQL mode stores typed records in relational tables and uses a fallback document table for uncategorized blobs such as cache records.
- This is the recommended production mode for audit readiness because it gives you durable storage, queryable history, and easier backup/restore handling.

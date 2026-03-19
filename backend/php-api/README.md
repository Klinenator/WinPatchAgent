# PatchAgent PHP API

This is a minimal PHP backend scaffold for the PatchAgent Windows service.

It is intentionally framework-free so it can run behind nginx with plain PHP-FPM.

Current endpoints:
- `POST /v1/agents/register`
- `POST /v1/agents/heartbeat`
- `POST /v1/agents/inventory`
- `POST /v1/agents/jobs/next`
- `POST /v1/agents/job-events`
- `GET /healthz`

Storage model:
- File-backed JSON and NDJSON under `storage/runtime/`
- Good enough for early prototyping
- Not intended for production scale

Files:
- `public/index.php`: front controller for nginx or the PHP built-in server
- `src/`: routing, auth, storage, and endpoint handlers
- `storage/runtime/`: local file-backed state

Environment variables:
- `PATCH_API_ENROLLMENT_KEY`: optional shared key required at registration time
- `PATCH_API_STORAGE_ROOT`: optional override for the runtime storage path
- `PATCH_API_HEARTBEAT_SECONDS`: default `300`
- `PATCH_API_JOBS_SECONDS`: default `120`
- `PATCH_API_INVENTORY_SECONDS`: default `21600`

Local run example once PHP is installed:

```bash
cd /Users/seankline/src/windows_patch_management_agent/backend/php-api
php -S 127.0.0.1:8080 -t public
```

Suggested nginx site:

```nginx
server {
    listen 80;
    server_name patch-api.local;
    root /Users/seankline/src/windows_patch_management_agent/backend/php-api/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;
    }
}
```

Suggested next steps:
- Replace file storage with MySQL or PostgreSQL
- Add agent key rotation and signed enrollment flow
- Add admin APIs for job creation and rollout targeting

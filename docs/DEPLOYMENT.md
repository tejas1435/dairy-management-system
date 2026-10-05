# Deployment

Production deployment to a Linux VPS running Nginx, PHP-FPM and MySQL 8.4.

**Status:** requirements and known risks recorded in Phase 0. Finalised and
verified in Phase 10.

No provider-specific secrets belong in this file or anywhere in the repository.

---

## 1. Server requirements

| Component | Version | Notes |
| --------- | ------- | ----- |
| PHP | 8.3 minimum, 8.4 preferred | FPM |
| MySQL | 8.4 LTS | InnoDB, `utf8mb4` |
| Nginx | current stable | |
| Composer | 2.x | |
| Node | pin to the same major used in development (26.x) | build only |

Required PHP extensions: `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `bcmath`,
`fileinfo`, `openssl`, `curl`, `dom`, `xml`, `xmlreader`, `xmlwriter`,
`simplexml`, `tokenizer`, `ctype`, `iconv`.

`gd` and `zip` are required by the Excel exporter; `dom`, `mbstring` and `gd` by
the PDF renderer.

---

## 2. PHP settings that are not optional

The development machine's defaults are too small for this application. Set
these explicitly in the production `php.ini`:

| Setting | Minimum | Why |
| ------- | ------- | --- |
| `upload_max_filesize` | 8M | Employee ID and bank documents are scanned PDFs; the 2M default rejects ordinary files |
| `post_max_size` | 16M | Must exceed `upload_max_filesize` |
| `memory_limit` | 512M | Excel and PDF report generation |
| `max_input_vars` | 5000 | Defence in depth only — the Customer Daily Entry grid submits JSON precisely so it does not depend on this (see [DECISIONS.md](DECISIONS.md) D5) |
| `date.timezone` | Asia/Kolkata | Application config sets this too |
| `opcache.enable` | 1 | |

Nginx must allow bodies at least as large as `post_max_size`:

```nginx
client_max_body_size 16m;
```

---

## 3. Database

Create the schema and a **least-privilege application user**. Never deploy with
`root`.

```sql
CREATE DATABASE dairy_management CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'dairy_app'@'localhost' IDENTIFIED BY '<generated-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON dairy_management.* TO 'dairy_app'@'localhost';
FLUSH PRIVILEGES;
```

The password is generated on the server and stored only in `.env`. It is never
written into documentation, source, commits or command output.

### Case sensitivity warning

Development on Windows runs with `lower_case_table_names = 1`; Linux defaults to
`0`, where table names are case-sensitive. The project uses lowercase
`snake_case` table names exclusively so this cannot bite (see
[DECISIONS.md](DECISIONS.md) D6). Do not introduce mixed-case identifiers.

---

## 4. Deploy procedure

```
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
sudo systemctl reload php8.4-fpm
```

`php artisan down` before migrating and `php artisan up` afterwards for
migrations that are not backward compatible.

After any `.env` change, re-run `php artisan config:cache`; cached config
ignores `.env` at runtime.

---

## 5. Environment

Production `.env` differs from development in:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<domain>
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

`APP_DEBUG=true` in production leaks configuration and stack traces. It must be
`false`.

`APP_KEY` must be set and must not be regenerated on an existing installation —
doing so makes encrypted values and sessions unreadable.

---

## 6. Permissions

```
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

Private uploads live under `storage/app/private` and are served only through the
authorised download controller. The web server must not expose `storage/app`
directly; only `public/storage` (the symlink) is public, and private documents
are never placed there.

---

## 7. HTTPS

TLS terminated at Nginx with a valid certificate and HTTP redirected to HTTPS.
Secure cookies require HTTPS, so `SESSION_SECURE_COOKIE=true` and a working
certificate go together.

Security headers are configured in Phase 10.

---

## 8. Scheduler and queue

Scheduler, for notifications and periodic work:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The queue uses the database driver. Run a worker under supervisor when queued
work is enabled:

```
php artisan queue:work --tries=3 --timeout=90
```

Restart workers after every deploy (`php artisan queue:restart`) — long-running
workers hold stale code otherwise.

---

## 9. Backups

Three things must be backed up:

1. **Database** — nightly `mysqldump` of `dairy_management`, retained offsite.
   This is the business's entire financial and milk history.
2. **Private uploads** — `storage/app/private`, which holds employee documents
   and receipts and is not reproducible. As of Phase 5 it also holds
   `milk-sale-slips/`: the Mandali collection slips, which are the farm's evidence
   for what a dairy collected and are not reproducible either. Their filenames are
   random, so the database rows and the files are only useful together — back them
   up on the same schedule as the database, and restore them together.
3. **Environment** — `.env` is not in version control, so its values must be
   recorded somewhere safe. Losing `APP_KEY` makes encrypted data unrecoverable.

Restores must be tested, not assumed.

---

## 10. Logs

`storage/logs` must be writable by the web server and should be rotated.
`LOG_LEVEL=error` in production; `debug` leaks detail and fills the disk.

````md
# Joomla Lightweight Backup Tools

Lightweight PHP backup scripts for Joomla websites running on shared hosting environments without SSH access.

Designed for:
- FTP-only hosting
- Shared hosting
- Low memory servers
- Timeout-sensitive environments
- Large Joomla installations

These scripts create:
- Full Joomla files ZIP backup
- Full MySQL database SQL/GZIP backup

Both scripts work in chunked batches with browser auto-refresh to avoid server timeouts.

---

# Features

## File Backup Script (`backup.php`)

- Chunked ZIP creation
- Auto-refresh progress UI
- Low memory usage
- Timeout resistant
- Excludes cache/tmp/logs automatically
- Download-ready ZIP archive

## Database Backup Script (`db-backup.php`)

- Chunked SQL export
- Auto-refresh progress UI
- Large database friendly
- GZIP compression
- Table-by-table export
- Shared hosting optimized

---

# Requirements

- PHP 7.4+
- ZipArchive extension enabled
- MySQLi enabled
- Write permissions in Joomla root

---

# Installation

Upload both files into Joomla root:

```text
backup.php
db-backup.php
```

Example Joomla root:

```text
administrator/
components/
images/
plugins/
templates/
configuration.php
backup.php
db-backup.php
```

---

# Security Configuration

Before usage, edit both scripts and change:

```php
$TOKEN = 'CHANGE_THIS_SECRET_TOKEN';
```

Use a long random token.

Example:

```php
$TOKEN = 'my-super-secret-token-123';
```

---

# File Backup Usage

Open:

```text
https://yourdomain.com/backup.php?token=YOUR_TOKEN
```

The script will:
1. Scan Joomla files
2. Create ZIP in chunks
3. Auto-refresh every few seconds
4. Show live progress
5. Generate downloadable ZIP archive

Generated backups are stored in:

```text
_backup_tmp/
```

---

# Database Backup Usage

Configure DB credentials inside:

```php
$dbHost
$dbUser
$dbPass
$dbName
```

Then open:

```text
https://yourdomain.com/db-backup.php?token=YOUR_TOKEN
```

The script will:
1. Export tables incrementally
2. Export rows in batches
3. Auto-refresh progress
4. Generate `.sql`
5. Generate compressed `.sql.gz`

Generated backups are stored in:

```text
_db_backup_tmp/
```

---

# Reset Backup

To reset running backup:

## File backup

```text
https://yourdomain.com/backup.php?token=YOUR_TOKEN&reset=1
```

## Database backup

```text
https://yourdomain.com/db-backup.php?token=YOUR_TOKEN&reset=1
```

---

# Recommended Workflow

## Step 1

Run database backup first:

```text
db-backup.php
```

Download:
- `.sql.gz`

---

## Step 2

Run files backup:

```text
backup.php
```

Download:
- `.zip`

---

# Restore Guide

## Restore Database

Using Adminer or phpMyAdmin:

1. Create empty database
2. Import `.sql` or `.sql.gz`

---

## Restore Files

Upload extracted ZIP contents back to hosting root via FTP.

---

# Notes

These scripts are optimized for:
- Shared hosting
- No SSH access
- Large Joomla sites
- Slow hosting providers
- Timeout-prone environments

The scripts intentionally:
- Avoid long-running single requests
- Use chunked processing
- Use browser refresh instead of AJAX
- Minimize RAM usage

---

# Important Security Notice

After downloading backups:

DELETE:
- `backup.php`
- `db-backup.php`
- `_backup_tmp/`
- `_db_backup_tmp/`

Never leave backup scripts publicly accessible.

---

# Excluded Directories

The file backup excludes:

```text
cache/
tmp/
logs/
administrator/cache/
_backup_tmp/
```

---

# License

MIT
````

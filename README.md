# Joomla Lightweight Backup Tools

Minimal PHP backup scripts for shared hosting environments without SSH access. Designed to handle timeouts and low memory via chunked processing and browser auto-refresh.

---

## Core Features

### File Backup (backup.php)

* Creates ZIP archives in chunks.
* Auto-excludes cache, tmp, and logs.
* Progress UI with auto-refresh.

### Database Backup (db-backup.php)

* Incremental SQL export with GZIP compression.
* Table-by-table processing.
* Shared hosting optimized.

---

## Setup & Security

1. Upload backup.php and db-backup.php to the Joomla root.
2. Edit both files to set a unique $TOKEN value.
3. For db-backup.php, manually enter your MySQL credentials.

---

## Usage

Access the scripts via your browser using your secret token:

* Files: [yourdomain.com/backup.php?token=SECRET](https://www.google.com/search?q=https://yourdomain.com/backup.php%3Ftoken%3DSECRET)
* Database: [yourdomain.com/db-backup.php?token=SECRET](https://www.google.com/search?q=https://yourdomain.com/db-backup.php%3Ftoken%3DSECRET)

To restart a process, append &reset=1 to the URL.

---

## Directory Structure

* File backups: _backup_tmp/
* DB backups: _db_backup_tmp/

---

## Recommended Workflow

1. Run db-backup.php and download the .sql.gz file.
2. Run backup.php and download the .zip file.
3. Delete both scripts and their temporary backup folders from the server immediately after use.

---

## Restore Process

* Database: Import the SQL file via phpMyAdmin or Adminer.
* Files: Extract the ZIP and upload contents to the root via FTP.

---

## License

MIT

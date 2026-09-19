# Opening and restoring a backup

Every night `cron/backup.php` writes `backup/backup_YYYYMMDD_HHMMSS.sql.gz` on the server (14 kept), and —
when **Off-site backup** is ON — `cron/backup-offsite.php` emails the same file, **encrypted**, to the company
mailbox as `backup_….sql.gz.enc`. Keep those emails.

You need the **backup password** typed in *Admin → Settings → backup_offsite_password*. It is not in the email
and not recoverable: keep it on paper, away from the office computer.

## 1. Decrypt (any computer with OpenSSL — Linux, macOS, Git Bash on Windows)

    openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 -in backup_XXXX.sql.gz.enc -out backup.sql.gz

It asks for the password. "bad decrypt" = wrong password.

## 2. Look before you restore

    gunzip -c backup.sql.gz | head -5          # "-- S Hari Global database backup" and its date
    gunzip -c backup.sql.gz | grep -c "^INSERT INTO `bookings`"

## 3. Restore into an EMPTY database (never over the live one)

    mysql -u root -e "CREATE DATABASE shari_restore CHARACTER SET utf8mb4"
    gunzip -c backup.sql.gz | mysql -u root shari_restore
    mysql -u root shari_restore -e "SELECT COUNT(*) FROM bookings; SELECT MAX(created_at) FROM bookings"

Only when the numbers look right: point `config/config.php` at the restored database (new server), or copy
the missing rows across. The dump starts with `DROP TABLE IF EXISTS` for every table — running it against the
live database replaces everything in it with the night the backup was taken.

## Restore drill

Do steps 1–3 once every three months with a real email. A backup that has never been opened is a hope, not a
backup. Last drill: _(write the date here)_.

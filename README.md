# Backup

Daily backups for NataPHP apps. One run:

1. dumps the app's MySQL/MariaDB database to `database.sql.gz`;
2. archives each configured folder to `{name}.tar.gz`;
3. keeps them in `{path}/YYYY-MM-DD/` and removes the dated folders older than `retentionDays`;
4. copies today's folder to an external destination with `rsync` over ssh (a Hetzner Storage Box by default) and applies the same retention there.

Design choices:

- The database password never appears on the command line: `mysqldump` reads it from a temporary `--defaults-extra-file` (mode 600), removed in a `finally` block.
- Without an external destination the run fails when `requireRemote` is on (by default, when `debug` is off): a copy on the same server is not a backup.
- Only dated folders (`YYYY-MM-DD`) are ever removed, locally and remotely. The remote is never mirrored from `{path}`, so a lost or rebuilt server does not delete the external copies. On the remote, the expired folders are deleted with `rsync` alone (a Storage Box has no full shell).
- A failed step throws `BackupException` with the reason, including the last line the failing command wrote to stderr (`command not found`, `Permission denied (publickey)`…).
- Messages are in English, with a Portuguese (pt-PT) translation (`__d('backup', …)`).

---

## Installation

Require the package (`nataphp/backup`; the VCS repository goes next to `nataphp/core`'s in the app's `composer.json`):

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/nataphp/core"},
    {"type": "vcs", "url": "https://github.com/nataphp/backup"}
],
"require": {
    "nataphp/backup": "^1.0"
}
```

Then load it in `config/plugins.php` (it has no bootstrap or routes; loading it makes the translations available):

```php
Plugin::load('Backup', [
    'path' => ROOT . 'vendor' . DS . 'nataphp' . DS . 'backup' . DS,
    'bootstrap' => false,
    'routes' => false,
]);
```

The package autoloads `Backup\`. To run its tests from the app, add `"Backup\\Test\\": "vendor/nataphp/backup/tests/"` to the app's `autoload-dev` and `vendor/nataphp/backup/tests/TestCase` to `phpunit.xml`.

## Configuration

Everything is under `Backup.*` in `Configure`, usually in `config/bootstrap.inc.php`:

```php
Configure::write('Backup', [
    'path' => ROOT . 'var' . DS . 'backups' . DS,   // required
    'retentionDays' => 14,                          // locally and remotely
    'timezone' => 'Europe/Lisbon',                  // of the folder dates (default: PHP's)
    'label' => 'myapp',                             // written in each archive's BACKUP.txt
    'mysqldumpBin' => env('MYSQLDUMP_BIN', 'mysqldump'),
    'routines' => true,                             // --routines; off on hosts without the privilege
    'folders' => [
        // {name}.tar.gz => folder; "exclude" lists relative path prefixes left out
        'storage' => ['path' => ROOT . 'var' . DS . 'storage' . DS, 'exclude' => ['tmp/']],
    ],
    'remote' => env('BACKUP_REMOTE', ''),          // e.g. u123-sub1@u123-sub1.your-storagebox.de:backups/
    'sshKey' => env('BACKUP_SSH_KEY', ''),
    'sshPort' => 23,                                // 23 on a Storage Box
    // 'knownHostsFile' => null,                    // default: known_hosts next to sshKey; '' = ssh's default
    // 'requireRemote' => null,                     // default: !debug; false when another machine pulls {path}
    // 'connection' => null,                        // default: Database.config
]);
```

Keep the retention fixed in the code rather than in `.env` when a privacy notice promises it: changing it changes what the users are told.

`known_hosts` sits next to the key by default because the home of a PHP pool user is often the deployed app itself, where `~/.ssh` is missing, not writable, or wiped by the next deploy.

## The command

Each app has its own `backup` command, so it can record the run its own way:

```php
namespace App\Command;

use Backup\Service\BackupException;
use Backup\Service\BackupRunner;
use Nata\Console\Arguments;
use Nata\Console\Command;
use Nata\Console\Io;

class Backup extends App {

    protected $_description = 'Database and files backup, with retention and an external copy.';

    public function execute(Arguments $args, Io $io): ?int {
        try {
            foreach ((new BackupRunner())->run() as $message) {
                $io->out($message);
            }
        } catch (BackupException $exception) {
            $io->error($exception->getMessage());

            return Command::CODE_ERROR;
        }

        return Command::CODE_SUCCESS;
    }

}
```

```cron
15 3 * * *  myapp-php  cd /var/www/myapp && flock -n var/tmp/backup.lock php bin/nata.php backup >> var/logs/cron-backup.log 2>&1
```

`BackupRunner` takes an optional process runner, `fn(string $command, ?string $stdoutFile): int`, so tests never call `mysqldump` or `rsync`.

## Status page

`(new BackupStatus())->local($limit)` lists the local copies, newest first: `['date' => '2026-10-28', 'files' => ['database.sql.gz' => 80083, …], 'bytes' => …]`. `BackupStatus::humanSize($bytes)` gives "78,2 KB". Whether the external copy went well is in the result of the last run.

## External destination (Hetzner Storage Box)

One sub-account per app, so a leaked key only reaches that app's copies:

1. Storage Box → Subaccounts → Create: base directory `myapp`, **Allow SSH** on, SMB and WebDAV off, not read-only. External reachability is not needed from a Hetzner server.
2. On the server, a key for the PHP pool user, outside the deployed files:
   ```bash
   install -d -m 700 -o myapp-php -g myapp /var/www/myapp/var/ssh
   sudo -u myapp-php ssh-keygen -t ed25519 -N "" -C "myapp backup" -f /var/www/myapp/var/ssh/storagebox
   ```
3. Install it on the sub-account (asks for its password once) and create the folder:
   ```bash
   cat /var/www/myapp/var/ssh/storagebox.pub | ssh -p 23 u123-sub1@u123-sub1.your-storagebox.de install-ssh-key
   sudo -u myapp-php ssh -p 23 -i /var/www/myapp/var/ssh/storagebox -o UserKnownHostsFile=/var/www/myapp/var/ssh/known_hosts u123-sub1@u123-sub1.your-storagebox.de mkdir backups
   ```
4. `.env`: `BACKUP_REMOTE=u123-sub1@u123-sub1.your-storagebox.de:backups/` and `BACKUP_SSH_KEY=/var/www/myapp/var/ssh/storagebox`.

Storage Box snapshots protect the copies from a compromised server (which holds a key that can delete them), but they keep the data longer than `retentionDays`: count them in what a privacy notice promises.

## Restoring

```bash
# 1. Get the copy (from the Storage Box if the server is gone)
rsync -az -e "ssh -p 23" u123-sub1@u123-sub1.your-storagebox.de:backups/2026-10-28/ /tmp/restore/

# 2. Database (into a new or empty database)
gunzip -c /tmp/restore/database.sql.gz | mysql -u <user> -p <database>

# 3. Files
tar -xzf /tmp/restore/storage.tar.gz -C /var/www/myapp/var/storage/
```

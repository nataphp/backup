<?php
/**
 * Backup plugin (NataPHP).
 *
 * @package Backup
 */

namespace Backup\Test\TestCase\Service;

use Backup\Service\BackupException;
use Backup\Service\BackupRunner;
use Nata\Core\Configure;
use Nata\I18n\Time;
use PharData;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

/**
 * Daily backup with a fake process runner (no real mysqldump or rsync):
 * credentials never on the command line, an external copy when required,
 * the same retention locally and in the external copy, and the folders
 * archived as configured.
 */
class BackupRunnerTest extends TestCase {

/**
 * Original configuration, restored after each test.
 *
 * @var array<string, mixed>
 */
    protected array $_original = [];

/**
 * Temporary folders created by the test.
 *
 * @var string[]
 */
    protected array $_folders = [];

/**
 * Point backups at a temporary folder, with no folders to archive and no remote.
 *
 * @return void
 */
    protected function setUp(): void {
        $this->_original = ['debug' => Configure::read('debug'), 'Backup' => Configure::read('Backup')];
        Configure::write('debug', true);
        Configure::write('Backup', [
            'path' => $this->_folder('backups'),
            'retentionDays' => 14,
            'timezone' => 'Europe/Lisbon',
            'label' => 'test',
            'remote' => '',
            'sshKey' => '',
            'folders' => [],
        ]);
    }

/**
 * Restore the configuration and remove the temporary folders.
 *
 * @return void
 */
    protected function tearDown(): void {
        Configure::write('debug', $this->_original['debug']);
        Configure::write('Backup', $this->_original['Backup']);
        foreach ($this->_folders as $folder) {
            $this->_remove($folder);
        }
    }

/**
 * Without a remote, a run that requires one (by default: debug off) fails
 * before doing anything.
 *
 * @return void
 */
    public function testRequiredRemoteFailsBeforeAnything(): void {
        Configure::write('debug', false);
        $commands = [];
        $runner = function (string $command) use (&$commands) {
            $commands[] = $command;

            return 0;
        };

        try {
            (new BackupRunner($runner))->run();
            $this->fail('Backup ran without the required remote');
        } catch (BackupException $exception) {
            $this->assertSame(__d('backup', 'No external destination (Backup.remote): an external copy is mandatory here.'), $exception->getMessage());
        }
        $this->assertSame([], $commands, 'nothing is executed');
    }

/**
 * requireRemote false (another machine pulls the backups) runs without a
 * remote even with debug off.
 *
 * @return void
 */
    public function testRemoteCanBeOptionalWithDebugOff(): void {
        Configure::write('debug', false);
        Configure::write('Backup.requireRemote', false);

        $log = (new BackupRunner($this->_dumpRunner()))->run();

        $this->assertStringContainsString(__d('backup', 'No external copy (Backup.remote is empty).'), implode("\n", $log));
    }

/**
 * The password goes in a temporary defaults file (never in the command line),
 * and that file is removed even when mysqldump fails.
 *
 * @return void
 */
    public function testCredentialsFileIsTemporaryAndRemoved(): void {
        $seen = ['command' => null, 'credentials' => null, 'mode' => null, 'content' => ''];
        $runner = function (string $command) use (&$seen) {
            $seen['command'] = $command;
            if (preg_match('/--defaults-extra-file=["\']?([^"\'\s]+)["\']?/', $command, $matches) === 1) {
                $seen['credentials'] = $matches[1];
                $seen['content'] = (string)file_get_contents($matches[1]);
                $seen['mode'] = fileperms($matches[1]) & 0777;
            }

            return 2;
        };

        try {
            (new BackupRunner($runner))->run();
            $this->fail('A failing mysqldump must fail the backup');
        } catch (BackupException $exception) {
            $this->assertSame(__d('backup', 'mysqldump failed (exit code %d).', [2]), $exception->getMessage());
        }

        $this->assertNotNull($seen['credentials']);
        $this->assertStringContainsString('[client]', $seen['content']);
        $this->assertStringNotContainsString('--password', $seen['command']);
        $this->assertStringNotContainsString(' -p', $seen['command']);
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0600, $seen['mode']);
        }
        $this->assertFileDoesNotExist($seen['credentials'], 'credentials file removed in finally');
    }

/**
 * --routines is on by default and can be turned off (shared hosts without
 * the privilege to read the routines).
 *
 * @return void
 */
    public function testRoutinesOption(): void {
        $commands = [];
        (new BackupRunner($this->_dumpRunner($commands)))->run();
        $this->assertStringContainsString(' --routines', $commands[0]);

        Configure::write('Backup.routines', false);
        $commands = [];
        (new BackupRunner($this->_dumpRunner($commands)))->run();
        $this->assertStringNotContainsString('--routines', $commands[0]);
    }

/**
 * Each configured folder becomes {name}.tar.gz, without the excluded paths
 * and with the BACKUP.txt marker; a missing folder still gives an archive.
 *
 * @return void
 */
    public function testFoldersAreArchivedWithExclusions(): void {
        $storage = $this->_folder('storage');
        mkdir($storage . 'photos', 0777, true);
        mkdir($storage . 'tmp', 0777, true);
        file_put_contents($storage . 'photos' . DS . 'a.jpg', 'jpeg');
        file_put_contents($storage . 'tmp' . DS . 'upload.part', 'partial');
        Configure::write('Backup.folders', [
            'storage' => ['path' => $storage, 'exclude' => ['tmp/']],
            'uploads' => $this->_folder('missing') . 'nowhere',
        ]);

        $log = (new BackupRunner($this->_dumpRunner()))->run();

        $directory = Configure::read('Backup.path') . (new Time('now'))->timezone('Europe/Lisbon')->format('Y-m-d') . DS;
        $this->assertSame(['BACKUP.txt', 'photos/a.jpg'], $this->_archiveEntries($directory . 'storage.tar.gz'));
        $this->assertSame(['BACKUP.txt'], $this->_archiveEntries($directory . 'uploads.tar.gz'));
        $this->assertFileExists($directory . 'database.sql.gz');
        $this->assertStringContainsString(__d('backup', 'Files (%s): %s', ['storage', $directory . 'storage.tar.gz']), implode("\n", $log));
    }

/**
 * A folder name that is not a plain file name is refused.
 *
 * @return void
 */
    public function testInvalidFolderNameIsRefused(): void {
        Configure::write('Backup.folders', ['../escape' => $this->_folder('storage')]);

        $this->expectException(BackupException::class);
        (new BackupRunner($this->_dumpRunner()))->run();
    }

/**
 * Local retention removes only dated folders older than retentionDays.
 *
 * @return void
 */
    public function testLocalRetentionRemovesOnlyExpiredDatedFolders(): void {
        $root = Configure::read('Backup.path');
        $today = (new Time('now'))->timezone('Europe/Lisbon');
        $old = (clone $today)->modify('-15 days')->format('Y-m-d');
        $kept = (clone $today)->modify('-14 days')->format('Y-m-d');
        foreach ([$old, $kept, 'manual-copy'] as $name) {
            mkdir($root . $name, 0777, true);
            file_put_contents($root . $name . DS . 'database.sql.gz', 'x');
        }

        (new BackupRunner($this->_dumpRunner()))->run();

        $this->assertDirectoryDoesNotExist($root . $old);
        $this->assertDirectoryExists($root . $kept);
        $this->assertDirectoryExists($root . 'manual-copy');
    }

/**
 * The external copy keeps the same retention as the local one: after
 * sending today's folder, the remote root is listed and only the dated
 * folders older than retentionDays are deleted (with rsync, every other
 * path excluded and so protected).
 *
 * @return void
 */
    public function testRemoteRetentionDeletesOnlyExpiredDatedFolders(): void {
        Configure::write('Backup.remote', 'u1@u1.your-storagebox.de:backups/');
        $today = (new Time('now'))->timezone('Europe/Lisbon');
        $old = (clone $today)->modify('-15 days')->format('Y-m-d');
        $older = (clone $today)->modify('-40 days')->format('Y-m-d');
        $kept = (clone $today)->modify('-14 days')->format('Y-m-d');
        $listing = implode("\n", [
            'drwxr-xr-x          4,096 2026/10/01 03:00:01 .',
            'drwxr-xr-x          4,096 2026/10/01 03:00:01 ' . $older,
            'drwxr-xr-x          4,096 2026/10/01 03:00:01 ' . $old,
            'drwxr-xr-x          4,096 2026/10/01 03:00:01 ' . $kept,
            'drwxr-xr-x          4,096 2026/10/01 03:00:01 ' . $today->format('Y-m-d'),
            'drwxr-xr-x          4,096 2026/10/01 03:00:01 manual-copy',
            '-rw-r--r--            120 2026/10/01 03:00:01 2026-01-01',
        ]) . "\n";
        $commands = [];
        $runner = function (string $command, ?string $stdoutFile) use (&$commands, $listing) {
            $commands[] = $command;
            if ($stdoutFile !== null) {
                file_put_contents($stdoutFile, str_contains($command, '--list-only') ? $listing : 'dump');
            }

            return 0;
        };

        $log = (new BackupRunner($runner))->run();

        $sends = array_values(array_filter($commands, fn(string $command) => str_starts_with($command, 'rsync -az')));
        $this->assertCount(1, $sends);
        $this->assertStringContainsString("u1@u1.your-storagebox.de:backups/" . $today->format('Y-m-d') . '/', $sends[0]);
        $deletes = array_values(array_filter($commands, fn(string $command) => str_contains($command, '--exclude')));
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString('/' . $old . '/***', $deletes[0]);
        $this->assertStringContainsString('/' . $older . '/***', $deletes[0]);
        $this->assertStringNotContainsString('/' . $kept . '/', $deletes[0]);
        $this->assertStringNotContainsString($today->format('Y-m-d'), $deletes[0]);
        $this->assertStringNotContainsString('manual-copy', $deletes[0]);
        $this->assertStringNotContainsString('2026-01-01/', $deletes[0]);
        $this->assertStringContainsString('--delete', $deletes[0]);
        $this->assertStringContainsString(__d('backup', 'External retention: %d old copies removed%s.', [2, ' (' . $older . ', ' . $old . ')']), implode("\n", $log));
    }

/**
 * When the external copy cannot be listed, the backup fails: the retention
 * would silently stop being applied.
 *
 * @return void
 */
    public function testRemoteListingFailureFailsTheBackup(): void {
        Configure::write('Backup.remote', 'u1@u1.your-storagebox.de:backups/');
        $runner = function (string $command, ?string $stdoutFile) {
            if ($stdoutFile !== null && !str_contains($command, '--list-only')) {
                file_put_contents($stdoutFile, 'dump');
            }

            return str_contains($command, '--list-only') ? 12 : 0;
        };

        try {
            (new BackupRunner($runner))->run();
            $this->fail('A failing remote listing must fail the backup');
        } catch (BackupException $exception) {
            $this->assertSame(__d('backup', 'Listing the external copy (rsync) failed (exit code %d): the external retention was not applied.', [12]), $exception->getMessage());
        }
    }

/**
 * ssh uses the configured port and key, and a known_hosts file next to the
 * key unless another one is configured.
 *
 * @return void
 */
    public function testSshUsesKnownHostsNextToTheKey(): void {
        Configure::write('Backup.remote', 'u1@u1.your-storagebox.de:backups/');
        Configure::write('Backup.sshKey', '/srv/app/var/ssh/storagebox');
        $commands = [];
        $runner = function (string $command, ?string $stdoutFile) use (&$commands) {
            $commands[] = $command;
            if ($stdoutFile !== null) {
                file_put_contents($stdoutFile, str_contains($command, '--list-only') ? '' : 'dump');
            }

            return 0;
        };

        (new BackupRunner($runner))->run();

        $send = $commands[1];
        $this->assertStringContainsString('ssh -p 23 ', $send);
        $this->assertStringContainsString('UserKnownHostsFile=', $send);
        $this->assertStringContainsString('/srv/app/var/ssh' . DIRECTORY_SEPARATOR . 'known_hosts', $send);
        $this->assertStringContainsString('/srv/app/var/ssh/storagebox', $send);

        Configure::write('Backup.knownHostsFile', '');
        Configure::write('Backup.sshPort', 22);
        $commands = [];
        (new BackupRunner($runner))->run();
        $this->assertStringContainsString('ssh -p 22 ', $commands[1]);
        $this->assertStringNotContainsString('UserKnownHostsFile', $commands[1]);
    }

/**
 * Only dated folder names expire.
 *
 * @return void
 */
    public function testIsExpired(): void {
        $today = (new Time('now'))->timezone('Europe/Lisbon');

        $this->assertTrue(BackupRunner::isExpired((clone $today)->modify('-15 days')->format('Y-m-d'), 14, 'Europe/Lisbon'));
        $this->assertFalse(BackupRunner::isExpired((clone $today)->modify('-14 days')->format('Y-m-d'), 14, 'Europe/Lisbon'));
        $this->assertFalse(BackupRunner::isExpired('pre-2020-01-01_update.sql.gz', 14, 'Europe/Lisbon'));
        $this->assertFalse(BackupRunner::isExpired('2020-01-01-extra', 14, 'Europe/Lisbon'));
    }

/**
 * A runner whose mysqldump succeeds and records the commands.
 *
 * @param array $commands Receives the commands run.
 * @return callable Runner.
 */
    protected function _dumpRunner(array &$commands = []): callable {
        return function (string $command, ?string $stdoutFile) use (&$commands) {
            $commands[] = $command;
            if ($stdoutFile !== null) {
                file_put_contents($stdoutFile, 'dump');
            }

            return 0;
        };
    }

/**
 * Entries of a .tar.gz, sorted.
 *
 * @param string $archiveFile Archive.
 * @return string[] Relative paths.
 */
    protected function _archiveEntries(string $archiveFile): array {
        $entries = [];
        $prefix = 'phar://' . str_replace('\\', '/', $archiveFile) . '/';
        foreach (new RecursiveIteratorIterator(new PharData($archiveFile)) as $file) {
            $entries[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($prefix));
        }
        sort($entries);

        return $entries;
    }

/**
 * A new temporary folder, removed after the test.
 *
 * @param string $name Name prefix.
 * @return string Path with a trailing separator.
 */
    protected function _folder(string $name): string {
        $folder = sys_get_temp_dir() . DS . 'nata-backup-test-' . $name . '-' . uniqid() . DS;
        mkdir($folder, 0777, true);
        $this->_folders[] = $folder;

        return $folder;
    }

/**
 * Remove a folder recursively.
 *
 * @param string $folder Folder.
 * @return void
 */
    protected function _remove(string $folder): void {
        if (!is_dir($folder)) {
            return;
        }
        foreach (scandir($folder) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = rtrim($folder, '/\\') . DS . $entry;
            if (is_dir($path)) {
                $this->_remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($folder);
    }

}

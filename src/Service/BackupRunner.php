<?php
/**
 * Backup plugin (NataPHP).
 *
 * @package Backup
 */

namespace Backup\Service;

use Nata\Core\Configure;
use Nata\Database\ConnectionManager;
use Nata\I18n\Time;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Daily backup: a dump of the app's database (database.sql.gz) and one
 * tar.gz per configured folder ({name}.tar.gz) in {path}/YYYY-MM-DD/, a copy
 * of that folder to an external destination with rsync over ssh (a Hetzner
 * Storage Box, port 23, by default) and the same retention (retentionDays)
 * on both sides, so data deleted from the app leaves the backups within that
 * many days.
 *
 * - The database password never appears on the command line: mysqldump reads
 *   it from a temporary --defaults-extra-file (permissions 600), removed in a
 *   `finally` block.
 * - requireRemote (by default: when debug is off) makes a run without an
 *   external destination fail explicitly, because a backup on the same server
 *   is not a backup. Turn it off when another machine pulls {path} instead.
 * - Only dated folders (YYYY-MM-DD) are ever removed, locally and remotely,
 *   and the remote is never mirrored from {path}: a lost or rebuilt server
 *   does not delete the external copies.
 *
 * Processes are started through an injectable runner so tests never call
 * mysqldump or rsync.
 */
class BackupRunner {

/**
 * Configuration defaults (Backup.* in Configure).
 *
 * @var array<string, mixed>
 */
    const DEFAULTS = [
        'path' => null,
        'retentionDays' => 14,
        'timezone' => null,
        'label' => 'NataPHP',
        'connection' => null,
        'mysqldumpBin' => 'mysqldump',
        'routines' => true,
        'folders' => [],
        'remote' => '',
        'sshKey' => '',
        'sshPort' => 23,
        'knownHostsFile' => null,
        'requireRemote' => null,
    ];

/**
 * Runs a command: fn(string $command, ?string $stdoutFile): int exit code.
 *
 * @var callable
 */
    protected $_runner;

/**
 * Standard error of the last command run by the default runner.
 *
 * @var string
 */
    protected string $_lastError = '';

/**
 * Constructor.
 *
 * @param callable|null $runner Process runner (default: proc_open).
 */
    public function __construct(?callable $runner = null) {
        $this->_runner = $runner ?? [$this, 'runProcess'];
    }

/**
 * The configuration (Backup.* in Configure) with the defaults filled in.
 *
 * @return array<string, mixed> Configuration.
 * @throws \Backup\Service\BackupException When Backup.path is not set.
 */
    public static function config(): array {
        $config = array_replace(self::DEFAULTS, array_filter((array)Configure::read('Backup'), fn($value) => $value !== null));
        if (trim((string)$config['path']) === '') {
            throw new BackupException(__d('backup', 'Backup.path is not set.'));
        }
        $config['path'] = rtrim((string)$config['path'], '/\\') . DIRECTORY_SEPARATOR;
        $config['retentionDays'] = max(1, (int)$config['retentionDays']);
        $config['timezone'] = (string)($config['timezone'] ?: date_default_timezone_get());
        $config['remote'] = trim((string)$config['remote']);
        $config['sshKey'] = trim((string)$config['sshKey']);
        if ($config['requireRemote'] === null) {
            $config['requireRemote'] = !Configure::read('debug');
        }
        if ($config['knownHostsFile'] === null && $config['sshKey'] !== '') {
            $config['knownHostsFile'] = dirname($config['sshKey']) . DIRECTORY_SEPARATOR . 'known_hosts';
        }

        return $config;
    }

/**
 * Run the backup.
 *
 * @return string[] Progress messages.
 * @throws \Backup\Service\BackupException When a step fails or the remote is missing while required.
 */
    public function run(): array {
        $config = self::config();
        if ($config['requireRemote'] && $config['remote'] === '') {
            throw new BackupException(__d('backup', 'No external destination (Backup.remote): an external copy is mandatory here.'));
        }

        $log = [];
        $date = (new Time('now'))->timezone($config['timezone'])->format('Y-m-d');
        $directory = $config['path'] . $date . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new BackupException(__d('backup', 'Could not create %s', [$directory]));
        }

        $dumpFile = $directory . 'database.sql.gz';
        $this->_dumpDatabase($config, $dumpFile);
        $log[] = __d('backup', 'Database: %s', [$dumpFile]);

        foreach ((array)$config['folders'] as $name => $folder) {
            if (preg_match('/^[A-Za-z0-9_-]+$/', (string)$name) !== 1 || $name === 'database') {
                throw new BackupException(__d('backup', 'Invalid folder name in Backup.folders: %s', [(string)$name]));
            }
            $folder = is_array($folder) ? $folder : ['path' => $folder];
            $archiveFile = $directory . $name . '.tar.gz';
            $this->_archiveFolder((string)$folder['path'], (array)($folder['exclude'] ?? []), $archiveFile, $config['label'] . ' ' . $name);
            $log[] = __d('backup', 'Files (%s): %s', [$name, $archiveFile]);
        }

        $removed = $this->_applyRetention($config['path'], $config['retentionDays'], $config['timezone']);
        $log[] = __d('backup', 'Local retention: %d old copies removed.', [$removed]);

        if ($config['remote'] !== '') {
            $this->_sendToRemote($directory, $date, $config);
            $log[] = __d('backup', 'External copy: %s', [rtrim($config['remote'], '/') . '/' . $date . '/']);
            $remoteRemoved = $this->_applyRemoteRetention($config);
            $log[] = __d('backup', 'External retention: %d old copies removed%s.', [count($remoteRemoved), $remoteRemoved !== [] ? ' (' . implode(', ', $remoteRemoved) . ')' : '']);
        } else {
            $log[] = __d('backup', 'No external copy (Backup.remote is empty).');
        }

        return $log;
    }

/**
 * Whether a backup folder is older than the retention: only dated folders
 * (YYYY-MM-DD) count, anything else is never removed. With 14 days, today's
 * copy and the 14 before it are kept.
 *
 * @param string $folderName Folder name.
 * @param int $retentionDays Days to keep.
 * @param string|null $timezone Timezone of the folder dates (default: PHP's).
 * @return bool Expired.
 */
    public static function isExpired(string $folderName, int $retentionDays, ?string $timezone = null): bool {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $folderName) !== 1) {
            return false;
        }
        $limit = (new Time('now'))->timezone($timezone ?: date_default_timezone_get())->modify('-' . max(1, $retentionDays) . ' days')->format('Y-m-d');

        return $folderName < $limit;
    }

/**
 * Dump the database through a temporary credentials file (never on the command line).
 *
 * @param array<string, mixed> $config Configuration.
 * @param string $dumpFile Destination .sql.gz.
 * @return void
 * @throws \Backup\Service\BackupException When mysqldump fails.
 */
    protected function _dumpDatabase(array $config, string $dumpFile): void {
        $connectionName = (string)($config['connection'] ?: Configure::read('Database.config'));
        $params = ConnectionManager::get($connectionName)->getDoctrineConnection()->getParams();
        $credentialsFile = (string)tempnam(sys_get_temp_dir(), 'nbdb');
        try {
            chmod($credentialsFile, 0600);
            file_put_contents($credentialsFile, sprintf(
                "[client]\nuser=\"%s\"\npassword=\"%s\"\nhost=\"%s\"\nport=%d\n",
                addcslashes((string)($params['user'] ?? ''), "\"\\"),
                addcslashes((string)($params['password'] ?? ''), "\"\\"),
                addcslashes((string)($params['host'] ?? 'localhost'), "\"\\"),
                (int)($params['port'] ?? 3306)
            ));

            $plainDump = $dumpFile . '.tmp';
            $command = escapeshellarg((string)$config['mysqldumpBin'])
                . ' --defaults-extra-file=' . escapeshellarg($credentialsFile)
                . ' --single-transaction --quick' . ($config['routines'] ? ' --routines' : '') . ' --no-tablespaces --default-character-set=utf8mb4 '
                . escapeshellarg((string)($params['dbname'] ?? ''));
            $exitCode = $this->_run($command, $plainDump);
            if ($exitCode !== 0 || !is_file($plainDump) || filesize($plainDump) === 0) {
                if (is_file($plainDump)) {
                    unlink($plainDump);
                }
                throw new BackupException($this->_withError(__d('backup', 'mysqldump failed (exit code %d).', [$exitCode])));
            }

            $this->_gzipFile($plainDump, $dumpFile);
            unlink($plainDump);
        } finally {
            if (is_file($credentialsFile)) {
                unlink($credentialsFile);
            }
        }
    }

/**
 * tar.gz of a folder. A missing folder gives an archive with only the
 * BACKUP.txt marker, so every copy has the same files.
 *
 * @param string $folder Folder to archive.
 * @param string[] $exclude Relative path prefixes left out (e.g. "tmp/").
 * @param string $archiveFile Destination .tar.gz.
 * @param string $marker Text of the BACKUP.txt marker.
 * @return void
 */
    protected function _archiveFolder(string $folder, array $exclude, string $archiveFile, string $marker): void {
        $tarFile = substr($archiveFile, 0, -3);
        foreach ([$tarFile, $archiveFile] as $existing) {
            if (is_file($existing)) {
                unlink($existing);
            }
        }

        $archive = new PharData($tarFile);
        $root = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR;
        if ($folder !== '' && is_dir($root)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root)));
                if ($file->isFile() && !$this->_isExcluded($relative, $exclude)) {
                    $archive->addFile($file->getPathname(), $relative);
                }
            }
        }
        $archive->addFromString('BACKUP.txt', $marker . ' ' . gmdate('c') . "\n");
        $archive->compress(Phar::GZ);
        unset($archive);
        unlink($tarFile);
    }

/**
 * Whether a relative path starts with one of the excluded prefixes.
 *
 * @param string $relative Path relative to the archived folder ("/" separators).
 * @param string[] $exclude Prefixes.
 * @return bool Excluded.
 */
    protected function _isExcluded(string $relative, array $exclude): bool {
        foreach ($exclude as $prefix) {
            if ($prefix !== '' && str_starts_with($relative, (string)$prefix)) {
                return true;
            }
        }

        return false;
    }

/**
 * Delete dated backup folders older than the retention.
 *
 * @param string $backupsRoot Backups root, with a trailing separator.
 * @param int $retentionDays Days to keep.
 * @param string $timezone Timezone of the folder dates.
 * @return int Folders removed.
 */
    protected function _applyRetention(string $backupsRoot, int $retentionDays, string $timezone): int {
        $removed = 0;
        foreach (glob($backupsRoot . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            if (self::isExpired(basename($folder), $retentionDays, $timezone)) {
                foreach (glob($folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($folder);
                $removed++;
            }
        }

        return $removed;
    }

/**
 * Delete the expired dated folders of the external copy, with rsync only
 * (a Storage Box has no full shell): list the remote root, then sync an
 * empty folder with --delete, including only the expired folders. Everything
 * else is excluded, and excluded paths are never deleted on the receiver, so
 * only those folders go.
 *
 * @param array<string, mixed> $config Configuration.
 * @return string[] Folders removed.
 * @throws \Backup\Service\BackupException When listing or deleting fails.
 */
    protected function _applyRemoteRetention(array $config): array {
        $remoteRoot = rtrim($config['remote'], '/') . '/';
        $ssh = escapeshellarg($this->_sshCommand($config));
        $listing = (string)tempnam(sys_get_temp_dir(), 'nbls');
        try {
            $exitCode = $this->_run('rsync --list-only -e ' . $ssh . ' ' . escapeshellarg($remoteRoot), $listing);
            if ($exitCode !== 0) {
                throw new BackupException($this->_withError(__d('backup', 'Listing the external copy (rsync) failed (exit code %d): the external retention was not applied.', [$exitCode])));
            }
            $expired = [];
            foreach (preg_split('/\R/', (string)file_get_contents($listing)) ?: [] as $line) {
                if (preg_match('/^d\S*\s.*\s(\S+)$/', trim($line), $matches) === 1 && self::isExpired($matches[1], $config['retentionDays'], $config['timezone'])) {
                    $expired[] = $matches[1];
                }
            }
        } finally {
            if (is_file($listing)) {
                unlink($listing);
            }
        }
        if ($expired === []) {
            return [];
        }

        $emptyDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nata-backup-empty-' . uniqid();
        mkdir($emptyDirectory, 0700);
        try {
            $includes = '';
            foreach ($expired as $folderName) {
                $includes .= ' --include=' . escapeshellarg('/' . $folderName . '/***');
            }
            $command = 'rsync -r --delete' . $includes . ' --exclude=' . escapeshellarg('*')
                . ' -e ' . $ssh . ' '
                . escapeshellarg($emptyDirectory . '/') . ' '
                . escapeshellarg($remoteRoot);
            $exitCode = $this->_run($command, null);
            if ($exitCode !== 0) {
                throw new BackupException($this->_withError(__d('backup', 'Removing the old external copies (rsync) failed (exit code %d).', [$exitCode])));
            }
        } finally {
            rmdir($emptyDirectory);
        }

        return $expired;
    }

/**
 * ssh command for rsync -e. With a key, the known_hosts file sits next to it
 * by default: the home of a PHP pool user is often the deployed app, where
 * ~/.ssh would be missing, unwritable or wiped by the next deploy.
 *
 * @param array<string, mixed> $config Configuration.
 * @return string Command.
 */
    protected function _sshCommand(array $config): string {
        $ssh = 'ssh -p ' . (int)$config['sshPort'] . ' -o BatchMode=yes -o StrictHostKeyChecking=accept-new';
        if ((string)$config['knownHostsFile'] !== '') {
            $ssh .= ' -o UserKnownHostsFile=' . escapeshellarg((string)$config['knownHostsFile']);
        }
        if ($config['sshKey'] !== '') {
            $ssh .= ' -i ' . escapeshellarg($config['sshKey']);
        }

        return $ssh;
    }

/**
 * Copy today's folder to the external destination with rsync over ssh.
 *
 * @param string $directory Local dated folder.
 * @param string $date Folder name (YYYY-MM-DD).
 * @param array<string, mixed> $config Configuration.
 * @return void
 * @throws \Backup\Service\BackupException When rsync fails.
 */
    protected function _sendToRemote(string $directory, string $date, array $config): void {
        $command = 'rsync -az --delete -e ' . escapeshellarg($this->_sshCommand($config)) . ' '
            . escapeshellarg(rtrim($directory, '/\\') . '/') . ' '
            . escapeshellarg(rtrim($config['remote'], '/') . '/' . $date . '/');
        $exitCode = $this->_run($command, null);
        if ($exitCode !== 0) {
            throw new BackupException($this->_withError(__d('backup', 'The external copy (rsync) failed (exit code %d).', [$exitCode])));
        }
    }

/**
 * Gzip a file in streaming mode.
 *
 * @param string $source Source file.
 * @param string $destination Destination .gz.
 * @return void
 */
    protected function _gzipFile(string $source, string $destination): void {
        $input = fopen($source, 'rb');
        $output = gzopen($destination, 'wb6');
        while (!feof($input)) {
            gzwrite($output, (string)fread($input, 1048576));
        }
        fclose($input);
        gzclose($output);
    }

/**
 * Run a command through the runner, forgetting the previous error output.
 *
 * @param string $command Escaped command line.
 * @param string|null $stdoutFile File to receive stdout, or null.
 * @return int Exit code.
 */
    protected function _run(string $command, ?string $stdoutFile): int {
        $this->_lastError = '';

        return (int)($this->_runner)($command, $stdoutFile);
    }

/**
 * Append the failed command's error output, if any, on one line and cut to
 * its last 300 characters (e.g. "command not found" or "Permission denied
 * (publickey)"; on Windows a missing command takes two lines).
 *
 * @param string $message Message.
 * @return string Message.
 */
    protected function _withError(string $message): string {
        $error = trim((string)preg_replace('/\s+/', ' ', $this->_lastError));
        if ($error === '') {
            return $message;
        }
        if (mb_strlen($error) > 300) {
            $error = '…' . mb_substr($error, -300);
        }

        return $message . ' ' . $error;
    }

/**
 * Default runner: execute a command, optionally writing stdout to a file,
 * and keep its error output for the failure message.
 *
 * @param string $command Escaped command line.
 * @param string|null $stdoutFile File to receive stdout, or null.
 * @return int Exit code.
 * @throws \Backup\Service\BackupException When the process cannot be started.
 */
    public function runProcess(string $command, ?string $stdoutFile): int {
        if (!function_exists('proc_open')) {
            throw new BackupException(__d('backup', 'proc_open is disabled in this PHP: mysqldump and rsync cannot run.'));
        }
        $descriptors = [
            1 => $stdoutFile !== null ? ['file', $stdoutFile, 'wb'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new BackupException(__d('backup', 'Could not run: %s', [$command]));
        }
        if (isset($pipes[1])) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        $this->_lastError = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process);
    }

}

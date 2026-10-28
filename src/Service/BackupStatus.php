<?php
/**
 * Backup plugin (NataPHP).
 *
 * @package Backup
 */

namespace Backup\Service;

/**
 * The local copies ({path}/YYYY-MM-DD/, BackupRunner), for a status page:
 * the dated folders kept and the size of each file in them. Whether the
 * external copy went well is in the result of the last run.
 */
class BackupStatus {

/**
 * The local copies, newest first.
 *
 * @param int $limit How many.
 * @return array<int, array{date: string, files: array<string, int>, bytes: int}> Copies: the files by name with their size, and the total.
 * @throws \Backup\Service\BackupException When Backup.path is not set.
 */
    public function local(int $limit = 5): array {
        $root = BackupRunner::config()['path'];
        $folders = [];
        foreach (glob($root . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            $name = basename($folder);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name) === 1) {
                $folders[$name] = $folder;
            }
        }
        krsort($folders);

        $copies = [];
        foreach (array_slice($folders, 0, max(1, $limit), true) as $date => $folder) {
            $files = [];
            foreach (glob($folder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    $files[basename($file)] = (int)filesize($file);
                }
            }
            ksort($files);
            $copies[] = ['date' => (string)$date, 'files' => $files, 'bytes' => array_sum($files)];
        }

        return $copies;
    }

/**
 * Size in a human unit.
 *
 * @param int|null $bytes Bytes.
 * @return string E.g. "12,4 MB", or "—" without a file.
 */
    public static function humanSize(?int $bytes): string {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float)$bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format($size, $unit === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$unit];
    }

}

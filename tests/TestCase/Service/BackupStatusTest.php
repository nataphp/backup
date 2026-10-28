<?php
/**
 * Backup plugin (NataPHP).
 *
 * @package Backup
 */

namespace Backup\Test\TestCase\Service;

use Backup\Service\BackupStatus;
use Nata\Core\Configure;
use PHPUnit\Framework\TestCase;

/**
 * The local copies listed for a status page.
 */
class BackupStatusTest extends TestCase {

/**
 * Original configuration, restored after the test.
 *
 * @var mixed
 */
    protected $_original;

/**
 * Backups root of the test.
 *
 * @var string
 */
    protected string $_root = '';

/**
 * Point backups at a temporary folder.
 *
 * @return void
 */
    protected function setUp(): void {
        $this->_original = Configure::read('Backup');
        $this->_root = sys_get_temp_dir() . DS . 'nata-backup-status-' . uniqid() . DS;
        mkdir($this->_root, 0777, true);
        Configure::write('Backup', ['path' => $this->_root]);
    }

/**
 * Restore the configuration and remove the folder.
 *
 * @return void
 */
    protected function tearDown(): void {
        Configure::write('Backup', $this->_original);
        foreach (glob($this->_root . '*' . DS . '*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->_root . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            rmdir($folder);
        }
        foreach (glob($this->_root . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->_root);
    }

/**
 * Dated folders only, newest first, with each file's size and the total.
 *
 * @return void
 */
    public function testLocalListsDatedFoldersNewestFirst(): void {
        foreach (['2026-10-01' => ['database.sql.gz' => 10, 'storage.tar.gz' => 5], '2026-10-03' => ['database.sql.gz' => 20], '2026-10-02' => []] as $date => $files) {
            mkdir($this->_root . $date);
            foreach ($files as $name => $size) {
                file_put_contents($this->_root . $date . DS . $name, str_repeat('x', $size));
            }
        }
        mkdir($this->_root . 'manual');
        file_put_contents($this->_root . 'pre-update.sql.gz', 'x');

        $copies = (new BackupStatus())->local(2);

        $this->assertSame([
            ['date' => '2026-10-03', 'files' => ['database.sql.gz' => 20], 'bytes' => 20],
            ['date' => '2026-10-02', 'files' => [], 'bytes' => 0],
        ], $copies);
        $this->assertSame('15 B', BackupStatus::humanSize(15));
        $this->assertSame('1,5 KB', BackupStatus::humanSize(1536));
        $this->assertSame('—', BackupStatus::humanSize(null));
    }

}

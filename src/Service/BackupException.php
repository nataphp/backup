<?php
/**
 * Backup plugin (NataPHP).
 *
 * @package Backup
 */

namespace Backup\Service;

use RuntimeException;

/**
 * A backup step failed: the message says which one and why.
 */
class BackupException extends RuntimeException {
}

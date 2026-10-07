<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use App\Support\Paths;
use App\Support\SqlSplitter;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Database backups through PDO only (no mysqldump needed): every table's
 * CREATE statement and its rows as batched INSERTs, optionally gzipped,
 * plus an optional zip of the uploaded images. Used by `bin/console
 * db:backup` / `db:restore`.
 *
 * A dump restores into the database configured in .env (it has no CREATE
 * DATABASE / USE line), dropping and recreating each table it contains.
 */
final class DatabaseBackup
{
    /** Image folders under public/assets/images/ that hold uploads (thumbs/ is a cache). */
    public const UPLOAD_DIRS = ['products', 'categories', 'branding', 'generated'];

    /** Rows per INSERT, and the size an INSERT may grow to before a new one starts. */
    private const BATCH_ROWS = 500;
    private const BATCH_BYTES = 1024 * 1024;

    private const NAME_PATTERN = '/^backup-.+-\d{8}-\d{6}\.sql(\.gz)?$/';

    public function __construct(
        private readonly Database $db,
        private readonly Paths $paths,
    ) {
    }

    public function defaultDir(): string
    {
        return $this->paths->var('backups');
    }

    /** "backup-online_store-20261007-031500.sql.gz" */
    public function fileName(string $database, bool $gzip, ?int $time = null): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $database);

        return sprintf('backup-%s-%s.sql%s', $safe !== '' ? $safe : 'db', date('Ymd-His', $time ?? time()), $gzip ? '.gz' : '');
    }

    /**
     * Writes the dump to $file.
     *
     * @return array{tables: int, rows: int, bytes: int}
     */
    public function dump(string $file, bool $gzip): array
    {
        if ($gzip && !function_exists('gzopen')) {
            throw new RuntimeException('--gzip needs the PHP zlib extension.');
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }

        $partial = $file . '.part';
        $handle = $gzip ? @gzopen($partial, 'wb6') : @fopen($partial, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Could not write {$partial}.");
        }
        $write = $gzip
            ? static fn (string $s) => gzwrite($handle, $s)
            : static fn (string $s) => fwrite($handle, $s);

        $pdo = $this->db->pdo();
        $tables = 0;
        $rows = 0;
        try {
            $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $write("-- Store database backup (bin/console db:backup)\n");
            $write('-- Database: ' . $database . ' — ' . date('c') . "\n\n");
            $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");

            // Dump TIMESTAMPs in UTC so restoring on a server in another zone keeps the same instants.
            $previousZone = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
            $pdo->exec("SET time_zone = '+00:00'");

            try {
                $all = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
                $views = [];
                foreach ($all as [$table, $type]) {
                    if ($type === 'VIEW') {
                        $views[] = (string) $table;
                        continue;
                    }
                    $rows += $this->dumpTable($pdo, (string) $table, $write);
                    $tables++;
                }
                foreach ($views as $view) {
                    $create = $pdo->query('SHOW CREATE VIEW ' . $this->ident($view))->fetch(PDO::FETCH_NUM);
                    $write('DROP VIEW IF EXISTS ' . $this->ident($view) . ";\n" . $create[1] . ";\n\n");
                }
            } finally {
                $pdo->exec('SET time_zone = ' . $pdo->quote($previousZone));
            }

            $write("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- end of backup\n");
        } catch (\Throwable $e) {
            $gzip ? gzclose($handle) : fclose($handle);
            @unlink($partial);
            throw $e;
        }

        $gzip ? gzclose($handle) : fclose($handle);
        if (!rename($partial, $file)) {
            @unlink($partial);
            throw new RuntimeException("Could not move the backup to {$file}.");
        }
        @chmod($file, 0640);

        return ['tables' => $tables, 'rows' => $rows, 'bytes' => (int) filesize($file)];
    }

    /**
     * Zips the uploaded images (public/assets/images/<UPLOAD_DIRS>) into $zipFile.
     *
     * @return int number of files
     */
    public function zipUploads(string $zipFile): int
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('--with-uploads needs the PHP zip extension.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not create {$zipFile}.");
        }
        $count = 0;
        $base = $this->paths->public();
        foreach (self::UPLOAD_DIRS as $dir) {
            $root = $base . '/assets/images/' . $dir;
            if (!is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile() && !$file->isLink()) {
                    $relative = 'assets/images/' . $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $zip->addFile($file->getPathname(), $relative);
                    $count++;
                }
            }
        }
        if ($count === 0) {
            $zip->addFromString('assets/images/.empty', '');
        }
        $zip->close();

        return $count;
    }

    /**
     * Runs a dump made by dump() (or a plain mysqldump file) against the configured database.
     *
     * @param (callable(int): void)|null $progress called with the statement count every 200 statements
     * @return int statements executed
     */
    public function restore(string $file, ?callable $progress = null): int
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("Backup file not found: {$file}");
        }
        $isGzip = $this->isGzip($file);
        if ($isGzip && !function_exists('gzopen')) {
            throw new RuntimeException('This backup is gzipped and the PHP zlib extension is missing.');
        }

        $handle = $isGzip ? gzopen($file, 'rb') : fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not read {$file}.");
        }
        $lines = (static function () use ($handle, $isGzip): \Generator {
            while (($line = $isGzip ? gzgets($handle) : fgets($handle)) !== false) {
                yield $line;
            }
        })();

        $pdo = $this->db->pdo();
        [$previousZone, $previousMode] = $pdo->query('SELECT @@session.time_zone, @@session.sql_mode')->fetch(PDO::FETCH_NUM);
        $count = 0;
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (SqlSplitter::statements($lines) as $statement) {
                if (preg_match('/^\s*(CREATE\s+DATABASE|USE)\b/i', $statement)) {
                    continue; // always restore into the configured database
                }
                $pdo->exec($statement);
                $count++;
                if ($progress !== null && $count % 200 === 0) {
                    $progress($count);
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->exec('SET UNIQUE_CHECKS = 1');
            $pdo->exec('SET time_zone = ' . $pdo->quote((string) $previousZone) . ', sql_mode = ' . $pdo->quote((string) $previousMode));
            $isGzip ? gzclose($handle) : fclose($handle);
        }

        return $count;
    }

    /**
     * Extracts an uploads zip made by zipUploads() into public/ (only paths under
     * assets/images/<UPLOAD_DIRS>/ are written; existing files are overwritten).
     *
     * @return int files written
     */
    public function restoreUploads(string $zipFile): int
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Restoring uploads needs the PHP zip extension.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException("{$zipFile} is not a valid zip file.");
        }
        $allowed = '#^assets/images/(' . implode('|', self::UPLOAD_DIRS) . ')/(?!.*(^|/)\.\.(/|$))[^\0\\\\]+$#';
        $count = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/') || !preg_match($allowed, $name) || preg_match('/\.(php\d*|phtml|phar|htaccess)$/i', $name)) {
                    continue;
                }
                $target = $this->paths->public($name);
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
                    throw new RuntimeException('Could not create ' . dirname($target));
                }
                $stream = $zip->getStream($name);
                if ($stream === false || file_put_contents($target, $stream) === false) {
                    throw new RuntimeException("Could not extract {$name}.");
                }
                fclose($stream);
                $count++;
            }
        } finally {
            $zip->close();
        }

        return $count;
    }

    /**
     * Deletes all but the newest $keep backups in $dir (with their -uploads.zip).
     *
     * @return list<string> deleted file names
     */
    public function prune(string $dir, int $keep): array
    {
        $backups = array_values(array_filter(
            scandir($dir) ?: [],
            static fn (string $name) => preg_match(self::NAME_PATTERN, $name) === 1
        ));
        // Names end in -YYYYmmdd-HHiiss, so newest first = sort by that stamp descending.
        usort($backups, static fn (string $a, string $b) => self::stamp($b) <=> self::stamp($a) ?: strcmp($b, $a));

        $deleted = [];
        foreach (array_slice($backups, max(0, $keep)) as $name) {
            if (@unlink($dir . '/' . $name)) {
                $deleted[] = $name;
            }
            $uploads = self::uploadsName($name);
            if (is_file($dir . '/' . $uploads) && @unlink($dir . '/' . $uploads)) {
                $deleted[] = $uploads;
            }
        }

        return $deleted;
    }

    /** "backup-x-20261007-031500.sql.gz" → "backup-x-20261007-031500-uploads.zip" */
    public static function uploadsName(string $backupName): string
    {
        return (string) preg_replace('/\.sql(\.gz)?$/', '', $backupName) . '-uploads.zip';
    }

    private static function stamp(string $name): string
    {
        return preg_match('/-(\d{8}-\d{6})\.sql/', $name, $m) === 1 ? $m[1] : '';
    }

    /** @param callable(string): mixed $write */
    private function dumpTable(PDO $pdo, string $table, callable $write): int
    {
        $create = $pdo->query('SHOW CREATE TABLE ' . $this->ident($table))->fetch(PDO::FETCH_NUM);
        $write('-- Table ' . $table . "\nDROP TABLE IF EXISTS " . $this->ident($table) . ";\n" . $create[1] . ";\n\n");

        // Unbuffered, so a big table streams instead of being loaded into memory.
        $bufferedAttr = $this->bufferedQueryAttribute();
        if ($bufferedAttr !== null) {
            $pdo->setAttribute($bufferedAttr, false);
        }
        $count = 0;
        try {
            $stmt = $pdo->query('SELECT * FROM ' . $this->ident($table));
            $columns = null;
            /** @var list<string> $batch */
            $batch = [];
            $batchBytes = 0;
            $flush = function () use (&$batch, &$batchBytes, &$columns, $table, $write): void {
                if ($batch !== []) {
                    $write('INSERT INTO ' . $this->ident($table) . ' (' . $columns . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                    $batchBytes = 0;
                }
            };
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns ??= implode(', ', array_map($this->ident(...), array_keys($row)));
                $values = '(' . implode(', ', array_map(fn ($v) => $this->literal($pdo, $v), $row)) . ')';
                $batch[] = $values;
                $batchBytes += strlen($values);
                $count++;
                if (count($batch) >= self::BATCH_ROWS || $batchBytes >= self::BATCH_BYTES) {
                    $flush();
                }
            }
            $stmt->closeCursor();
            $flush();
        } finally {
            if ($bufferedAttr !== null) {
                $pdo->setAttribute($bufferedAttr, true);
            }
        }
        $write("\n");

        return $count;
    }

    private function literal(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null                           => 'NULL',
            is_int($value), is_float($value)          => (string) $value,
            is_bool($value)                           => $value ? '1' : '0',
            !mb_check_encoding((string) $value, 'UTF-8') => '0x' . bin2hex((string) $value),
            default                                   => (string) $pdo->quote((string) $value),
        };
    }

    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function isGzip(string $file): bool
    {
        $handle = fopen($file, 'rb');
        $magic = $handle !== false ? (string) fread($handle, 2) : '';
        if ($handle !== false) {
            fclose($handle);
        }

        return $magic === "\x1f\x8b";
    }

    /** Pdo\Mysql::ATTR_USE_BUFFERED_QUERY (PHP 8.4+) or the older PDO constant. */
    private function bufferedQueryAttribute(): ?int
    {
        foreach (['Pdo\Mysql::ATTR_USE_BUFFERED_QUERY', 'PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'] as $constant) {
            if (defined($constant)) {
                return (int) constant($constant);
            }
        }

        return null;
    }
}

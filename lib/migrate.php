<?php

// Minimal forward-only migration runner.
//
// Conventions:
//   - Migrations live in /migrations/NNN_name.sql, applied in filename order.
//   - Each file runs in a single transaction. If it fails, it rolls back and
//     the process exits non-zero.
//   - Applied filenames are recorded in schema_migrations. Already-applied
//     files are skipped on subsequent runs.
//   - No down-migrations. Forward-only. If you need to undo, write a new
//     migration that undoes it.
//
// This is deliberately ~40 lines of code. A full migration tool (Phinx,
// doctrine-migrations, etc.) is overkill for this project's scale and
// would obscure what's happening. If the project grows past ~20 migrations
// or needs cross-environment coordination, swap this out.

require_once __DIR__ . '/bootstrap.php';

function run_migrations(?callable $log = null): int {
    $pdo = db();
    $log ??= static function (string $msg): void { fwrite(STDOUT, $msg . "\n"); };

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS schema_migrations (
            filename TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $applied = [];
    foreach ($pdo->query('SELECT filename FROM schema_migrations') as $row) {
        $applied[$row['filename']] = true;
    }

    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($files);

    $ran = 0;
    foreach ($files as $path) {
        $filename = basename($path);
        if (isset($applied[$filename])) {
            continue;
        }

        $sql = file_get_contents($path);
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
            $stmt->execute([$filename]);
            $pdo->commit();
            $log("  applied {$filename}");
            $ran++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, "  FAILED {$filename}: " . $e->getMessage() . "\n");
            throw $e;
        }
    }

    return $ran;
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 26.3 — Smoke test that the generated SQL matches the migrations (drift
 * guard).
 *
 * Production is stood up on shared cPanel hosting with no SSH: the schema is
 * applied by pasting the committed, versioned raw SQL files under
 * `database/sql/` into phpMyAdmin in filename order. Laravel migrations remain
 * the single source of truth; the raw SQL is generated from the migrated schema
 * (scoped `mysqldump --no-data`). These two representations MUST NOT drift — if
 * a migration adds/changes/drops a column, key, or foreign key and the matching
 * `database/sql/NNN_*.sql` file is not regenerated, prod would be built from a
 * stale schema.
 *
 * This test guards against exactly that. For every application table that has a
 * versioned SQL file, it reconstructs the schema the committed SQL would
 * produce (parsing each file's `CREATE TABLE` block plus any follow-on
 * `ALTER TABLE ... ADD COLUMN` / `ADD CONSTRAINT`) and compares it, definition
 * by definition, against the live schema built by `php artisan migrate`
 * (`RefreshDatabase`) as reported by `SHOW CREATE TABLE`.
 *
 * The comparison is a normalized SET comparison of the column / key / foreign
 * key definition lines (whitespace collapsed, AUTO_INCREMENT counters stripped,
 * order ignored). That makes it robust to cosmetic differences — a foreign key
 * emitted inline by MySQL but replayed as an ALTER in the file, a column added
 * by a later ALTER in a different physical position — while still FAILING the
 * moment the actual set of columns/keys/constraints diverges.
 *
 * It performs only reads (SHOW CREATE TABLE) against the already-migrated test
 * database and parses the committed files on disk: it never issues DDL, never
 * truncates, and never touches a scratch database, so it cannot pollute the
 * shared test schema for other tests.
 *
 * _Design: Hosting and Deployment Notes → Database schema (no SSH on prod)_
 */
class SqlSchemaDriftGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Application tables that are described by a versioned SQL file and must be
     * kept byte-consistent with the migrations. Test-support tables (see
     * 2024_01_01_009999_create_test_support_tables) and the framework `cache`
     * tables intentionally have no SQL file and are excluded.
     *
     * @return array<int, string>
     */
    private function guardedTables(): array
    {
        return [
            'migrations',
            'jobs',
            'job_batches',
            'failed_jobs',
            'companies',
            'platform_settings',
            'users',
            'events',
            'invitations',
            'ticket_types',
            'orders',
            'tickets',
            'order_consents',
            'processed_webhooks',
            'audit_logs',
        ];
    }

    public function test_every_guarded_table_has_a_sql_file_and_no_drift(): void
    {
        $expected = $this->parseCommittedSqlSchema();
        $actual = $this->liveMigratedSchema();

        foreach ($this->guardedTables() as $table) {
            $this->assertArrayHasKey(
                $table,
                $expected,
                "No committed database/sql file describes the `{$table}` table. "
                .'Every guarded table must have a versioned SQL file (see database/sql/CHANGELOG.md).'
            );

            $this->assertArrayHasKey(
                $table,
                $actual,
                "Migrations did not create the `{$table}` table, but a committed SQL file describes it. "
                .'The migrations and the raw SQL have drifted.'
            );

            $expectedDefs = $expected[$table];
            $actualDefs = $actual[$table];

            $missingFromSql = array_values(array_diff($actualDefs, $expectedDefs));
            $staleInSql = array_values(array_diff($expectedDefs, $actualDefs));

            $this->assertSame(
                [],
                $missingFromSql,
                "Schema drift on `{$table}`: the migration defines the following that the committed "
                ."database/sql file is MISSING (regenerate the SQL file from the migrated schema):\n  - "
                .implode("\n  - ", $missingFromSql)
            );

            $this->assertSame(
                [],
                $staleInSql,
                "Schema drift on `{$table}`: the committed database/sql file defines the following that the "
                ."migration no longer produces (the SQL file is STALE — regenerate it):\n  - "
                .implode("\n  - ", $staleInSql)
            );
        }
    }

    /**
     * Reconstruct the schema (per table, as a normalized set of definition
     * lines) that the committed database/sql/*.sql files would produce.
     *
     * @return array<string, array<int, string>>
     */
    private function parseCommittedSqlSchema(): array
    {
        $dir = database_path('sql');
        $files = glob($dir.'/*.sql');
        sort($files); // filename order = application order

        /** @var array<string, array<int, string>> $tables */
        $tables = [];

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            // Drop line comments so `-- ...` prose never leaks into parsing.
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);

            $this->applyCreateTables($sql, $tables);
            $this->applyAlterTables($sql, $tables);
        }

        // Normalize each table's definition list into a comparable set.
        foreach ($tables as $name => $defs) {
            $tables[$name] = $this->normalizeDefinitions($defs);
        }

        return $tables;
    }

    /**
     * Extract every `CREATE TABLE <name> ( ... )` block and store its inner
     * definition lines.
     *
     * @param  array<string, array<int, string>>  $tables
     */
    private function applyCreateTables(string $sql, array &$tables): void
    {
        if (! preg_match_all(
            '/CREATE\s+TABLE\s+`?(?<name>\w+)`?\s*\((?<body>.*?)\)\s*ENGINE=/is',
            $sql,
            $matches,
            PREG_SET_ORDER
        )) {
            return;
        }

        foreach ($matches as $match) {
            $tables[$match['name']] = $this->splitDefinitionLines($match['body']);
        }
    }

    /**
     * Apply `ALTER TABLE <name> ADD COLUMN ...` and
     * `ALTER TABLE <name> ADD CONSTRAINT ...` statements to the reconstructed
     * schema so incremental files (e.g. 013 adding orders.fulfilled_at, 005
     * replaying the users FK as an ALTER) are reflected.
     *
     * @param  array<string, array<int, string>>  $tables
     */
    private function applyAlterTables(string $sql, array &$tables): void
    {
        if (! preg_match_all(
            '/ALTER\s+TABLE\s+`?(?<name>\w+)`?\s+(?<body>.*?);/is',
            $sql,
            $matches,
            PREG_SET_ORDER
        )) {
            return;
        }

        foreach ($matches as $match) {
            $table = $match['name'];
            if (! isset($tables[$table])) {
                $tables[$table] = [];
            }

            // A single ALTER can carry several comma-separated clauses.
            foreach ($this->splitDefinitionLines($match['body']) as $clause) {
                if (preg_match('/^ADD\s+COLUMN\s+(.*)$/is', $clause, $m)) {
                    // Strip positional hints (AFTER `x`, FIRST) — they do not
                    // affect the logical schema and never appear in a
                    // CREATE TABLE body.
                    $col = preg_replace('/\s+(AFTER\s+`?\w+`?|FIRST)\s*$/is', '', $m[1]);
                    $tables[$table][] = trim($col);
                } elseif (preg_match('/^DROP\s+COLUMN\s+(?:IF\s+EXISTS\s+)?`?(?<col>\w+)`?\s*$/is', $clause, $m)) {
                    // DROP COLUMN removes a previously-added/created column from
                    // the reconstructed schema so a dropped column no longer
                    // registers as drift against the migrated table.
                    $name = $m['col'];
                    $tables[$table] = array_values(array_filter(
                        $tables[$table],
                        fn (string $existing): bool => preg_match('/^`?'.preg_quote($name, '/').'`?\s/i', trim($existing)) !== 1,
                    ));
                } elseif (preg_match('/^MODIFY\s+(?:COLUMN\s+)?`?(?<col>\w+)`?\s+(?<def>.*)$/is', $clause, $m)) {
                    // MODIFY COLUMN redefines an existing column in place (e.g.
                    // widening an enum). Replace the column's prior definition
                    // so the reconstructed schema reflects the new type rather
                    // than carrying both the old and new definitions.
                    $name = $m['col'];
                    $def = preg_replace('/\s+(AFTER\s+`?\w+`?|FIRST)\s*$/is', '', $m['def']);
                    $tables[$table] = array_values(array_filter(
                        $tables[$table],
                        fn (string $existing): bool => preg_match('/^`?'.preg_quote($name, '/').'`?\s/i', trim($existing)) !== 1,
                    ));
                    $tables[$table][] = trim('`'.$name.'` '.$def);
                } elseif (preg_match('/^DROP\s+COLUMN\s+`?(?<col>\w+)`?/is', $clause, $m)) {
                    // DROP COLUMN removes an existing column: filter out the
                    // column's prior definition so the reconstructed schema no
                    // longer carries a column the migration has since dropped.
                    $name = $m['col'];
                    $tables[$table] = array_values(array_filter(
                        $tables[$table],
                        fn (string $existing): bool => preg_match('/^`?'.preg_quote($name, '/').'`?\s/i', trim($existing)) !== 1,
                    ));
                } elseif (preg_match('/^ADD\s+(CONSTRAINT\s+.*)$/is', $clause, $m)) {
                    $tables[$table][] = trim($m[1]);
                } elseif (preg_match('/^ADD\s+(KEY|UNIQUE\s+KEY|INDEX|PRIMARY\s+KEY|FOREIGN\s+KEY)\s+(.*)$/is', $clause, $m)) {
                    $tables[$table][] = trim($m[1].' '.$m[2]);
                }
            }
        }
    }

    /**
     * Fetch the live migrated schema (per table) via SHOW CREATE TABLE and
     * store each table's inner definition lines. Read-only; no DDL.
     *
     * @return array<string, array<int, string>>
     */
    private function liveMigratedSchema(): array
    {
        /** @var array<string, array<int, string>> $tables */
        $tables = [];

        foreach ($this->guardedTables() as $table) {
            if (! $this->tableExists($table)) {
                continue;
            }

            $row = (array) DB::select('SHOW CREATE TABLE `'.$table.'`')[0];
            $create = $row['Create Table'] ?? $row['Create View'] ?? '';

            if (! preg_match('/\((?<body>.*)\)\s*ENGINE=/is', $create, $m)) {
                continue;
            }

            $tables[$table] = $this->normalizeDefinitions(
                $this->splitDefinitionLines($m['body'])
            );
        }

        return $tables;
    }

    private function tableExists(string $table): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($table);
    }

    /**
     * Split a CREATE TABLE body (or ALTER clause list) into top-level
     * comma-separated definition lines, respecting parentheses so an enum's
     * value list or a `case when ... end` in a generated column is not split.
     *
     * @return array<int, string>
     */
    private function splitDefinitionLines(string $body): array
    {
        $lines = [];
        $depth = 0;
        $current = '';
        $len = strlen($body);
        $inString = false;
        $stringChar = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];

            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar) {
                    // Handle doubled quote escapes ('' inside a string).
                    if ($i + 1 < $len && $body[$i + 1] === $stringChar) {
                        $current .= $body[++$i];
                    } else {
                        $inString = false;
                    }
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringChar = $ch;
                $current .= $ch;

                continue;
            }

            if ($ch === '(') {
                $depth++;
                $current .= $ch;

                continue;
            }

            if ($ch === ')') {
                $depth--;
                $current .= $ch;

                continue;
            }

            if ($ch === ',' && $depth === 0) {
                $lines[] = $current;
                $current = '';

                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $lines[] = $current;
        }

        return array_values(array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
    }

    /**
     * Normalize a set of definition lines so cosmetic differences (whitespace,
     * backtick placement, AUTO_INCREMENT counter) never register as drift while
     * genuine schema changes still do. Returns a sorted, de-duplicated set so
     * comparison is order-independent.
     *
     * @param  array<int, string>  $defs
     * @return array<int, string>
     */
    private function normalizeDefinitions(array $defs): array
    {
        $normalized = [];

        foreach ($defs as $def) {
            $line = $def;

            // Collapse all whitespace runs to a single space.
            $line = preg_replace('/\s+/', ' ', $line);
            // Strip the AUTO_INCREMENT=<n> table counter if it ever appears in
            // a definition line (defensive; it normally lives after the paren).
            $line = preg_replace('/\s*AUTO_INCREMENT=\d+/i', '', $line);
            // Normalize backtick usage away so `col` and col compare equal.
            $line = str_replace('`', '', $line);
            // Uppercase SQL keywords are already consistent from both
            // SHOW CREATE TABLE and mysqldump; lowercase the whole line to be
            // safe against future casing differences, but preserve quoted
            // string literals (enum values, defaults) untouched.
            $line = $this->lowercaseOutsideStrings($line);
            $line = trim($line);

            if ($line !== '') {
                $normalized[] = $line;
            }
        }

        sort($normalized);

        return array_values(array_unique($normalized));
    }

    /**
     * Lowercase a definition line except for the contents of single-quoted
     * string literals (so enum values / string defaults keep their exact case).
     */
    private function lowercaseOutsideStrings(string $line): string
    {
        $out = '';
        $len = strlen($line);
        $inString = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($ch === "'") {
                $inString = ! $inString;
                $out .= $ch;

                continue;
            }

            $out .= $inString ? $ch : strtolower($ch);
        }

        return $out;
    }
}

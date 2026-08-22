<?php
/**
 * PDO connection + tiny query helpers.
 * Prepared statements only — there is no string-concatenation path here.
 */

declare(strict_types=1);

function db(): PDO
{
    if (!isset($GLOBALS['__pdo']) || !$GLOBALS['__pdo'] instanceof PDO) {
        throw new RuntimeException('db_init() has not been called');
    }
    return $GLOBALS['__pdo'];
}

function db_init(array $cfg): PDO
{
    if (isset($GLOBALS['__pdo']) && $GLOBALS['__pdo'] instanceof PDO) {
        return $GLOBALS['__pdo'];
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['name'],
        $cfg['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    // Keep MySQL's clock aligned with PHP's so expires_at comparisons agree.
    $pdo->exec("SET time_zone = '+01:00'");

    $GLOBALS['__pdo'] = $pdo;
    return $pdo;
}

/** Run a statement, return the PDOStatement. */
function q(string $sql, array $args = []): PDOStatement
{
    $st = $GLOBALS['__pdo']->prepare($sql);
    $st->execute($args);
    return $st;
}

/** First row, or null. */
function one(string $sql, array $args = []): ?array
{
    $row = q($sql, $args)->fetch();
    return $row === false ? null : $row;
}

/** All rows. */
function all(string $sql, array $args = []): array
{
    return q($sql, $args)->fetchAll();
}

/** First column of the first row, or null. */
function scalar(string $sql, array $args = [])
{
    $v = q($sql, $args)->fetchColumn();
    return $v === false ? null : $v;
}

function last_id(): int
{
    return (int) $GLOBALS['__pdo']->lastInsertId();
}

function tx_begin(): void  { $GLOBALS['__pdo']->beginTransaction(); }
function tx_commit(): void { $GLOBALS['__pdo']->commit(); }
function tx_rollback(): void
{
    if ($GLOBALS['__pdo']->inTransaction()) {
        $GLOBALS['__pdo']->rollBack();
    }
}

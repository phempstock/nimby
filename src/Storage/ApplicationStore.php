<?php
declare(strict_types=1);

namespace Nimby\Storage;

use Nimby\Council\ApplicationRecord;
use PDO;

/**
 * Reads and writes the applications and application_events tables.
 */
final class ApplicationStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function isEmpty(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn() === 0;
    }

    public function countActive(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM applications WHERE removed_at IS NULL')->fetchColumn();
    }

    /**
     * Insert new applications and update changed ones, all in one transaction.
     * Every changed tracked field is recorded in application_events.
     *
     * @param array<int, array<string, mixed>> $records normalised by ApplicationRecord
     * @param string $now UTC timestamp 'Y-m-d H:i:s' for this run
     * @param string|null $backfillBefore new rows received before this date ('Y-m-d') are marked
     *        as history and never emailed: '9999-12-31' on the first run marks everything;
     *        null (the usual case) marks nothing
     * @return array{inserted: int, updated: int, removed: int, restored: int, backfilled: int}
     */
    public function sync(array $records, string $now, ?string $backfillBefore = null): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'removed' => 0, 'restored' => 0, 'backfilled' => 0];
        $fields = ApplicationRecord::TRACKED_FIELDS;

        $select = $this->pdo->prepare('SELECT * FROM applications WHERE keyval = ?');
        $insert = $this->pdo->prepare(sprintf(
            'INSERT INTO applications (keyval, %s, first_seen_at, last_seen_at, updated_at, backfilled)
             VALUES (:keyval, %s, :now, :now2, :now3, :backfilled)',
            implode(', ', $fields),
            implode(', ', array_map(static fn($f) => ':' . $f, $fields))
        ));
        $touch = $this->pdo->prepare('UPDATE applications SET last_seen_at = ? WHERE keyval = ?');
        $event = $this->pdo->prepare(
            'INSERT INTO application_events (keyval, field, old_value, new_value, changed_at) VALUES (?, ?, ?, ?, ?)'
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($records as $record) {
                $select->execute([$record['keyval']]);
                $existing = $select->fetch();
                $select->closeCursor();

                if ($existing === false) {
                    $received = $record['date_received'] ?? $record['date_valid'] ?? '0000-00-00';
                    $isHistory = $backfillBefore !== null && $received < $backfillBefore;
                    $stats['backfilled'] += $isHistory ? 1 : 0;
                    $params = [':keyval' => $record['keyval'], ':now' => $now, ':now2' => $now, ':now3' => $now,
                               ':backfilled' => $isHistory ? 1 : 0];
                    foreach ($fields as $f) {
                        $params[':' . $f] = $record[$f];
                    }
                    $insert->execute($params);
                    $stats['inserted']++;
                    continue;
                }

                $changes = [];
                foreach ($fields as $f) {
                    if (!self::same($existing[$f], $record[$f])) {
                        $changes[$f] = $record[$f];
                        $event->execute([$record['keyval'], $f, self::str($existing[$f]), self::str($record[$f]), $now]);
                    }
                }
                if ($existing['removed_at'] !== null) {
                    $event->execute([$record['keyval'], 'removed_at', $existing['removed_at'], null, $now]);
                    $stats['restored']++;
                }

                if ($changes || $existing['removed_at'] !== null) {
                    $sets = array_map(static fn($f) => "{$f} = :{$f}", array_keys($changes));
                    $sets[] = 'last_seen_at = :now';
                    $sets[] = 'removed_at = NULL';
                    if ($changes) {
                        $sets[] = 'updated_at = :now2';
                    }
                    $update = $this->pdo->prepare('UPDATE applications SET ' . implode(', ', $sets) . ' WHERE keyval = :keyval');
                    $params = [':keyval' => $record['keyval'], ':now' => $now];
                    if ($changes) {
                        $params[':now2'] = $now;
                    }
                    foreach ($changes as $f => $v) {
                        $params[':' . $f] = $v;
                    }
                    $update->execute($params);
                    if ($changes) {
                        $stats['updated']++;
                    }
                } else {
                    $touch->execute([$now, $record['keyval']]);
                }
            }

            // Anything we hold that the council no longer returns is marked, not deleted.
            $gone = $this->pdo->prepare('SELECT keyval FROM applications WHERE removed_at IS NULL AND last_seen_at < ?');
            $gone->execute([$now]);
            $markRemoved = $this->pdo->prepare('UPDATE applications SET removed_at = ? WHERE keyval = ?');
            foreach ($gone->fetchAll(PDO::FETCH_COLUMN) as $keyval) {
                $markRemoved->execute([$now, $keyval]);
                $event->execute([$keyval, 'removed_at', null, $now, $now]);
                $stats['removed']++;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $stats;
    }

    public function getSetting(string $name): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function setSetting(string $name, ?string $value): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
            $this->pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** All applications still present in the council feed, newest first. */
    public function allActive(): array
    {
        return $this->pdo->query(
            'SELECT * FROM applications WHERE removed_at IS NULL
             ORDER BY date_received IS NULL, date_received DESC, app_ref DESC'
        )->fetchAll();
    }

    /** Compare a stored value with a fresh one, tolerating numeric/string differences from the driver. */
    private static function same(mixed $stored, mixed $fresh): bool
    {
        if ($stored === null || $fresh === null) {
            return $stored === $fresh;
        }
        if (is_float($fresh) || is_int($fresh)) {
            return is_numeric($stored) && abs((float) $stored - (float) $fresh) < 0.0000005;
        }
        return (string) $stored === (string) $fresh;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}

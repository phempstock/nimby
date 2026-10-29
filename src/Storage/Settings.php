<?php
declare(strict_types=1);

namespace Nimby\Storage;

use PDO;

/** Small named values kept in the settings table. */
final class Settings
{
    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $name): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function set(string $name, ?string $value): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $this->pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
    }

    /**
     * A random secret made on first use and kept in the database, used to sign
     * unsubscribe links and to hash IP addresses. Never leaves the server.
     */
    public function secret(): string
    {
        $secret = $this->get('secret');
        if ($secret === null || strlen($secret) < 64) {
            $secret = bin2hex(random_bytes(32));
            $this->set('secret', $secret);
        }
        return $secret;
    }

    public function signupOpen(): bool
    {
        return $this->get('signup_open') === '1';
    }
}

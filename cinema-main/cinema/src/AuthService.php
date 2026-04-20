<?php

declare(strict_types=1);

namespace CinemaApp\Src;

use PDO;

final class AuthService
{
    private const GUEST_LOGIN = '__guest__';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function userById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, name, login, email, role, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /** @return array<string, mixed>|null */
    public function login(string $login, string $password): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE login = ? LIMIT 1');
        $stmt->execute([$login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'login' => (string) $user['login'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'created_at' => (string) $user['created_at'],
        ];
    }

    /** @return array<string, mixed> */
    public function register(string $login, string $password): array
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE login = ?');
        $stmt->execute([$login]);
        if ($stmt->fetchColumn() !== false) {
            throw new \RuntimeException('Користувач з таким логіном вже існує');
        }

        $name = $login;
        $email = '';
        $insert = $this->db->prepare("INSERT INTO users (name, login, email, password_hash, role) VALUES (?, ?, ?, ?, 'user')");
        $insert->execute([$name, $login, $email, password_hash($password, PASSWORD_DEFAULT)]);

        $id = (int) $this->db->lastInsertId();
        $user = $this->userById($id);
        if ($user === null) {
            throw new \RuntimeException('Не вдалося створити користувача');
        }

        return $user;
    }

    public function guestUserId(): int
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE login = ? LIMIT 1');
        $stmt->execute([self::GUEST_LOGIN]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $insert = $this->db->prepare(
            "INSERT INTO users (name, login, email, password_hash, role) VALUES (?, ?, ?, ?, 'user')"
        );
        $insert->execute([
            'Гість',
            self::GUEST_LOGIN,
            '',
            password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
        ]);

        return (int) $this->db->lastInsertId();
    }
}

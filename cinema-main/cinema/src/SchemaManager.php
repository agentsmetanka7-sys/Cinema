<?php

declare(strict_types=1);

namespace CinemaApp\Src;

use PDO;

final class SchemaManager
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function migrate(): void
    {
        $driver = $this->driver();
        if ($driver === 'sqlite') {
            $this->db->exec('PRAGMA foreign_keys = ON');
        }

        $idCol = $this->idColumn();
        $boolType = $this->boolType();
        $trueValue = $this->trueValue();
        $falseValue = $this->falseValue();
        $dateTimeType = $this->dateTimeType();
        $timestampType = $this->timestampType();

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id {$idCol},
                name VARCHAR(255) NOT NULL,
                login VARCHAR(191) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL DEFAULT '',
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(32) NOT NULL DEFAULT 'user',
                created_at {$timestampType}
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS genres (
                id {$idCol},
                name VARCHAR(191) NOT NULL UNIQUE
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS movies (
                id {$idCol},
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                description TEXT NOT NULL,
                poster_url VARCHAR(1000) NOT NULL,
                banner_url VARCHAR(1000) NOT NULL,
                trailer_url VARCHAR(1000) NOT NULL,
                duration_minutes INTEGER NOT NULL,
                release_date DATE NOT NULL,
                age_rating VARCHAR(32) NOT NULL,
                language VARCHAR(64) NOT NULL,
                format VARCHAR(32) NOT NULL,
                is_now_showing {$boolType} NOT NULL DEFAULT {$falseValue},
                is_coming_soon {$boolType} NOT NULL DEFAULT {$falseValue},
                is_popular {$boolType} NOT NULL DEFAULT {$falseValue},
                popularity_score INTEGER NOT NULL DEFAULT 0,
                created_at {$timestampType}
            )"
        );

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS movie_genres (
                movie_id BIGINT NOT NULL,
                genre_id BIGINT NOT NULL,
                PRIMARY KEY (movie_id, genre_id),
                FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
                FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
            )'
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS cinemas (
                id {$idCol},
                name VARCHAR(255) NOT NULL,
                address VARCHAR(500) NOT NULL,
                work_hours VARCHAR(255) NOT NULL
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS halls (
                id {$idCol},
                cinema_id BIGINT NOT NULL,
                name VARCHAR(100) NOT NULL,
                seat_rows INTEGER NOT NULL,
                seat_cols INTEGER NOT NULL,
                FOREIGN KEY (cinema_id) REFERENCES cinemas(id) ON DELETE CASCADE
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS hall_seats (
                id {$idCol},
                hall_id BIGINT NOT NULL,
                row_label VARCHAR(8) NOT NULL,
                seat_number INTEGER NOT NULL,
                seat_label VARCHAR(32) NOT NULL,
                is_active {$boolType} NOT NULL DEFAULT {$trueValue},
                UNIQUE (hall_id, row_label, seat_number),
                FOREIGN KEY (hall_id) REFERENCES halls(id) ON DELETE CASCADE
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS showtimes (
                id {$idCol},
                movie_id BIGINT NOT NULL,
                hall_id BIGINT NOT NULL,
                start_time {$dateTimeType} NOT NULL,
                format VARCHAR(32) NOT NULL,
                base_price DECIMAL(10,2) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
                FOREIGN KEY (hall_id) REFERENCES halls(id) ON DELETE CASCADE
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS bookings (
                id {$idCol},
                user_id BIGINT NOT NULL,
                showtime_id BIGINT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'confirmed',
                total_amount DECIMAL(10,2) NOT NULL,
                booking_code VARCHAR(32) NOT NULL UNIQUE,
                movie_title_snapshot VARCHAR(255) NOT NULL,
                hall_name_snapshot VARCHAR(255) NOT NULL,
                showtime_snapshot {$dateTimeType} NOT NULL,
                customer_name VARCHAR(255) NOT NULL DEFAULT '',
                customer_email VARCHAR(255) NOT NULL DEFAULT '',
                created_at {$timestampType},
                canceled_at {$dateTimeType} NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (showtime_id) REFERENCES showtimes(id) ON DELETE CASCADE
            )"
        );

        $this->addColumnIfMissing('users', 'login', "VARCHAR(191) NOT NULL DEFAULT ''");
        $this->addColumnIfMissing('bookings', 'customer_name', "VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addColumnIfMissing('bookings', 'customer_email', "VARCHAR(255) NOT NULL DEFAULT ''");
        $this->normalizeMissingLogins();

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS booking_items (
                id {$idCol},
                booking_id BIGINT NOT NULL,
                showtime_id BIGINT NOT NULL,
                hall_seat_id BIGINT NOT NULL,
                seat_label VARCHAR(32) NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                UNIQUE (showtime_id, hall_seat_id),
                FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
                FOREIGN KEY (hall_seat_id) REFERENCES hall_seats(id) ON DELETE CASCADE,
                FOREIGN KEY (showtime_id) REFERENCES showtimes(id) ON DELETE CASCADE
            )"
        );

        $this->db->exec('DROP TABLE IF EXISTS favorites');

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS banners (
                id {$idCol},
                title VARCHAR(255) NOT NULL,
                subtitle VARCHAR(255) NOT NULL,
                image_url VARCHAR(1000) NOT NULL,
                cta_text VARCHAR(255) NOT NULL,
                cta_url VARCHAR(1000) NOT NULL,
                is_active {$boolType} NOT NULL DEFAULT {$trueValue},
                sort_order INTEGER NOT NULL DEFAULT 0
            )"
        );

        $this->createIndex('idx_movies_release_date', 'movies', 'release_date');
        $this->createIndex('idx_showtimes_start_time', 'showtimes', 'start_time');
        $this->createIndex('idx_showtimes_movie_id', 'showtimes', 'movie_id');
        $this->createIndex('idx_bookings_user_id', 'bookings', 'user_id');
        $this->createIndex('idx_users_login_unique', 'users', 'login', true);
    }

    public function seedIfEmpty(): void
    {
        $this->ensureAdminUser();
        $this->ensureCinemaAndFourHalls();
    }

    private function ensureAdminUser(): void
    {
        $exists = (int) $this->db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($exists > 0) {
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO users (name, login, email, password_hash, role) VALUES (:name, :login, :email, :password_hash, :role)');
        $stmt->execute([
            'name' => 'Адміністратор',
            'login' => 'admin',
            'email' => 'admin@cinema.local',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'role' => 'admin',
        ]);
    }

    private function ensureCinemaAndFourHalls(): void
    {
        $cinemaStmt = $this->db->query('SELECT id FROM cinemas ORDER BY id ASC LIMIT 1');
        $cinemaId = (int) $cinemaStmt->fetchColumn();
        if ($cinemaId <= 0) {
            $this->db->prepare('INSERT INTO cinemas (name, address, work_hours) VALUES (?, ?, ?)')
                ->execute(['Кінотеатр', 'м. Хмельницький, ТРЦ "Оазис"', '09:00 - 23:30']);
            $cinemaId = (int) $this->db->lastInsertId();
        }

        for ($i = 1; $i <= 4; $i++) {
            $hallName = 'Зал ' . $i;
            $hallStmt = $this->db->prepare('SELECT id, seat_rows, seat_cols FROM halls WHERE cinema_id = ? AND name = ? LIMIT 1');
            $hallStmt->execute([$cinemaId, $hallName]);
            $hall = $hallStmt->fetch(PDO::FETCH_ASSOC);

            if (!$hall) {
                $this->db->prepare('INSERT INTO halls (cinema_id, name, seat_rows, seat_cols) VALUES (?, ?, ?, ?)')
                    ->execute([$cinemaId, $hallName, 10, 12]);
                $hallId = (int) $this->db->lastInsertId();
                $this->seedHallSeats($hallId, 10, 12);
                continue;
            }

            $hallId = (int) $hall['id'];
            $rows = max(1, (int) ($hall['seat_rows'] ?? 10));
            $cols = max(1, (int) ($hall['seat_cols'] ?? 12));
            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM hall_seats WHERE hall_id = ?');
            $countStmt->execute([$hallId]);
            $seatsCount = (int) $countStmt->fetchColumn();
            if ($seatsCount === 0) {
                $this->seedHallSeats($hallId, $rows, $cols);
            }
        }
    }

    private function seedHallSeats(int $hallId, int $rows, int $cols): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO hall_seats (hall_id, row_label, seat_number, seat_label, is_active) VALUES (?, ?, ?, ?, 1)'
        );

        for ($r = 0; $r < $rows; $r++) {
            $row = chr(65 + $r);
            for ($c = 1; $c <= $cols; $c++) {
                $stmt->execute([$hallId, $row, $c, $row . $c]);
            }
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->hasColumn($table, $column)) {
            return;
        }

        $this->db->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private function hasColumn(string $table, string $column): bool
    {
        $driver = $this->driver();
        if ($driver === 'sqlite') {
            $stmt = $this->db->query('PRAGMA table_info(' . $table . ')');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT 1
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);
            return $stmt->fetchColumn() !== false;
        }

        $stmt = $this->db->prepare(
            'SELECT 1
             FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        return $stmt->fetchColumn() !== false;
    }

    private function normalizeMissingLogins(): void
    {
        $rows = $this->db->query('SELECT id, email, login FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $seen = [];
        $update = $this->db->prepare('UPDATE users SET login = :login WHERE id = :id');

        foreach ($rows as $row) {
            $current = trim((string) ($row['login'] ?? ''));
            if ($current !== '' && !isset($seen[$current])) {
                $seen[$current] = true;
                continue;
            }

            $base = trim((string) strstr((string) ($row['email'] ?? ''), '@', true));
            if ($base === '') {
                $base = 'user' . (int) $row['id'];
            }

            $login = $base;
            $i = 1;
            while (isset($seen[$login])) {
                $i++;
                $login = $base . $i;
            }

            $seen[$login] = true;
            $update->execute(['login' => $login, 'id' => (int) $row['id']]);
        }
    }

    private function createIndex(string $indexName, string $table, string $columns, bool $unique = false): void
    {
        $driver = $this->driver();
        $uniqueSql = $unique ? 'UNIQUE ' : '';

        if ($driver !== 'mysql') {
            $this->db->exec(sprintf(
                'CREATE %sINDEX IF NOT EXISTS %s ON %s(%s)',
                $uniqueSql,
                $indexName,
                $table,
                $columns
            ));
            return;
        }

        $existsStmt = $this->db->prepare(
            'SELECT 1
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name'
        );
        $existsStmt->execute([
            'table' => $table,
            'index_name' => $indexName,
        ]);
        if ($existsStmt->fetchColumn() !== false) {
            return;
        }

        $this->db->exec(sprintf(
            'CREATE %sINDEX %s ON %s(%s)',
            $uniqueSql,
            $indexName,
            $table,
            $columns
        ));
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function idColumn(): string
    {
        $driver = $this->driver();
        if ($driver === 'mysql') {
            return 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        }

        if ($driver === 'pgsql') {
            return 'BIGSERIAL PRIMARY KEY';
        }

        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    private function boolType(): string
    {
        $driver = $this->driver();
        if ($driver === 'mysql') {
            return 'TINYINT(1)';
        }

        if ($driver === 'pgsql') {
            return 'BOOLEAN';
        }

        return 'INTEGER';
    }

    private function trueValue(): string
    {
        return $this->driver() === 'pgsql' ? 'TRUE' : '1';
    }

    private function falseValue(): string
    {
        return $this->driver() === 'pgsql' ? 'FALSE' : '0';
    }

    private function dateTimeType(): string
    {
        return $this->driver() === 'sqlite' ? 'TEXT' : 'DATETIME';
    }

    private function timestampType(): string
    {
        return $this->driver() === 'sqlite'
            ? 'TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP'
            : 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
    }
}

<?php

declare(strict_types=1);

namespace CinemaApp\Src;

use PDO;
use Throwable;

final class CinemaRepository
{
    private const SUPER_LUX_PRICE = 280.0;
    /** @var array<string, array<string, array{nullable: bool, default: mixed, type: string}>> */
    private array $tableMetaCache = [];
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed> */
    public function homeData(): array
    {
        $today = date('Y-m-d');

        return [
            'nowShowing' => $this->moviesNowShowing($today, 100),
            'comingSoon' => $this->moviesComingSoon($today, 100),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function moviesNowShowing(string $today, int $limit): array
    {
        $genreAgg = $this->genreAggregateSql();
        $stmt = $this->db->prepare(
            "SELECT m.*, MIN(date(s.start_time)) AS first_show_date, {$genreAgg} AS genres
             FROM movies m
             JOIN showtimes s ON s.movie_id = m.id AND s.status = 'active'
             LEFT JOIN movie_genres mg ON mg.movie_id = m.id
             LEFT JOIN genres g ON g.id = mg.genre_id
             GROUP BY m.id
             HAVING MIN(date(s.start_time)) <= :today AND MAX(date(s.start_time)) >= :today
             ORDER BY m.popularity_score DESC, m.release_date DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':today', $today);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    private function moviesComingSoon(string $today, int $limit): array
    {
        $genreAgg = $this->genreAggregateSql();
        $stmt = $this->db->prepare(
            "SELECT m.*, MIN(date(s.start_time)) AS first_show_date, {$genreAgg} AS genres
             FROM movies m
             JOIN showtimes s ON s.movie_id = m.id AND s.status = 'active'
             LEFT JOIN movie_genres mg ON mg.movie_id = m.id
             LEFT JOIN genres g ON g.id = mg.genre_id
             GROUP BY m.id
             HAVING MIN(date(s.start_time)) > :today
             ORDER BY MIN(date(s.start_time)) ASC, m.title ASC
             LIMIT :lim"
        );
        $stmt->bindValue(':today', $today);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }


    /** @return array<string, mixed>|null */
    public function movieBySlug(string $slug): ?array
    {
        $genreAgg = $this->genreAggregateSql();
        $stmt = $this->db->prepare(
            "SELECT m.*, {$genreAgg} AS genres
             FROM movies m
             LEFT JOIN movie_genres mg ON mg.movie_id = m.id
             LEFT JOIN genres g ON g.id = mg.genre_id
             WHERE m.slug = :slug
             GROUP BY m.id"
        );
        $stmt->execute(['slug' => $slug]);
        $movie = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$movie) {
            return null;
        }

        $movie['showtimes'] = $this->showtimesForMovie((int) $movie['id']);

        return $movie;
    }

    /** @return array<int, array<string, mixed>> */
    public function showtimesForMovie(int $movieId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, h.name AS hall_name
             FROM showtimes s
             JOIN halls h ON h.id = s.hall_id
             WHERE s.movie_id = :movie_id AND s.status = 'active'
             ORDER BY s.start_time ASC"
        );
        $stmt->execute(['movie_id' => $movieId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<int, array<string, mixed>> */
    public function scheduleByDate(string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, m.title AS movie_title, m.slug AS movie_slug, m.poster_url, h.name AS hall_name
             FROM showtimes s
             JOIN movies m ON m.id = s.movie_id
             JOIN halls h ON h.id = s.hall_id
             WHERE date(s.start_time) = :d AND s.status = 'active'
             ORDER BY m.title ASC, s.start_time ASC"
        );
        $stmt->execute(['d' => $date]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function showtimeDetails(int $showtimeId): ?array
    {
        $activeSeatCondition = $this->activeSeatCondition('hs.is_active');
        $stmt = $this->db->prepare(
            "SELECT s.*, m.title AS movie_title, m.slug AS movie_slug, m.poster_url, m.age_rating, m.duration_minutes, h.name AS hall_name, h.id AS hall_id
             FROM showtimes s
             JOIN movies m ON m.id = s.movie_id
             JOIN halls h ON h.id = s.hall_id
             WHERE s.id = :id AND s.status = 'active'"
        );
        $stmt->execute(['id' => $showtimeId]);
        $showtime = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$showtime) {
            return null;
        }

        $activeSeatCondition = $this->activeSeatCondition('hs.is_active');
        $seatStmt = $this->db->prepare(
            'SELECT hs.*
             FROM hall_seats hs
             WHERE hs.hall_id = :hall_id AND ' . $activeSeatCondition . '
             ORDER BY hs.row_label, hs.seat_number'
        );
        $seatStmt->execute(['hall_id' => (int) $showtime['hall_id']]);
        $seats = $seatStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $busyStmt = $this->db->prepare(
            "SELECT bi.hall_seat_id
             FROM booking_items bi
             JOIN bookings b ON b.id = bi.booking_id
             WHERE bi.showtime_id = :showtime_id AND b.status IN ('pending', 'confirmed')"
        );
        $busyStmt->execute(['showtime_id' => $showtimeId]);
        $busy = array_map('intval', $busyStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $showtime['seats'] = $seats;
        $showtime['busy_seat_ids'] = $busy;

        return $showtime;
    }

    /** @param array<int, int> $seatIds
      * @return array<string, mixed>
      */
    public function createBooking(int $userId, int $showtimeId, array $seatIds, string $customerName, string $customerEmail): array
    {
        if ($seatIds === []) {
            throw new \RuntimeException('Оберіть хоча б одне місце');
        }
        if (trim($customerName) === '' || trim($customerEmail) === '') {
            throw new \RuntimeException('Вкажіть імʼя та email для отримання квитка');
        }

        $showtime = $this->showtimeDetails($showtimeId);
        if ($showtime === null) {
            throw new \RuntimeException('Сеанс недоступний');
        }

        $cleanIds = array_values(array_unique(array_map('intval', $seatIds)));
        $busyMap = array_flip(array_map('intval', $showtime['busy_seat_ids']));
        foreach ($cleanIds as $id) {
            if (isset($busyMap[$id])) {
                throw new \RuntimeException('Одне з обраних місць вже зайняте');
            }
        }

        $activeSeatCondition = $this->activeSeatCondition();
        $seatStmt = $this->db->prepare(
            'SELECT id, seat_label, row_label
             FROM hall_seats
             WHERE hall_id = ? AND ' . $activeSeatCondition . ' AND id IN (' . implode(',', array_fill(0, count($cleanIds), '?')) . ')'
        );
        $bind = array_merge([(int) $showtime['hall_id']], $cleanIds);
        $seatStmt->execute($bind);
        $seats = $seatStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (count($seats) !== count($cleanIds)) {
            throw new \RuntimeException('Некоректний вибір місць');
        }

        $lastRowStmt = $this->db->prepare(
            'SELECT row_label
             FROM hall_seats
             WHERE hall_id = ? AND ' . $activeSeatCondition . '
             ORDER BY row_label DESC, seat_number DESC
             LIMIT 1'
        );
        $lastRowStmt->execute([(int) $showtime['hall_id']]);
        $lastRowLabel = (string) ($lastRowStmt->fetchColumn() ?: '');

        $basePrice = (float) $showtime['base_price'];
        $total = 0.0;
        $seatPrices = [];
        foreach ($seats as $seat) {
            $isSuperLux = $lastRowLabel !== '' && (string) ($seat['row_label'] ?? '') === $lastRowLabel;
            $seatPrice = $isSuperLux ? self::SUPER_LUX_PRICE : $basePrice;
            $seatPrices[(int) $seat['id']] = $seatPrice;
            $total += $seatPrice;
        }

        $bookingCode = 'BK' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));

        $this->db->beginTransaction();
        try {
            $bookingStmt = $this->db->prepare(
                "INSERT INTO bookings
                    (user_id, showtime_id, status, total_amount, booking_code, movie_title_snapshot, hall_name_snapshot, showtime_snapshot, customer_name, customer_email)
                 VALUES
                    (:user_id, :showtime_id, 'confirmed', :total_amount, :booking_code, :movie_title, :hall_name, :showtime_snapshot, :customer_name, :customer_email)"
            );
            $bookingStmt->execute([
                'user_id' => $userId,
                'showtime_id' => $showtimeId,
                'total_amount' => $total,
                'booking_code' => $bookingCode,
                'movie_title' => $showtime['movie_title'],
                'hall_name' => $showtime['hall_name'],
                'showtime_snapshot' => $showtime['start_time'],
                'customer_name' => trim($customerName),
                'customer_email' => trim($customerEmail),
            ]);

            $bookingId = (int) $this->db->lastInsertId();
            $itemStmt = $this->db->prepare(
                'INSERT INTO booking_items (booking_id, showtime_id, hall_seat_id, seat_label, price) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($seats as $seat) {
                $seatId = (int) $seat['id'];
                $itemStmt->execute([
                    $bookingId,
                    $showtimeId,
                    $seatId,
                    (string) $seat['seat_label'],
                    (float) ($seatPrices[$seatId] ?? $basePrice),
                ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->bookingByCode($bookingCode) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function bookingByCode(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM bookings WHERE booking_code = ?');
        $stmt->execute([$code]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            return null;
        }

        $itemsStmt = $this->db->prepare('SELECT * FROM booking_items WHERE booking_id = ? ORDER BY seat_label ASC');
        $itemsStmt->execute([(int) $booking['id']]);
        $booking['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $booking;
    }

    /** @return array<int, array<string, mixed>> */
    public function userBookings(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $itemStmt = $this->db->prepare('SELECT seat_label FROM booking_items WHERE booking_id = ? ORDER BY seat_label ASC');
        foreach ($rows as &$row) {
            $itemStmt->execute([(int) $row['id']]);
            $labels = array_map('strval', $itemStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $labels = array_map([$this, 'convertSeatLabelToNumericRow'], $labels);
            $row['seats'] = implode(', ', $labels);
        }

        return $rows;
    }

    private function convertSeatLabelToNumericRow(string $seatLabel): string
    {
        $seatLabel = trim($seatLabel);
        if ($seatLabel === '') {
            return $seatLabel;
        }

        if (preg_match('/^([A-Za-z]+)\s*(\d+)$/', $seatLabel, $m) !== 1) {
            return $seatLabel;
        }

        $letters = strtoupper((string) $m[1]);
        $seatNumber = (string) $m[2];
        $rowNumber = 0;
        foreach (str_split($letters) as $char) {
            $rowNumber = ($rowNumber * 26) + (ord($char) - 64);
        }

        return $rowNumber . '-' . $seatNumber;
    }

    public function cancelBooking(int $userId, int $bookingId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE bookings
             SET status = 'canceled', canceled_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id AND status IN ('pending', 'confirmed')"
        );
        $stmt->execute(['id' => $bookingId, 'user_id' => $userId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function adminMovies(): array
    {
        return $this->db->query('SELECT * FROM movies ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function adminMovieById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM movies WHERE id = ?');
        $stmt->execute([$id]);
        $movie = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$movie) {
            return null;
        }

        $genres = $this->db->prepare('SELECT genre_id FROM movie_genres WHERE movie_id = ?');
        $genres->execute([$id]);
        $movie['genre_ids'] = array_map('intval', $genres->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $showtimesStmt = $this->db->prepare('SELECT hall_id, start_time FROM showtimes WHERE movie_id = ? ORDER BY start_time ASC');
        $showtimesStmt->execute([$id]);
        $showtimes = $showtimesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $movie['showtimes'] = $showtimes;

        if ($showtimes !== []) {
            $startDate = null;
            $endDate = null;
            $hours = [];
            $hallId = (int) $showtimes[0]['hall_id'];

            foreach ($showtimes as $show) {
                $date = date('Y-m-d', strtotime((string) $show['start_time']));
                $time = date('H:i', strtotime((string) $show['start_time']));
                $startDate = $startDate === null ? $date : min($startDate, $date);
                $endDate = $endDate === null ? $date : max($endDate, $date);
                $hours[$time] = true;
            }

            $movie['show_start_date'] = $startDate;
            $movie['show_end_date'] = $endDate;
            $movie['hall_id'] = $hallId;
            $movie['show_hours'] = array_keys($hours);
        } else {
            $movie['show_start_date'] = (string) ($movie['release_date'] ?? date('Y-m-d'));
            $movie['show_end_date'] = (string) ($movie['release_date'] ?? date('Y-m-d'));
            $movie['hall_id'] = 0;
            $movie['show_hours'] = [];
        }

        return $movie;
    }

    /** @param array<string, mixed> $payload */
    public function saveMovie(array $payload): void
    {
        $id = (int) ($payload['id'] ?? 0);
        $showStartDate = (string) ($payload['show_start_date'] ?? '');
        $showEndDate = (string) ($payload['show_end_date'] ?? '');
        $hallId = (int) ($payload['hall_id'] ?? 0);
        $showHours = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($payload['show_hours'] ?? [])
        ))));

        if ($showStartDate === '' || $showEndDate === '') {
            throw new \RuntimeException('Оберіть діапазон дат показу');
        }
        if ($showStartDate > $showEndDate) {
            throw new \RuntimeException('Дата початку не може бути пізніше дати завершення');
        }
        if ($hallId <= 0) {
            throw new \RuntimeException('Оберіть зал');
        }
        if ($showHours === []) {
            throw new \RuntimeException('Оберіть хоча б одну годину показу');
        }

        $requestedStartTimes = [];
        $startTs = strtotime($showStartDate);
        $endTs = strtotime($showEndDate);
        for ($dayTs = $startTs; $dayTs <= $endTs; $dayTs += 86400) {
            $day = date('Y-m-d', $dayTs);
            foreach ($showHours as $time) {
                if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
                    continue;
                }
                $requestedStartTimes[] = $day . ' ' . $time . ':00';
            }
        }
        if ($requestedStartTimes === []) {
            throw new \RuntimeException('Некоректні години показу');
        }

        $conflictSql =
            'SELECT s.start_time, m.title
             FROM showtimes s
             JOIN movies m ON m.id = s.movie_id
             WHERE s.hall_id = ?
               AND s.status = \'active\'
               AND s.movie_id <> ?
               AND s.start_time IN (' . implode(',', array_fill(0, count($requestedStartTimes), '?')) . ')
             ORDER BY s.start_time ASC
             LIMIT 3';
        $conflictStmt = $this->db->prepare($conflictSql);
        $conflictStmt->execute(array_merge([$hallId, $id], $requestedStartTimes));
        $conflicts = $conflictStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($conflicts !== []) {
            $samples = [];
            foreach ($conflicts as $conflict) {
                $samples[] = date('d.m.Y H:i', strtotime((string) $conflict['start_time']));
            }
            throw new \RuntimeException('Час уже зайнятий у цьому залі: ' . implode(', ', $samples));
        }

        $boolFalse = $this->driver() === 'pgsql' ? 'false' : 0;
        $boolTrue = $this->driver() === 'pgsql' ? 'true' : 1;

        $base = [
            'title' => (string) $payload['title'],
            'slug' => (string) $payload['slug'],
            'description' => (string) $payload['description'],
            'poster_url' => (string) $payload['poster_url'],
            'banner_url' => (string) ($payload['banner_url'] ?? $payload['poster_url']),
            'trailer_url' => (string) $payload['trailer_url'],
            'duration_minutes' => (int) ($payload['duration_minutes'] ?? 120),
            'release_date' => $showStartDate,
            'age_rating' => (string) ($payload['age_rating'] ?? '12+'),
            'language' => (string) ($payload['language'] ?? 'UA дубляж'),
            'format' => (string) ($payload['format'] ?? '2D'),
            'is_now_showing' => $boolFalse,
            'is_coming_soon' => $boolFalse,
            'is_popular' => $boolFalse,
            'popularity_score' => (int) ($payload['popularity_score'] ?? 0),
        ];

        $today = date('Y-m-d');
        if ($showStartDate > $today) {
            $base['is_coming_soon'] = $boolTrue;
        } elseif ($showEndDate >= $today) {
            $base['is_now_showing'] = $boolTrue;
        }

        $movieMeta = $this->tableColumnMetadata('movies');
        if ($id > 0) {
            $updateData = $this->filterDataByExistingColumns($base, $movieMeta);
            if ($updateData === []) {
                throw new \RuntimeException('Некоректна структура таблиці movies: немає полів для оновлення');
            }

            $setParts = [];
            foreach (array_keys($updateData) as $column) {
                $setParts[] = $column . '=:' . $column;
            }
            $sql = 'UPDATE movies SET ' . implode(', ', $setParts) . ' WHERE id=:id';
            $updateData['id'] = $id;
            $this->db->prepare($sql)->execute($updateData);
        } else {
            $insertData = $this->buildMovieInsertData($base, $movieMeta);
            if ($insertData === []) {
                throw new \RuntimeException('Некоректна структура таблиці movies: немає полів для вставки');
            }

            $columns = array_keys($insertData);
            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
            $sql = 'INSERT INTO movies (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $this->db->prepare($sql)->execute($insertData);
            $id = (int) $this->db->lastInsertId();
        }

        $this->db->prepare('DELETE FROM showtimes WHERE movie_id = ?')->execute([$id]);

        $showtimeMeta = $this->tableColumnMetadata('showtimes');
        foreach ($requestedStartTimes as $startTime) {
            $showtimeBase = [
                'movie_id' => $id,
                'hall_id' => $hallId,
                'start_time' => $startTime,
                'format' => $base['format'],
                'base_price' => 170.0,
                'status' => 'active',
            ];
            $showtimeInsertData = $this->buildShowtimeInsertData($showtimeBase, $showtimeMeta);
            $columns = array_keys($showtimeInsertData);
            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
            $sql = 'INSERT INTO showtimes (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $this->db->prepare($sql)->execute($showtimeInsertData);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function adminActiveHalls(): array
    {
        return $this->db->query('SELECT id, name FROM halls ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function deleteMovie(int $id): void
    {
        $this->db->prepare('DELETE FROM movies WHERE id = ?')->execute([$id]);
    }

    private function genreAggregateSql(): string
    {
        return $this->driver() === 'pgsql'
            ? "STRING_AGG(g.name, ', ')"
            : "GROUP_CONCAT(g.name, ', ')";
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function activeSeatCondition(string $column = 'is_active'): string
    {
        return $column . ' = ' . ($this->driver() === 'pgsql' ? 'TRUE' : '1');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array{nullable: bool, default: mixed, type: string}> $meta
     * @return array<string, mixed>
     */
    private function filterDataByExistingColumns(array $data, array $meta): array
    {
        $filtered = [];
        foreach ($data as $column => $value) {
            if (isset($meta[$column])) {
                $filtered[$column] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, array{nullable: bool, default: mixed, type: string}> $meta
     * @return array<string, mixed>
     */
    private function buildMovieInsertData(array $base, array $meta): array
    {
        $insert = $this->filterDataByExistingColumns($base, $meta);

        foreach ($meta as $column => $spec) {
            if (array_key_exists($column, $insert) || $column === 'id') {
                continue;
            }

            $default = $spec['default'];
            if ($spec['nullable'] || $default !== null) {
                continue;
            }

            $insert[$column] = $this->fallbackValueForRequiredColumn($column, $spec['type'], $base);
        }

        return $this->normalizeInsertDataTypes($insert, $meta);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, array{nullable: bool, default: mixed, type: string}> $meta
     * @return array<string, mixed>
     */
    private function buildShowtimeInsertData(array $base, array $meta): array
    {
        $insert = $this->filterDataByExistingColumns($base, $meta);

        foreach ($meta as $column => $spec) {
            if (array_key_exists($column, $insert) || $column === 'id') {
                continue;
            }

            $default = $spec['default'];
            if ($spec['nullable'] || $default !== null) {
                continue;
            }

            $insert[$column] = $this->fallbackValueForRequiredShowtimeColumn($column, $spec['type'], $base);
        }

        return $this->normalizeInsertDataTypes($insert, $meta);
    }

    /** @param array<string, mixed> $base */
    private function fallbackValueForRequiredColumn(string $column, string $type, array $base): mixed
    {
        $columnLower = strtolower($column);
        $typeLower = strtolower($type);

        if ($columnLower === 'price') {
            return 170.0;
        }
        if ($columnLower === 'slug') {
            return (string) ($base['slug'] ?? ('movie-' . time()));
        }
        if ($columnLower === 'title') {
            return (string) ($base['title'] ?? 'Без назви');
        }
        if ($columnLower === 'poster_url') {
            return (string) ($base['poster_url'] ?? '');
        }
        if ($columnLower === 'banner_url') {
            return (string) ($base['banner_url'] ?? ($base['poster_url'] ?? ''));
        }
        if ($columnLower === 'trailer_url') {
            return (string) ($base['trailer_url'] ?? '');
        }
        if (str_contains($typeLower, 'bool') || str_starts_with($columnLower, 'is_') || str_starts_with($columnLower, 'has_')) {
            return $this->driver() === 'pgsql' ? 'false' : 0;
        }
        if (str_contains($typeLower, 'date') && !str_contains($typeLower, 'time')) {
            return (string) ($base['release_date'] ?? date('Y-m-d'));
        }
        if (str_contains($typeLower, 'time')) {
            return date('Y-m-d H:i:s');
        }
        if (preg_match('/int|numeric|decimal|real|double|float/', $typeLower) === 1) {
            return 0;
        }

        return '';
    }

    /** @param array<string, mixed> $base */
    private function fallbackValueForRequiredShowtimeColumn(string $column, string $type, array $base): mixed
    {
        $columnLower = strtolower($column);
        $typeLower = strtolower($type);

        if ($columnLower === 'status') {
            return 'active';
        }
        if ($columnLower === 'format') {
            return (string) ($base['format'] ?? '2D');
        }
        if ($columnLower === 'base_price' || $columnLower === 'price') {
            return (float) ($base['base_price'] ?? 170.0);
        }
        if ($columnLower === 'start_time') {
            return (string) ($base['start_time'] ?? date('Y-m-d H:i:s'));
        }
        if (str_contains($typeLower, 'bool') || str_starts_with($columnLower, 'is_') || str_starts_with($columnLower, 'has_')) {
            return $this->driver() === 'pgsql' ? 'false' : 0;
        }
        if (str_contains($typeLower, 'date') && !str_contains($typeLower, 'time')) {
            return date('Y-m-d');
        }
        if (str_contains($typeLower, 'time')) {
            return date('Y-m-d H:i:s');
        }
        if (preg_match('/int|numeric|decimal|real|double|float/', $typeLower) === 1) {
            return 0;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $insert
     * @param array<string, array{nullable: bool, default: mixed, type: string}> $meta
     * @return array<string, mixed>
     */
    private function normalizeInsertDataTypes(array $insert, array $meta): array
    {
        foreach ($insert as $column => $value) {
            $spec = $meta[$column] ?? null;
            if ($spec === null) {
                continue;
            }

            if ($this->isBooleanColumnSpec($column, $spec['type'], $spec['default'])) {
                if ($value === '' || $value === null) {
                    $insert[$column] = $this->driver() === 'pgsql' ? 'false' : 0;
                    continue;
                }

                if (is_string($value)) {
                    $v = strtolower(trim($value));
                    if (in_array($v, ['1', 'true', 't', 'yes', 'on'], true)) {
                        $insert[$column] = $this->driver() === 'pgsql' ? 'true' : 1;
                        continue;
                    }
                    if (in_array($v, ['0', 'false', 'f', 'no', 'off', ''], true)) {
                        $insert[$column] = $this->driver() === 'pgsql' ? 'false' : 0;
                        continue;
                    }
                }
            }
        }

        return $insert;
    }

    private function isBooleanColumnSpec(string $column, string $type, mixed $default): bool
    {
        $columnLower = strtolower($column);
        $typeLower = strtolower($type);
        $defaultStr = strtolower((string) ($default ?? ''));

        if (str_contains($typeLower, 'bool') || str_contains($typeLower, 'bit')) {
            return true;
        }

        if (
            str_starts_with($columnLower, 'is_')
            || str_starts_with($columnLower, 'has_')
            || str_starts_with($columnLower, 'can_')
            || str_starts_with($columnLower, 'allow_')
            || str_ends_with($columnLower, '_enabled')
        ) {
            return true;
        }

        return str_contains($defaultStr, 'true') || str_contains($defaultStr, 'false');
    }

    /**
     * @return array<string, array{nullable: bool, default: mixed, type: string}>
     */
    private function tableColumnMetadata(string $table): array
    {
        if (isset($this->tableMetaCache[$table])) {
            return $this->tableMetaCache[$table];
        }

        $driver = $this->driver();
        $meta = [];

        if ($driver === 'sqlite') {
            $rows = $this->db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $meta[$name] = [
                    'nullable' => (int) ($row['notnull'] ?? 0) === 0,
                    'default' => $row['dflt_value'] ?? null,
                    'type' => strtolower((string) ($row['type'] ?? '')),
                ];
            }

            return $this->tableMetaCache[$table] = $meta;
        }

        if ($driver === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT column_name, is_nullable, column_default, data_type
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table'
            );
            $stmt->execute(['table' => $table]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT column_name, is_nullable, column_default, data_type, udt_name
                 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = :table'
            );
            $stmt->execute(['table' => $table]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $name = (string) ($row['column_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = strtolower((string) ($row['data_type'] ?? ''));
            $udtName = strtolower((string) ($row['udt_name'] ?? ''));
            if ($type === 'user-defined' && $udtName !== '') {
                $type = $udtName;
            }
            $meta[$name] = [
                'nullable' => strtoupper((string) ($row['is_nullable'] ?? 'YES')) === 'YES',
                'default' => $row['column_default'] ?? null,
                'type' => $type,
            ];
        }

        return $this->tableMetaCache[$table] = $meta;
    }
}

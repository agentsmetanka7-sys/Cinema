<?php

declare(strict_types=1);

namespace CinemaApp\Src;

use PDO;
use Throwable;

final class CinemaRepository
{
    private const SUPER_LUX_PRICE = 280.0;
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string, mixed> */
    public function homeData(): array
    {
        $today = date('Y-m-d');

        return [
            'nowShowing' => $this->moviesNowShowing($today, 8),
            'comingSoon' => $this->moviesComingSoon($today, 8),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function moviesNowShowing(string $today, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, MIN(date(s.start_time)) AS first_show_date, GROUP_CONCAT(g.name, ', ') AS genres
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
        $stmt = $this->db->prepare(
            "SELECT m.*, MIN(date(s.start_time)) AS first_show_date, GROUP_CONCAT(g.name, ', ') AS genres
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
        $stmt = $this->db->prepare(
            "SELECT m.*, GROUP_CONCAT(g.name, ', ') AS genres
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

        $seatStmt = $this->db->prepare(
            'SELECT hs.*
             FROM hall_seats hs
             WHERE hs.hall_id = :hall_id AND hs.is_active = 1
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

        $seatStmt = $this->db->prepare(
            'SELECT id, seat_label, row_label
             FROM hall_seats
             WHERE hall_id = ? AND is_active = 1 AND id IN (' . implode(',', array_fill(0, count($cleanIds), '?')) . ')'
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
             WHERE hall_id = ? AND is_active = 1
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
            'is_now_showing' => 0,
            'is_coming_soon' => 0,
            'is_popular' => 0,
            'popularity_score' => (int) ($payload['popularity_score'] ?? 0),
        ];

        $today = date('Y-m-d');
        if ($showStartDate > $today) {
            $base['is_coming_soon'] = 1;
        } elseif ($showEndDate >= $today) {
            $base['is_now_showing'] = 1;
        }

        if ($id > 0) {
            $sql =
                'UPDATE movies SET
                    title=:title, slug=:slug, description=:description, poster_url=:poster_url, banner_url=:banner_url,
                    trailer_url=:trailer_url, duration_minutes=:duration_minutes, release_date=:release_date,
                    age_rating=:age_rating, language=:language, format=:format,
                    is_now_showing=:is_now_showing, is_coming_soon=:is_coming_soon, is_popular=:is_popular,
                    popularity_score=:popularity_score
                 WHERE id=:id';
            $base['id'] = $id;
            $this->db->prepare($sql)->execute($base);
        } else {
            $sql =
                'INSERT INTO movies
                    (title, slug, description, poster_url, banner_url, trailer_url, duration_minutes, release_date, age_rating, language, format, is_now_showing, is_coming_soon, is_popular, popularity_score)
                 VALUES
                    (:title, :slug, :description, :poster_url, :banner_url, :trailer_url, :duration_minutes, :release_date, :age_rating, :language, :format, :is_now_showing, :is_coming_soon, :is_popular, :popularity_score)';
            $this->db->prepare($sql)->execute($base);
            $id = (int) $this->db->lastInsertId();
        }

        $this->db->prepare('DELETE FROM showtimes WHERE movie_id = ?')->execute([$id]);

        $insertShowtime = $this->db->prepare(
            "INSERT INTO showtimes (movie_id, hall_id, start_time, format, base_price, status) VALUES (?, ?, ?, ?, ?, 'active')"
        );
        foreach ($requestedStartTimes as $startTime) {
            $insertShowtime->execute([$id, $hallId, $startTime, $base['format'], 170.0]);
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

}

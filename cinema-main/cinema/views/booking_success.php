<section class="container py-5 text-center">
  <h1 class="mb-3">Бронювання успішне</h1>
  <p class="lead">Код бронювання: <strong><?= e($booking['booking_code']) ?></strong></p>
  <p>Фільм: <?= e($booking['movie_title_snapshot']) ?> · Зал: <?= e($booking['hall_name_snapshot']) ?></p>
  <p>Сеанс: <?= e(date('d.m.Y H:i', strtotime((string) $booking['showtime_snapshot']))) ?></p>
  <p>Отримувач: <strong><?= e((string) ($booking['customer_name'] ?? '')) ?></strong></p>
  <p>Email для квитка: <strong><?= e((string) ($booking['customer_email'] ?? '')) ?></strong></p>
  <p>Місця:
    <?php
      $labels = array_map(static fn($x) => (string) ($x['seat_label'] ?? ''), $booking['items'] ?? []);
      $labels = array_map(static function (string $label): string {
          if (preg_match('/^([A-Za-z]+)\s*(\d+)$/', $label, $m) !== 1) {
              return $label;
          }

          $letters = strtoupper($m[1]);
          $seatNumber = $m[2];
          $rowNumber = 0;
          foreach (str_split($letters) as $ch) {
              $rowNumber = ($rowNumber * 26) + (ord($ch) - 64);
          }

          return $rowNumber . '-' . $seatNumber;
      }, $labels);
    ?>
    <strong><?= e(implode(', ', $labels)) ?></strong>
  </p>
  <p class="mb-4">Сума: <strong><?= number_format((float) $booking['total_amount'], 2, '.', '') ?> ₴</strong></p>
  <div class="d-flex justify-content-center gap-2">
    <?php if ($currentUser !== null): ?>
      <a class="btn btn-danger" href="/profile">До кабінету</a>
    <?php else: ?>
      <a class="btn btn-danger" href="/">На головну</a>
    <?php endif; ?>
    <a class="btn btn-outline-light" href="/?tab=now">Продовжити вибір</a>
  </div>
</section>

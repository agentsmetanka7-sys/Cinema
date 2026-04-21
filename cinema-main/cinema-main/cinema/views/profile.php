<section class="container py-4 profile-shell">
  <div class="profile-top animate-fade-up">
    <div class="profile-avatar">
      <i class="bi bi-person-circle"></i>
    </div>
    <div class="profile-main">
      <p class="profile-kicker mb-0">Особистий кабінет</p>
      <h1><?= e($currentUser['name']) ?></h1>
      <div class="d-flex flex-wrap gap-2 mt-2">
        <?php if ((string) ($currentUser['email'] ?? '') !== ''): ?>
          <span>
            <i class="bi bi-envelope"></i><?= e($currentUser['email']) ?>
          </span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ((string) ($currentUser['role'] ?? '') === 'admin'): ?>
      <div class="profile-top-actions">
        <a href="/admin/movies/new" class="btn btn-danger btn-sm">
          <i class="bi bi-plus-circle me-1"></i> Додати фільм
        </a>
      </div>
    <?php endif; ?>
  </div>

  <div class="profile-bookings panel">
    <div class="profile-bookings-head">
      <h3 class="h5 mb-0">Історія бронювань</h3>
      <span class="profile-bookings-count"><?= count($bookings) ?> записів</span>
    </div>

    <?php if ($bookings === []): ?>
      <div class="text-center py-5">
        <p class="mb-0 text-secondary">Бронювань ще немає</p>
        <a href="/?tab=now" class="btn btn-outline-light mt-3">Перейти до фільмів</a>
      </div>
    <?php else: ?>
      <div class="profile-booking-list">
        <?php foreach ($bookings as $b): ?>
          <article class="profile-booking-item">
            <div class="profile-booking-head">
              <span class="profile-booking-code"><?= e($b['booking_code']) ?></span>
              <span class="profile-booking-status status-<?= e((string) $b['status']) ?>"><?= e((string) $b['status']) ?></span>
            </div>
            <h4><?= e($b['movie_title_snapshot']) ?></h4>
            <div class="profile-booking-meta">
              <span><i class="bi bi-calendar3"></i><?= e(date('d.m.Y H:i', strtotime((string) $b['showtime_snapshot']))) ?></span>
              <span><i class="bi bi-grid-3x3-gap"></i><?= e($b['seats']) ?></span>
            </div>
            <div class="profile-booking-foot">
              <strong><?= number_format((float) $b['total_amount'], 0, '.', ' ') ?> ₴</strong>
              <?php if (in_array((string) $b['status'], ['pending', 'confirmed'], true)): ?>
                <form method="post" action="/profile/bookings/<?= (int) $b['id'] ?>/cancel" class="m-0">
                  <button class="btn btn-sm btn-outline-light" type="submit">Скасувати</button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

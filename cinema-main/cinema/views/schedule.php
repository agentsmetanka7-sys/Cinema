<section class="container py-4">
  <div class="section-head">
    <div class="d-flex align-items-center gap-3">
      <i class="bi bi-calendar2-week text-primary fs-2"></i>
      <h1 class="mb-0 h3">Розклад сеансів</h1>
    </div>
  </div>

  <div class="schedule-filter">
    <form class="d-flex flex-wrap gap-3 align-items-end" method="get" action="/schedule" id="schedule-form">
      <div class="flex-grow-1" style="max-width: 300px;">
        <label class="form-label">
          <i class="bi bi-calendar3"></i> Оберіть дату
        </label>
        <input class="form-control schedule-date" type="date" name="date" value="<?= e($date) ?>" required>
      </div>
      <button class="btn btn-danger" type="submit" id="schedule-btn">
        <i class="bi bi-search me-2"></i>
        <span>Показати</span>
      </button>
    </form>
  </div>

  <?php if ($schedule === []): ?>
  <div class="empty-state">
    <i class="bi bi-calendar-x"></i>
    <h4>На обрану дату сеансів немає</h4>
    <p>Спробуйте обрати іншу дату або перегляньте розклад на інші дні</p>
  </div>
  <?php else: ?>
    <div class="schedule-list">
      <?php $index = 0; ?>
      <?php foreach ($schedule as $row): ?>
      <article class="schedule-item animate-fade-up" style="animation-delay: <?= $index * 0.05 ?>s">
        <?php $index++; ?>
      <div class="flex-grow-1">
        <h5 class="mb-2">
          <a href="/movie/<?= e(rawurlencode((string) $row['movie_slug'])) ?>">
            <?= e($row['movie_title']) ?>
          </a>
        </h5>
        <div class="d-flex flex-wrap align-items-center gap-3">
          <span class="badge">
            <i class="bi bi-film me-1"></i>
            <?= e($row['hall_name']) ?>
          </span>
          <span class="badge badge-success">
            <?= e($row['format'] ?? '2D') ?>
          </span>
          <span class="text-secondary">
            <i class="bi bi-clock me-1"></i>
            <?= e(date('H:i', strtotime((string) $row['start_time']))) ?>
          </span>
        </div>
      </div>
      <div class="d-flex align-items-center gap-3">
        <span class="h4 mb-0 text-primary" style="font-family: 'Oswald', sans-serif;">
          <?= number_format((float) $row['base_price'], 0, '.', ' ') ?> ₴
        </span>
        <a class="btn btn-danger" href="/booking/<?= (int) $row['id'] ?>">
          <i class="bi bi-ticket-perforated me-2"></i>
          <span>Квитки</span>
        </a>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<script>
(function() {
  const form = document.getElementById('schedule-form');
  const btn = document.getElementById('schedule-btn');
  if (form && btn) {
    form.addEventListener('submit', function() {
      btn.classList.add('btn-loading');
      btn.disabled = true;
    });
  }
})();
</script>

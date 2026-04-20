<?php
$isEdit = is_array($movie);
$selectedHours = array_map('strval', (array) ($movie['show_hours'] ?? []));
$selectedHallId = (int) ($movie['hall_id'] ?? 0);
$hourOptions = ['09:00', '11:40', '14:00', '16:30', '19:00', '20:10', '21:30'];
?>
<section class="container py-4 admin-shell">
  <div class="admin-header admin-header-form">
    <div class="admin-header-copy">
      <p class="admin-kicker mb-0">Адмін-панель</p>
      <h1 class="h3 mb-0"><?= $isEdit ? 'Редагувати фільм' : 'Додати фільм' ?></h1>
      <p class="admin-subtitle mb-0">Заповніть всі обовʼязкові поля, оберіть зал і графік показів.</p>
    </div>
    <a class="btn btn-outline-light admin-back-btn" href="/admin/movies">← Назад до списку</a>
  </div>

  <form method="post" action="/admin/movies/save" class="panel admin-form-panel">
    <input type="hidden" name="id" value="<?= (int) ($movie['id'] ?? 0) ?>">
    <input type="hidden" name="slug" value="<?= e($movie['slug'] ?? '') ?>">

    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Назва</label>
        <input class="form-control" name="title" required value="<?= e($movie['title'] ?? '') ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Зал</label>
        <select class="form-select" name="hall_id" required>
          <option value="">Оберіть зал</option>
          <?php foreach ($halls as $hall): ?>
            <option value="<?= (int) $hall['id'] ?>" <?= $selectedHallId === (int) $hall['id'] ? 'selected' : '' ?>>
              <?= e($hall['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label">Опис</label>
        <textarea class="form-control" name="description" rows="4" required><?= e($movie['description'] ?? '') ?></textarea>
      </div>

      <div class="col-md-6">
        <label class="form-label">Посилання на постер</label>
        <input class="form-control" name="poster_url" required value="<?= e($movie['poster_url'] ?? '') ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Посилання на трейлер (YouTube)</label>
        <input class="form-control" name="trailer_url" required value="<?= e($movie['trailer_url'] ?? '') ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Дата початку показу</label>
        <input class="form-control" type="date" name="show_start_date" required value="<?= e($movie['show_start_date'] ?? date('Y-m-d')) ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Дата завершення показу</label>
        <input class="form-control" type="date" name="show_end_date" required value="<?= e($movie['show_end_date'] ?? date('Y-m-d', strtotime('+7 days'))) ?>">
      </div>

      <div class="col-12">
        <label class="form-label d-block">Години показу (оберіть галочками)</label>
        <div class="admin-hours-grid">
          <?php foreach ($hourOptions as $hour): ?>
            <label class="admin-hour-chip">
              <input type="checkbox" name="show_hours[]" value="<?= e($hour) ?>" <?= in_array($hour, $selectedHours, true) ? 'checked' : '' ?>>
              <span><?= e($hour) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="admin-form-actions">
      <button class="btn btn-danger" type="submit">Зберегти</button>
      <a class="btn btn-outline-light" href="/admin/movies">Скасувати</a>
    </div>
  </form>
</section>

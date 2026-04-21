<?php
$busy = array_flip(array_map('intval', $showtime['busy_seat_ids'] ?? []));
$seats = $showtime['seats'] ?? [];
$price = (float) ($showtime['base_price'] ?? 0);
$luxPrice = 280.0;
$startAt = strtotime((string) ($showtime['start_time'] ?? 'now'));
$durationMinutes = max(1, (int) ($showtime['duration_minutes'] ?? 120));
$endAt = $startAt + ($durationMinutes * 60);
$timeRange = date('H:i', $startAt) . ' - ' . date('H:i', $endAt);

$seatRows = [];
$rowOrder = [];
foreach ($seats as $seat) {
  $rowLabel = (string) ($seat['row_label'] ?? '');
  if (!array_key_exists($rowLabel, $seatRows)) {
    $seatRows[$rowLabel] = [];
    $rowOrder[] = $rowLabel;
  }
  $seatRows[$rowLabel][] = $seat;
}
$lastRowLabel = $rowOrder !== [] ? (string) end($rowOrder) : '';
$rowNumberMap = [];
foreach ($rowOrder as $idx => $rowLabel) {
  $rowNumberMap[(string) $rowLabel] = (string) ($idx + 1);
}
$maxSeatsInRow = 0;
foreach ($seatRows as $rowSeats) {
  $maxSeatsInRow = max($maxSeatsInRow, count($rowSeats));
}
$maxSeatsInRow = max(1, $maxSeatsInRow);
?>
<section class="container py-4 booking-page">
  <form method="post" action="/booking/<?= (int) $showtime['id'] ?>" class="booking-layout-v2" id="booking-form-v2">
    <aside class="booking-poster-col">
      <?php if (!empty($showtime['poster_url'])): ?>
        <img src="<?= e($showtime['poster_url']) ?>" alt="<?= e($showtime['movie_title']) ?>" class="booking-movie-poster">
      <?php endif; ?>
    </aside>

    <div class="booking-middle-col">
      <div class="booking-main-info">
        <h1><?= e($showtime['movie_title']) ?></h1>

        <div class="booking-facts-grid">
          <article class="booking-fact-card">
            <div class="fact-icon"><i class="bi bi-geo-alt"></i></div>
            <div class="fact-content">
              <small><?= e($showtime['hall_name']) ?></small>
              <strong>Кінотеатр</strong>
            </div>
          </article>
          <article class="booking-fact-card">
            <div class="fact-icon"><i class="bi bi-calendar3"></i></div>
            <div class="fact-content">
              <small><?= e(date('d.m.Y', $startAt)) ?></small>
              <strong><?= e(formatUADate(date('Y-m-d', $startAt), 'l')) ?></strong>
            </div>
          </article>
          <article class="booking-fact-card">
            <div class="fact-icon"><i class="bi bi-clock"></i></div>
            <div class="fact-content">
              <small>Час</small>
              <strong><?= e($timeRange) ?></strong>
            </div>
          </article>
        </div>
      </div>

      <div class="seat-price-legend">
        <span class="legend-item good"><i></i>GOOD - <?= number_format($price, 0, '.', ' ') ?> грн</span>
        <span class="legend-item lux"><i></i>SUPER LUX - <?= number_format($luxPrice, 0, '.', ' ') ?> грн</span>
      </div>

      <div class="screen-arc"></div>
      <div class="screen-label mb-3">ЕКРАН</div>

      <div class="seat-map-wrap">
        <div class="seat-map-v2">
        <?php foreach ($seatRows as $rowLabel => $rowSeats): ?>
          <?php $isSuperLuxRow = $rowLabel === $lastRowLabel; ?>
          <div class="seat-row<?= $isSuperLuxRow ? ' seat-row-lux' : '' ?>" style="--row-cols: <?= (int) $maxSeatsInRow ?>">
            <?php foreach ($rowSeats as $seat): ?>
              <?php $id = (int) $seat['id']; ?>
              <?php $isBusy = isset($busy[$id]); ?>
              <?php $seatPrice = $isSuperLuxRow ? $luxPrice : $price; ?>
              <?php $seatType = $isSuperLuxRow ? 'SUPER LUX' : 'GOOD'; ?>
              <?php $rowDisplay = (string) ($rowNumberMap[$rowLabel] ?? $rowLabel); ?>
              <label class="seat <?= $isBusy ? 'seat-busy' : 'seat-free' ?><?= $isSuperLuxRow ? ' seat-lux' : '' ?>">
                <input
                  type="checkbox"
                  name="seat_ids[]"
                  value="<?= $id ?>"
                  data-seat-label="<?= e((string) ($seat['seat_label'] ?? '')) ?>"
                  data-row-label="<?= e($rowDisplay) ?>"
                  data-seat-number="<?= e((string) ($seat['seat_number'] ?? '')) ?>"
                  data-seat-type="<?= e($seatType) ?>"
                  data-price="<?= e(number_format((float) $seatPrice, 2, '.', '')) ?>"
                  <?= $isBusy ? 'disabled' : '' ?>
                >
                <span></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        </div>
      </div>

      <ul class="seat-legend list-unstyled mb-0 mt-3 d-flex gap-3 flex-wrap">
        <li><span class="dot free"></span> Вільне</li>
        <li><span class="dot busy"></span> Зайняте</li>
        <li><span class="dot selected"></span> Вибране</li>
      </ul>
    </div>

    <aside class="booking-right">
      <div class="booking-right-head d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Квитки</h3>
        <strong id="ticket-summary-top">0 квиток, 0 грн</strong>
      </div>

      <div class="booking-side-placeholder" id="selected-seats-placeholder">
        <div class="booking-placeholder-empty" id="booking-placeholder-empty">
          <div class="booking-ticket-illustration">
          <i class="bi bi-ticket-perforated"></i>
          </div>
          <p class="mb-0">З онлайн квитком відразу в зал!<br>Друкувати не потрібно</p>
        </div>
        <div class="booking-selected-list" id="booking-selected-list"></div>
      </div>

      <div class="booking-total-line d-flex justify-content-between align-items-center">
        <strong>Всього до сплати:</strong>
        <strong id="booking-total-price">0 грн</strong>
      </div>
      <p id="booking-inline-error" class="booking-inline-error">Оберіть хоча б одне місце.</p>

      <button class="btn btn-danger w-100 mt-3" id="continue-booking-btn" type="button">Продовжити</button>
    </aside>

    <div class="booking-modal" id="booking-modal" aria-hidden="true">
      <div class="booking-modal-card">
        <h4 class="booking-modal-title">Дані для квитка</h4>
        <p class="booking-modal-subtitle">Вкажіть дані, куди надіслати квиток</p>
        <div class="mb-2">
          <label class="form-label">Імʼя</label>
          <input class="form-control modal-field" type="text" name="customer_name" value="<?= e((string) ($currentUser['name'] ?? '')) ?>" disabled required>
        </div>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input class="form-control modal-field" type="email" name="customer_email" value="<?= e((string) ($currentUser['email'] ?? '')) ?>" disabled required>
        </div>
        <div class="d-flex gap-2 booking-modal-actions">
          <button class="btn btn-outline-light flex-grow-1" type="button" id="cancel-modal-btn">Скасувати</button>
          <button class="btn btn-danger flex-grow-1" type="submit">Замовити</button>
        </div>
      </div>
    </div>
  </form>
</section>

<script>
  (function () {
    const defaultPrice = <?= json_encode($price, JSON_UNESCAPED_UNICODE) ?>;
    const form = document.getElementById('booking-form-v2');
    if (!form) return;

    const totalNode = document.getElementById('booking-total-price');
    const topSummaryNode = document.getElementById('ticket-summary-top');
    const continueBtn = document.getElementById('continue-booking-btn');
    const modal = document.getElementById('booking-modal');
    const cancelBtn = document.getElementById('cancel-modal-btn');
    const inlineError = document.getElementById('booking-inline-error');
    const modalFields = form.querySelectorAll('.modal-field');
    const seatsPlaceholder = document.getElementById('selected-seats-placeholder');
    const seatsEmptyState = document.getElementById('booking-placeholder-empty');
    const seatsList = document.getElementById('booking-selected-list');

    const getSelectedCount = () => {
      return form.querySelectorAll('input[name="seat_ids[]"]:checked').length;
    };

    const selectedInputs = () => Array.from(form.querySelectorAll('input[name="seat_ids[]"]:checked'));
    const escapeHtml = (value) => String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

    const ticketWord = (count) => {
      if (count % 10 === 1 && count % 100 !== 11) return 'квиток';
      if ([2, 3, 4].includes(count % 10) && ![12, 13, 14].includes(count % 100)) return 'квитки';
      return 'квитків';
    };

    const renderSelectedSeats = () => {
      if (!seatsList || !seatsPlaceholder || !seatsEmptyState) return;
      const selected = selectedInputs();
      if (selected.length === 0) {
        seatsList.innerHTML = '';
        seatsEmptyState.classList.remove('d-none');
        return;
      }

      seatsEmptyState.classList.add('d-none');
      seatsList.innerHTML = selected.map((input) => {
        const rowLabel = input.dataset.rowLabel || '';
        const seatNumber = input.dataset.seatNumber || '';
        const seatType = input.dataset.seatType || 'GOOD';
        const seatPrice = Number.parseFloat(input.dataset.price || `${defaultPrice}`);
        const safeSeatId = escapeHtml(input.value);
        return `
          <div class="selected-seat-item">
            <div class="selected-seat-copy">
              <strong>${escapeHtml(rowLabel)} ряд</strong>
              <span>${escapeHtml(seatNumber)} місце ${escapeHtml(seatType)}</span>
              <em>${seatPrice.toFixed(0)} грн</em>
            </div>
            <button class="selected-seat-remove" type="button" data-remove-seat="${safeSeatId}" aria-label="Видалити місце">×</button>
          </div>
        `;
      }).join('');
    };

    const getSelectedTotal = () => {
      return selectedInputs().reduce((sum, input) => {
        const seatPrice = Number.parseFloat(input.dataset.price || `${defaultPrice}`);
        return sum + (Number.isFinite(seatPrice) ? seatPrice : defaultPrice);
      }, 0);
    };

    const renderTotal = () => {
      const selected = getSelectedCount();
      const total = getSelectedTotal();
      totalNode.textContent = `${total.toFixed(0)} грн`;
      topSummaryNode.textContent = `${selected} ${ticketWord(selected)}, ${total.toFixed(0)} грн`;
      renderSelectedSeats();
    };

    const openModal = () => {
      if (getSelectedCount() === 0) {
        inlineError?.classList.add('show');
        return;
      }
      inlineError?.classList.remove('show');
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      modalFields.forEach((field) => { field.disabled = false; });
    };

    const closeModal = () => {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      modalFields.forEach((field) => { field.disabled = true; });
    };

    form.querySelectorAll('input[name="seat_ids[]"]').forEach((input) => {
      input.addEventListener('change', () => {
        input.closest('.seat')?.classList.toggle('seat-selected', input.checked);
        if (getSelectedCount() > 0) inlineError?.classList.remove('show');
        renderTotal();
      });
    });

    continueBtn?.addEventListener('click', openModal);
    cancelBtn?.addEventListener('click', closeModal);
    seatsPlaceholder?.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const removeSeatId = target.getAttribute('data-remove-seat');
      if (!removeSeatId) return;
      const input = form.querySelector(`input[name="seat_ids[]"][value="${removeSeatId}"]`);
      if (!(input instanceof HTMLInputElement)) return;
      input.checked = false;
      input.closest('.seat')?.classList.remove('seat-selected');
      renderTotal();
    });
    modal?.addEventListener('click', (event) => {
      if (event.target === modal) closeModal();
    });

    form.addEventListener('submit', (event) => {
      const selected = form.querySelectorAll('input[name="seat_ids[]"]:checked').length;
      if (selected === 0) {
        event.preventDefault();
      }
    });

    renderTotal();
  })();
</script>

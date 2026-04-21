<?php
$nowShowing = $data['nowShowing'] ?? [];
$comingSoon = $data['comingSoon'] ?? [];
$kyivToday = (new DateTimeImmutable('now', new DateTimeZone('Europe/Kiev')))->format('Y-m-d');

$tab = ($_GET['tab'] ?? 'now') === 'soon' ? 'soon' : 'now';
$sourceMovies = $tab === 'soon' ? $comingSoon : $nowShowing;

$homeMovies = array_values(array_filter($sourceMovies, static function ($movie) {
    return !empty($movie['poster_url']);
}));

$weekdays = ['Неділя', 'Понеділок', 'Вівторок', 'Середа', 'Четвер', 'Пʼятниця', 'Субота'];

$rows = [];
if ($tab === 'soon') {
    $grouped = [];
    foreach ($homeMovies as $movie) {
        $dateKey = trim((string) ($movie['first_show_date'] ?? $movie['release_date'] ?? ''));
        if ($dateKey === '') {
            $dateKey = $kyivToday;
        }
        if (!isset($grouped[$dateKey])) {
            $grouped[$dateKey] = [];
        }
        $grouped[$dateKey][] = $movie;
    }
    ksort($grouped);
    foreach ($grouped as $dateKey => $items) {
        $rows[] = ['date' => $dateKey, 'items' => $items];
    }
} else {
    if ($homeMovies !== []) {
        $rows[] = ['date' => $kyivToday, 'items' => $homeMovies];
    }
}
?>



<section class="container multiplex-home py-4 py-md-5">
    <?php if ($rows === []): ?>
    <div class="empty-state">
        <i class="bi bi-film"></i>
        <h4>Фільми поки що не додані</h4>
        <p>Зачекайте, скоро тут з'являться нові фільми</p>
    </div>
    <?php endif; ?>

    <?php foreach ($rows as $row): ?>
        <?php
            $items = $row['items'];
            $labelDate = $tab === 'now' ? $kyivToday : (string) $row['date'];
            $date = new DateTimeImmutable($labelDate);
            $dayNumber = $date->format('d');
            $month = mb_strtolower(formatUADate($date->format('Y-m-d'), 'F'));
            $weekdayIndex = (int) $date->format('w');
            $weekday = $weekdays[$weekdayIndex] ?? '';
        ?>
        <div class="multiplex-day-row">
            <div class="multiplex-day-label">
                <strong><?= e($dayNumber . ' ' . $month) ?></strong>
                <span><?= e($weekday) ?></span>
            </div>

            <div class="multiplex-day-panel">
                <div class="multiplex-poster-grid">
                    <?php foreach ($items as $movie): ?>
                    <article class="multiplex-poster-card">
                        <a href="/movie/<?= e(rawurlencode((string) $movie['slug'])) ?>" class="poster-link-wrap">
                            <img 
                                src="<?= e($movie['poster_url']) ?>" 
                                alt="<?= e($movie['title']) ?>" 
                                class="multiplex-poster-img"
                                loading="lazy"
                            >
                            <span class="ticket-ribbon">Квитки у продажу</span>
                        </a>
                        <h3>
                            <a href="/movie/<?= e(rawurlencode((string) $movie['slug'])) ?>">
                                <?= e($movie['title']) ?>
                            </a>
                        </h3>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</section>

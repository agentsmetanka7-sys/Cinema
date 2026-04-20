<?php
$showtimes = $movie['showtimes'] ?? [];
$rawDescription = trim((string) ($movie['description'] ?? ''));
$metaLabels = [
    'Вікові обмеження',
    'Рік',
    'Оригінальна назва',
    'Режисер',
    'Рейтинг глядачів',
    'Рейтинг критиків',
    'Мова',
    'Жанр',
    'Тривалість',
    'Виробництво',
    'Студія',
    'Сценарій',
    'У головних ролях',
    'Інклюзивна адаптація',
];
$metaMap = [];
$descriptionLines = [];
foreach (preg_split('/\R/u', $rawDescription) ?: [] as $line) {
    $line = trim((string) $line);
    if ($line === '') {
        $descriptionLines[] = '';
        continue;
    }

    $matched = false;
    foreach ($metaLabels as $label) {
        $prefix = $label . ':';
        if (mb_stripos($line, $prefix) === 0) {
            $metaMap[$label] = trim((string) mb_substr($line, mb_strlen($prefix)));
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        $descriptionLines[] = $line;
    }
}

$descriptionText = trim(preg_replace("/\n{3,}/u", "\n\n", implode("\n", $descriptionLines)) ?? '');
$descriptionParagraphs = $descriptionText !== '' ? preg_split("/\n\s*\n/u", $descriptionText) : [];
$scheduleByDate = [];
foreach ($showtimes as $show) {
    $dateKey = date('Y-m-d', strtotime((string) $show['start_time']));
    $hallKey = (string) ($show['hall_name'] ?? 'Зал');
    if (!isset($scheduleByDate[$dateKey])) {
        $scheduleByDate[$dateKey] = [];
    }
    if (!isset($scheduleByDate[$dateKey][$hallKey])) {
        $scheduleByDate[$dateKey][$hallKey] = [];
    }
    $scheduleByDate[$dateKey][$hallKey][] = $show;
}
$scheduleDates = array_keys($scheduleByDate);
?>
<section class="container py-4 animate-fade-up">
    <div class="movie-page-top">
        <aside class="movie-poster-panel">
            <img class="movie-detail-poster" src="<?= e($movie['poster_url']) ?>" alt="<?= e($movie['title']) ?>" loading="lazy">
            <?php if (!empty($movie['trailer_url'])): ?>
                <a class="movie-trailer-link" href="#trailer">Дивитись трейлер</a>
            <?php endif; ?>
        </aside>

        <div class="movie-info-panel">
            <h1><?= e($movie['title']) ?></h1>

            <?php if ($metaMap !== []): ?>
                <div class="movie-description-meta">
                    <?php foreach ($metaLabels as $label): ?>
                        <?php if (array_key_exists($label, $metaMap)): ?>
                            <div class="movie-meta-row">
                                <strong><?= e($label) ?>:</strong>
                                <span><?= e($metaMap[$label]) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($descriptionParagraphs !== []): ?>
                <div class="movie-description-text">
                    <?php foreach ($descriptionParagraphs as $paragraph): ?>
                        <p><?= e(trim((string) $paragraph)) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <aside id="showtimes" class="movie-side-schedule">
            <div class="movie-side-schedule-head">
                <h2>Розклад сеансів</h2>
                <?php if (!empty($showtimes)): ?>
                    <select class="form-select schedule-day-select" id="schedule-day-select">
                        <?php foreach ($scheduleDates as $dateKey): ?>
                            <option value="<?= e($dateKey) ?>"><?= e(formatUADate($dateKey, 'D, d F')) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="movie-side-schedule-body">
                <?php if (empty($showtimes)): ?>
                    <p class="movie-no-sessions">Наразі немає доступних сеансів</p>
                <?php else: ?>
                    <?php foreach ($scheduleByDate as $dateKey => $halls): ?>
                        <div class="movie-day-schedule <?= $dateKey === ($scheduleDates[0] ?? '') ? 'active' : '' ?>" data-day="<?= e($dateKey) ?>">
                            <?php foreach ($halls as $hallName => $items): ?>
                                <article class="movie-hall-card">
                                    <h4><?= e($hallName) ?></h4>
                                    <div class="movie-hall-times">
                                        <?php foreach ($items as $show): ?>
                                            <a class="hall-time-btn" href="/booking/<?= (int) $show['id'] ?>">
                                                <strong><?= e(date('H:i', strtotime((string) $show['start_time']))) ?></strong>
                                                <span><?= e($show['format'] ?? '2D') ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </aside>
    </div>

    <?php if (!empty($movie['trailer_url'])): ?>
        <div class="movie-trailer-section mt-5" id="trailer">
            <h2>Трейлер</h2>
            <div class="trailer-wrapper">
                <iframe
                    src="<?= e($movie['trailer_url']) ?>"
                    title="Трейлер <?= e($movie['title']) ?>"
                    allowfullscreen
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    loading="lazy">
                </iframe>
            </div>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    const select = document.getElementById('schedule-day-select');
    if (!select) return;

    const blocks = document.querySelectorAll('.movie-day-schedule');

    const render = () => {
        const day = select.value;
        blocks.forEach((block) => {
            if (block.dataset.day === day) {
                block.classList.add('active');
                block.style.opacity = '0';
                block.style.transform = 'translateY(10px)';
                requestAnimationFrame(() => {
                    block.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                    block.style.opacity = '1';
                    block.style.transform = 'translateY(0)';
                });
            } else {
                block.classList.remove('active');
            }
        });
    };

    select.addEventListener('change', render);
    render();
})();
</script>

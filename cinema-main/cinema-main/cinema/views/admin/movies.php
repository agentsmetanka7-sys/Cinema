<section class="container py-4 admin-shell">
    <div class="admin-header">
        <h1 class="mb-0 h3">Адмін-панель</h1>
        <a href="/admin/movies/new" class="btn btn-danger admin-add-btn">
            <i class="bi bi-plus-lg"></i> Додати фільм
        </a>
    </div>

    <?php if ($movies === []): ?>
    <div class="empty-state admin-empty">
        <i class="bi bi-film"></i>
        <h4>Фільми поки що не додані</h4>
        <p>Натисніть кнопку вище, щоб додати перший фільм</p>
    </div>
    <?php else: ?>
    <div class="admin-grid admin-movie-grid">
        <?php $index = 0; ?>
        <?php foreach ($movies as $movie): ?>
        <article class="movie-card-v2 admin-movie-card animate-fade-up" style="animation-delay: <?= $index * 0.05 ?>s">
            <?php $index++; ?>
            <a class="movie-poster-link" href="/movie/<?= e(rawurlencode((string) $movie['slug'])) ?>">
                <img class="movie-poster-v2" src="<?= e($movie['poster_url']) ?>" alt="<?= e($movie['title']) ?>" loading="lazy">
            </a>
            <div class="movie-card-body">
                <h3 class="movie-card-title">
                    <a href="/movie/<?= e(rawurlencode((string) $movie['slug'])) ?>"><?= e($movie['title']) ?></a>
                </h3>
                <p class="movie-meta mb-2">
                    <i class="bi bi-calendar me-1"></i> Премʼєра: <?= e(date('d.m.Y', strtotime((string) $movie['release_date']))) ?>
                </p>
                <div class="d-flex gap-2 mt-auto admin-movie-actions">
                    <a class="btn btn-sm btn-outline-light" href="/admin/movies/<?= (int) $movie['id'] ?>/edit">
                        <i class="bi bi-pencil me-1"></i>Редагувати
                    </a>
                    <form method="post" action="/admin/movies/<?= (int) $movie['id'] ?>/delete" class="m-0" onsubmit="return confirm('Видалити фільм?');">
                        <button class="btn btn-sm btn-danger" type="submit">
                            <i class="bi bi-trash me-1"></i>Видалити
                        </button>
                    </form>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

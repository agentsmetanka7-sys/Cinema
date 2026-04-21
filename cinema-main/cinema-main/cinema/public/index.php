<?php

declare(strict_types=1);

use CinemaApp\Src\AuthService;
use CinemaApp\Src\CinemaRepository;
use CinemaApp\Src\Database;
use CinemaApp\Src\Mailer;
use CinemaApp\Src\SchemaManager;

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/SchemaManager.php';
require_once __DIR__ . '/../src/AuthService.php';
require_once __DIR__ . '/../src/CinemaRepository.php';
require_once __DIR__ . '/../src/Mailer.php';

if (PHP_SAPI === 'cli-server') {
    $requestedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $staticFile = realpath(__DIR__ . $requestedPath);
    $publicRoot = realpath(__DIR__);

    if ($staticFile !== false && $publicRoot !== false && str_starts_with($staticFile, $publicRoot) && is_file($staticFile)) {
        return false;
    }
}

session_start();

$db = Database::connection();
$schema = new SchemaManager($db);
$schema->migrate();
$schema->seedIfEmpty();

$repo = new CinemaRepository($db);
$authService = new AuthService($db);
$mailer = Mailer::fromEnv();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$currentUser = currentUser($authService);

if ($method === 'POST') {
    handlePost($uriPath, $repo, $authService, $mailer, $currentUser);
    exit;
}

handleGet($uriPath, $repo, $authService, $currentUser);

function handleGet(string $path, CinemaRepository $repo, AuthService $authService, ?array $currentUser): void
{
    if ($path === '/') {
        render('home', [
            'pageTitle' => 'Кінотеатр - Премʼєри та бронювання',
            'currentUser' => $currentUser,
            'data' => $repo->homeData(),
        ]);
        return;
    }

    if (preg_match('#^/movie/([^/]+)$#u', $path, $m) === 1) {
        $movieSlug = urldecode((string) $m[1]);
        $movie = $repo->movieBySlug($movieSlug);
        if ($movie === null) {
            http_response_code(404);
            render('404', ['pageTitle' => '404', 'currentUser' => $currentUser]);
            return;
        }

        render('movie', [
            'pageTitle' => (string) $movie['title'],
            'currentUser' => $currentUser,
            'movie' => $movie,
        ]);
        return;
    }

    if ($path === '/schedule') {
        $date = (string) ($_GET['date'] ?? date('Y-m-d'));
        render('schedule', [
            'pageTitle' => 'Розклад сеансів',
            'currentUser' => $currentUser,
            'date' => $date,
            'schedule' => $repo->scheduleByDate($date),
        ]);
        return;
    }

    if (preg_match('#^/booking/(\d+)$#', $path, $m) === 1) {
        $showtime = $repo->showtimeDetails((int) $m[1]);
        if ($showtime === null) {
            http_response_code(404);
            render('404', ['pageTitle' => '404', 'currentUser' => $currentUser]);
            return;
        }

        render('booking', [
            'pageTitle' => 'Бронювання квитків',
            'currentUser' => $currentUser,
            'showtime' => $showtime,
            'flash' => pullFlash(),
        ]);
        return;
    }

    if (preg_match('#^/booking/success/([A-Z0-9]+)$#', $path, $m) === 1) {
        $bookingCode = (string) $m[1];
        $booking = $repo->bookingByCode($bookingCode);
        if ($booking === null) {
            http_response_code(404);
            render('404', ['pageTitle' => '404', 'currentUser' => $currentUser]);
            return;
        }

        if ($currentUser !== null) {
            if ((int) $booking['user_id'] !== (int) $currentUser['id']) {
                http_response_code(404);
                render('404', ['pageTitle' => '404', 'currentUser' => $currentUser]);
                return;
            }
        } else {
            $guestCodes = array_map('strval', (array) ($_SESSION['guest_booking_codes'] ?? []));
            if (!in_array($bookingCode, $guestCodes, true)) {
                http_response_code(404);
                render('404', ['pageTitle' => '404', 'currentUser' => $currentUser]);
                return;
            }
        }

        render('booking_success', [
            'pageTitle' => 'Бронювання успішне',
            'currentUser' => $currentUser,
            'booking' => $booking,
        ]);
        return;
    }

    if ($path === '/login') {
        if ($currentUser) {
            if (($currentUser['role'] ?? '') === 'admin') {
                redirect('/admin/movies');
            }
            redirect('/');
        }
        render('login', ['pageTitle' => 'Вхід', 'currentUser' => null, 'flash' => pullFlash()]);
        return;
    }

    if ($path === '/register') {
        if ($currentUser) {
            if (($currentUser['role'] ?? '') === 'admin') {
                redirect('/admin/movies');
            }
            redirect('/');
        }
        render('register', ['pageTitle' => 'Реєстрація', 'currentUser' => null, 'flash' => pullFlash()]);
        return;
    }

    if ($path === '/profile') {
        requireUser($currentUser, '/login?next=%2Fprofile');
        render('profile', [
            'pageTitle' => 'Особистий кабінет',
            'currentUser' => $currentUser,
            'bookings' => $repo->userBookings((int) $currentUser['id']),
            'flash' => pullFlash(),
        ]);
        return;
    }

    if ($path === '/admin') {
        requireAdmin($currentUser);
        redirect('/admin/movies');
        return;
    }

    if ($path === '/admin/movies') {
        requireAdmin($currentUser);
        render('admin/movies', [
            'pageTitle' => 'Адмін: Фільми',
            'currentUser' => $currentUser,
            'movies' => $repo->adminMovies(),
            'flash' => pullFlash(),
        ]);
        return;
    }

    if ($path === '/admin/movies/new') {
        requireAdmin($currentUser);
        render('admin/movie_form', [
            'pageTitle' => 'Адмін: Додати фільм',
            'currentUser' => $currentUser,
            'movie' => null,
            'halls' => $repo->adminActiveHalls(),
        ]);
        return;
    }

    if (preg_match('#^/admin/movies/(\d+)/edit$#', $path, $m) === 1) {
        requireAdmin($currentUser);
        $movie = $repo->adminMovieById((int) $m[1]);
        if ($movie === null) {
            http_response_code(404);
            render('404', ['pageTitle' => '404', 'currentUser' => $currentUser]);
            return;
        }

        render('admin/movie_form', [
            'pageTitle' => 'Адмін: Редагувати фільм',
            'currentUser' => $currentUser,
            'movie' => $movie,
            'halls' => $repo->adminActiveHalls(),
        ]);
        return;
    }

    http_response_code(404);
    render('404', ['pageTitle' => '404', 'currentUser' => $currentUser]);
}

function handlePost(string $path, CinemaRepository $repo, AuthService $authService, Mailer $mailer, ?array $currentUser): void
{
    try {
        if ($path === '/auth/login') {
            $login = trim((string) ($_POST['login'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $user = $authService->login($login, $password);
            if ($user === null) {
                setFlash('error', 'Невірний логін або пароль');
                redirect('/login');
            }

            $_SESSION['user_id'] = (int) $user['id'];
            $next = trim((string) ($_POST['next'] ?? ''));
            if ($next !== '' && str_starts_with($next, '/')) {
                redirect($next);
            }

            redirect($user['role'] === 'admin' ? '/admin/movies' : '/');
        }

        if ($path === '/auth/register') {
            $login = trim((string) ($_POST['login'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
            if ($login === '' || strlen($password) < 6) {
                throw new RuntimeException('Введіть коректні дані (пароль мінімум 6 символів)');
            }
            if ($password !== $passwordConfirm) {
                throw new RuntimeException('Паролі не співпадають');
            }

            $user = $authService->register($login, $password);
            $_SESSION['user_id'] = (int) $user['id'];
            redirect('/');
        }

        if ($path === '/auth/logout') {
            unset($_SESSION['user_id']);
            redirect('/');
        }

        if (preg_match('#^/booking/(\d+)$#', $path, $m) === 1) {
            $seatIds = array_map('intval', (array) ($_POST['seat_ids'] ?? []));
            $customerName = trim((string) ($_POST['customer_name'] ?? ''));
            $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
            $bookingUserId = $currentUser !== null ? (int) $currentUser['id'] : $authService->guestUserId();
            $booking = $repo->createBooking($bookingUserId, (int) $m[1], $seatIds, $customerName, $customerEmail);
            if ($booking === []) {
                throw new RuntimeException('Не вдалося створити бронювання');
            }

            if ($currentUser === null) {
                $guestCodes = array_map('strval', (array) ($_SESSION['guest_booking_codes'] ?? []));
                $guestCodes[] = (string) $booking['booking_code'];
                $_SESSION['guest_booking_codes'] = array_slice(array_values(array_unique($guestCodes)), -20);
            }

            sendTicketEmail($mailer, $booking);
            redirect('/booking/success/' . $booking['booking_code']);
        }

        if (preg_match('#^/profile/bookings/(\d+)/cancel$#', $path, $m) === 1) {
            requireUser($currentUser, '/login?next=%2Fprofile');
            $repo->cancelBooking((int) $currentUser['id'], (int) $m[1]);
            setFlash('success', 'Бронювання скасовано');
            redirect('/profile');
        }

        requireAdmin($currentUser);

        if ($path === '/admin/movies/save') {
            $payload = [
                'id' => (int) ($_POST['id'] ?? 0),
                'title' => trim((string) ($_POST['title'] ?? '')),
                'slug' => trim((string) ($_POST['slug'] ?? '')),
                'description' => trim((string) ($_POST['description'] ?? '')),
                'poster_url' => trim((string) ($_POST['poster_url'] ?? '')),
                'trailer_url' => normalizeYouTubeEmbedUrl(trim((string) ($_POST['trailer_url'] ?? ''))),
                'show_start_date' => trim((string) ($_POST['show_start_date'] ?? '')),
                'show_end_date' => trim((string) ($_POST['show_end_date'] ?? '')),
                'hall_id' => (int) ($_POST['hall_id'] ?? 0),
                'show_hours' => array_map('strval', (array) ($_POST['show_hours'] ?? [])),
                'format' => 'SDH',
            ];

            if ($payload['title'] === '' || $payload['description'] === '' || $payload['poster_url'] === '') {
                throw new RuntimeException('Заповніть обовʼязкові поля фільму');
            }
            if ($payload['slug'] === '') {
                $payload['slug'] = slugify($payload['title']);
            }

            $repo->saveMovie($payload);
            setFlash('success', 'Фільм збережено');
            redirect('/admin/movies');
        }

        if (preg_match('#^/admin/movies/(\d+)/delete$#', $path, $m) === 1) {
            $repo->deleteMovie((int) $m[1]);
            setFlash('success', 'Фільм видалено');
            redirect('/admin/movies');
        }

        http_response_code(404);
        echo 'Not found';
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        if (str_starts_with($path, '/admin')) {
            redirectBack('/admin/movies');
        }
        if (str_starts_with($path, '/booking/')) {
            redirect($path);
        }
        if (str_starts_with($path, '/profile')) {
            redirect('/profile');
        }

        redirectBack('/');
    }
}

/** @param array<string, mixed> $vars */
function render(string $view, array $vars): void
{
    $viewFile = __DIR__ . '/../views/' . $view . '.php';
    if (!is_file($viewFile)) {
        http_response_code(500);
        echo 'View not found: ' . htmlspecialchars($view);
        return;
    }

    extract($vars, EXTR_SKIP);
    $view = $view;
    include __DIR__ . '/../views/layout.php';
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function redirectBack(string $fallback): void
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $path = (string) (parse_url($referer, PHP_URL_PATH) ?? '');
    redirect($path !== '' ? $path : $fallback);
}

/** @return array<string, mixed>|null */
function currentUser(AuthService $authService): ?array
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    return $authService->userById($id);
}

/** @param array<string, mixed>|null $user */
function requireUser(?array $user, string $redirectTo): void
{
    if ($user !== null) {
        return;
    }

    redirect($redirectTo);
}

/** @param array<string, mixed>|null $user */
function requireAdmin(?array $user): void
{
    if ($user !== null && ($user['role'] ?? '') === 'admin') {
        return;
    }

    $requested = (string) ($_SERVER['REQUEST_URI'] ?? '/admin');
    redirect('/login?next=' . urlencode($requested));
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/** @return array{type:string,message:string}|null */
function pullFlash(): ?array
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $value = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return [
        'type' => (string) ($value['type'] ?? 'success'),
        'message' => (string) ($value['message'] ?? ''),
    ];
}

function normalizeYouTubeEmbedUrl(string $url): string
{
    $trimmed = trim($url);
    if ($trimmed === '') {
        return $trimmed;
    }

    $parts = parse_url($trimmed);
    if ($parts === false) {
        return $trimmed;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);
    $videoId = null;

    if ($host === 'youtu.be' || $host === 'www.youtu.be') {
        $videoId = trim($path, '/');
    } elseif (str_contains($host, 'youtube.com')) {
        if (isset($query['v']) && is_string($query['v'])) {
            $videoId = $query['v'];
        } elseif (preg_match('#^/embed/([^/?]+)#', $path, $m) === 1) {
            $videoId = $m[1];
        } elseif (preg_match('#^/shorts/([^/?]+)#', $path, $m) === 1) {
            $videoId = $m[1];
        }
    }

    if ($videoId === null || preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId) !== 1) {
        return $trimmed;
    }

    return 'https://www.youtube.com/embed/' . $videoId;
}

function slugify(string $title): string
{
    $value = trim($title);
    $latin = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $source = $latin !== false ? $latin : $value;
    $slug = strtolower($source);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'movie-' . time();
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** @param array<string, mixed> $booking */
function sendTicketEmail(Mailer $mailer, array $booking): bool
{
    $email = trim((string) ($booking['customer_email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $name = trim((string) ($booking['customer_name'] ?? 'Гість'));

    return $mailer->sendTicket($email, $name, $booking);
}

function formatUADate(string $date, string $pattern = 'd.m.Y'): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $months = [
        1 => 'січня', 2 => 'лютого', 3 => 'березня', 4 => 'квітня', 5 => 'травня', 6 => 'червня',
        7 => 'липня', 8 => 'серпня', 9 => 'вересня', 10 => 'жовтня', 11 => 'листопада', 12 => 'грудня',
    ];
    $weekdays = [
        0 => 'неділя', 1 => 'понеділок', 2 => 'вівторок', 3 => 'середа',
        4 => 'четвер', 5 => 'пʼятниця', 6 => 'субота',
    ];
    $weekdaysShort = [
        0 => 'Нд', 1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб',
    ];

    $replacements = [
        'd' => date('d', $timestamp),
        'm' => date('m', $timestamp),
        'Y' => date('Y', $timestamp),
        'F' => $months[(int) date('n', $timestamp)] ?? date('F', $timestamp),
        'l' => $weekdays[(int) date('w', $timestamp)] ?? date('l', $timestamp),
        'D' => $weekdaysShort[(int) date('w', $timestamp)] ?? date('D', $timestamp),
    ];

    return preg_replace_callback('/[dFmYlD]/u', static fn (array $m): string => $replacements[$m[0]] ?? $m[0], $pattern) ?? $pattern;
}

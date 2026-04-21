# Кінотеатр (PHP)

Сайт кінотеатру з афішею, сторінкою фільму, розкладом у картці фільму, вибором місць, бронюванням, кабінетом користувача та адмін-панеллю для керування фільмами.

## Запуск

### Локально (PHP built-in server)

```bash
php -S localhost:8000 -t public public/index.php
```

Відкрити: `http://localhost:8000`

### Через Docker

```bash
docker build -t cinema-app .
docker run --rm -p 8080:80 \
  -e DB_HOST=host.docker.internal \
  -e DB_PORT=3306 \
  -e DB_NAME=cinema \
  -e DB_USER=cinema_user \
  -e DB_PASSWORD=cinema_password \
  -e DB_DRIVER=mysql \
  cinema-app
```

Відкрити: `http://localhost:8080`

## Конфігурація БД

Підтримуються такі варіанти конфігурації (у пріоритеті зверху вниз):

1. `DATABASE_URL` (формат: `mysql://...`, `mariadb://...`, `postgres://...`, `postgresql://...`)
2. Набір змінних `DB_*`:
   - `DB_DRIVER` (`mysql` або `pgsql`, за замовчуванням `mysql`)
   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASSWORD`
   - `DB_CHARSET` (для MySQL, за замовчуванням `utf8mb4`)
3. Fallback для локальної розробки: `SQLITE_PATH` (або `storage/cinema.sqlite`)

### Важливо для Render + Supabase

- Для продакшн на Render обовʼязково задайте `DATABASE_URL` від Supabase (Postgres).
- Якщо `DATABASE_URL`/`DB_*` відсутні, застосунок раніше переходив на локальний `SQLite`, через що після рестарту могли “зникати” нові фільми і повертатися старі з `storage/cinema.sqlite`.
- Тепер у runtime Render fallback на SQLite заблокований: застосунок віддасть помилку конфігурації, якщо remote БД не налаштована.
- Додатково можна ввімкнути таку ж поведінку локально/на інших хостингах через `REQUIRE_DATABASE_URL=1`.

## Доступ

- Адмін: `admin` / `admin123`
- Користувач: реєстрація через `/register` (логін + пароль)

## Основні можливості

- Головна сторінка:
  - вкладки `Зараз у кіно` / `Скоро у прокаті`
  - групування фільмів за датами
- Сторінка фільму:
  - постер, структурований опис, трейлер (YouTube embed)
  - розклад сеансів з вибором дня
- Бронювання:
  - вибір місць у залі
  - різна ціна для `GOOD` і `SUPER LUX`
  - введення імені та email перед підтвердженням
  - сторінка успішного бронювання
- Кабінет:
  - історія бронювань
  - скасування бронювання
- Адмін-панель:
  - список фільмів
  - додавання / редагування / видалення фільму
  - вибір діапазону дат, годин показу та залу
  - перевірка конфліктів часу в одному залі

## SMTP (опціонально)

Щоб реально надсилати квитки на email, задайте змінні:

```bash
export SMTP_HOST='smtp.gmail.com'
export SMTP_PORT='587'
export SMTP_ENCRYPTION='tls'
export SMTP_USERNAME='your_email@gmail.com'
export SMTP_PASSWORD='your_app_password'
export SMTP_FROM_EMAIL='your_email@gmail.com'
export SMTP_FROM_NAME='Кінотеатр'
export SMTP_TIMEOUT='15'
```

Без SMTP бронювання створюється, але лист не відправляється.

## Структура проєкту

- `public/` — фронт-контролер і статичні ресурси
- `src/` — БД, репозиторії, авторизація, пошта
- `views/` — сторінки та шаблони

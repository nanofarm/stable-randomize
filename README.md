# StableRandom — Open Source

Открытый исходный код бота для проведения розыгрышей в Telegram с Mini App.

> Эта версия содержит **весь рабочий код** — можно склонировать, запустить у себя
> и убедиться что в коде нет ничего вредного: никаких скрытых вебхуков, кражи подписчиков,
> сбора лишних данных. Все внешние запросы идут только к Telegram Bot API.

## Что внутри

| Компонент | Стек | Описание |
|---|---|---|
| `bot/` | Python 3.12 + aiogram 3 | Telegram-бот + внутренний HTTP-сервер |
| `api/` | PHP 8.4 + Laravel 12 + MySQL | REST API |
| `webapp/` | Vue 3 + ES-модули | Telegram Mini App (без сборщика) |
| `nginx/` | nginx:alpine | Статика + проксирование PHP-FPM |

## Возможности

- **Розыгрыши** — создание через Mini App: название, описание, фото, призы, число победителей, дата окончания
- **Подписка на каналы** — участники обязаны подписаться на привязанные каналы
- **Задания** — участники выполняют задания за дополнительные билеты (скриншоты, проверка организатором)
- **Честный рандом** — `random_int()` (криптографически безопасный PRNG), взвешенный по билетам
- **Аудит розыгрышей** — SHA-256 снапшот участников + HMAC-SHA256 подпись результатов
- **Верификация** — команда `/check_hash` показывает хеши файлов на сервере для сравнения с GitHub
- **Реферальная система** — бонусные билеты за приглашённых друзей
- **Бонус за ник** — доп. билеты за ключевое слово в нике Telegram
- **Аналитика** — география, статистика участников, CSV-экспорт
- **Рассылка** — массовое сообщение всем участникам розыгрыша
- **Автозавершение** — розыгрыши завершаются по `end_date` через Laravel Scheduler
- **Публикация в каналы** — посты с кнопкой участия прямо в каналах

## Быстрый запуск (Docker)

```bash
# 1. Клонировать
git clone <repo-url> stablerandom && cd stablerandom

# 2. Скопировать env
cp .env.example .env

# 3. Заполнить .env:
#    BOT_TOKEN     — получить у @BotFather
#    WEBAPP_URL    — публичный HTTPS-URL (например https://yourdomain.com)
#    APP_KEY       — оставь пустым, сгенерируем ниже
#    GIVEAWAY_AUDIT_SECRET — openssl rand -base64 32

# 4. Поднять контейнеры
docker compose up -d --build

# 5. Сгенерировать APP_KEY и миграции
docker compose exec api php artisan key:generate
docker compose exec api php artisan migrate --force

# 6. Права на storage
docker compose exec api chown -R www-data:www-data /var/www/storage
docker compose exec api chmod -R 775 /var/www/storage
```

После этого:
- бот реагирует на `/start` и показывает кнопку «Открыть приложение»
- сделайте бота админом канала → канал появится в Mini App
- из Mini App можно создавать розыгрыши, участвовать, смотреть аналитику

## Проверка честности (для пользователей)

```bash
# 1. Скачай исходный код с GitHub
git clone <repo-url> && cd stablerandom

# 2. Посчитай хеши ключевых файлов
sha256sum api/app/Models/Giveaway.php
sha256sum api/app/Services/GiveawayDrawAuditService.php
sha256sum api/app/Console/Commands/FinishExpiredGiveaways.php

# 3. Напиши боту /check_hash — сравни хеши
# Если совпадают — на сервере тот же код что и на GitHub
```

## Структура

```
.
├── bot/
│   ├── main.py                    # точка входа + регистрация команд
│   ├── config.py                  # env-конфиг
│   ├── core/
│   │   ├── api_client.py          # HTTP-клиент к Laravel API
│   │   ├── server.py              # внутренний HTTP-сервер (отправка постов, загрузка фото)
│   │   ├── states.py              # FSM-состояния
│   │   └── throttle.py            # анти-флуд middleware
│   ├── handlers/
│   │   ├── common.py              # /start, /menu, /help, /check_hash
│   │   ├── channels.py            # подключение каналов
│   │   ├── participants.py        # участие
│   │   ├── tasks.py               # задания + проверка организатором
│   │   ├── giveaways.py           # управление розыгрышами
│   │   ├── launch_draw.py         # запуск и розыгрыш
│   │   ├── photo.py               # загрузка фото
│   │   └── admin.py               # админские команды
│   └── keyboards/
├── api/
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── GiveawayController.php    ← основная логика
│   │   │   └── AnalyticsController.php
│   │   ├── Http/Middleware/
│   │   │   ├── VerifyTelegramInitData.php  # HMAC-проверка initData
│   │   │   └── VerifyCsrfToken.php         # CSRF + bot-auth
│   │   ├── Models/
│   │   │   ├── Giveaway.php          ← алгоритм выбора победителей
│   │   │   ├── Participant.php
│   │   │   ├── Prize.php
│   │   │   └── ...
│   │   ├── Services/
│   │   │   ├── GiveawayDrawAuditService.php  ← криптоподпись результатов
│   │   │   ├── BotSenderService.php
│   │   │   └── TelegramAuth.php
│   │   └── Console/Commands/
│   │       └── FinishExpiredGiveaways.php    ← автозавершение
│   ├── database/migrations/
│   └── routes/api.php
├── webapp/
│   ├── index.html
│   ├── css/style.css
│   └── js/
│       ├── main.js          # Vue app + hash-router
│       ├── store.js          # API-клиент + friendlyError()
│       ├── pages/            # Home, My, Create, Detail, Analytics
│       └── components/       # DatePicker, WinnerReveal
├── nginx/
├── docker-compose.yml
└── .env.example
```

## Как проверить «нет ли наёба»

1. `bot/core/api_client.py` — бот ходит только на ваш API (`API_URL`)
2. `api/app/Services/TelegramService.php` — единственный внешний URL: `api.telegram.org`
3. `webapp/js/store.js` — фронтенд ходит только на `/api` (same origin)
4. `grep -rE "https?://" --include='*.py' --include='*.php' --include='*.js'` — покажет все URL в проекте
5. `/check_hash` в боте — хеши файлов на сервере совпадают с GitHub

## Лицензия

MIT

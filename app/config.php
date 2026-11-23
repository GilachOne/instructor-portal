<?php
// ключи лежат рядом в .env (в git и в веб-корне их нет)
$env = static function (string $key, string $default = '') {
    static $vals = null;
    if ($vals === null) {
        $vals = [];
        $file = __DIR__ . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $vals[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    return $vals[$key] ?? $default;
};

return [
    'sheet_id'         => $env('GOOGLE_SHEET_ID', ''),
    'google_key'       => __DIR__ . '/' . $env('GOOGLE_KEY_FILE', 'google-key.json'),
    'db'               => __DIR__ . '/data/app.sqlite',
    // true — первый вход инструктора подтверждает админ; админы из «Верификации» входят сразу
    'require_approval' => false,
    'session_days'     => 365,
    'cache_ttl'        => 300,
    'tz'               => 'Europe/Moscow',
    'photo_dir'        => __DIR__ . '/data/photos',   // вне public_html: отдаём через /p/… только вошедшим
    'photo_base'       => $env('APP_BASE_URL', 'https://example.com'),

    // Подтверждение номера звонком (zvonok.com)
    'zvonok' => [
        'enabled'             => true,
        'mode'                => 'tellcode',   // flashcall — код в номере; tellcode — робот диктует код
        'campaign_id'         => $env('ZVONOK_CAMPAIGN_ID'),
        'confirm_campaign_id' => $env('ZVONOK_CONFIRM_CAMPAIGN_ID'),
        'public_key'          => $env('ZVONOK_PUBLIC_KEY'),
        'code_ttl'            => 600,          // сколько живёт код, секунд
        'resend_wait'         => 40,           // пауза между звонками, секунд
        'per_day'             => 5,            // сколько звонков в сутки на один номер
    ],

    // Проверка фотоотчётов ИИ (Yandex AI Studio, агент на Qwen3.6-35B)
    'ai' => [
        'enabled'   => true,
        'base_url'  => $env('YANDEX_BASE_URL', 'https://ai.api.cloud.yandex.net/v1'),
        'api_key'   => $env('YANDEX_API_KEY'),
        'project'   => $env('YANDEX_PROJECT_ID'),
        'prompt_id' => $env('YANDEX_PROMPT_ID'),
        'max_side'  => 512,   // до какого размера ужимать фото перед отправкой
    ],

    // Хранилище S3 (Timeweb Cloud): фото и документы
    's3' => [
        'endpoint'   => $env('S3_ENDPOINT', 'https://s3.twcstorage.ru'),
        'region'     => $env('S3_REGION', 'ru-1'),
        'bucket'     => $env('S3_BUCKET'),
        'access_key' => $env('S3_ACCESS_KEY'),
        'secret_key' => $env('S3_SECRET_KEY'),
        'prefix'     => $env('S3_PREFIX', 'photos/'),
    ],

    // чем подписываем ссылки на фото для таблицы (открываются без входа)
    'link_secret' => $env('APP_LINK_SECRET'),

    // сквозной вход в кабинет рефералки (общий секрет с referal.example.com)
    'ref' => [
        'url'    => $env('REF_URL', 'https://referral.example.com'),
        'secret' => $env('REF_SSO_SECRET'),
    ],

    'sheets' => [
        'exam'       => 'Экзамен',
        'verify'     => 'Верификация',
        'reasons'    => 'Причины',
        'inspectors' => 'Инспектор',
        'booking'    => 'Бронь авто',
        'repair'     => 'Ремонт авто',
        'fleet'      => 'Автопарк',
        'photo'      => 'Фотоотчет',
    ],
];

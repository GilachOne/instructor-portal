<?php
/**
 * Переписывает ссылки на фото в листе «Фотоотчет» на новый адрес и добавляет ключ,
 * чтобы руководитель открывал снимок прямо из таблицы, без входа в портал.
 * Запуск разовый: /usr/bin/php /srv/team/app/links-fix.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (cfg('link_secret') === '') exit("Не задан APP_LINK_SECRET в .env\n");

$sheet = cfg('sheets')['photo'];
$rows = sheet_get($sheet . '!A2:H');
$fixed = 0;

foreach ($rows as $n => $row) {
    $line = $n + 2;
    $new = [];
    $changed = false;
    for ($i = 4; $i <= 7; $i++) {                       // колонки E–H: четыре фото
        $url = (string)($row[$i] ?? '');
        if ($url === '' || !str_contains($url, '/p/')) { $new[] = $url; continue; }
        $rel = explode('?', substr($url, strpos($url, '/p/') + 3))[0];
        $fresh = photo_url($rel);
        if ($fresh !== $url) $changed = true;
        $new[] = $fresh;
    }
    if (!$changed) continue;
    http_json('PUT', sheet_url($sheet . "!E$line:H$line", '?valueInputOption=USER_ENTERED'),
        ['values' => [$new]], g_token());
    $fixed++;
}

echo "строк обновлено: $fixed\n";

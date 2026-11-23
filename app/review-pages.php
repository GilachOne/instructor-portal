<?php
// Страницы сбора отзывов на school.example.com (Тильда): лист «Страницы отзывов» в таблице «Отзывы Яндекс»
// → /srv/team/public/review/branches.js, его читает HTML-блок на каждой странице филиала.
// Крон drive-team раз в 10 минут. Если Google недоступен или лист пустой — старый файл не трогаем.
declare(strict_types=1);
require __DIR__ . '/lib.php';

$sheetId = env('REVIEWS_SHEET_ID', '');
$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId . '/values/'
     . rawurlencode('Страницы отзывов!A2:I200');
$rows = http_json('GET', $url, null, g_token())['values'] ?? [];

$out = [];
foreach ($rows as $r) {
    // колонки: A город, B филиал, C адрес страницы, D Яндекс, E 2ГИС, F Google, G ВК, H QR (не нужен странице), I телефон филиала
    [$city, $name, $page, $ya, $gis, $google, $vk, , $phone] = array_map('trim', array_pad($r, 9, ''));
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if ($name === '' || !preg_match('~school\.example\.com/review-([a-z0-9-]+)~', $page, $m)) continue;
    $out[$m[1]] = array_filter([
        'name' => $name, 'city' => $city,
        'yandex' => $ya, 'gis' => $gis, 'google' => $google, 'vk' => $vk, 'phone' => $phone,
    ], fn($v) => $v !== '');
}
if (!$out) { fwrite(STDERR, "review-pages: лист пустой, файл не обновлён\n"); exit(1); }

$dir = dirname(__DIR__) . '/public/review';
if (!is_dir($dir)) mkdir($dir, 0755, true);
// .js, а не .json: <script src> с другого домена работает без CORS и правок nginx
$json = 'window.DRIVE_REVIEW=' . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n";
$file = $dir . '/branches.js';
if (!is_file($file) || file_get_contents($file) !== $json) {
    file_put_contents($file . '.tmp', $json);
    rename($file . '.tmp', $file);
    echo date('c') . ' review-pages: ' . count($out) . " филиалов\n";
}

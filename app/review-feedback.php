<?php
// Приём отзывов с оценкой 1–4 со страниц school.example.com/review-<филиал>/ (Тильда).
// POST https://team.example.com/api/review-feedback, тело — JSON в text/plain (без CORS-preflight).
// Пишет строку в лист «Отзывы QR» таблицы «Отзывы Яндекс»; копия — data/review-feedback.jsonl.
declare(strict_types=1);

function review_feedback(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (preg_match('~^https://(school\.example\.com|www\.school\.example\.com|example\.tilda\.ws)$~', $origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);

    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) fail('Bad request');
    if (!empty($in['website'])) out(['ok' => true]);            // ловушка для ботов: поле скрыто от людей

    $cut = fn($v, $n) => mb_substr(trim((string)$v), 0, $n);
    $slug   = $cut($in['branch'] ?? '', 60);
    $rating = (int)($in['rating'] ?? 0);
    $text   = $cut($in['text'] ?? '', 3000);
    $name   = $cut($in['name'] ?? '', 100);
    $phone  = $cut($in['phone'] ?? '', 30);
    $src    = $cut($in['src'] ?? '', 100);
    if ($rating < 1 || $rating > 4) fail('Оценка от 1 до 4');
    if (mb_strlen($text) < 3) fail('Напишите, что пошло не так');
    if (strlen(norm_phone($phone)) !== 11) fail('Укажите номер телефона, чтобы мы могли связаться с вами');

    // филиал — только из справочника страниц
    $branches = [];
    $js = @file_get_contents(dirname(__DIR__) . '/public/review/branches.js');
    if ($js && preg_match('~=\s*(\{.*\})\s*;~s', $js, $m)) $branches = json_decode($m[1], true) ?: [];
    if (!isset($branches[$slug])) fail('Неизвестный филиал');
    $branch = $branches[$slug]['name'];

    // не больше 5 отзывов в час с одного адреса
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
    $key = 'rvfb_' . md5($ip);
    $hits = array_filter(kv_get($key, 3600) ?? [], fn($t) => $t > time() - 3600);
    if (count($hits) >= 5) fail('Слишком много отзывов, попробуйте позже', 429);
    $hits[] = time();
    kv_set($key, array_values($hits));

    $tel = norm_phone($phone);
    $row = [date('d.m.Y H:i'), $branch, $rating, $text, $name, $tel !== '' ? '+' . $tel : $phone, $src, 'Новый'];
    @file_put_contents(__DIR__ . '/data/review-feedback.jsonl',
        json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    $sheetId = env('REVIEWS_SHEET_ID', '');
    try {
        http_json('POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId . '/values/'
            . rawurlencode('Отзывы QR!A:H') . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',
            ['values' => [$row]], g_token());
    } catch (Throwable $e) {
        error_log('[review-feedback] ' . $e->getMessage());       // строка осталась в jsonl
    }
    out(['ok' => true]);
}

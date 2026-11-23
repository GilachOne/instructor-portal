<?php
/**
 * Догоняющая проверка фотоотчётов: обычно они проверяются сразу после отправки,
 * сюда попадают только те, где модель была недоступна.
 * Запуск по расписанию (Crontab):
 *   /usr/bin/php /var/www/app-root/app/ai-check.php >> /var/www/app-root/app/data/ai.log 2>&1
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$lock = fopen(cfg('photo_dir') . '/../ai.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);

foreach (q("SELECT id FROM reports WHERE kind = 'photo' AND ai_at IS NULL ORDER BY created_at LIMIT 10")->fetchAll() as $r) {
    try {
        $sum = check_photo_report($r['id']);
        echo date('c'), ' ', $r['id'], ' -> ', $sum['status'] ?? '?', ' ', implode('; ', $sum['notes'] ?? []), PHP_EOL;
    } catch (Throwable $e) {
        echo date('c'), ' ОШИБКА ', $r['id'], ': ', $e->getMessage(), PHP_EOL;
    }
}

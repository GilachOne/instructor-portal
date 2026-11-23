<?php
/**
 * Заливает в хранилище фото, которых там ещё нет, и чистит локальные копии старше 30 дней
 * (локальный файл — только кэш для превью и проверки ИИ, оригинал живёт в S3).
 * Запуск вручную или по расписанию:
 *   /usr/bin/php /srv/team/app/s3-sync.php
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

if (!s3_on()) exit("S3 не настроен: заполни S3_BUCKET, S3_ACCESS_KEY и S3_SECRET_KEY в .env\n");

$dir = cfg('photo_dir');
$up = $skip = $err = $cleaned = 0;

foreach (glob($dir . '/*/*') ?: [] as $file) {
    $rel = substr($file, strlen($dir) + 1);
    if (str_contains($rel, '.t.jpg')) continue;          // превью не храним, они делаются заново
    try {
        if (s3_has(photo_key($rel))) {
            $skip++;
        } else {
            s3_put(photo_key($rel), (string)file_get_contents($file), (string)(getimagesize($file)['mime'] ?? 'image/jpeg'));
            $up++;
        }
        if (filemtime($file) < time() - 30 * 86400) { unlink($file); $cleaned++; }
    } catch (Throwable $e) {
        $err++;
        echo 'ошибка ', $rel, ': ', $e->getMessage(), PHP_EOL;
    }
}

echo "залито: $up, уже было: $skip, ошибок: $err, локальных копий удалено: $cleaned\n";

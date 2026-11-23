<?php
/**
 * Минимальный клиент S3 (Timeweb Cloud): подпись AWS SigV4 на curl, без composer.
 * Используется для хранения фотоотчётов и документов.
 */
declare(strict_types=1);

function s3_on(): bool
{
    $s = cfg('s3');
    return !empty($s['bucket']) && !empty($s['access_key']) && !empty($s['secret_key']);
}

/** Канонический запрос и заголовок Authorization по правилам AWS Signature V4 */
function s3_sign(string $method, string $path, array $query, array $headers, string $payloadHash): array
{
    $s = cfg('s3');
    $host = parse_url($s['endpoint'], PHP_URL_HOST);
    $now = gmdate('Ymd\THis\Z');
    $day = substr($now, 0, 8);
    $scope = "$day/{$s['region']}/s3/aws4_request";

    $headers['host'] = $host;
    $headers['x-amz-date'] = $now;
    $headers['x-amz-content-sha256'] = $payloadHash;
    $headers = array_change_key_case($headers);
    ksort($headers);

    $canonHeaders = '';
    foreach ($headers as $k => $v) $canonHeaders .= $k . ':' . trim((string)$v) . "\n";
    $signed = implode(';', array_keys($headers));

    ksort($query);
    $canonQuery = [];
    foreach ($query as $k => $v) $canonQuery[] = rawurlencode($k) . '=' . rawurlencode((string)$v);

    $canonPath = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    $canonical = implode("\n", [$method, '/' . $canonPath, implode('&', $canonQuery), $canonHeaders, $signed, $payloadHash]);
    $toSign = implode("\n", ['AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonical)]);

    $k = hash_hmac('sha256', $day, 'AWS4' . $s['secret_key'], true);
    $k = hash_hmac('sha256', $s['region'], $k, true);
    $k = hash_hmac('sha256', 's3', $k, true);
    $k = hash_hmac('sha256', 'aws4_request', $k, true);
    $sig = hash_hmac('sha256', $toSign, $k);

    $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$s['access_key']}/$scope, SignedHeaders=$signed, Signature=$sig";
    return $headers;
}

/** Выполняет подписанный запрос к бакету; возвращает [код, тело] */
function s3_call(string $method, string $key, string $body = '', array $extra = [], array $query = []): array
{
    $s = cfg('s3');
    $path = '/' . trim($s['bucket'], '/') . '/' . ltrim($key, '/');
    $headers = s3_sign($method, $path, $query, $extra, hash('sha256', $body));

    $url = rtrim($s['endpoint'], '/') . '/' . trim($s['bucket'], '/') . '/'
         . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    if ($query) $url .= '?' . http_build_query($query);

    $head = [];
    foreach ($headers as $k => $v) if ($k !== 'host') $head[] = "$k: $v";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $head,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) throw new RuntimeException('S3: ' . $err);
    return [$code, (string)$out];
}

function s3_put(string $key, string $bytes, string $type = 'application/octet-stream'): void
{
    [$code, $body] = s3_call('PUT', $key, $bytes, ['content-type' => $type]);
    if ($code < 200 || $code >= 300) throw new RuntimeException("S3 PUT $key: $code " . substr($body, 0, 200));
}

function s3_get(string $key): ?string
{
    [$code, $body] = s3_call('GET', $key);
    return $code === 200 ? $body : null;
}

function s3_has(string $key): bool
{
    [$code] = s3_call('HEAD', $key);
    return $code === 200;
}

function s3_delete(string $key): void
{
    s3_call('DELETE', $key);
}

/** Временная ссылка на приватный объект: отдаём её браузеру, трафик идёт мимо нашего сервера */
function s3_link(string $key, int $ttl = 900): string
{
    $s = cfg('s3');
    $host = parse_url($s['endpoint'], PHP_URL_HOST);
    $now = gmdate('Ymd\THis\Z');
    $day = substr($now, 0, 8);
    $scope = "$day/{$s['region']}/s3/aws4_request";

    $query = [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => $s['access_key'] . '/' . $scope,
        'X-Amz-Date'          => $now,
        'X-Amz-Expires'       => (string)$ttl,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($query);
    $canonQuery = [];
    foreach ($query as $k => $v) $canonQuery[] = rawurlencode($k) . '=' . rawurlencode($v);

    $path = '/' . trim($s['bucket'], '/') . '/' . ltrim($key, '/');
    $canonPath = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    $canonical = implode("\n", ['GET', $canonPath, implode('&', $canonQuery), "host:$host\n", 'host', 'UNSIGNED-PAYLOAD']);
    $toSign = implode("\n", ['AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonical)]);

    $k = hash_hmac('sha256', $day, 'AWS4' . $s['secret_key'], true);
    $k = hash_hmac('sha256', $s['region'], $k, true);
    $k = hash_hmac('sha256', 's3', $k, true);
    $k = hash_hmac('sha256', 'aws4_request', $k, true);

    return rtrim($s['endpoint'], '/') . $canonPath . '?' . implode('&', $canonQuery)
         . '&X-Amz-Signature=' . hash_hmac('sha256', $toSign, $k);
}

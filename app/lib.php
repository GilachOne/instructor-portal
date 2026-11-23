<?php
declare(strict_types=1);

$CFG = require __DIR__ . '/config.php';
date_default_timezone_set($CFG['tz']);

function cfg(string $k)
{
    global $CFG;
    return $CFG[$k];
}

/** Ключи из app/.env — в коде и в git их нет */
function env(string $key, string $default = ''): string
{
    static $vals;
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
}

// ---------- База ----------

function db(): PDO
{
    static $pdo;
    if ($pdo) return $pdo;
    $path = cfg('db');
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            phone TEXT PRIMARY KEY,
            pass_hash TEXT,
            status TEXT NOT NULL,            -- pending | active | blocked
            created_at TEXT, approved_by TEXT, last_login TEXT
        );
        CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT PRIMARY KEY,
            phone TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            user_agent TEXT
        );
        CREATE TABLE IF NOT EXISTS reports (
            id TEXT PRIMARY KEY,
            phone TEXT NOT NULL,
            created_at TEXT NOT NULL,
            row_json TEXT NOT NULL,
            synced INTEGER NOT NULL DEFAULT 0,
            sync_error TEXT
        );
        CREATE TABLE IF NOT EXISTS phone_codes (
            phone TEXT PRIMARY KEY,
            code_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            verified_at INTEGER,
            sent_today INTEGER NOT NULL DEFAULT 0,
            day TEXT
        );
        CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT, t INTEGER);
        CREATE TABLE IF NOT EXISTS attempts (k TEXT PRIMARY KEY, n INTEGER, t INTEGER);
    ");
    // вид отчёта: exam | booking | repair — у каждого свой лист
    $cols = array_column($pdo->query('PRAGMA table_info(reports)')->fetchAll(), 'name');
    if (!in_array('kind', $cols, true)) $pdo->exec("ALTER TABLE reports ADD COLUMN kind TEXT NOT NULL DEFAULT 'exam'");
    // результат проверки моделью и отпечатки фото
    foreach (['ai_status TEXT', 'ai_notes TEXT', 'ai_json TEXT', 'ai_at TEXT', 'phash TEXT'] as $col) {
        if (!in_array(explode(' ', $col)[0], $cols, true)) $pdo->exec('ALTER TABLE reports ADD COLUMN ' . $col);
    }
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function kv_get(string $k, ?int $ttl)
{
    $r = q('SELECT v, t FROM kv WHERE k = ?', [$k])->fetch();
    if (!$r || ($ttl !== null && $r['t'] < time() - $ttl)) return null;
    return json_decode($r['v'], true);
}

function kv_set(string $k, $v): void
{
    q('INSERT OR REPLACE INTO kv (k, v, t) VALUES (?, ?, ?)', [$k, json_encode($v, JSON_UNESCAPED_UNICODE), time()]);
}

// ---------- Google Sheets ----------

function http_json(string $method, string $url, $body = null, ?string $token = null, bool $form = false): array
{
    $ch = curl_init($url);
    $headers = [];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) {
        if ($form) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Google недоступен: ' . $err);
    $data = json_decode($raw, true) ?? [];
    if ($code >= 300) throw new RuntimeException('Google ' . $code . ': ' . ($data['error']['message'] ?? $raw));
    return $data;
}

function g_token(): string
{
    $cached = kv_get('g_token', 3000);
    if ($cached) return $cached;
    $key = json_decode(file_get_contents(cfg('google_key')), true);
    $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => $key['token_uri'],
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    openssl_sign($unsigned, $sig, $key['private_key'], 'sha256WithRSAEncryption');
    $r = http_json('POST', $key['token_uri'], [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $unsigned . '.' . $b64($sig),
    ], null, true);
    kv_set('g_token', $r['access_token']);
    return $r['access_token'];
}

function sheet_url(string $range, string $suffix = ''): string
{
    return 'https://sheets.googleapis.com/v4/spreadsheets/' . cfg('sheet_id') . '/values/' . rawurlencode($range) . $suffix;
}

function sheet_get(string $range): array
{
    return http_json('GET', sheet_url($range), null, g_token())['values'] ?? [];
}

function sheet_append(string $range, array $rows): void
{
    http_json('POST', sheet_url($range, ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS'),
        ['values' => $rows], g_token());
}

// Справочник из таблицы с кэшем; если Google недоступен — отдаём последнюю сохранённую версию
function cached(string $key, callable $load, bool $force = false): array
{
    if (!$force && ($v = kv_get($key, cfg('cache_ttl'))) !== null) return $v;
    try {
        $v = $load();
        kv_set($key, $v);
        return $v;
    } catch (Throwable $e) {
        $stale = kv_get($key, null);
        if ($stale !== null) return $stale;
        throw $e;
    }
}

function norm_phone(string $s): string
{
    $d = preg_replace('/\D+/', '', $s);
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    if (strlen($d) === 10) $d = '7' . $d;
    return $d;
}

/** @return array<string, array{name:string, role:string}> телефон => человек */
function verified_people(bool $force = false): array
{
    return cached('verify', function () {
        $out = [];
        foreach (sheet_get(cfg('sheets')['verify'] . '!A2:C') as $r) {
            $name = trim($r[0] ?? '');
            $phone = norm_phone($r[1] ?? '');
            if ($name === '' || strlen($phone) !== 11) continue;
            $role = mb_strtolower(trim($r[2] ?? '')) === 'админ' ? 'admin' : 'instructor';
            $out[$phone] = ['name' => $name, 'role' => $role];
        }
        return $out;
    }, $force);
}

function list_column(string $range): array
{
    $out = [];
    foreach (sheet_get($range) as $r) {
        $v = trim($r[0] ?? '');
        if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
    }
    return $out;
}

function reasons(bool $force = false): array
{
    return cached('reasons', fn() => list_column(cfg('sheets')['reasons'] . '!A1:A'), $force);
}

function inspectors(bool $force = false): array
{
    $list = cached('inspectors', fn() => list_column(cfg('sheets')['inspectors'] . '!A2:A'), $force);
    sort($list, SORT_LOCALE_STRING);
    return $list;
}

/** @return list<array{name:string, kind:string, kpp:string}> лист «Автопарк»: A — ТС, B — Авто/Мото, C — АКПП/МКПП */
function fleet(bool $force = false): array
{
    return cached('fleet', function () {
        $out = [];
        foreach (sheet_get(cfg('sheets')['fleet'] . '!A2:C') as $r) {
            $name = trim($r[0] ?? '');
            if ($name === '') continue;
            $moto = mb_stripos(trim($r[1] ?? ''), 'мото') === 0;
            $kpp = mb_strtoupper(trim($r[2] ?? ''));
            $out[] = ['name' => $name, 'kind' => $moto ? 'moto' : 'auto', 'kpp' => in_array($kpp, ['АКПП', 'МКПП'], true) ? $kpp : ''];
        }
        return $out;
    }, $force);
}

// ---------- Подтверждение номера звонком ----------

function zvonok_key(): string
{
    $cfg = cfg('zvonok');
    if ($cfg['public_key'] !== '') return $cfg['public_key'];
    $file = __DIR__ . '/zvonok-key.txt';
    return is_file($file) ? trim(file_get_contents($file)) : '';
}

/** Заказывает звонок, возвращает код или null при ошибке сервиса */
function zvonok_call(string $phone): ?string
{
    $cfg = cfg('zvonok');
    $url = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/flashcall/?' . http_build_query([
        'campaign_id' => $cfg['campaign_id'],
        'phone'       => '+' . $phone,
        'public_key'  => zvonok_key(),
    ]);
    try {
        $r = http_json('GET', $url);
    } catch (Throwable $e) {
        error_log('[zvonok] ' . $e->getMessage());
        return null;
    }
    if (($r['status'] ?? '') !== 'ok' || empty($r['data']['pincode'])) {
        error_log('[zvonok] ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        return null;
    }
    return (string)$r['data']['pincode'];
}

/**
 * Заказ звонка с кодом. $soft = true — для автозвонка при вводе номера:
 * молча возвращает null, если звонить нельзя (ограничения или сбой сервиса).
 */
function request_call_code(string $phone, bool $soft = false): ?array
{
    $cfg = cfg('zvonok');
    $stop = function (string $msg) use ($soft) { if ($soft) return null; fail($msg); };
    if (!$cfg['enabled']) return $stop('Подтверждение по звонку временно отключено');
    $row = q('SELECT created_at, sent_today, day FROM phone_codes WHERE phone = ?', [$phone])->fetch();
    $today = date('Y-m-d');
    $sentToday = ($row && $row['day'] === $today) ? (int)$row['sent_today'] : 0;
    if ($row && time() - (int)$row['created_at'] < $cfg['resend_wait']) {
        return $stop('Звонок уже отправлен. Подождите ' . ($cfg['resend_wait'] - (time() - (int)$row['created_at'])) . ' секунд.');
    }
    if ($sentToday >= $cfg['per_day']) return $stop('Слишком много попыток за сегодня. Обратитесь к администратору.');

    $code = zvonok_call($phone);
    if ($code === null) return $stop('Не получилось позвонить. Попробуйте ещё раз через минуту.');
    q('INSERT OR REPLACE INTO phone_codes (phone, code_hash, created_at, attempts, verified_at, sent_today, day)
       VALUES (?, ?, ?, 0, NULL, ?, ?)',
        [$phone, password_hash($code, PASSWORD_DEFAULT), time(), $sentToday + 1, $today]);
    return ['sent' => true, 'wait' => $cfg['resend_wait'], 'mode' => $cfg['mode'] ?? 'flashcall'];
}

/** Второй способ: человек сам звонит на проверочный номер. Возвращает номера для звонка */
function confirm_start(string $phone): array
{
    $cfg = cfg('zvonok');
    $url = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/confirm/?' . http_build_query([
        'campaign_id' => $cfg['confirm_campaign_id'],
        'phone'       => '+' . $phone,
        'public_key'  => zvonok_key(),
    ]);
    try {
        $r = http_json('GET', $url);
    } catch (Throwable $e) {
        error_log('[zvonok confirm] ' . $e->getMessage());
        fail('Сервис проверки недоступен, попробуйте позже', 502);
    }
    $numbers = $r['data']['allowed_phones_for_call'] ?? [];
    if (($r['status'] ?? '') !== 'ok' || !$numbers) fail('Не удалось начать проверку, попробуйте позже', 502);
    kv_set('confirm:' . $phone, ['call_id' => $r['data']['call_id'] ?? null, 'at' => time()]);
    return ['numbers' => array_values($numbers)];
}

/** Дозвонился ли человек на проверочный номер */
function confirm_done(string $phone): bool
{
    $cfg = cfg('zvonok');
    $started = kv_get('confirm:' . $phone, 1800);
    if (!$started) return false;
    $url = 'https://zvonok.com/manager/cabapi_external/api/v1/phones/calls_by_phone/?' . http_build_query([
        'campaign_id' => $cfg['confirm_campaign_id'],
        'phone'       => '+' . $phone,
        'public_key'  => zvonok_key(),
    ]);
    try {
        $calls = http_json('GET', $url);
    } catch (Throwable $e) {
        return false;
    }
    foreach ($calls as $c) {
        if (($c['call_id'] ?? null) != $started['call_id']) continue;
        $ok = in_array($c['status'] ?? '', ['compl_finished', 'pincode_ok'], true);
        if (!$ok) return false;
        // номер подтверждён — отмечаем так же, как после кода
        q('INSERT OR REPLACE INTO phone_codes (phone, code_hash, created_at, attempts, verified_at, sent_today, day)
           VALUES (?, ?, ?, 0, ?, 0, ?)', [$phone, '-', time(), time(), date('Y-m-d')]);
        return true;
    }
    return false;
}

function check_call_code(string $phone, string $code): void
{
    $cfg = cfg('zvonok');
    $row = q('SELECT code_hash, created_at, attempts FROM phone_codes WHERE phone = ?', [$phone])->fetch();
    if (!$row || time() - (int)$row['created_at'] > $cfg['code_ttl']) fail('Код устарел, закажите звонок заново');
    if ((int)$row['attempts'] >= 5) fail('Слишком много неверных попыток, закажите звонок заново');
    if (!password_verify($code, $row['code_hash'])) {
        q('UPDATE phone_codes SET attempts = attempts + 1 WHERE phone = ?', [$phone]);
        fail('Неверный код. Это последние 4 цифры номера, с которого звонили, или цифры, которые продиктовал робот.');
    }
    q('UPDATE phone_codes SET verified_at = ? WHERE phone = ?', [time(), $phone]);
}

/** Номер подтверждён звонком и с этого момента прошло не больше 15 минут */
function phone_verified(string $phone): bool
{
    $t = q('SELECT verified_at FROM phone_codes WHERE phone = ?', [$phone])->fetchColumn();
    return $t && time() - (int)$t < 900;
}

function clear_phone_code(string $phone): void
{
    q('DELETE FROM phone_codes WHERE phone = ?', [$phone]);
}

// ---------- Сессии ----------

const COOKIE = 'dc_session';

function start_session(string $phone): void
{
    $token = bin2hex(random_bytes(32));
    $exp = time() + cfg('session_days') * 86400;
    q('INSERT INTO sessions (token_hash, phone, expires_at, user_agent) VALUES (?, ?, ?, ?)',
        [hash('sha256', $token), $phone, $exp, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]);
    q('UPDATE users SET last_login = ? WHERE phone = ?', [date('c'), $phone]);
    set_session_cookie($token, $exp);
}

function set_session_cookie(string $token, int $exp): void
{
    setcookie(COOKIE, $token, [
        'expires'  => $exp,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Текущий пользователь или null. Доступ пропадает сразу, как только человека убирают из «Верификации». */
function current_user(): ?array
{
    $token = $_COOKIE[COOKIE] ?? '';
    if ($token === '') return null;
    $hash = hash('sha256', $token);
    $s = q('SELECT phone, expires_at FROM sessions WHERE token_hash = ?', [$hash])->fetch();
    if (!$s || $s['expires_at'] < time()) return null;
    $u = q('SELECT status FROM users WHERE phone = ?', [$s['phone']])->fetch();
    $person = verified_people()[$s['phone']] ?? null;
    if (!$u || $u['status'] !== 'active' || !$person) return null;
    // скользящее продление: активный пользователь не разлогинивается никогда
    $full = time() + cfg('session_days') * 86400;
    if ($full - $s['expires_at'] > 86400) {
        q('UPDATE sessions SET expires_at = ? WHERE token_hash = ?', [$full, $hash]);
        set_session_cookie($token, $full);
    }
    return ['phone' => $s['phone'], 'name' => $person['name'], 'role' => $person['role']];
}

function end_session(): void
{
    if (!empty($_COOKIE[COOKIE])) q('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $_COOKIE[COOKIE])]);
    set_session_cookie('', 1);
}

// Не больше $max попыток за 15 минут на ключ
function throttle(string $key, int $max = 8): void
{
    $r = q('SELECT n, t FROM attempts WHERE k = ?', [$key])->fetch();
    if ($r && $r['t'] > time() - 900) {
        if ($r['n'] >= $max) fail('Слишком много попыток. Подождите 15 минут.', 429);
        q('UPDATE attempts SET n = n + 1 WHERE k = ?', [$key]);
    } else {
        q('INSERT OR REPLACE INTO attempts (k, n, t) VALUES (?, 1, ?)', [$key, time()]);
    }
}

// ---------- Отчёты ----------

// Текст, начинающийся с = + - @, таблица считает формулой — экранируем
function cell_text(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return preg_match('/^[=+\-@]/', $s) && $s !== '-' ? "'" . $s : $s;
}

// ---------- Фото ----------

// Порядок = порядок колонок E…I листа «Фотоотчет».
// Два кадра по диагонали с противоположных углов показывают все четыре стороны кузова
require __DIR__ . '/s3.php';

const PHOTO_SLOTS = [
    'selfie' => 'Внешний вид инструктора',
    'fl'     => 'Перед и левый борт',
    'rr'     => 'Зад и правый борт',
    'cabin'  => 'Салон через открытые двери',
];
// набор одинаковый и для будней, и для экзамена
const PHOTO_EXAM = ['selfie', 'fl', 'rr', 'cabin'];

/** Сохраняет загруженный файл в data/photos/ГГГГ-ММ/ и возвращает путь вида «2026-09/uuid.jpg» */
function save_photo(array $file, string $slot): string
{
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) fail('Файл «' . $slot . '» не загрузился, попробуйте ещё раз');
    if ($file['size'] > 12 * 1024 * 1024) fail('Фото «' . $slot . '» слишком большое');
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) fail('Фото «' . $slot . '» в неизвестном формате');
    $ext = ['1' => 'gif', '2' => 'jpg', '3' => 'png', '18' => 'webp'][(string)$info[2]] ?? 'jpg';
    $rel = date('Y-m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $path = cfg('photo_dir') . '/' . $rel;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    if (!move_uploaded_file($file['tmp_name'], $path)) fail('Не удалось сохранить фото');
    chmod($path, 0600);
    photo_upload($rel);
    return $rel;
}

/** Превью 400 px для списка отчётов: делается один раз и лежит рядом */
function photo_thumb(string $file): string
{
    $thumb = preg_replace('/\.(\w+)$/', '.t.jpg', $file);
    if (is_file($thumb)) return $thumb;
    $img = @imagecreatefromstring(file_get_contents($file));
    if (!$img) return $file;
    $w = imagesx($img);
    $h = imagesy($img);
    $k = min(1, 400 / max($w, $h));
    $small = imagescale($img, (int)round($w * $k), (int)round($h * $k));
    imagejpeg($small ?: $img, $thumb, 72);
    imagedestroy($img);
    if ($small) imagedestroy($small);
    chmod($thumb, 0600);
    return $thumb;
}

function photo_url(string $rel): string
{
    return cfg('photo_base') . '/p/' . $rel . '?k=' . photo_token($rel);
}

// ---------- Проверка фотоотчётов моделью ----------

/** Отпечаток картинки 8×8: одинаковые и почти одинаковые фото дают близкие отпечатки */
function photo_hash(string $file): string
{
    $img = @imagecreatefromstring(file_get_contents($file));
    if (!$img) return '';
    $small = imagescale($img, 8, 8);
    imagedestroy($img);
    $vals = [];
    for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) {
        $c = imagecolorat($small, $x, $y);
        $vals[] = (($c >> 16 & 255) * 3 + ($c >> 8 & 255) * 6 + ($c & 255)) / 10;
    }
    imagedestroy($small);
    $avg = array_sum($vals) / 64;
    $bits = '';
    foreach ($vals as $v) $bits .= $v > $avg ? '1' : '0';
    return implode('', array_map(fn($b) => dechex(bindec($b)), str_split($bits, 4)));
}

function hash_distance(string $a, string $b): int
{
    if (strlen($a) !== strlen($b) || $a === '') return 99;
    $d = 0;
    for ($i = 0; $i < strlen($a); $i++) $d += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
    return $d;
}

/** Не присылал ли инструктор это же фото раньше */
function find_repeat(string $phone, array $hashes, string $exceptId): ?string
{
    $rows = q("SELECT id, created_at, phash FROM reports WHERE kind = 'photo' AND phone = ? AND id <> ? AND phash IS NOT NULL
               ORDER BY created_at DESC LIMIT 60", [$phone, $exceptId])->fetchAll();
    foreach ($rows as $r) {
        $old = json_decode($r['phash'], true) ?: [];
        foreach ($hashes as $slot => $h) {
            if (!empty($old[$slot]) && hash_distance($h, $old[$slot]) <= 5) {
                return date('d.m.Y', strtotime($r['created_at']));
            }
        }
    }
    return null;
}

/** Ужимает фото для модели: чем меньше картинка, тем дешевле запрос */
function photo_data_uri(string $file, int $max): string
{
    $img = @imagecreatefromstring(file_get_contents($file));
    if (!$img) return '';
    $k = min(1, $max / max(imagesx($img), imagesy($img)));
    $small = imagescale($img, (int)round(imagesx($img) * $k));
    ob_start();
    imagejpeg($small ?: $img, null, 80);
    $bin = ob_get_clean();
    imagedestroy($img);
    if ($small) imagedestroy($small);
    return 'data:image/jpeg;base64,' . base64_encode($bin);
}

/** Отправляет фото агенту Yandex AI Studio и возвращает его JSON-ответ */
function ai_review(array $photos): array
{
    $cfg = cfg('ai');
    if (!$cfg['enabled'] || !$cfg['api_key']) throw new RuntimeException('Проверка ИИ не настроена');
    $content = [['type' => 'input_text', 'text' => 'Проверь фотоотчёт. Фото идут по порядку: ' . implode(', ', array_keys($photos)) . '.']];
    foreach ($photos as $file) {
        $uri = photo_data_uri($file, (int)$cfg['max_side']);
        if ($uri) $content[] = ['type' => 'input_image', 'image_url' => $uri];
    }
    $ch = curl_init(rtrim($cfg['base_url'], '/') . '/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'prompt' => ['id' => $cfg['prompt_id']],
            'input'  => [['role' => 'user', 'content' => $content]],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Api-Key ' . $cfg['api_key'],
            'Content-Type: application/json',
            'x-project-id: ' . $cfg['project'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) throw new RuntimeException('AI HTTP ' . $code . ': ' . substr((string)$raw, 0, 300));
    $j = json_decode($raw, true);
    $text = '';
    foreach ($j['output'] ?? [] as $o) foreach ($o['content'] ?? [] as $c) $text .= $c['text'] ?? '';
    $text = trim(preg_replace('/^```(json)?|```$/mi', '', $text));
    $verdict = json_decode($text, true);
    if (!is_array($verdict)) throw new RuntimeException('модель вернула не JSON: ' . mb_substr($text, 0, 200));
    $verdict['_usage'] = $j['usage'] ?? null;
    return $verdict;
}

/** Короткий человекочитаемый итог для таблицы и приложения */
function ai_summary(array $v, ?string $repeat): array
{
    $status = $v['итог'] ?? 'проверить вручную';
    $notes = array_values(array_filter((array)($v['причины'] ?? [])));
    foreach ((array)($v['салон']['замечания'] ?? []) as $n) if ($n && $n !== 'не видно') $notes[] = 'Салон: ' . $n;
    $body = $v['кузов']['чистота'] ?? '';
    if ($body === 'грязно') $notes[] = 'Кузов грязный';
    if (($v['кузов']['повреждения'] ?? '') === 'есть') $notes[] = 'Похоже на повреждение кузова';
    if (($v['selfie']['бейдж'] ?? '') === 'нет') $notes[] = 'Не видно бейджа';
    if (($v['selfie']['есть_человек'] ?? true) === false) $notes[] = 'На селфи нет человека';
    if ($repeat) {
        $notes[] = 'Фото уже присылали ' . $repeat;
        $status = 'проверить вручную';
    }
    if ($status !== 'принято' && !$notes) $notes[] = 'Нужна ручная проверка';
    return ['status' => $status, 'notes' => array_values(array_unique($notes))];
}

/** Полная проверка одного фотоотчёта: отпечатки, модель, запись в базу и в таблицу */
function check_photo_report(string $id): ?array
{
    $r = q("SELECT id, phone, row_json, phash FROM reports WHERE id = ? AND kind = 'photo'", [$id])->fetch();
    if (!$r) return null;
    $row = json_decode($r['row_json'], true);
    $slots = array_keys(PHOTO_SLOTS);
    $files = [];
    $hashes = $r['phash'] ? json_decode($r['phash'], true) : [];
    foreach ($slots as $i => $slot) {
        $url = $row[4 + $i] ?? '';
        if (!$url) continue;
        $rel = explode("?", substr($url, strpos($url, "/p/") + 3))[0];
        $file = photo_local($rel);
        if (!$file) continue;
        $files[PHOTO_SLOTS[$slot]] = $file;
        if (empty($hashes[$slot])) $hashes[$slot] = photo_hash($file);
    }
    q('UPDATE reports SET phash = ? WHERE id = ?', [json_encode($hashes), $id]);
    if (!$files) {
        q("UPDATE reports SET ai_at = ?, ai_status = 'нет фото' WHERE id = ?", [date('c'), $id]);
        return ['status' => 'нет фото', 'notes' => []];
    }
    $repeat = find_repeat($r['phone'], $hashes, $id);
    $verdict = ai_review($files);                  // бросит исключение — проверим позже по расписанию
    $sum = ai_summary($verdict, $repeat);
    $notes = implode('; ', $sum['notes']);
    q('UPDATE reports SET ai_status = ?, ai_notes = ?, ai_json = ?, ai_at = ? WHERE id = ?',
        [$sum['status'], $notes, json_encode($verdict, JSON_UNESCAPED_UNICODE), date('c'), $id]);
    try {
        $ids = sheet_get(cfg('sheets')['photo'] . '!A:A');
        foreach ($ids as $n => $cell) {
            if (($cell[0] ?? '') !== $id) continue;
            $line = $n + 1;
            http_json('PUT', sheet_url(cfg('sheets')['photo'] . "!J$line:L$line", '?valueInputOption=USER_ENTERED'),
                ['values' => [[$sum['status'], cell_text($notes), date('d.m.Y H:i')]]], g_token());
            break;
        }
    } catch (Throwable $e) {
        error_log('[ai] таблица: ' . $e->getMessage());
    }
    return $sum;
}

// id отчёта генерирует телефон — повторная отправка того же отчёта не создаёт дубль
function report_id(array $in): string
{
    $id = (string)($in['id'] ?? '');
    if (!preg_match('/^[0-9a-f-]{36}$/', $id)) fail('Некорректный отчёт');
    if (q('SELECT 1 FROM reports WHERE id = ?', [$id])->fetch()) out(['ok' => true, 'synced' => true, 'duplicate' => true]);
    return $id;
}

function save_report(string $kind, string $id, array $row, array $me): void
{
    q('INSERT INTO reports (id, kind, phone, created_at, row_json) VALUES (?, ?, ?, ?, ?)',
        [$id, $kind, $me['phone'], date('c'), json_encode($row, JSON_UNESCAPED_UNICODE)]);
    $sync = sync_reports();
    $synced = (bool)q('SELECT synced FROM reports WHERE id = ?', [$id])->fetchColumn();
    out(['ok' => true, 'synced' => $synced, 'sync_error' => $sync['error'] ?? null]);
}

function find_vehicle(string $name): array
{
    foreach (fleet() as $v) if ($v['name'] === $name) return $v;
    fail('Выберите транспорт из списка');
}

/** @return array<string,string> ТС => кто забронировал, на дату «22.09.2026» (таблица + ещё не записанные) */
function bookings_on(string $date): array
{
    $taken = [];
    foreach (sheet_get(cfg('sheets')['booking'] . '!C3:F') as $r) {
        if (trim($r[0] ?? '') === $date && !empty($r[3])) $taken[trim($r[3])] = trim($r[1] ?? '');
    }
    foreach (q("SELECT row_json FROM reports WHERE kind = 'booking' AND synced = 0")->fetchAll() as $r) {
        $x = json_decode($r['row_json'], true);
        if ($x[2] === $date) $taken[$x[5]] = $x[3];
    }
    return $taken;
}

// «08:45» → «8:45», как писал бот
function short_time(string $t): string
{
    return (int)substr($t, 0, 2) . substr($t, 2);
}

function sync_reports(): array
{
    // один синхронизатор за раз, иначе параллельные запросы задублируют строки в таблице
    $lock = fopen(dirname(cfg('db')) . '/sync.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) return ['synced' => 0, 'left' => -1];
    $pending = q('SELECT id, kind, row_json FROM reports WHERE synced = 0 ORDER BY created_at LIMIT 50')->fetchAll();
    if (!$pending) return ['synced' => 0, 'left' => 0];
    $synced = 0;
    $error = null;
    foreach (['exam', 'booking', 'repair', 'photo'] as $kind) {
        $group = array_values(array_filter($pending, fn($r) => $r['kind'] === $kind));
        if (!$group) continue;
        try {
            sheet_append(cfg('sheets')[$kind] . '!A:M', array_map(fn($r) => json_decode($r['row_json'], true), $group));
            $in = implode(',', array_fill(0, count($group), '?'));
            q("UPDATE reports SET synced = 1, sync_error = NULL WHERE id IN ($in)", array_column($group, 'id'));
            $synced += count($group);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            q('UPDATE reports SET sync_error = ? WHERE synced = 0 AND kind = ?', [$error, $kind]);
        }
    }
    return ['synced' => $synced, 'left' => count($pending) - $synced] + ($error ? ['error' => $error] : []);
}

// ---------- Ответы API ----------

function out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    // телефон получает ответ сразу, а долгая работа (проверка фото) идёт уже после
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { @ob_end_flush(); @flush(); }
    if (!empty($GLOBALS['after_response'])) {
        ignore_user_abort(true);
        set_time_limit(180);
        try { ($GLOBALS['after_response'])(); } catch (Throwable $e) { error_log('[after] ' . $e->getMessage()); }
    }
    exit;
}

function fail(string $msg, int $code = 400): void
{
    out(['error' => $msg], $code);
}

// ---------- Фото в S3 ----------

/** Ключ объекта в бакете: photos/2026-10/<hash>.jpg */
function photo_key(string $rel): string
{
    return cfg('s3')['prefix'] . $rel;
}

/** Кладёт фото в хранилище. Локальный файл остаётся кэшем: он нужен ИИ и превью. */
function photo_upload(string $rel): void
{
    if (!s3_on()) return;
    $file = cfg('photo_dir') . '/' . $rel;
    if (!is_file($file)) return;
    try {
        s3_put(photo_key($rel), (string)file_get_contents($file), (string)(getimagesize($file)['mime'] ?? 'image/jpeg'));
    } catch (Throwable $e) {
        error_log('[s3] ' . $e->getMessage());   // не роняем отчёт: файл лежит локально, зальём позже
    }
}

/** Путь к локальному файлу; если его почистили, тянет обратно из хранилища */
function photo_local(string $rel): ?string
{
    $file = cfg('photo_dir') . '/' . $rel;
    if (is_file($file)) return $file;
    if (!s3_on()) return null;
    $bytes = s3_get(photo_key($rel));
    if ($bytes === null) return null;
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
    file_put_contents($file, $bytes);
    chmod($file, 0600);
    return $file;
}

/**
 * Ключ ссылки на фото: по нему руководитель открывает снимок прямо из таблицы, без входа.
 * Привязан к файлу, подобрать нельзя, отзывается сменой APP_LINK_SECRET.
 */
function photo_token(string $rel): string
{
    return substr(hash_hmac('sha256', $rel, cfg('link_secret')), 0, 24);
}

function photo_token_ok(string $rel, string $token): bool
{
    return cfg('link_secret') !== '' && hash_equals(photo_token($rel), $token);
}

<?php
declare(strict_types=1);
require __DIR__ . '/../app/lib.php';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Фото лежат в приватном хранилище. Открывает либо вошедший, либо тот, у кого есть
// ссылка с ключом из таблицы: ключ привязан к файлу и подобрать его нельзя.
if (preg_match('#^/p/(\d{4}-\d{2}/[0-9a-f]{32}\.(jpe?g|png|webp))$#', $path, $m)) {
    $rel = $m[1];
    if (!photo_token_ok($rel, (string)($_GET['k'] ?? '')) && !current_user()) {
        http_response_code(403);
        exit('Нужно войти в приложение «Автошкола»');
    }
    // оригинал отдаёт само хранилище по временной ссылке — быстрее и мимо нашего сервера
    if (!isset($_GET['s']) && s3_on() && s3_has(photo_key($rel))) {
        header('Location: ' . s3_link(photo_key($rel)), true, 302);
        exit;
    }
    $file = photo_local($rel);
    if (!$file) { http_response_code(404); exit('Фото не найдено'); }
    if (isset($_GET['s'])) $file = photo_thumb($file);   // превью для списка
    header('Content-Type: ' . (getimagesize($file)['mime'] ?? 'image/jpeg'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=86400');
    readfile($file);
    exit;
}

// Отзывы 1–4 звезды со страниц school.example.com/review-* (чужой домен, без входа и X-App)
if ($path === '/api/review-feedback') {
    require __DIR__ . '/../app/review-feedback.php';
    review_feedback();
}

if (strpos($path, '/api/') !== 0) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/app.html');
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
// защита от CSRF: POST принимаем только от нашего JS
if ($method === 'POST' && ($_SERVER['HTTP_X_APP'] ?? '') !== '1') fail('Bad request', 403);

$route = $method . ' ' . substr($path, 4);
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip = trim(explode(',', $ip)[0]);

try {
    switch ($route) {

    // ---------- Вход ----------

    case 'POST /auth/check': {
        throttle('check:' . $ip, 30);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        // нет в кэше — перечитываем таблицу: человека могли только что добавить
        $person = verified_people()[$phone] ?? verified_people(true)[$phone] ?? null;
        if (!$person) out(['state' => 'not_found']);
        $u = q('SELECT pass_hash, status FROM users WHERE phone = ?', [$phone])->fetch();
        // кому всё равно нужен код — звоним сразу, не дожидаясь второго запроса от телефона
        $callNow = fn(string $state) => out(['state' => $state, 'call' => request_call_code($phone, true)]);
        if (!$u) $callNow('new');
        if ($u['status'] === 'blocked') out(['state' => 'blocked']);
        if ($u['status'] === 'pending') out(['state' => 'pending']);
        if (!$u['pass_hash']) $callNow('set_password');
        out(['state' => 'password']);
    }

    // звонок с кодом: код — последние 4 цифры номера, с которого звонят
    case 'POST /auth/call': {
        throttle('call:' . $ip, 15);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        if (!isset(verified_people()[$phone]) && !isset(verified_people(true)[$phone])) {
            fail('Этого номера нет в списке. Обратитесь к администратору.');
        }
        out(request_call_code($phone));
    }

    // второй способ: человек сам звонит на проверочный номер
    case 'POST /auth/confirm': {
        throttle('confirm:' . $ip, 20);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        if (!isset(verified_people()[$phone]) && !isset(verified_people(true)[$phone])) {
            fail('Этого номера нет в списке. Обратитесь к администратору.');
        }
        out(confirm_start($phone));
    }

    case 'POST /auth/confirm/status': {
        throttle('confirmst:' . $ip, 120);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        out(['verified' => confirm_done($phone)]);
    }

    case 'POST /auth/call/check': {
        throttle('callcheck:' . $ip, 30);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        check_call_code($phone, trim((string)($in['code'] ?? '')));
        out(['ok' => true]);
    }

    case 'POST /auth/register': {
        throttle('reg:' . $ip, 10);
        $phone = norm_phone((string)($in['phone'] ?? ''));
        $pass = (string)($in['password'] ?? '');
        $person = verified_people(true)[$phone] ?? null;
        if (!$person) fail('Этого номера нет в списке. Обратитесь к администратору.');
        if (cfg('zvonok')['enabled'] && !phone_verified($phone)) fail('Сначала подтвердите номер звонком');
        if (mb_strlen($pass) < 6) fail('Пароль — минимум 6 символов');
        $u = q('SELECT pass_hash, status FROM users WHERE phone = ?', [$phone])->fetch();
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        if (!$u) {
            $status = (!cfg('require_approval') || $person['role'] === 'admin') ? 'active' : 'pending';
            q('INSERT INTO users (phone, pass_hash, status, created_at) VALUES (?, ?, ?, ?)', [$phone, $hash, $status, date('c')]);
            if ($status === 'pending') out(['state' => 'pending']);
        } elseif ($u['status'] === 'blocked') {
            fail('Доступ заблокирован администратором');
        } else {
            // номер подтверждён звонком — значит это владелец: задаём новый пароль
            q("UPDATE users SET pass_hash = ?, status = 'active' WHERE phone = ?", [$hash, $phone]);
            q('DELETE FROM sessions WHERE phone = ?', [$phone]);   // старые входы сбрасываем
        }
        clear_phone_code($phone);
        start_session($phone);
        out(['ok' => true]);
    }

    case 'POST /auth/login': {
        $phone = norm_phone((string)($in['phone'] ?? ''));
        throttle('login:' . $phone);
        throttle('loginip:' . $ip, 30);
        $u = q('SELECT pass_hash, status FROM users WHERE phone = ?', [$phone])->fetch();
        if (!$u || !$u['pass_hash'] || !password_verify((string)($in['password'] ?? ''), $u['pass_hash'])) {
            fail('Неверный пароль');
        }
        if ($u['status'] !== 'active') fail('Доступ ещё не подтверждён администратором');
        if (!isset(verified_people()[$phone])) fail('Этого номера больше нет в списке');
        q('DELETE FROM attempts WHERE k = ?', ['login:' . $phone]);
        start_session($phone);
        out(['ok' => true]);
    }

    case 'POST /auth/logout':
        end_session();
        out(['ok' => true]);
    }

    // ---------- Дальше только для вошедших ----------

    $me = current_user();
    if (!$me) fail('Нужно войти', 401);
    $isAdmin = $me['role'] === 'admin';

    switch ($route) {

    // Рефералка живёт отдельным сервисом, но человек этого не видит:
    // запросы идут на наш домен, а мы ходим к ней сервер к серверу и подписываем их.
    case 'GET /ref':
    case 'POST /ref/payout':
    case 'POST /ref/payout-update':
    case 'POST /ref/claim':
    case 'POST /ref/claim-cancel':
    case 'POST /ref/push-subscribe':
    case 'GET /ref/push-pending':
    case 'POST /ref/notify-settings':
    case 'GET /ref/stats': {
        $cfgRef = cfg('ref');
        if (($cfgRef['secret'] ?? '') === '') fail('Рефералка ещё не подключена', 503);
        // бюджет и аналитика программы — только руководству
        if ($route === 'GET /ref/stats' && !$isAdmin) fail('Нет доступа', 403);
        $map = [
            'GET /ref' => '/api/me',
            'POST /ref/payout' => '/api/payout',
            'POST /ref/payout-update' => '/api/payout/update',
            'POST /ref/claim' => '/api/claim',
            'POST /ref/claim-cancel' => '/api/claim/cancel',
            'POST /ref/push-subscribe' => '/api/push/subscribe',
            'GET /ref/push-pending' => '/api/push/pending',
            'POST /ref/notify-settings' => '/api/notify-settings',
            'GET /ref/stats' => '/api/stats',
        ];
        $target = $map[$route];
        $payload = $method === 'POST' ? json_encode($in, JSON_UNESCAPED_UNICODE) : '';
        $ts = time();
        $sig = hash_hmac('sha256', $me['phone'] . '|' . $ts . '|' . $target . '|' . hash('sha256', $payload), $cfgRef['secret']);
        $query = !empty($_GET['fresh']) ? '?fresh=1' : '';   // подпись — по пути, без параметров

        $ch = curl_init(rtrim($cfgRef['url'], '/') . $target . $query);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Int-Phone: ' . $me['phone'],
                'X-Int-Ts: ' . $ts,
                'X-Int-Sig: ' . $sig,
                'X-Int-Name: ' . $me['name'],
                'X-Int-From: team',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 502;
        curl_close($ch);
        if ($raw === false) fail('Рефералка не отвечает, попробуйте позже', 502);
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo $raw;
        exit;
    }

    // Запасной вариант: открыть кабинет рефералки на её домене (вход без повторного звонка)
    case 'GET /ref-link': {
        $ref = cfg('ref');
        if (($ref['secret'] ?? '') === '') fail('Рефералка ещё не подключена', 503);
        $exp = time() + 120;
        $sig = hash_hmac('sha256', $me['phone'] . '|' . $me['name'] . '|team|' . $exp, $ref['secret']);
        $to = preg_replace('/[^a-z]/', '', (string)($_GET['to'] ?? ''));   // сразу на нужный экран
        out(['url' => rtrim($ref['url'], '/') . '/sso?' . http_build_query(array_filter([
            'p' => $me['phone'], 'n' => $me['name'], 'r' => 'team', 'e' => $exp, 's' => $sig, 'to' => $to,
        ]))]);
    }

    case 'GET /me': {
        if (q("SELECT 1 FROM reports WHERE synced = 0 LIMIT 1")->fetch()) sync_reports();
        $instructors = [];
        if ($isAdmin) {
            $instructors = array_values(array_unique(array_column(verified_people(), 'name')));
            sort($instructors, SORT_LOCALE_STRING);
        }
        $pendingCount = $isAdmin ? (int)q("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn() : 0;
        $photoToday = (bool)q("SELECT 1 FROM reports WHERE kind = 'photo' AND phone = ? AND created_at >= ?", [$me['phone'], date('Y-m-d')])->fetch();
        out([
            'user'        => $me,
            'reasons'     => reasons(),
            'inspectors'  => inspectors(),
            'fleet'       => fleet(),
            'instructors' => $instructors,
            'pending'     => $pendingCount,
            'photo_today' => $photoToday,
        ]);
    }

    case 'POST /report': {
        $id = report_id($in);

        $date = DateTime::createFromFormat('!Y-m-d', (string)($in['exam_date'] ?? ''));
        if (!$date || $date > new DateTime('tomorrow') || $date < new DateTime('-60 days')) fail('Проверьте дату сдачи');
        $start = (string)($in['start_time'] ?? '');
        $end = (string)($in['end_time'] ?? '');
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) fail('Укажите время начала и окончания');
        if ($end <= $start) fail('Время окончания должно быть позже начала');

        $instructor = $me['name'];
        if ($isAdmin && !empty($in['instructor'])) {
            $instructor = (string)$in['instructor'];
            if (!in_array($instructor, array_column(verified_people(), 'name'), true)) fail('Инструктор не найден в списке');
        }
        $inspector = trim((string)($in['inspector'] ?? ''));
        if (!in_array($inspector, inspectors(), true)) fail('Выберите инспектора из списка или добавьте нового');
        $cadet = trim((string)($in['cadet'] ?? ''));
        if (mb_strlen($cadet) < 3) fail('Укажите ФИО курсанта');
        $try = (int)($in['try'] ?? 0);
        if ($try < 1 || $try > 5) fail('Укажите номер попытки');
        $passed = ($in['status'] ?? '') === 'passed';
        if (!$passed && ($in['status'] ?? '') !== 'failed') fail('Укажите результат экзамена');
        $comment = trim((string)($in['comment'] ?? ''));
        $error = '-';
        if (!$passed) {
            $error = (string)($in['error'] ?? '');
            if (!in_array($error, reasons(), true)) fail('Выберите ошибку');
            if (mb_strlen($comment) < 3) fail('Опишите ошибку в комментарии');
        }

        // формат строки — как у бота, от него считается лист «Стата»
        $row = [
            $id,
            date('d.m.Y H:i:s'),
            $date->format('d.m.Y'),
            $instructor,
            cell_text($cadet),
            $passed ? 'Сдал' : 'Не сдал',
            $error,
            short_time($start),
            short_time($end),
            $inspector,
            cell_text($comment),
            $me['name'],
            $try,
        ];
        save_report('exam', $id, $row, $me);
    }

    case 'GET /bookings': {
        $date = DateTime::createFromFormat('!Y-m-d', (string)($_GET['date'] ?? ''));
        if (!$date) fail('Укажите дату');
        out(['taken' => bookings_on($date->format('d.m.Y'))]);
    }

    case 'POST /booking': {
        $id = report_id($in);
        $date = DateTime::createFromFormat('!Y-m-d', (string)($in['date'] ?? ''));
        if (!$date || $date < new DateTime('-7 days') || $date > new DateTime('+180 days')) fail('Проверьте дату брони');
        $vehicle = find_vehicle((string)($in['auto'] ?? ''));
        $kpp = (string)($in['kpp'] ?? '');
        if ($vehicle['kind'] === 'moto') $kpp = 'Мотоциклы';
        if (!in_array($kpp, ['АКПП', 'МКПП', 'Мотоциклы'], true)) fail('Выберите тип КПП');
        $taken = bookings_on($date->format('d.m.Y'))[$vehicle['name']] ?? null;
        if ($taken === $me['name']) fail('Вы уже забронировали ' . $vehicle['name'] . ' на эту дату');
        if ($taken && empty($in['force'])) out(['conflict' => $taken]);
        // формат как у бота: id, дата отчёта, дата брони, инструктор, КПП, автомобиль
        save_report('booking', $id, [$id, date('d.m.Y H:i:s'), $date->format('d.m.Y'), $me['name'], $kpp, $vehicle['name']], $me);
    }

    case 'POST /repair': {
        $id = report_id($in);
        $vehicle = find_vehicle((string)($in['auto'] ?? ''));
        $urgency = (string)($in['urgency'] ?? '');
        if (!in_array($urgency, ['Планово', 'Срочно'], true)) fail('Укажите срочность ремонта');
        $problem = trim((string)($in['problem'] ?? ''));
        if (mb_strlen($problem) < 3) fail('Опишите проблему');
        // формат как у бота: id, дата отчёта, срочность, инструктор, проблема, автомобиль
        save_report('repair', $id, [$id, date('d.m.Y H:i:s'), $urgency, $me['name'], cell_text($problem), $vehicle['name']], $me);
    }

    case 'POST /photo': {
        // multipart: поля в $_POST, снимки в $_FILES
        $id = report_id($_POST);
        $type = (string)($_POST['type'] ?? '');
        if (!in_array($type, ['Будни', 'Экзамен'], true)) fail('Выберите тип фотоотчёта');
        $comment = trim((string)($_POST['comment'] ?? ''));
        $urls = [];
        foreach (PHOTO_SLOTS as $slot => $label) {
            $need = in_array($slot, $type === 'Экзамен' ? PHOTO_EXAM : array_keys(PHOTO_SLOTS), true);
            $sent = !empty($_FILES[$slot]['name']);
            if (!$sent) {
                if ($need) fail('Не хватает фото: ' . $label);
                $urls[] = '';
                continue;
            }
            $urls[] = photo_url(save_photo($_FILES[$slot], $label));
        }
        // проверку запускаем сразу после ответа телефону — инструктор видит результат через пару секунд
        $GLOBALS['after_response'] = function () use ($id) { check_photo_report($id); };
        save_report('photo', $id, array_merge([$id, date('d.m.Y H:i:s'), $type, $me['name']], $urls, [cell_text($comment)]), $me);
    }

    // статус проверки одного отчёта — телефон опрашивает его сразу после отправки
    case 'GET /report/status': {
        $r = q('SELECT ai_status, ai_notes FROM reports WHERE id = ? AND phone = ?',
            [(string)($_GET['id'] ?? ''), $me['phone']])->fetch();
        out(['status' => $r['ai_status'] ?? null, 'notes' => $r['ai_notes'] ?? '']);
    }

    case 'GET /reports': {
        $rows = q('SELECT kind, row_json, synced, ai_status, ai_notes FROM reports WHERE phone = ? ORDER BY created_at DESC LIMIT 50', [$me['phone']])->fetchAll();
        out(['reports' => array_map(function ($r) {
            $x = array_map(fn($v) => is_string($v) ? ltrim($v, "'") : $v, json_decode($r['row_json'], true));
            $base = ['kind' => $r['kind'], 'created' => $x[1], 'synced' => (bool)$r['synced']];
            if ($r['kind'] === 'booking') return $base + ['date' => $x[2], 'kpp' => $x[4], 'auto' => $x[5]];
            if ($r['kind'] === 'repair') return $base + ['urgency' => $x[2], 'problem' => $x[4], 'auto' => $x[5]];
            if ($r['kind'] === 'photo') {
                $photos = [];
                foreach (array_values(PHOTO_SLOTS) as $i => $label) if (!empty($x[4 + $i])) $photos[] = ['label' => $label, 'url' => $x[4 + $i]];
                return $base + [
                    'type' => $x[2], 'photos' => $photos, 'comment' => $x[4 + count(PHOTO_SLOTS)] ?? '',
                    'ai_status' => $r['ai_status'], 'ai_notes' => $r['ai_notes'],
                ];
            }
            return $base + [
                'date' => $x[2], 'instructor' => $x[3], 'cadet' => $x[4], 'status' => $x[5], 'error' => $x[6],
                'start' => $x[7], 'end' => $x[8], 'inspector' => $x[9], 'comment' => $x[10], 'try' => $x[12],
            ];
        }, $rows)]);
    }

    case 'POST /inspector': {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if (!preg_match('/^[\p{Cyrillic}\-]+( [\p{Cyrillic}\-]+){1,3}$/u', $name)) fail('Введите ФИО инспектора кириллицей, например: Иванов Иван Иванович');
        $name = mb_convert_case($name, MB_CASE_TITLE);
        $existing = inspectors(true);
        foreach ($existing as $e) {
            if (mb_strtolower($e) === mb_strtolower($name)) out(['ok' => true, 'name' => $e, 'inspectors' => $existing]);
        }
        sheet_append(cfg('sheets')['inspectors'] . '!A:A', [[$name]]);
        out(['ok' => true, 'name' => $name, 'inspectors' => inspectors(true)]);
    }

    // своей машины нет в «Автопарке» — инструктор добавляет её сам, список общий
    case 'POST /vehicle': {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) fail('Напишите марку и госномер, например: Лада Гранта А123АА');
        if (!preg_match('/^[\p{Cyrillic}\p{Latin}0-9][\p{Cyrillic}\p{Latin}0-9 \-\.\/]*$/u', $name)) fail('В названии только буквы, цифры, пробел и дефис');
        $kind = ($in['kind'] ?? '') === 'moto' ? 'Мото' : 'Авто';
        $list = fleet(true);
        foreach ($list as $v) {
            if (mb_strtolower($v['name']) === mb_strtolower($name)) out(['ok' => true, 'name' => $v['name'], 'fleet' => $list]);
        }
        sheet_append(cfg('sheets')['fleet'] . '!A:C', [[$name, $kind, '']]);
        out(['ok' => true, 'name' => $name, 'fleet' => fleet(true)]);
    }
    }

    // ---------- Админка ----------

    if (!$isAdmin) fail('Нет доступа', 403);

    switch ($route) {

    case 'GET /admin/users': {
        $people = verified_people(true);
        $users = [];
        foreach (q('SELECT phone, status, pass_hash IS NOT NULL AS has_pass, created_at, last_login FROM users')->fetchAll() as $u) {
            $users[$u['phone']] = $u;
        }
        $list = [];
        foreach ($people as $phone => $p) {
            $u = $users[$phone] ?? null;
            $list[] = [
                'phone' => (string)$phone, 'name' => $p['name'], 'role' => $p['role'],
                'status' => $u ? ($u['status'] === 'active' && !$u['has_pass'] ? 'reset' : $u['status']) : 'none',
                'created_at' => $u['created_at'] ?? null, 'last_login' => $u['last_login'] ?? null,
            ];
        }
        $unsynced = (int)q('SELECT COUNT(*) FROM reports WHERE synced = 0')->fetchColumn();
        $syncError = $unsynced ? q('SELECT sync_error FROM reports WHERE synced = 0 LIMIT 1')->fetchColumn() : null;
        out(['users' => $list, 'unsynced' => $unsynced, 'sync_error' => $syncError]);
    }

    case 'POST /admin/user': {
        $phone = norm_phone((string)($in['phone'] ?? ''));
        $action = (string)($in['action'] ?? '');
        if (!q('SELECT 1 FROM users WHERE phone = ?', [$phone])->fetch()) fail('Пользователь ещё не регистрировался');
        switch ($action) {
            case 'approve':
            case 'unblock':
                q("UPDATE users SET status = 'active', approved_by = ? WHERE phone = ?", [$me['name'], $phone]);
                break;
            case 'block':
                q("UPDATE users SET status = 'blocked' WHERE phone = ?", [$phone]);
                q('DELETE FROM sessions WHERE phone = ?', [$phone]);
                break;
            case 'reset':
                // человек задаст новый пароль при следующем входе
                q("UPDATE users SET pass_hash = NULL, status = 'active' WHERE phone = ?", [$phone]);
                q('DELETE FROM sessions WHERE phone = ?', [$phone]);
                break;
            case 'delete':
                q('DELETE FROM users WHERE phone = ?', [$phone]);
                q('DELETE FROM sessions WHERE phone = ?', [$phone]);
                break;
            default:
                fail('Неизвестное действие');
        }
        out(['ok' => true]);
    }

    case 'POST /admin/refresh': {
        verified_people(true);
        reasons(true);
        inspectors(true);
        fleet(true);
        out(['ok' => true, 'sync' => sync_reports()]);
    }
    }

    fail('Not found', 404);
} catch (Throwable $e) {
    error_log('[drive-challenge] ' . $e->getMessage());
    fail('Ошибка сервера: ' . $e->getMessage(), 500);
}

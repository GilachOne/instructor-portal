<?php
/**
 * Сбор отзывов о «Автошколе» с Яндекс Карт, 2ГИС и Zoon в таблицу «Отзывы Яндекс», лист «Отзывы AI».
 * Филиалы и ссылки на карточки берутся из листа «Справочник» (A — филиал, B — Яндекс, C — 2ГИС, E — Zoon),
 * строка без ссылок вроде «КАЗАНЬ» задаёт город для филиалов ниже.
 * Дописываются только отзывы, которых ещё нет в колонке ID, так что запускать можно сколько угодно раз.
 * Запуск вручную или по расписанию:
 *   /usr/bin/php /srv/team/app/reviews.php            — все филиалы
 *   /usr/bin/php /srv/team/app/reviews.php --dry      — только посчитать, в таблицу не писать
 *   /usr/bin/php /srv/team/app/reviews.php --report   — разметить «Сводную» формулами (один раз)
 *   /usr/bin/php /srv/team/app/reviews.php --report --formulas — только обновить формулы строки 2
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';

const RV_SHEET  = 'Отзывы AI';
const RV_DIR    = 'Справочник';
const RV_REPORT = 'Сводная';
const RV_UA     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
// площадки — так они пишутся в колонке B «Отзывов AI» и в шапке «Сводной»
const RV_SITES  = ['Яндекс Карты', '2ГИС', 'Zoon', 'Google'];
// оценка отзыва (1–5) в колонке M — по ней негатив уходит в отдел заботы
const RV_EXTRA  = 'Рейтинг';
// служебный лист: общее число отзывов и рейтинг каждого филиала на каждой площадке — как на карточке, на сегодня
const RV_TOTALS = 'Площадки';
const RV_TOTALS_HEAD = ['Город', 'Филиал', 'Площадка', 'Отзывов всего', 'Рейтинг', 'Отзывы × рейтинг', 'Обновлено'];

/** Общие цифры карточки, которые площадка отдала при последнем запросе (забирает основной цикл) */
function rv_card(?int $count = null, $rating = null): ?array
{
    static $last = null;
    if ($count === null) { $r = $last; $last = null; return $r; }
    $last = ['count' => $count, 'rating' => is_numeric($rating) ? round((float)$rating, 2) : null];
    return null;
}

$dry = in_array('--dry', $argv, true);
echo '== ', date('d.m.Y H:i'), $dry ? ' (без записи)' : '', PHP_EOL;
// отзывы раньше этой даты не берём (и не листаем площадки дальше неё): --from=2026-10-01
$fromArg = preg_grep('/^--from=/', $argv);
define('RV_FROM', strtotime($fromArg ? substr(reset($fromArg), 7) : env('REVIEWS_FROM', '2026-10-01')));
// с --from (догрузка истории) листаем до конца, даже если страница целиком уже записана;
// в ежедневном сборе останавливаемся на первой такой странице
define('RV_FULL', (bool)$fromArg);
$sheetId = env('REVIEWS_SHEET_ID', '');

function rv_url(string $sheetId, string $range, string $suffix = ''): string
{
    return 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId . '/values/' . rawurlencode($range) . $suffix;
}

function rv_get(string $url, ?string $cookies = null, string $fromIp = '', string $proxy = ''): array
{
    $ch = curl_init($url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . RV_UA, 'Accept-Language: ru-RU,ru;q=0.9', 'Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
        CURLOPT_ENCODING       => '',
    ];
    if ($cookies) $opt += [CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies];
    // запрос с отдельного адреса сервера (доп. IPv4 для Zoon)
    if ($fromIp !== '') $opt += [CURLOPT_INTERFACE => $fromIp, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
    // или через прокси: http://логин:пароль@хост:порт либо socks5h://…
    if ($proxy !== '') $opt += [CURLOPT_PROXY => $proxy, CURLOPT_CONNECTTIMEOUT => 20];
    curl_setopt_array($ch, $opt);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$raw];
}

/** Подпись запроса к fetchReviews — так же её считает сам сайт Яндекс Карт */
function ya_sig(array $p): string
{
    ksort($p);
    $s = implode('&', array_map(fn($k) => $k . '=' . rawurlencode((string)$p[$k]), array_keys($p)));
    $n = 5381;
    for ($i = 0, $len = strlen($s); $i < $len; $i++) $n = (($n * 33) & 0xFFFFFFFF) ^ ord($s[$i]);
    return (string)$n;
}

/** Отзывы организации на Яндексе, от новых к старым; листаем, пока не дойдём до уже записанных */
function ya_reviews(string $org, array $known): array
{
    $jar = tempnam(sys_get_temp_dir(), 'ya');
    try {
        [$code, $html] = rv_get("https://yandex.ru/maps/org/$org/reviews/", $jar);
        if (str_contains($html, 'showcaptcha')) throw new RuntimeException('Яндекс показал капчу');
        preg_match('/"csrfToken":"([^"]+)"/', $html, $c);
        preg_match('/"sessionId":"([^"]+)"/', $html, $s);
        if (!$c || !$s) throw new RuntimeException("Яндекс: не открылась страница отзывов ($code)");
        if (preg_match('/"ratingData":(\{[^{}]*\})/', $html, $rd)) {
            $rd = json_decode($rd[1], true) ?? [];
            if (isset($rd['reviewCount'])) rv_card((int)$rd['reviewCount'], $rd['ratingValue'] ?? null);
        }
        $out = [];
        for ($page = 1; $page <= 60; $page++) {
            $p = ['ajax' => 1, 'businessId' => $org, 'csrfToken' => $c[1], 'locale' => 'ru_RU',
                  'page' => $page, 'pageSize' => 50, 'ranking' => 'by_time', 'sessionId' => $s[1]];
            [, $raw] = rv_get('https://yandex.ru/maps/api/business/fetchReviews?' . http_build_query($p + ['s' => ya_sig($p)], '', '&', PHP_QUERY_RFC3986), $jar);
            $list = json_decode($raw, true)['data']['reviews'] ?? null;
            if ($list === null) throw new RuntimeException('Яндекс: непонятный ответ на странице ' . $page);
            $fresh = 0;
            foreach ($list as $r) {
                $id = 'ya:' . $r['reviewId'];
                // закреплённый отзыв стоит первым при любой дате — по нему не останавливаемся
                if (strtotime($r['updatedTime']) < RV_FROM) { if (empty($r['pinned'])) $old = true; continue; }
                if (isset($known[$id])) continue;
                $fresh++;
                $pid = $r['author']['publicId'] ?? '';
                $out[] = [
                    'id'     => $id,
                    'time'   => strtotime($r['updatedTime']),
                    // без публичного профиля автора Яндекс открывает только общую ленту отзывов филиала
                    'link'   => $pid ? "https://yandex.ru/maps/org/$org/reviews?reviews%5BpublicId%5D=$pid&utm_source=review"
                                        : "https://yandex.ru/maps/org/drayv/$org/reviews/",
                    'site'   => 'Яндекс Карты',
                    'rating' => $r['rating'] ?? '',
                    'author' => $r['author']['name'] ?? '',
                    'text'   => $r['text'] ?? '',
                    'answer' => (string)($r['businessComment']['text'] ?? ''),
                ];
            }
            if (count($list) < 50 || ($fresh === 0 && !RV_FULL) || !empty($old)) break;
            usleep(1200000);
        }
        return $out;
    } finally {
        @unlink($jar);
    }
}

/** Отзывы филиала в 2ГИС — через тот же открытый API, которым пользуется сам сайт */
function gis_reviews(string $place, array $known): array
{
    [$city, $firm] = explode('/', $place);   // «kazan/70000001067307775» из ссылки в справочнике
    $key = env('REVIEWS_2GIS_KEY', '6e7e1929-4ea9-4a5d-8c05-d601860389bd');
    $url = "https://public-api.reviews.2gis.com/2.0/branches/$firm/reviews?limit=50&sort_by=date_created&locale=ru_RU"
         . "&fields=meta.branch_rating,meta.branch_reviews_count&key=$key";
    $out = [];
    for ($page = 1; $url && $page <= 60; $page++) {
        [$code, $raw] = rv_get($url);
        $j = json_decode($raw, true);
        if ($code !== 200 || !isset($j['reviews'])) throw new RuntimeException("2ГИС: ответ $code");
        if ($page === 1 && isset($j['meta']['branch_reviews_count'])) {
            rv_card((int)$j['meta']['branch_reviews_count'], $j['meta']['branch_rating'] ?? null);
        }
        $fresh = 0;
        foreach ($j['reviews'] as $r) {
            $id = '2gis:' . $r['id'];
            if (strtotime($r['date_created']) < RV_FROM) { $old = true; continue; }
            if (isset($known[$id])) continue;
            $fresh++;
            $out[] = [
                'id'     => $id,
                'time'   => strtotime($r['date_created']),
                'link'   => gis_link($city, $firm, (string)$r['id']),
                'site'   => '2ГИС',
                'rating' => $r['rating'] ?? '',
                'author' => $r['user']['name'] ?? '',
                'text'   => $r['text'] ?? '',
                'answer' => (string)($r['official_answer']['text'] ?? ''),
            ];
        }
        if (($fresh === 0 && !RV_FULL) || !empty($old)) break;
        $url = $j['meta']['next_link'] ?? null;
        if ($url && !str_contains($url, 'key=')) $url .= '&key=' . $key;
        usleep(500000);
    }
    return $out;
}

/**
 * Отзывы филиала на Zoon — прямо из разметки страницы (до 50 штук, больше у филиалов не бывает).
 * Zoon банит частые запросы с серверных адресов (429). Основной путь — резидентный прокси (REVIEWS_ZOON_PROXY):
 * у него на каждый запрос свой «домашний» адрес, поэтому на 429 просто повторяем. Без прокси ходим с отдельного
 * IP (REVIEWS_ZOON_IP), медленно (REVIEWS_ZOON_PAUSE секунд между филиалами) и после первого 429 до завтра Zoon не трогаем.
 */
function zoon_reviews(string $page, array $known): array
{
    static $last = 0, $banned = false;
    if ($banned) throw new RuntimeException('Zoon: пропуск, сегодня уже был отказ 429');
    $proxy = env('REVIEWS_ZOON_PROXY');
    $base = rtrim(preg_replace('~(reviews/?)?([?#].*)?$~', '', $page), '/') . '/';
    $wait = $last + (int)env('REVIEWS_ZOON_PAUSE', $proxy ? '15' : '180') - time();
    if ($last && $wait > 0) sleep($wait);
    for ($try = 1; ; $try++) {
        [$code, $html] = $proxy ? rv_get($base . 'reviews/', null, '', $proxy) : rv_get($base . 'reviews/', null, env('REVIEWS_ZOON_IP'));
        $last = time();
        if ($code !== 429 || !$proxy || $try >= 3) break;
        sleep(10);   // через прокси следующий запрос уйдёт с другого адреса
    }
    if ($code === 429) $banned = true;
    if ($code !== 200) throw new RuntimeException("Zoon: ответ $code");
    // общая оценка карточки — в блоке AggregateRating (у отдельных отзывов ratingValue без кавычек)
    if (preg_match('/itemprop="reviewCount" content\s*=\s*"(\d+)"/', $html, $rc)) {
        $agg = strpos($html, 'schema.org/AggregateRating');
        $rv = $agg !== false && preg_match('/itemprop="ratingValue" content="([\d.]+)"/', $html, $m, 0, $agg) ? $m[1] : null;
        rv_card((int)$rc[1], $rv);
    }
    $out = [];
    // кроме отзывов филиала на странице есть блоки с отзывами о других местах — берём только отзывы
    // с кодом организации этого филиала (его код стоит у первого отзыва основного списка)
    $list = strpos($html, 'data-uitest="org-reviews-list"');
    if ($list === false || !preg_match('/organizationId: "([0-9a-f]{24})"/', $html, $org, 0, $list)) return [];
    foreach (array_slice(explode('<li class="comment-item js-comment', $html), 1) as $li) {
        if (!preg_match('/data-id="([0-9a-f]{24})"/', $li, $id)) continue;
        // ответы самой автошколы — автор «Официальный комментарий заведения» (schema.org/Organization), это не отзывы;
        // а вот schema.org/Comment бывает и у обычных старых отзывов без звёзд — их Zoon считает, берём
        if (str_contains($li, 'data-author="Официальный комментарий заведения"')) continue;
        if (!preg_match('/organizationId: "([0-9a-f]{24})"/', $li, $o) || $o[1] !== $org[1]) continue;
        $rid = 'zoon:' . $id[1];
        if (isset($known[$rid]) || isset($out[$rid])) continue;
        preg_match('/itemprop="datePublished" content="([^"]+)"/', $li, $d);
        preg_match('/itemprop="ratingValue" content=([\d.]+)/', $li, $r);
        preg_match('/data-author="([^"]*)"/', $li, $a);
        $text = preg_match('/js-comment-text">(.*?)(<div class="comment-item__footer|$)/s', $li, $t) ? strip_tags($t[1]) : '';
        $out[$rid] = [
            'id'     => $rid,
            // в коде отзыва (ObjectId) первые 4 байта — время создания
            'time'   => $d ? strtotime($d[1]) : hexdec(substr($id[1], 0, 8)),
            'link'   => $base . '#comment' . $id[1],
            'site'   => 'Zoon',
            'rating' => $r ? (float)$r[1] : '',
            'author' => html_entity_decode($a[1] ?? ''),
            'text'   => html_entity_decode(trim(preg_replace('/\s+/u', ' ', $text))),
            'answer' => '',
        ];
    }
    if (count($out) >= 50) echo "Zoon $base: на странице 50 отзывов, могли быть ещё\n";
    return array_values($out);
}

/** Ссылка на конкретный отзыв в 2ГИС (API отдаёт поле url пустым) */
function gis_link(string $city, string $firm, string $id): string
{
    return "https://2gis.ru/$city/firm/$firm/tab/reviews/review/$id";
}

/** «окт.26» — как месяц записан в листе «Отзывы» */
function rv_month(int $t): string
{
    $m = ['янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.'];
    return $m[(int)date('n', $t) - 1] . date('y', $t);
}

// ---------- Филиалы из справочника ----------

$token = g_token();
// у листов бывают лишние пробелы в названии («Справочник »), ищем без них
$tabs = $grid = [];
foreach (http_json('GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId . '?fields=sheets.properties(sheetId,title,gridProperties.columnCount)', null, $token)['sheets'] as $s) {
    $tabs[trim($s['properties']['title'])] = "'" . str_replace("'", "''", $s['properties']['title']) . "'";
    $grid[trim($s['properties']['title'])] = $s['properties'];
}
foreach ([RV_DIR, RV_SHEET] as $t) if (!isset($tabs[$t])) exit("Нет листа «$t»\n");

// --report: размечает «Сводную» формулами — дальше она пересчитывается сама.
// Колонку A (филиалы, строки городов заглавными) ведёт Влада, скрипт её не трогает
if (in_array('--report', $argv, true)) {
    if (!isset($tabs[RV_REPORT])) exit("Нет листа «" . RV_REPORT . "»\n");
    $s = $tabs[RV_SHEET];
    // строка города («КАЗАНЬ», заглавными) — итог по всем его филиалам: отбор по колонке J «Город»;
    // строка филиала — отбор по колонке A «Филиал»
    $isCity = fn(string $x) => "EXACT($x,UPPER($x))";
    // раскладка — в каждой группе 4 колонки: за выбранный период (отзывы, рейтинг) и за весь период (отзывы, рейтинг):
    // Яндекс B-E, 2ГИС G-J, Zoon L-O, Google Q-T, Всего V-Y; F, K, P, U — узкие тёмные разделители;
    // период — даты «с» в AB1 и «по» в AD1 включительно (по колонке D «Дата» в «Отзывах AI»).
    // «За весь период» берётся из листа «Площадки» (цифры карточек).
    // Цвета групп и разделителей задавались в таблице один раз вручную — здесь только формулы
    $period = $s . '!$D:$D,">="&$AB$1,' . $s . '!$D:$D,"<="&$AD$1';
    $cnt = fn(string $by, string $col) => 'COUNTIFS(' . $s . '!$' . $by . ':$' . $by . ',TRIM($A2:$A),'
        . $period . ',' . $s . '!$B:$B,' . $col . '$1)';
    $count = fn(string $col) => '=ARRAYFORMULA(IF($A2:$A="",,IF(' . $isCity('$A2:$A') . ',' . $cnt('J', $col) . ',' . $cnt('A', $col) . ')))';
    // средняя оценка за период; пусто, если отзывов с оценкой не было.
    // SUMIFS внутри ARRAYFORMULA по строкам не разворачивается — считаем каждую строку через MAP
    $av = fn(string $by, string $site) => 'AVERAGEIFS(' . $s . '!$M:$M,' . $s . '!$' . $by . ':$' . $by . ',TRIM(f),' . $period
        . ($site ? ',' . $s . '!$B:$B,' . $site . '$1' : '') . ')';
    $avg = fn(string $site = '') => '=MAP($A2:$A,LAMBDA(f,IF(f="",,IFERROR(ROUND(IF(' . $isCity('f') . ','
        . $av('J', $site) . ',' . $av('A', $site) . '),2),))))';
    // за весь период — из листа «Площадки»: по филиалу колонка B, по городу колонка A
    $P = "'" . RV_TOTALS . "'";
    $by2 = fn(callable $x) => 'IF(' . $isCity('f') . ',' . $x('A') . ',' . $x('B') . ')';
    $plat = fn(string $site) => $site ? ",$P!\$C:\$C,$site\$1" : '';
    $tCnt = fn(string $site = '') => '=MAP($A2:$A,LAMBDA(f,IF(f="",,' . $by2(fn($b) =>
        "IF(COUNTIFS($P!\$$b:\$$b,TRIM(f)" . $plat($site) . ')=0,"",SUMIFS(' . "$P!\$D:\$D,$P!\$$b:\$$b,TRIM(f)" . $plat($site) . '))') . ')))';
    // общий рейтинг = сумма (отзывы × рейтинг) / сумма отзывов — для одного филиала это ровно рейтинг карточки
    $tAvg = fn(string $site = '') => '=MAP($A2:$A,LAMBDA(f,IF(f="",,' . $by2(fn($b) =>
        "IFERROR(ROUND(SUMIFS($P!\$F:\$F,$P!\$$b:\$$b,TRIM(f)" . $plat($site) . ")/SUMIFS($P!\$D:\$D,$P!\$$b:\$$b,TRIM(f)" . $plat($site) . '),2),"")') . ')))';
    [$ya, $gis, $zoon, $google] = RV_SITES;
    $all = ['Всего отзывов (весь период)', 'Общий рейтинг (весь период)'];
    $values = [
        ['ФИЛИАЛ', $ya, 'Рейтинг Яндекс', ...$all, '', $gis, 'Рейтинг 2ГИС', ...$all, '', $zoon, 'Рейтинг Zoon', ...$all, '',
         $google, 'Рейтинг Google', ...$all, '', 'Всего', 'Средний рейтинг', ...$all, '',
         'Период с', date('Y-m-01'), 'по', date('Y-m-t')],
        [null, $count('B'), $avg('B'), $tCnt('B'), $tAvg('B'), null, $count('G'), $avg('G'), $tCnt('G'), $tAvg('G'), null,
         $count('L'), $avg('L'), $tCnt('L'), $tAvg('L'), null, $count('Q'), $avg('Q'), $tCnt('Q'), $tAvg('Q'), null,
         '=ARRAYFORMULA(IF($A2:$A="",,B2:B+G2:G+L2:L+Q2:Q))', $avg(), $tCnt(), $tAvg(),
         null, null, null, null, ''],   // AD2 — бывший список месяцев, очищаем
    ];
    // таблица в русской локали: аргументы формул разделяются «;» (запятых внутри строк в формулах нет)
    $values[1] = array_map(fn($v) => is_string($v) ? str_replace(',', ';', $v) : $v, $values[1]);
    // --formulas: обновить только формулы строки 2, не трогая шапку, выбранный месяц и оформление
    if (in_array('--formulas', $argv, true)) {
        http_json('PUT', rv_url($sheetId, $tabs[RV_REPORT] . '!B2:Y2', '?valueInputOption=USER_ENTERED'),
            ['values' => [array_slice($values[1], 1, 24)]], $token);
        exit("формулы «" . RV_REPORT . "» обновлены\n");
    }
    http_json('PUT', rv_url($sheetId, $tabs[RV_REPORT] . '!A1:AD2', '?valueInputOption=USER_ENTERED'), ['values' => $values], $token);
    $api = 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId;
    $gid = null;
    foreach (http_json('GET', $api . '?fields=sheets.properties(sheetId,title)', null, $token)['sheets'] as $sh) {
        if (trim($sh['properties']['title']) === RV_REPORT) $gid = $sh['properties']['sheetId'];
    }
    $cell = fn(int $c, int $r = 0) => ['sheetId' => $gid, 'startRowIndex' => $r, 'endRowIndex' => $r + 1, 'startColumnIndex' => $c, 'endColumnIndex' => $c + 1];
    $req = [
        ['repeatCell' => ['range' => ['sheetId' => $gid, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 26, 'endColumnIndex' => 30],
            'cell' => ['userEnteredFormat' => ['textFormat' => ['bold' => true, 'fontSize' => 12], 'backgroundColor' => ['red' => 1, 'green' => 0.95, 'blue' => 0.7],
                'horizontalAlignment' => 'CENTER', 'verticalAlignment' => 'MIDDLE']],
            'fields' => 'userEnteredFormat(textFormat,backgroundColor,horizontalAlignment,verticalAlignment)']],
        // старый выпадающий список месяцев больше не нужен
        ['setDataValidation' => ['range' => ['sheetId' => $gid, 'startRowIndex' => 0, 'endRowIndex' => 200, 'startColumnIndex' => 29, 'endColumnIndex' => 30]]],
    ];
    // ячейки дат: проверка «это дата» даёт календарь при двойном клике
    foreach ([27, 29] as $c) {
        $req[] = ['setDataValidation' => ['range' => $cell($c), 'rule' => ['condition' => ['type' => 'DATE_IS_VALID'], 'strict' => true]]];
        $req[] = ['repeatCell' => ['range' => $cell($c), 'cell' => ['userEnteredFormat' => ['numberFormat' => ['type' => 'DATE', 'pattern' => 'dd.mm.yyyy']]],
            'fields' => 'userEnteredFormat.numberFormat']];
        $req[] = ['updateDimensionProperties' => ['range' => ['sheetId' => $gid, 'dimension' => 'COLUMNS', 'startIndex' => $c, 'endIndex' => $c + 1],
            'properties' => ['pixelSize' => 110], 'fields' => 'pixelSize']];
    }
    http_json('POST', $api . ':batchUpdate', ['requests' => $req], $token);
    exit("«" . RV_REPORT . "» размечена\n");
}
$dir = http_json('GET', rv_url($sheetId, $tabs[RV_DIR] . '!A1:E200'), null, $token)['values'] ?? [];
$branches = [];
$city = '';
$zoonSeen = [];
foreach ($dir as $row) {
    $name = trim((string)($row[0] ?? ''));
    $ya = preg_match('~/org/(?:[^/]+/)?(\d+)~', (string)($row[1] ?? ''), $m) ? $m[1] : '';
    $gis = preg_match('~2gis\.ru/([a-z_-]+)/.*?firm/(\d+)~', (string)($row[2] ?? ''), $m) ? "$m[1]/$m[2]" : '';
    $zoon = preg_match('~^https?://zoon\.ru/[^\s?#]+~', trim((string)($row[4] ?? '')), $m) ? $m[0] : '';
    if ($name !== '' && !$ya && !$gis && !$zoon) { $city = $name; continue; }
    if ($name === '') continue;
    if ($zoon && isset($zoonSeen[$zoon])) {
        echo "Справочник: у «{$name}» та же ссылка на Zoon, что у «{$zoonSeen[$zoon]}» — пропускаю\n";
        $zoon = '';
    } elseif ($zoon) {
        $zoonSeen[$zoon] = $name;
    }
    $branches[] = compact('name', 'city', 'ya', 'gis', 'zoon');
}
if (!$branches) exit("В листе «" . RV_DIR . "» не нашлось филиалов со ссылками\n");

// ---------- Что уже записано ----------

// колонки M «Оценка» в листе может ещё не быть — тогда добавим её при записи
$wide = ($grid[RV_SHEET]['gridProperties']['columnCount'] ?? 0) >= 13;
$head = $wide ? (http_json('GET', rv_url($sheetId, $tabs[RV_SHEET] . '!M1'), null, $token)['values'][0] ?? []) : [];
$known = [];
$fix = [];
foreach (http_json('GET', rv_url($sheetId, $tabs[RV_SHEET] . '!A2:I'), null, $token)['values'] ?? [] as $i => $r) {
    $id = $r[8] ?? '';
    if ($id === '') continue;
    $known[$id] = true;
    // строки 2ГИС, записанные без ссылки, — дописываем ссылку
    if (str_starts_with($id, '2gis:') && trim($r[5] ?? '') === '') {
        foreach ($branches as $b) {
            if ($b['name'] === trim($r[0] ?? '') && $b['gis']) {
                [$c, $firm] = explode('/', $b['gis']);
                $fix[] = ['range' => $tabs[RV_SHEET] . '!F' . ($i + 2), 'values' => [[gis_link($c, $firm, substr($id, 5))]]];
            }
        }
    }
}
if ($fix && !$dry) {
    http_json('POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId . '/values:batchUpdate',
        ['valueInputOption' => 'RAW', 'data' => $fix], $token);
}
if ($fix) echo 'ссылок 2ГИС дописано: ', count($fix), PHP_EOL;

// ---------- Сбор ----------

$rows = [];
$errors = 0;
// Яндекс и 2ГИС — по всем филиалам подряд. Zoon пускает с одного адреса около десятка запросов в сутки,
// поэтому его филиалы идут в конце, начиная с тех, что дольше всего не проверялись
$fns = ['ya' => 'ya_reviews', 'gis' => 'gis_reviews', 'zoon' => 'zoon_reviews'];
$tasks = [];
foreach (['ya', 'gis'] as $site) foreach ($branches as $b) $tasks[] = [$b, $site];
$zoonChecked = kv_get('zoon_checked', null) ?? [];
$zb = array_filter($branches, fn($b) => $b['zoon'] !== '');
usort($zb, fn($a, $b) => ($zoonChecked[$a['name']] ?? 0) <=> ($zoonChecked[$b['name']] ?? 0));
foreach ($zb as $b) $tasks[] = [$b, 'zoon'];

$siteName = ['ya' => 'Яндекс Карты', 'gis' => '2ГИС', 'zoon' => 'Zoon'];
$cards = [];   // «филиал|площадка» => строка листа «Площадки»
foreach ($tasks as [$b, $site]) {
    $fn = $fns[$site];
    if (!$b[$site] || in_array("--skip=$site", $argv, true)) continue;
    try {
        $got = $fn($b[$site], $known);
    } catch (Throwable $e) {
        $errors++;
        echo $b['name'], ' / ', $site, ': ', $e->getMessage(), PHP_EOL;
        continue;
    } finally {
        if ($card = rv_card()) {
            $cards[$b['name'] . '|' . $siteName[$site]] = [$b['city'], $b['name'], $siteName[$site], $card['count'],
                $card['rating'] ?? '', $card['rating'] === null ? '' : round($card['count'] * $card['rating'], 2), date('Y-m-d H:i')];
        }
    }
    if ($site === 'zoon' && !$dry) {
        $zoonChecked[$b['name']] = time();
        kv_set('zoon_checked', $zoonChecked);
    }
    $got = array_filter($got, fn($r) => $r['time'] >= RV_FROM);
    foreach ($got as $r) {
        $known[$r['id']] = true;
        // A филиал, B площадка, C месяц, D дата, E проверка ОМ, F ссылка, G картхолдер, H дубли,
        // I ID, J город, K текст отзыва, L текст ответа, M оценка
        $rows[] = [$r['time'], [
            $b['name'], $r['site'], "'" . rv_month($r['time']), date('Y-m-d', $r['time']), '', $r['link'], '', '',
            $r['id'], $b['city'], cell_text(mb_substr($r['text'], 0, 3000)), cell_text(mb_substr($r['answer'], 0, 3000)), $r['rating'],
        ]];
    }
    echo $b['name'], ' / ', $site, ': новых ', count($got), PHP_EOL;
}

usort($rows, fn($a, $b) => $a[0] <=> $b[0]);
$rows = array_column($rows, 1);
echo 'всего новых: ', count($rows), ', ошибок: ', $errors, ', карточек обновлено: ', count($cards), PHP_EOL;

// Лист «Площадки»: обновляем строки, по которым сегодня были ответы, остальные (например, Zoon,
// до которого сегодня не дошла очередь) оставляем с прошлыми цифрами
if (!$dry && $cards) {
    if (!isset($tabs[RV_TOTALS])) {
        http_json('POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId . ':batchUpdate',
            ['requests' => [['addSheet' => ['properties' => ['title' => RV_TOTALS]]]]], $token);
        $tabs[RV_TOTALS] = "'" . RV_TOTALS . "'";
    }
    $all = [];
    // без форматирования: иначе «4,9» вернётся строкой и запишется обратно текстом
    foreach (array_slice(http_json('GET', rv_url($sheetId, $tabs[RV_TOTALS] . '!A1:G', '?valueRenderOption=UNFORMATTED_VALUE'), null, $token)['values'] ?? [], 1) as $r) {
        if (($r[1] ?? '') !== '') $all[$r[1] . '|' . ($r[2] ?? '')] = array_pad($r, 7, '');
    }
    $all = array_replace($all, $cards);
    $order = array_flip(array_column($branches, 'name'));
    uasort($all, fn($a, $b) => [($order[$a[1]] ?? 999), $a[2]] <=> [($order[$b[1]] ?? 999), $b[2]]);
    http_json('POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId . '/values/' . rawurlencode($tabs[RV_TOTALS] . '!A:G') . ':clear', new stdClass(), $token);
    http_json('PUT', rv_url($sheetId, $tabs[RV_TOTALS] . '!A1', '?valueInputOption=RAW'),
        ['values' => array_merge([RV_TOTALS_HEAD], array_values($all))], $token);
    echo "обновлён лист «" . RV_TOTALS . "»\n";
}
if ($dry || !$rows) exit;

if (!$wide) {
    http_json('POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . $sheetId . ':batchUpdate', ['requests' => [
        ['appendDimension' => ['sheetId' => $grid[RV_SHEET]['sheetId'], 'dimension' => 'COLUMNS', 'length' => 13 - $grid[RV_SHEET]['gridProperties']['columnCount']]],
    ]], $token);
}
if (($head[0] ?? '') !== RV_EXTRA) {
    http_json('PUT', rv_url($sheetId, $tabs[RV_SHEET] . '!M1', '?valueInputOption=RAW'), ['values' => [[RV_EXTRA]]], $token);
}
foreach (array_chunk($rows, 500) as $chunk) {
    http_json('POST', rv_url($sheetId, $tabs[RV_SHEET] . '!A:M', ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS'),
        ['values' => $chunk], g_token());
}
echo "записано в «" . RV_SHEET . "»\n";

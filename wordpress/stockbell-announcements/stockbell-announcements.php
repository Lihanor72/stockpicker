<?php
/**
 * Plugin Name: StockBell Announcements
 * Description: Every UK regulated announcement from the FCA National Storage Mechanism (NSM), tagged, searchable and linked to the official document. Collected on this server every five minutes. Tickers resolved via GLEIF (LEI to ISIN) and OpenFIGI (ISIN to London ticker). Also builds automated five-factor scores from each company's filings and delayed daily prices.
 * Version: 1.1.1
 * Author: StockBell
 */
defined('ABSPATH') || exit;

define('SBA_VERSION', '1.1.1');
define('SBA_PRICES', 'https://raw.githubusercontent.com/Lihanor72/stockpicker/main/data/prices.json');
define('SBA_PRICES_PAGE', 'https://github.com/Lihanor72/stockpicker/blob/main/data/prices.json');
define('SBA_NSM_PAGE', 'https://data.fca.org.uk/#/nsm/nationalstoragemechanism');
define('SBA_NSM', 'https://api.data.fca.org.uk/search?index=nsm-search');

function sba_table() { global $wpdb; return $wpdb->prefix . 'sb_announcements'; }
function sba_map_table() { global $wpdb; return $wpdb->prefix . 'sb_lei_map'; }

/* ------------------------------------------------------------------ install */
register_activation_hook(__FILE__, 'sba_install');
function sba_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE " . sba_table() . " (
  id varchar(64) NOT NULL,
  published datetime NOT NULL,
  company varchar(255) NOT NULL DEFAULT '',
  lei varchar(20) NOT NULL DEFAULT '',
  headline varchar(400) NOT NULL DEFAULT '',
  type varchar(120) NOT NULL DEFAULT '',
  type_code varchar(12) NOT NULL DEFAULT '',
  category varchar(80) NOT NULL DEFAULT '',
  source varchar(40) NOT NULL DEFAULT '',
  url varchar(255) NOT NULL DEFAULT '',
  tag varchar(30) NOT NULL DEFAULT '',
  routine tinyint(1) NOT NULL DEFAULT 0,
  doc_checked tinyint(1) NOT NULL DEFAULT 0,
  doc_signal varchar(12) NOT NULL DEFAULT '',
  detail varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY published (published),
  KEY lei (lei)
) $c;");
    dbDelta("CREATE TABLE " . sba_map_table() . " (
  lei varchar(20) NOT NULL,
  ticker varchar(20) NOT NULL DEFAULT '',
  isin varchar(12) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  security varchar(60) NOT NULL DEFAULT '',
  checked datetime NOT NULL,
  history datetime NULL,
  PRIMARY KEY  (lei)
) $c;");
    update_option('sba_db_version', SBA_VERSION, false);
    if (!wp_next_scheduled('sba_collect')) wp_schedule_event(time() + 60, 'sba_five_minutes', 'sba_collect');
    wp_clear_scheduled_hook('sba_enrich'); // scoring work runs from the server cron via run-enrich.php
}
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('sba_collect'); wp_clear_scheduled_hook('sba_enrich'); });
add_action('plugins_loaded', function () { if (get_option('sba_db_version') !== SBA_VERSION) sba_install(); });
add_filter('cron_schedules', function ($s) {
    $s['sba_five_minutes'] = ['interval' => 300, 'display' => 'Every five minutes (StockBell announcements)'];
    return $s;
});

/* ------------------------------------------------------------------ tagging */
function sba_classify($type, $code, $headline, $category) {
    $t = strtolower($type . ' | ' . $headline);
    $routine_types = '/net asset value|^form 8|form 8\.|final terms|frn variable rate|cboe markets|irish takeover panel|stabilisation notice|total voting rights|block listing|director declaration|pricing supplement|base prospectus|supplementary prospectus|publication of (a )?(prospectus|supplement|final terms)|notice of redemption|rule 2\.9|interest rate fix|annual information|price monitoring|form 1 |doc re|employee benefit|exercise of options|transaction in own shares|treasury|monthly net asset|nav\b/';
    if ($category === 'Investment Funds' && stripos($type, 'Net Asset') !== false) return ['Fund NAV', 1];
    $rules = [
        ['Warning',        '/profit warning|below (market |board )?expectations|going concern|lower than expected|guidance cut/'],
        ['Takeover',       '/(possible|firm|recommended|cash|share|revised|final) offer|rule 2\.[4-8]|scheme of arrangement|offer update|offer for|takeover|sale process|strategic review|statement re (possible )?offer|approach/'],
        ['Results',        '/results|half-year financial report|annual financial report|interim report|quarterly|q[1-4] (update|results|trading)|trading statement|trading update|pre-close|preliminary/'],
        ['Director dealing', '/director\/pdmr|pdmr|director dealing|directors?.? dealing|director shareholding/'],
        ['Fundraise',      '/placing|subscription|fundrais|retail offer|open offer|issue of equity|bookbuild|convertible|loan note|capital raise/'],
        ['Trial / regulatory', '/\bfda\b|\bema\b|mhra|clinical|trial|phase (1|2|3|i|ii|iii)\b|approval|clearance/'],
        ['Contract',       '/contract|award|order|agreement|partnership|licen[cs]e|collaboration|framework/'],
        ['Drilling / resources', '/drill|resource|reserve|assay|\bwell\b|exploration|production|grade|ore\b|helium|gold|lithium|copper|tin\b/'],
        ['Acquisition / disposal', '/acquisition|disposal|acquire|divest|sale of/'],
        ['Board change',   '/directorate change|appointment|resignation|retirement of|board change|chief executive|chair/'],
        ['Suspension',     '/suspension|cancellation|delisting|restoration|admission to trading/'],
        ['Holdings',       '/holding\(s\) in company|holding in company|major shareholding/'],
        ['Dividend',       '/dividend/'],
        ['Buyback',        '/own shares|buyback|repurchase/'],
        ['Meeting',        '/\bagm\b|general meeting|result of (agm|meeting)|notice of (agm|meeting)/'],
    ];
    $routine = preg_match($routine_types, $t) ? 1 : 0;
    if ($category === 'Prospectuses') $routine = 1;
    foreach ($rules as [$tag, $re]) {
        if (preg_match($re, $t)) {
            if (in_array($tag, ['Buyback', 'Meeting'], true)) $routine = 1;
            if ($tag === 'Takeover' && preg_match('/form 8/', $t)) return ['Takeover disclosure', 1];
            return [$tag, $routine];
        }
    }
    if (strtoupper($code) === 'DSH') return ['Director dealing', 0];
    return ['Company news', $routine];
}

/* ------------------------------------------------------------------ collector */
function sba_nsm_page($from, $size = 100, $criteria = []) {
    $r = wp_remote_post(SBA_NSM, [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json', 'Origin' => 'https://data.fca.org.uk', 'Referer' => 'https://data.fca.org.uk/', 'User-Agent' => 'StockBell/1.0 (UK announcements list)'],
        'body' => wp_json_encode(['from' => $from, 'size' => $size, 'sort' => 'publication_date', 'sortorder' => 'desc', 'criteriaObj' => ['criteria' => $criteria, 'dateCriteria' => []]]),
    ]);
    if (is_wp_error($r)) return $r;
    if (wp_remote_retrieve_response_code($r) !== 200) return new WP_Error('sba_http', 'NSM HTTP ' . wp_remote_retrieve_response_code($r));
    $j = json_decode(wp_remote_retrieve_body($r), true);
    return is_array($j) ? ($j['hits']['hits'] ?? []) : new WP_Error('sba_json', 'NSM returned invalid JSON');
}

/** Insert one NSM hit; returns true when it was new. */
function sba_insert_hit($h) {
    global $wpdb;
    $t = sba_table();
    $s = $h['_source'] ?? [];
    $id = substr((string) ($s['disclosure_id'] ?? $h['_id'] ?? ''), 0, 64);
    if ($id === '' || empty($s['publication_date'])) return false;
    if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM $t WHERE id=%s", $id))) return false;
    [$tag, $routine] = sba_classify((string) ($s['type'] ?? ''), (string) ($s['type_code'] ?? ''), (string) ($s['headline'] ?? ''), (string) ($s['category_group'] ?? ''));
    $link = ltrim((string) ($s['download_link'] ?? ''), '/');
    return (bool) $wpdb->insert($t, [
        'id' => $id,
        'published' => gmdate('Y-m-d H:i:s', strtotime($s['publication_date'])),
        'company' => mb_substr(sanitize_text_field($s['company'] ?? ''), 0, 255),
        'lei' => preg_match('/^[A-Z0-9]{20}$/', (string) ($s['lei'] ?? '')) ? $s['lei'] : '',
        'headline' => mb_substr(sanitize_text_field($s['headline'] ?? ''), 0, 400),
        'type' => mb_substr(sanitize_text_field($s['type'] ?? ''), 0, 120),
        'type_code' => mb_substr(sanitize_text_field($s['type_code'] ?? ''), 0, 12),
        'category' => mb_substr(sanitize_text_field($s['category_group'] ?? ''), 0, 80),
        'source' => mb_substr(sanitize_text_field($s['source'] ?? ''), 0, 40),
        'url' => $link && preg_match('#^NSM/[A-Za-z0-9/_.\-]+$#', $link) ? 'https://data.fca.org.uk/artefacts/' . $link : '',
        'tag' => $tag,
        'routine' => $routine,
    ]);
}

function sba_collect($max_pages = 15) {
    if (get_transient('sba_lock')) return;
    set_transient('sba_lock', 1, 240);
    @set_time_limit(150);
    global $wpdb;
    $t = sba_table(); $new = 0;
    try {
        for ($page = 0; $page < (int) $max_pages; $page++) {
            $hits = sba_nsm_page($page * 100);
            if (is_wp_error($hits)) {
                update_option('sba_health', ['at' => gmdate('c'), 'ok' => false, 'error' => $hits->get_error_message(), 'last_ok' => get_option('sba_health')['last_ok'] ?? null], false);
                return;
            }
            if (!$hits) break;
            $page_new = 0;
            foreach ($hits as $h) {
                if (sba_insert_hit($h)) { $new++; $page_new++; }
            }
            $oldest = end($hits)['_source']['publication_date'] ?? '';
            if ($page_new === 0 || ($oldest && strtotime($oldest) < time() - 3 * DAY_IN_SECONDS)) break;
        }
        // Keep company history for scoring (400 days); routine filings for 45 days (buybacks 120).
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE published < %s OR (routine=1 AND tag<>'Buyback' AND published < %s) OR (tag='Buyback' AND published < %s)",
            gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS)));
        update_option('sba_health', ['at' => gmdate('c'), 'ok' => true, 'new' => $new, 'last_ok' => gmdate('c')], false);
        sba_resolve_leis(12, 20);
    } finally {
        delete_transient('sba_lock');
    }
}
add_action('sba_collect', 'sba_collect');

/* ------------------------------------------------------------------ tickers (GLEIF + OpenFIGI, free) */
function sba_resolve_leis($limit = 12, $budget = 20) {
    global $wpdb;
    $t = sba_table(); $m = sba_map_table();
    $stop = microtime(true) + $budget;
    $leis = $wpdb->get_col("SELECT a.lei FROM $t a LEFT JOIN $m m ON m.lei=a.lei WHERE a.lei<>'' AND m.lei IS NULL AND a.routine=0 GROUP BY a.lei ORDER BY MAX(a.published) DESC LIMIT " . (int) $limit);
    foreach ($leis as $lei) {
        if (microtime(true) > $stop) break;
        $g = wp_remote_get('https://api.gleif.org/api/v1/lei-records/' . rawurlencode($lei) . '/isins?page[size]=100', ['timeout' => 15]);
        if (is_wp_error($g)) continue;
        $code = wp_remote_retrieve_response_code($g);
        if ($code === 429 || $code >= 500) break;
        $isins = [];
        if ($code === 200) foreach ((json_decode(wp_remote_retrieve_body($g), true)['data'] ?? []) as $d) $isins[] = $d['attributes']['isin'] ?? '';
        $isins = array_values(array_filter($isins, fn($i) => preg_match('/^[A-Z]{2}[A-Z0-9]{10}$/', $i)));
        usort($isins, fn($a, $b) => (int) !preg_match('/^(GB|JE|GG|IM|IE)/', $a) <=> (int) !preg_match('/^(GB|JE|GG|IM|IE)/', $b));
        $best = ['ticker' => '', 'isin' => '', 'name' => '', 'security' => $isins ? 'no London share found' : 'no ISIN'];
        foreach (array_chunk(array_slice($isins, 0, 30), 10) as $chunk) {
            $jobs = array_map(fn($i) => ['idType' => 'ID_ISIN', 'idValue' => $i, 'exchCode' => 'LN'], $chunk);
            $o = wp_remote_post('https://api.openfigi.com/v3/mapping', ['timeout' => 20, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($jobs)]);
            if (is_wp_error($o)) continue 2;
            $oc = wp_remote_retrieve_response_code($o);
            if ($oc === 429) return; // rate limited: try again next run
            if ($oc !== 200) continue 2;
            foreach ((json_decode(wp_remote_retrieve_body($o), true) ?: []) as $k => $res) {
                foreach (($res['data'] ?? []) as $d) {
                    $sec = ($d['securityType2'] ?? '') . ' ' . ($d['securityType'] ?? '');
                    if (preg_match('/Common Stock|Depositary Receipt|Ordinary|Preference|REIT|Closed-End Fund|ETP/i', $sec)) {
                        $best = ['ticker' => mb_substr($d['ticker'] ?? '', 0, 20), 'isin' => $chunk[$k], 'name' => mb_substr($d['name'] ?? '', 0, 255), 'security' => mb_substr(trim($sec), 0, 60)];
                        break 3;
                    }
                }
            }
            usleep(300000);
        }
        if ($best['ticker'] === '') {
            // Fallback: OpenFIGI name search on the London exchange; accept only an exact name match after removing legal suffixes.
            $company = (string) $wpdb->get_var($wpdb->prepare("SELECT company FROM $t WHERE lei=%s ORDER BY published DESC LIMIT 1", $lei));
            $want = sba_norm_name($company);
            if ($want !== '') {
                $o = wp_remote_post('https://api.openfigi.com/v3/search', ['timeout' => 20, 'headers' => ['Content-Type' => 'application/json'],
                    'body' => wp_json_encode(['query' => $want, 'exchCode' => 'LN', 'securityType2' => 'Common Stock'])]);
                if (!is_wp_error($o) && wp_remote_retrieve_response_code($o) === 429) return;
                if (!is_wp_error($o) && wp_remote_retrieve_response_code($o) === 200) {
                    foreach ((json_decode(wp_remote_retrieve_body($o), true)['data'] ?? []) as $d) {
                        if (sba_norm_name($d['name'] ?? '') === $want && !empty($d['ticker'])) {
                            $best = ['ticker' => mb_substr($d['ticker'], 0, 20), 'isin' => '', 'name' => mb_substr($d['name'], 0, 255), 'security' => 'Common Stock (name match)'];
                            break;
                        }
                    }
                }
            }
        }
        $wpdb->replace($m, ['lei' => $lei] + $best + ['checked' => gmdate('Y-m-d H:i:s')]);
    }
}
function sba_norm_name($s) {
    $s = strtoupper(html_entity_decode((string) $s));
    $s = preg_replace('/\b(P\.?L\.?C\.?|LIMITED|LTD\.?|S\.?E\.?|LLC|B\.?V\.?|N\.?V\.?|OYJ?|INC\.?|CORP(ORATION)?|AG|SA|THE)\b/', ' ', $s);
    return trim(preg_replace('/[^A-Z0-9&]+/', ' ', $s));
}

/* ------------------------------------------------------------------ automated scores
 * Fixed, published rules applied to (a) each company's official filings on the FCA NSM and
 * (b) delayed daily prices produced by the free GitHub scan. A factor that the data cannot
 * establish is left empty, and a company without all five factors gets no score.
 */
/* Runs from the server's own cron (run-enrich.php, PHP CLI) so it is not cut off by web request time limits. */
function sba_enrich($backfill = 90, $docs = 200) {
    if (get_transient('sba_enrich_lock')) return;
    set_transient('sba_enrich_lock', 1, $docs + 60);
    @set_time_limit($docs + 50);
    $start = microtime(true);
    try {
        sba_backfill($start + $backfill);
        sba_read_docs($start + $docs);
        sba_score_all();
    } finally {
        delete_transient('sba_enrich_lock');
    }
}

/** London ticker as used by Yahoo (BT/A -> BT-A, RR/ -> RR). */
function sba_ykey($ticker) {
    return preg_replace('/[^A-Z0-9-]/', '', str_replace(['/', '.'], '-', rtrim(strtoupper((string) $ticker), '/.')));
}
function sba_display_ticker($ticker) { return rtrim(strtoupper((string) $ticker), '/'); }

/** Load up to ~400 days of each listed company's filings from the NSM (once per company). */
function sba_backfill($until) {
    global $wpdb;
    $m = sba_map_table();
    $cut = time() - 400 * DAY_IN_SECONDS;
    $leis = $wpdb->get_col("SELECT lei FROM $m WHERE ticker<>'' AND history IS NULL ORDER BY checked DESC LIMIT 60");
    foreach ($leis as $lei) {
        if (microtime(true) > $until) break;
        $ok = true;
        for ($p = 0; $p < 3; $p++) {
            $hits = sba_nsm_page($p * 100, 100, [['name' => 'lei', 'value' => [$lei]]]);
            if (is_wp_error($hits)) { $ok = false; break; }
            foreach ($hits as $h) {
                $d = strtotime($h['_source']['publication_date'] ?? '');
                if ($d && $d >= $cut) sba_insert_hit($h);
            }
            if (count($hits) < 100) break;
            $oldest = strtotime(end($hits)['_source']['publication_date'] ?? '');
            if ($oldest && $oldest < $cut) break;
        }
        if (!$ok) break;
        $wpdb->update($m, ['history' => gmdate('Y-m-d H:i:s')], ['lei' => $lei]);
    }
}

/** Read results, trading updates, warnings and director dealings (HTML documents only). */
function sba_read_docs($until) {
    global $wpdb;
    $t = sba_table(); $m = sba_map_table();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT a.id,a.url,a.tag FROM $t a JOIN $m m ON m.lei=a.lei WHERE m.ticker<>'' AND a.doc_checked=0 AND a.url<>'' AND a.tag IN ('Director dealing','Results','Warning') AND a.published > %s ORDER BY a.published DESC LIMIT 80",
        gmdate('Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS)), ARRAY_A);
    foreach ($rows as $r) {
        if (microtime(true) > $until) break;
        $res = wp_remote_get($r['url'], ['timeout' => 15, 'limit_response_size' => 800000, 'headers' => ['User-Agent' => 'StockBell/1.0 (UK announcements list)']]);
        if (is_wp_error($res)) break;
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code === 429 || $code >= 500) break;
        $sig = 'unread'; $detail = '';
        if ($code === 200 && stripos((string) wp_remote_retrieve_header($res, 'content-type'), 'html') !== false) {
            $txt = sba_doc_text(wp_remote_retrieve_body($res));
            [$sig, $detail] = $r['tag'] === 'Director dealing' ? sba_read_dealing($txt) : sba_read_results($txt);
        }
        $wpdb->update($t, ['doc_checked' => 1, 'doc_signal' => $sig, 'detail' => mb_substr($detail, 0, 255)], ['id' => $r['id']]);
        usleep(150000);
    }
}
function sba_doc_text($html) {
    $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', (string) $html);
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}
/** PDMR notification: classify each "Nature of the transaction" as buy, sell or other (awards, options, SIP, vesting). */
function sba_read_dealing($txt) {
    preg_match_all('/Nature of (?:the )?transaction:?\s*(.{3,160}?)(?=\s*(?:\(?c\)|c\.|Price\(s\)|Price and volume|$))/iu', $txt, $mm);
    if (!$mm[1]) return ['unclear', ''];
    $buy = $sell = $other = 0;
    foreach ($mm[1] as $n) {
        $l = strtolower($n);
        if (preg_match('/\bisa\b|bed[- ]and|\bsipp\b|\bnotes?\b|\bbonds?\b|award|grant|\bvest|option|sharesave|\bsip\b|share incentive|incentive plan|ltip|deferred|bonus|dividend reinvest|\bdrip\b|transfer|gift|nil[- ]cost|release|conditional|lapse|partnership shares|matching shares|free shares|exercise|settle/', $l)) $other++;
        elseif (preg_match('/purchase|acquisition|acquired|bought|\bbuy\b|subscri/', $l) && preg_match('/\bsale\b|\bsold\b|disposal|\bsell/', $l)) $other++; // sale and purchase together: a switch, not a view
        elseif (preg_match('/purchase|acquisition|acquired|bought|\bbuy\b|subscri/', $l)) $buy++;
        elseif (preg_match('/\bsale\b|\bsold\b|disposal|disposed|\bsell/', $l)) $sell++;
        else $other++;
    }
    $sig = $buy && !$sell ? 'buy' : ($sell && !$buy ? 'sell' : ($buy && $sell ? 'mixed' : 'other'));
    return [$sig, trim(mb_substr($mm[1][0], 0, 160))];
}
/** Results / trading update: look for explicit statements about trading versus expectations in the opening text. */
function sba_read_results($txt) {
    $x = mb_substr($txt, 0, 9000);
    $hardpos = '/ahead of (?:market |board |management |our |current )?expectations|above (?:market |board )?expectations|exceed\w* (?:market |board |our )?expectations|(?:raise[sd]?|increase[sd]?|upgrade[sd]?) (?:its |our |the )?(?:full[- ]year |fy\d* )?(?:guidance|forecast|outlook|expectations)/i';
    $hardneg = '/below (?:market |board |management |our )?expectations|profit warning|lower than (?:previously )?expected|(?:reduce[sd]?|lower(?:ed)?|cut|downgrade[sd]?) (?:its |our |the )?(?:full[- ]year |fy\d* )?(?:guidance|forecast|outlook|expectations)|material uncertainty|going concern/i';
    $pos = '/record (?:revenue|results|profit|sales|orders?|year|half|performance)|strong (?:trading|performance|growth|demand|results)|(?:revenue|profit|sales|ebitda|earnings|eps|turnover) (?:up|increased|grew|rose|growth of)|return(?:ed)? to profit|maiden profit|order book (?:up|increased|at a record)/i';
    $neg = '/(?:revenue|profit|sales|ebitda|earnings|eps|turnover) (?:down|decreased|fell|declined)|loss (?:widened|increased)|challenging|weaker|impairment|headwinds|softer|difficult (?:market|trading)/i';
    $inline = '/in line with (?:market |board |management |our |current )?expectations|on track|reiterate[sd]?|(?:maintain|confirm)(?:s|ed)? (?:its |our |the )?(?:full[- ]year )?(?:guidance|outlook|expectations)/i';
    $f = function ($re) use ($x) { preg_match_all($re, $x, $m); return array_values(array_unique(array_map('strtolower', $m[0]))); };
    $hp = $f($hardpos); $hn = $f($hardneg); $p = $f($pos); $n = $f($neg); $il = $f($inline);
    if ($hn && !$hp) $sig = 'e0';
    elseif ($hp && !$hn) $sig = ($p && !$n) ? 'e5' : 'e4';
    elseif ($hp && $hn) $sig = 'e2';
    elseif (count($p) >= 2 && !$n) $sig = 'e4';
    elseif ($il) $sig = $n ? 'e2' : 'e3';
    elseif (count($p) > count($n)) $sig = 'e3';
    elseif (count($n) > count($p)) $sig = count($n) >= 2 ? 'e1' : 'e2';
    elseif ($p || $n) $sig = 'e2';
    else $sig = 'none';
    $bits = [];
    if ($hp || $p) $bits[] = 'positive: ' . implode(', ', array_slice(array_merge($hp, $p), 0, 3));
    if ($hn || $n) $bits[] = 'negative: ' . implode(', ', array_slice(array_merge($hn, $n), 0, 3));
    if ($il) $bits[] = 'in line: ' . $il[0];
    return [$sig, implode('; ', $bits)];
}

function sba_prices() {
    $c = get_transient('sba_prices');
    if (is_array($c)) return $c;
    $r = wp_remote_get(SBA_PRICES, ['timeout' => 20]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return null;
    $j = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($j) || !isset($j['uk']) || !is_array($j['uk'])) return null;
    set_transient('sba_prices', $j, 20 * MINUTE_IN_SECONDS);
    return $j;
}

function sba_factor($value, $reason, $url, $observed, $precision = 'timestamp') {
    return ['value' => max(0, min(5, (int) $value)), 'reason' => mb_substr($reason, 0, 300), 'source_url' => $url, 'observed_at' => gmdate('c', is_numeric($observed) ? (int) $observed : strtotime($observed . ' UTC')), 'time_precision' => $precision];
}
function sba_short($h) { $h = trim(preg_replace('/^.{2,80}? - /u', '', (string) $h)); return mb_strlen($h) > 90 ? mb_substr($h, 0, 88) . '…' : $h; }

function sba_score_all($force = false) {
    global $wpdb;
    $prices = sba_prices();
    if (!$prices) return;
    $meta = get_option('sba_auto_meta', []);
    if (!$force && ($meta['prices'] ?? '') === ($prices['generated'] ?? '') && time() - strtotime($meta['at'] ?? '2000-01-01') < 30 * MINUTE_IN_SECONDS) return;
    $t = sba_table(); $m = sba_map_table();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT a.lei,a.published,a.company,a.headline,a.type,a.tag,a.routine,a.url,a.doc_checked,a.doc_signal,a.detail,m.ticker FROM $t a JOIN $m m ON m.lei=a.lei
         WHERE m.ticker<>'' AND m.history IS NOT NULL AND a.published > %s AND (a.routine=0 OR a.tag='Buyback') ORDER BY a.published DESC",
        gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS)), ARRAY_A);
    $by = [];
    foreach ($rows as $r) { $r['ts'] = strtotime($r['published'] . ' UTC'); $by[$r['ticker']][] = $r; }
    $now = time(); $out = []; $partial = 0;
    foreach ($by as $ticker => $list) {
        $key = sba_ykey($ticker);
        $p = $prices['uk'][$key] ?? null;
        $P = $p ? array_combine(array_slice($prices['fields'], 0, count($p)), $p) : null;
        $f = ['catalyst' => sba_f_catalyst($list, $now), 'earnings' => sba_f_earnings($list, $now), 'momentum' => sba_f_momentum($P, $now),
              'insider' => sba_f_insider($list, $now), 'risk' => sba_f_risk($list, $P, $now)];
        $inputs = array_map(fn($x) => $x ? $x['value'] : null, array_values($f));
        $complete = !in_array(null, $inputs, true);
        if (!$complete) { $partial++; continue; }
        $raw = $inputs[0] * 6 + $inputs[1] * 5 + $inputs[2] * 4 + $inputs[3] * 3 - $inputs[4] * 4;
        $out[$key] = ['market' => 'LSE', 'symbol' => sba_display_ticker($ticker), 'company' => $list[0]['company'], 'factors' => $f, 'inputs' => $inputs,
            'complete' => true, 'input_count' => 5, 'score' => max(0, min(100, (int) round($raw / 90 * 100))), 'reviewed_at' => gmdate('c'),
            'price' => ['label' => $P['pxs'] ?? '', 'date' => $P['date'] ?? ''],
            'method' => 'Automated rules v1', 'assessment' => 'Automated: fixed rules applied to official FCA filings and delayed daily prices. Not reviewed by a person.'];
    }
    uasort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    update_option('sba_auto_scores', $out, false);
    update_option('sba_auto_meta', ['at' => gmdate('c'), 'prices' => $prices['generated'] ?? '', 'scored' => count($out), 'partial' => $partial,
        'companies' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $m WHERE ticker<>''"), 'with_history' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $m WHERE ticker<>'' AND history IS NOT NULL")], false);
}

function sba_f_catalyst($list, $now) {
    $cand = [];
    foreach ($list as $r) {
        if ($r['routine'] || $now - $r['ts'] > 30 * DAY_IN_SECONDS) continue;
        $h = strtolower($r['headline']); $v = 2; $why = 'company update';
        switch ($r['tag']) {
            case 'Takeover': $v = preg_match('/firm|recommended|rule 2\.7|scheme/', $h) ? 5 : 4; $why = 'takeover or offer news'; break;
            case 'Warning': $v = 0; $why = 'profit warning or going-concern language'; break;
            case 'Suspension': $v = preg_match('/restor/', $h) ? 2 : 0; $why = 'trading suspension or cancellation notice'; break;
            case 'Results':
                $map = ['e5' => 5, 'e4' => 4, 'e3' => 3, 'e2' => 2, 'e1' => 1, 'e0' => 0];
                $v = $map[$r['doc_signal']] ?? 3; $why = isset($map[$r['doc_signal']]) ? 'results/trading statement (' . ($r['detail'] ?: 'no clear direction') . ')' : 'results/trading statement'; break;
            case 'Contract': case 'Trial / regulatory': case 'Drilling / resources':
                if (preg_match('/delay|fail|terminat|negative|halt|withdraw|suspend|did not meet|unsuccessful/', $h)) { $v = 1; $why = 'adverse ' . strtolower($r['tag']) . ' news'; }
                elseif (preg_match('/award|\bwin\b|\bwon\b|positive|approv|success|discover|significant|record|extension|increase|upgrade|high[- ]grade|commercial|first|order/', $h)) { $v = 4; $why = 'positive ' . strtolower($r['tag']) . ' news'; }
                else { $v = 3; $why = strtolower($r['tag']) . ' news'; }
                break;
            case 'Acquisition / disposal': $v = 3; $why = 'acquisition or disposal'; break;
            case 'Fundraise': $v = 2; $why = 'fundraise (dilution counted under risk)'; break;
            case 'Director dealing': $v = $r['doc_signal'] === 'buy' ? 3 : 2; $why = $r['doc_signal'] === 'buy' ? 'director share purchase' : 'director dealing'; break;
        }
        $cand[] = [$v, $r, $why];
    }
    if ($cand) {
        $neg = array_filter($cand, fn($c) => $c[0] <= 1);
        usort($cand, fn($a, $b) => $b[0] <=> $a[0] ?: $b[1]['ts'] <=> $a[1]['ts']);
        $pick = $neg ? array_values($neg)[0] : $cand[0];
        return sba_factor($pick[0], 'Most significant filing in the last 30 days: ' . $pick[2] . ', "' . sba_short($pick[1]['headline']) . '" (' . gmdate('j M Y', $pick[1]['ts']) . ').', $pick[1]['url'], $pick[1]['ts']);
    }
    foreach ($list as $r) {
        if ($r['routine']) continue;
        if ($now - $r['ts'] <= 90 * DAY_IN_SECONDS) return sba_factor(1, 'No material filing in the last 30 days. Latest: "' . sba_short($r['headline']) . '" (' . gmdate('j M Y', $r['ts']) . ').', $r['url'], $r['ts']);
        return sba_factor(0, 'No material filing in the last 90 days. Latest: "' . sba_short($r['headline']) . '" (' . gmdate('j M Y', $r['ts']) . ').', $r['url'], $r['ts']);
    }
    return null;
}
function sba_f_earnings($list, $now) {
    foreach ($list as $r) {
        if (!in_array($r['tag'], ['Results', 'Warning'], true) || $now - $r['ts'] > 200 * DAY_IN_SECONDS) continue;
        if ($r['tag'] === 'Warning' && !preg_match('/^e\d$/', $r['doc_signal'])) return sba_factor(0, 'Profit warning or going-concern statement: "' . sba_short($r['headline']) . '" (' . gmdate('j M Y', $r['ts']) . ').', $r['url'], $r['ts']);
        if (!preg_match('/^e(\d)$/', $r['doc_signal'], $mm)) continue; // unread (PDF) or no clear statement: try the previous one
        $txt = ['0' => 'trading below expectations or guidance reduced', '1' => 'mostly negative trading language', '2' => 'mixed trading language', '3' => 'trading in line / modestly positive', '4' => 'positive trading, ahead of expectations or strong growth', '5' => 'ahead of expectations or guidance raised, with strong growth'][$mm[1]];
        return sba_factor((int) $mm[1], 'Latest results or trading update, "' . sba_short($r['headline']) . '" (' . gmdate('j M Y', $r['ts']) . '): ' . $txt . ($r['detail'] ? ' (' . $r['detail'] . ')' : '') . '.', $r['url'], $r['ts']);
    }
    return null;
}
function sba_f_momentum($P, $now) {
    if (!$P || empty($P['ma50']) || empty($P['px']) || empty($P['date']) || $now - strtotime($P['date']) > 7 * DAY_IN_SECONDS) return null;
    $v = 0; $bits = [];
    $px = (float) $P['px']; $ma50 = (float) $P['ma50']; $ma200 = $P['ma200'] !== null ? (float) $P['ma200'] : null;
    if ($px > $ma50) $v++; $bits[] = ($px > $ma50 ? 'above' : 'below') . ' its 50-day average';
    if ($ma200) { if ($px > $ma200) $v++; if ($ma50 > $ma200) $v++; $bits[] = ($px > $ma200 ? 'above' : 'below') . ' its 200-day average'; }
    if ($P['d20'] !== null) { if ($P['d20'] >= 5) $v++; if ($P['d20'] <= -15) $v--; $bits[] = sprintf('%+.1f%% over 20 trading days', $P['d20']); }
    if ($P['fromHi'] !== null) { if ($P['fromHi'] >= -10) $v++; $bits[] = sprintf('%.1f%% from its 12-month high', $P['fromHi']); }
    return sba_factor($v, 'Price ' . $P['pxs'] . ' on ' . $P['date'] . ': ' . implode(', ', $bits) . '.', SBA_PRICES_PAGE, $P['date'], 'date');
}
function sba_f_insider($list, $now) {
    $buy = $sell = 0; $last = null; $pending = false; $bb = null;
    foreach ($list as $r) {
        $age = $now - $r['ts'];
        if ($r['tag'] === 'Buyback' && $age <= 90 * DAY_IN_SECONDS && !$bb) $bb = $r;
        if ($r['tag'] !== 'Director dealing' || $age > 180 * DAY_IN_SECONDS) continue;
        if (!$r['doc_checked']) { $pending = true; continue; }
        if ($r['doc_signal'] === 'buy') { $buy++; $last = $last ?: $r; }
        elseif ($r['doc_signal'] === 'sell') { $sell++; $last = $last ?: $r; }
        elseif ($r['doc_signal'] === 'mixed') { $buy++; $sell++; $last = $last ?: $r; }
    }
    if ($pending) return null; // wait until every recent dealing notice has been read
    if ($buy >= 2 && !$sell) $v = 5; elseif ($buy && !$sell) $v = 4; elseif ($buy > $sell) $v = 3; elseif ($sell >= 3 && !$buy) $v = 0; elseif ($sell > $buy) $v = 1; else $v = 2;
    if ($bb && !$sell && $v < 5) $v++;
    $txt = "Director dealings in the last 180 days: $buy purchase notice(s), $sell sale notice(s) (awards, options and plan shares ignored)" . ($bb ? '; company share buyback active in the last 90 days' : '') . '.';
    $src = $last ?: $bb;
    return $src ? sba_factor($v, $txt, $src['url'], $src['ts']) : sba_factor($v, $txt, SBA_NSM_PAGE, $now);
}
function sba_f_risk($list, $P, $now) {
    if (!$P) return null;
    $v = 2; $bits = []; $src = null;
    foreach ($list as $r) {
        $age = $now - $r['ts'];
        if ($r['tag'] === 'Warning' && $age <= 365 * DAY_IN_SECONDS && !isset($w)) { $w = 1; $v += 2; $bits[] = 'profit warning or going-concern language (' . gmdate('M Y', $r['ts']) . ')'; $src = $src ?: $r; }
        if ($r['tag'] === 'Fundraise' && $age <= 180 * DAY_IN_SECONDS && !isset($fr)) { $fr = 1; $v += 1; $bits[] = 'fundraise or new shares (' . gmdate('M Y', $r['ts']) . ')'; $src = $src ?: $r; }
        if ($r['tag'] === 'Suspension' && $age <= 365 * DAY_IN_SECONDS && !preg_match('/restor/i', $r['headline']) && !isset($su)) { $su = 1; $v += 1; $bits[] = 'suspension or cancellation notice'; $src = $src ?: $r; }
        if (preg_match('/going concern|material uncertainty/', $r['detail']) && $age <= 365 * DAY_IN_SECONDS && !isset($gc)) { $gc = 1; $v += 1; $bits[] = 'going-concern wording in results'; $src = $src ?: $r; }
    }
    if (empty($P['liquid'])) { $v++; $bits[] = 'thinly traded'; }
    if (isset($P['px']) && (float) $P['px'] < 5 && substr((string) $P['pxs'], -1) === 'p') { $v++; $bits[] = 'share price under 5p'; }
    if (($P['val'] ?? 0) >= 5000000) { $v--; $bits[] = 'large and heavily traded (lower risk)'; }
    $txt = 'Risk flags: ' . ($bits ? implode('; ', $bits) : 'none found beyond normal equity risk') . '.';
    return $src ? sba_factor($v, $txt, $src['url'], $src['ts']) : sba_factor($v, $txt, SBA_PRICES_PAGE, $P['date'], 'date');
}

function sba_rest_scores() {
    $r = rest_ensure_response(['items' => array_values(get_option('sba_auto_scores', [])), 'meta' => get_option('sba_auto_meta', []),
        'method' => 'Automated rules v1: catalyst x6 + earnings x5 + momentum x4 + insider x3 - risk x4, scaled to 0-100. Inputs come from FCA NSM filings and delayed daily prices. Companies missing any input are not scored.']);
    $r->header('Cache-Control', 'public, max-age=300');
    return $r;
}
function sba_rest_tickers() {
    global $wpdb;
    $m = sba_map_table();
    $rows = $wpdb->get_results("SELECT ticker,name FROM $m WHERE ticker<>''", ARRAY_A);
    $items = array_map(fn($r) => ['t' => sba_display_ticker($r['ticker']), 'y' => sba_ykey($r['ticker']), 'n' => $r['name']], $rows ?: []);
    $r = rest_ensure_response(['items' => $items, 'count' => count($items)]);
    $r->header('Cache-Control', 'public, max-age=900');
    return $r;
}

/* ------------------------------------------------------------------ REST API */
add_action('rest_api_init', function () {
    register_rest_route('stockbell/v1', '/announcements', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sba_rest']);
    register_rest_route('stockbell/v1', '/auto-scores', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sba_rest_scores']);
    register_rest_route('stockbell/v1', '/tickers', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sba_rest_tickers']);
});
function sba_rest($req) {
    global $wpdb;
    $t = sba_table(); $m = sba_map_table();
    $tz = new DateTimeZone('Europe/London');
    $date = (string) $req->get_param('date');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) $date = wp_date('Y-m-d', null, $tz);
    $start = (new DateTimeImmutable($date . ' 00:00', $tz))->setTimezone(new DateTimeZone('UTC'));
    $end = $start->modify('+1 day');
    $routine = $req->get_param('routine') ? '' : ' AND a.routine=0';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT a.id,a.published,a.company,a.headline,a.type,a.tag,a.routine,a.url,a.source,m.lei AS mapped,m.ticker FROM $t a LEFT JOIN $m m ON m.lei=a.lei WHERE a.published>=%s AND a.published<%s$routine ORDER BY a.published DESC LIMIT 4000",
        $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')), ARRAY_A);
    $items = array_map(fn($r) => [
        'id' => $r['id'], 'time' => gmdate('c', strtotime($r['published'] . ' UTC')), 'company' => $r['company'],
        'ticker' => (string) $r['ticker'], 'mapped' => $r['mapped'] !== null, 'headline' => $r['headline'], 'type' => $r['type'],
        'tag' => $r['tag'], 'routine' => (bool) $r['routine'], 'url' => $r['url'], 'source' => $r['source'],
    ], $rows ?: []);
    $dates = [];
    foreach ($wpdb->get_col("SELECT published FROM $t WHERE routine=0 AND published > UTC_TIMESTAMP() - INTERVAL 45 DAY ORDER BY published DESC") as $p) {
        $dates[wp_date('Y-m-d', strtotime($p . ' UTC'), $tz)] = true;
    }
    $resp = rest_ensure_response(['date' => $date, 'items' => $items, 'available_dates' => array_keys($dates), 'health' => get_option('sba_health', []),
        'source' => 'FCA National Storage Mechanism', 'note' => 'Headlines and links to official regulated announcements. Tags are automatic.']);
    $resp->header('Cache-Control', 'public, max-age=60');
    return $resp;
}

/* ------------------------------------------------------------------ page */
add_shortcode('stockbell_announcements', function () {
    wp_enqueue_style('sba', plugins_url('announcements.css', __FILE__), [], filemtime(__DIR__ . '/announcements.css'));
    wp_enqueue_script('sba', plugins_url('announcements.js', __FILE__), [], filemtime(__DIR__ . '/announcements.js'), true);
    wp_add_inline_script('sba', 'window.sbaEndpoint=' . wp_json_encode(rest_url('stockbell/v1/announcements')) . ';', 'before');
    return '<div class="sba-app" data-sba><div class="sba-head"><span class="sba-kicker">EVERY UK REGULATED ANNOUNCEMENT</span><h1>UK announcements today</h1><p>All AIM and Main Market regulatory news, tagged and searchable, with a link to each official document on the FCA National Storage Mechanism. Updated every five minutes.</p></div>'
        . '<div class="sba-controls"><label>Date <select class="sba-date" aria-label="Choose a date"></select></label><label class="sba-search">Search <input type="search" class="sba-q" placeholder="Company, ticker or headline"></label><label class="sba-check"><input type="checkbox" class="sba-listed" checked> Listed shares only</label><label class="sba-check"><input type="checkbox" class="sba-routine"> Show routine filings</label></div>'
        . '<div class="sba-tags" role="group" aria-label="Filter by type"></div><p class="sba-status" role="status">Loading announcements…</p><ol class="sba-list" aria-live="polite"></ol>'
        . '<p class="sba-foot">Source: <a href="https://data.fca.org.uk/#/nsm/nationalstoragemechanism" target="_blank" rel="noopener">FCA National Storage Mechanism</a>. Tags are assigned automatically from the announcement type and headline. Not investment advice.</p></div>';
});

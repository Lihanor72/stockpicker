<?php
/**
 * Plugin Name: StockBell Announcements
 * Description: Every UK regulated announcement from the FCA National Storage Mechanism (NSM), tagged, searchable and linked to the official document. Collected on this server every five minutes. Tickers resolved via GLEIF (LEI to ISIN) and OpenFIGI (ISIN to London ticker).
 * Version: 1.0.2
 * Author: StockBell
 */
defined('ABSPATH') || exit;

define('SBA_VERSION', '1.0.2');
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
  PRIMARY KEY  (lei)
) $c;");
    update_option('sba_db_version', SBA_VERSION, false);
    if (!wp_next_scheduled('sba_collect')) wp_schedule_event(time() + 60, 'sba_five_minutes', 'sba_collect');
}
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('sba_collect'); });
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
function sba_nsm_page($from, $size = 100) {
    $r = wp_remote_post(SBA_NSM, [
        'timeout' => 30,
        'headers' => ['Content-Type' => 'application/json', 'Origin' => 'https://data.fca.org.uk', 'Referer' => 'https://data.fca.org.uk/', 'User-Agent' => 'StockBell/1.0 (UK announcements list)'],
        'body' => wp_json_encode(['from' => $from, 'size' => $size, 'sort' => 'publication_date', 'sortorder' => 'desc', 'criteriaObj' => ['criteria' => [], 'dateCriteria' => []]]),
    ]);
    if (is_wp_error($r)) return $r;
    if (wp_remote_retrieve_response_code($r) !== 200) return new WP_Error('sba_http', 'NSM HTTP ' . wp_remote_retrieve_response_code($r));
    $j = json_decode(wp_remote_retrieve_body($r), true);
    return is_array($j) ? ($j['hits']['hits'] ?? []) : new WP_Error('sba_json', 'NSM returned invalid JSON');
}

function sba_collect($max_pages = 15) {
    if (get_transient('sba_lock')) return;
    set_transient('sba_lock', 1, 240);
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
                $s = $h['_source'] ?? [];
                $id = substr((string) ($s['disclosure_id'] ?? $h['_id'] ?? ''), 0, 64);
                if ($id === '' || empty($s['publication_date'])) continue;
                if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM $t WHERE id=%s", $id))) continue;
                [$tag, $routine] = sba_classify((string) ($s['type'] ?? ''), (string) ($s['type_code'] ?? ''), (string) ($s['headline'] ?? ''), (string) ($s['category_group'] ?? ''));
                $link = ltrim((string) ($s['download_link'] ?? ''), '/');
                $wpdb->insert($t, [
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
                $new++; $page_new++;
            }
            $oldest = end($hits)['_source']['publication_date'] ?? '';
            if ($page_new === 0 || ($oldest && strtotime($oldest) < time() - 3 * DAY_IN_SECONDS)) break;
        }
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE published < %s", gmdate('Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS)));
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

/* ------------------------------------------------------------------ REST API */
add_action('rest_api_init', function () {
    register_rest_route('stockbell/v1', '/announcements', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => 'sba_rest']);
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

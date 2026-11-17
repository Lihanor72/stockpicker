<?php
/* StockBell: scoring work (history backfill, document reading, automated scores).
 * Run by the hosting cron with PHP CLI, e.g. every 5 minutes. Refuses web requests. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('DOING_CRON', true);
require dirname(__DIR__, 3) . '/wp-load.php';
if (function_exists('sba_enrich')) {
    $t = microtime(true);
    sba_enrich();
    echo gmdate('c') . ' enrich done in ' . round(microtime(true) - $t, 1) . "s\n";
}

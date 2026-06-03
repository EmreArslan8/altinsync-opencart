<?php
/**
 * AltınSync Cron — her 5 dakikada bir çalışır.
 * Crontab:  *\/5 * * * * /opt/homebrew/opt/php@8.2/bin/php /path/to/upload/altinsync_cron.php >> /tmp/altinsync.log 2>&1
 *
 * Canlı altın kurunu çeker, altına bağlı ürünlerin fiyatlarını günceller,
 * OpenCart ürün önbelleğini temizler.
 */

$started = microtime(true);

// --- OpenCart config.php'yi bul (web kökü VEYA extension/altinsync/ içinden çalışabilir) ---
$config = null;
foreach (['/config.php', '/../../config.php', '/../../../config.php'] as $rel) {
	if (is_file(__DIR__ . $rel)) { $config = realpath(__DIR__ . $rel); break; }
}
if (!$config) {
	fwrite(STDERR, '[AltinSync] config.php bulunamadı' . PHP_EOL);
	exit(1);
}
require $config;
$webroot = dirname($config);

// --- Çekirdek kütüphaneyi yükle ---
require DIR_EXTENSION . 'altinsync/altinsync.lib.php';

// --- Veritabanı bağlantısı ---
$link = @new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
if ($link->connect_errno) {
	fwrite(STDERR, '[AltinSync] DB bağlantı hatası: ' . $link->connect_error . PHP_EOL);
	exit(1);
}
$link->set_charset('utf8mb4');
$prefix = DB_PREFIX;

// --- Ayarları oku (oc_setting içinde JSON) ---
$cfg = ['margin' => 12, 'vat' => 0, 'base_metal' => 'gram-has-altin', 'source' => 'Truncgil (Canlı)'];
$res = $link->query("SELECT `value` FROM `{$prefix}setting` WHERE `code`='module_altinsync' AND `key`='module_altinsync'");
if ($res && ($row = $res->fetch_assoc())) {
	$saved = json_decode($row['value'], true);
	if (is_array($saved)) {
		$cfg = array_merge($cfg, $saved);
	}
}

// --- Güncellemeyi çalıştır ---
$report = altinsync_run($link, $prefix, $cfg);

// --- OpenCart ürün önbelleğini temizle ---
$cache_dir = $webroot . '/system/storage/cache/';
if (is_dir($cache_dir)) {
	foreach (glob($cache_dir . 'cache.product.*') as $f) { @unlink($f); }
}

$elapsed = round((microtime(true) - $started) * 1000);
printf("[%s] AltinSync: %s · gram=%s · %d ürün · %dms%s\n",
	date('Y-m-d H:i:s'),
	strtoupper($report['status']),
	$report['gram_price'] !== null ? number_format($report['gram_price'], 2, ',', '.') : '—',
	$report['updated'],
	$elapsed,
	PHP_EOL === "\n" ? '' : ''
);

$link->close();

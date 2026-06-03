<?php
/**
 * AltınSync — paylaşılan çekirdek mantık
 * --------------------------------------------------------------------------
 * Hem cron script (altinsync_cron.php) hem de admin "Şimdi Güncelle" butonu
 * bu fonksiyonları kullanır. Saf PHP + mysqli; OpenCart'ın DB_* sabitleriyle
 * uyumludur (config.php yüklendiğinde sabitler tanımlıdır).
 */

if (!function_exists('altinsync_parse_tr')) {
	/** "6.560,98" -> 6560.98 (Türk sayı formatını çevirir) */
	function altinsync_parse_tr($s): float {
		$s = preg_replace('/[^0-9.,-]/', '', (string)$s);
		$s = str_replace('.', '', $s);
		$s = str_replace(',', '.', $s);
		return (float)$s;
	}
}

if (!function_exists('altinsync_fetch_gold')) {
	/**
	 * Canlı gram altın fiyatını çeker (Truncgil ücretsiz API).
	 * @return array{price: float, change: float}|null  başarısızsa null
	 */
	function altinsync_fetch_gold(string $base_metal = 'gram-has-altin'): ?array {
		$ch = curl_init('https://finans.truncgil.com/today.json');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_USERAGENT      => 'AltinSync/1.0 (OpenCart)',
		]);
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false || $code !== 200) {
			return null;
		}

		$data = json_decode($body, true);
		if (!isset($data[$base_metal]['Satış'])) {
			return null;
		}

		return [
			'price'  => altinsync_parse_tr($data[$base_metal]['Satış']),
			'change' => altinsync_parse_tr($data[$base_metal]['Değişim'] ?? '0'),
		];
	}
}

if (!function_exists('altinsync_calc_price')) {
	/** Tek ürün fiyatı: gram × milyem × kur + işçilik, sonra marj (+ KDV) */
	function altinsync_calc_price(array $p, float $gold, array $cfg): float {
		$v  = $p['gram'] * $p['milyem'] * $gold;       // saf altın değeri
		$v += $p['gram'] * (float)$p['iscilik'];        // işçilik (gram başı)
		$v *= 1 + ((float)$cfg['margin'] / 100);        // kâr marjı
		if (!empty($cfg['vat'])) {
			$v *= 1.20;                                 // KDV
		}
		return round($v, 2);
	}
}

if (!function_exists('altinsync_run')) {
	/**
	 * Tüm altına bağlı ürünleri canlı kura göre günceller.
	 * @return array  rapor: status, gram_price, updated, message
	 */
	function altinsync_run(mysqli $link, string $prefix, array $cfg): array {
		$gold = altinsync_fetch_gold($cfg['base_metal'] ?? 'gram-has-altin');

		// --- Fallback: API çökerse son geçerli fiyatı kullan ---
		if ($gold === null) {
			$res = $link->query("SELECT gram_price FROM `{$prefix}altinsync_log` WHERE status='success' ORDER BY log_id DESC LIMIT 1");
			$row = $res ? $res->fetch_assoc() : null;
			if (!$row) {
				$msg = 'API erişilemedi ve önbellekte fiyat yok';
				$link->query("INSERT INTO `{$prefix}altinsync_log` (gram_price,updated_count,status,message) VALUES (NULL,0,'failed','" . $link->real_escape_string($msg) . "')");
				return ['status' => 'failed', 'gram_price' => null, 'updated' => 0, 'message' => $msg];
			}
			$goldPrice = (float)$row['gram_price'];
			$status = 'fallback';
		} else {
			$goldPrice = $gold['price'];
			$status = 'success';
		}

		// --- Altına bağlı ürünleri güncelle ---
		$updated = 0;
		$res = $link->query("SELECT product_id, milyem, gram, iscilik FROM `{$prefix}altinsync` WHERE is_gold = 1");
		while ($res && ($p = $res->fetch_assoc())) {
			$price = altinsync_calc_price($p, $goldPrice, $cfg);
			$stmt = $link->prepare("UPDATE `{$prefix}product` SET price = ?, date_modified = NOW() WHERE product_id = ?");
			$stmt->bind_param('di', $price, $p['product_id']);
			$stmt->execute();
			$stmt->close();
			$updated++;
		}

		$msg = $status === 'fallback' ? 'API yavaş — önbellek fiyatı kullanıldı' : 'Canlı kur ile güncellendi';
		$stmt = $link->prepare("INSERT INTO `{$prefix}altinsync_log` (gram_price,updated_count,status,message) VALUES (?,?,?,?)");
		$stmt->bind_param('diss', $goldPrice, $updated, $status, $msg);
		$stmt->execute();
		$stmt->close();

		return ['status' => $status, 'gram_price' => $goldPrice, 'updated' => $updated, 'message' => $msg];
	}
}

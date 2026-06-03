<?php
namespace Opencart\Admin\Controller\Extension\Altinsync\Module;

/**
 * AltınSync — OpenCart 4.x admin modülü
 *
 * Altın takı fiyatlarını canlı gram altın kuruna göre otomatik günceller.
 * Çekirdek mantık extension/altinsync/altinsync.lib.php içindedir (cron ile paylaşılır).
 */
class Altinsync extends \Opencart\System\Engine\Controller {

	private function cfg(): array {
		$this->load->model('setting/setting');
		$saved = $this->model_setting_setting->getSetting('module_altinsync');
		$default = ['margin' => 12, 'vat' => 0, 'interval' => 5, 'base_metal' => 'gram-has-altin', 'source' => 'Truncgil (Canlı)', 'status' => 1];
		return array_merge($default, $saved['module_altinsync'] ?? []);
	}

	public function index(): void {
		$this->load->language('extension/altinsync/module/altinsync');
		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		// breadcrumbs
		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/altinsync/module/altinsync', $token)],
		];

		$data['save']   = $this->url->link('extension/altinsync/module/altinsync.save', $token);
		$data['update'] = $this->url->link('extension/altinsync/module/altinsync.update', $token);
		$data['back']   = $this->url->link('marketplace/extension', $token . '&type=module');

		// ayarlar
		$data['cfg'] = $this->cfg();

		// son senkron durumu
		$log = $this->db->query("SELECT * FROM `" . DB_PREFIX . "altinsync_log` ORDER BY log_id DESC LIMIT 1")->row;
		$data['last_log'] = $log ?: null;

		// altına bağlı ürünler + güncel fiyat
		$data['products'] = $this->db->query("
			SELECT a.product_id, d.name, a.gram, a.milyem, a.iscilik, p.price
			FROM `" . DB_PREFIX . "altinsync` a
			JOIN `" . DB_PREFIX . "product` p ON p.product_id = a.product_id
			JOIN `" . DB_PREFIX . "product_description` d ON d.product_id = a.product_id AND d.language_id = 1
			WHERE a.is_gold = 1
			ORDER BY p.price DESC
		")->rows;

		$data['currency'] = '₺';

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/altinsync/module/altinsync', $data));
	}

	public function save(): void {
		$this->load->language('extension/altinsync/module/altinsync');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/altinsync/module/altinsync')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$cfg = [
				'margin'     => (float)($this->request->post['margin'] ?? 12),
				'vat'        => !empty($this->request->post['vat']) ? 1 : 0,
				'interval'   => (int)($this->request->post['interval'] ?? 5),
				'source'     => $this->request->post['source'] ?? 'Truncgil (Canlı)',
				'base_metal' => 'gram-has-altin',
				'status'     => !empty($this->request->post['status']) ? 1 : 0,
			];

			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('module_altinsync', ['module_altinsync' => $cfg]);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * AJAX "Şimdi Güncelle" — cron ile aynı çekirdeği çalıştırır.
	 */
	public function update(): void {
		$json = [];

		try {

		if (!$this->user->hasPermission('modify', 'extension/altinsync/module/altinsync')) {
			$json['error'] = 'Yetki yok';
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
			return;
		}

		require_once DIR_EXTENSION . 'altinsync/altinsync.lib.php';

		$cfg  = $this->cfg();
		$link = new \mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
		$link->set_charset('utf8mb4');

		$report = altinsync_run($link, DB_PREFIX, $cfg);
		$link->close();

		// OpenCart ürün önbelleğini temizle
		$this->cache->delete('product');

		$json['success']    = 'Güncellendi: ' . $report['updated'] . ' ürün · gram ₺' . number_format((float)$report['gram_price'], 2, ',', '.');
		$json['status']     = $report['status'];
		$json['gram_price'] = $report['gram_price'];
		$json['updated']    = $report['updated'];

		} catch (\Throwable $e) {
			$json = ['error' => 'İç hata: ' . $e->getMessage()];
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Kurulum — modül "Install" edilince OpenCart tarafından çağrılır.
	 * Gerekli tabloları oluşturur (varsa dokunmaz).
	 */
	public function install(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "altinsync` (
			`product_id` INT(11) NOT NULL PRIMARY KEY,
			`milyem` DECIMAL(6,4) NOT NULL DEFAULT 0.9160,
			`gram` DECIMAL(10,3) NOT NULL DEFAULT 0,
			`iscilik` DECIMAL(10,2) NOT NULL DEFAULT 0,
			`is_gold` TINYINT(1) NOT NULL DEFAULT 1
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "altinsync_log` (
			`log_id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`gram_price` DECIMAL(12,4),
			`updated_count` INT(11),
			`status` VARCHAR(20),
			`message` VARCHAR(255),
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	/**
	 * Kaldırma — modül "Uninstall" edilince çağrılır.
	 * Mağaza verisini korumak için ürün öznitelik tablosu silinmez; sadece ayar temizlenir.
	 */
	public function uninstall(): void {
		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('module_altinsync');
	}
}

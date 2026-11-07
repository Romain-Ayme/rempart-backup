<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * A place backups can be kept. Uploads and downloads move in steps that stop at a deadline
 * and keep their progress in $state, so a large file can span many requests.
 */
abstract class Backup_Guard_Storage {
	/** @var array The location's settings, secrets included. */
	protected $config;

	public function __construct(array $config) {
		$this->config = $config;
	}

	/** Connect and make sure the backup folder exists. Throws with a readable message on failure. */
	abstract public function test();

	/** @return array List of ['name' => file name, 'size' => bytes]. */
	abstract public function list_files();

	/** Upload some of $local. Returns true once the whole file is stored. */
	abstract public function upload_step($local, $remote, array &$state, $deadline, $beat);

	/** Download some of $remote into $local. Returns true once the whole file is here. */
	abstract public function download_step($remote, $local, array &$state, $deadline, $beat);

	abstract public function put_string($remote, $content);

	abstract public function get_string($remote);

	abstract public function delete($remote);

	/** Give up on an unfinished upload and clean up on the storage side. */
	public function upload_abort($remote, array $state) {
		try {
			$this->delete($remote);
		} catch (Exception $e) {
			backup_guard_debug(__('Nettoyage distant : ', 'backup-guard') . $e->getMessage());
		}
	}

	/** @return array|null ['used' => bytes, 'total' => bytes], or null when unknown. */
	public function quota() {
		return null;
	}

	protected function folder() {
		return trim((string) ($this->config['folder'] ?? ''), '/');
	}

	/** Slices can run longer than the usual limit while one request moves a big chunk. */
	protected static function keep_alive() {
		@set_time_limit(300);
	}

	/**
	 * One HTTP request. Returns ['code', 'body', 'headers'] and throws when the network fails.
	 * Authentication problems and such are left to the caller, which knows what the codes mean.
	 */
	protected function http($method, $url, array $headers = array(), $body = null, $timeout = 120) {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 5,
		);
		if ($body !== null) {
			$args['body'] = $body;
		}
		$response = wp_remote_request($url, $args);
		if (is_wp_error($response)) {
			throw new RuntimeException(sprintf(__('Connexion impossible : %s', 'backup-guard'), $response->get_error_message()));
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code($response),
			'body'    => wp_remote_retrieve_body($response),
			'headers' => wp_remote_retrieve_headers($response),
		);
	}

	protected static function read_range($local, $offset, $length) {
		$handle = fopen($local, 'rb');
		if (!$handle) {
			throw new RuntimeException(__('Lecture impossible du fichier local.', 'backup-guard'));
		}
		fseek($handle, $offset);
		$data = fread($handle, $length);
		fclose($handle);
		return $data === false ? '' : $data;
	}

	protected static function append_local($local, $offset, $data) {
		$handle = fopen($local, 'c+b');
		if (!$handle) {
			throw new RuntimeException(__('Écriture impossible du fichier local.', 'backup-guard'));
		}
		ftruncate($handle, $offset);
		fseek($handle, $offset);
		$written = fwrite($handle, $data);
		fclose($handle);
		if ($written !== strlen($data)) {
			throw new RuntimeException(__('Écriture impossible : le disque est peut-être plein.', 'backup-guard'));
		}
	}

	/** Build the driver for a saved location. */
	public static function make(array $location) {
		$protocol = $location['protocol'];
		$map = array(
			'ftp'      => 'Backup_Guard_Storage_Ftp',
			'ftps'     => 'Backup_Guard_Storage_Ftp',
			'sftp'     => 'Backup_Guard_Storage_Sftp',
			'webdav'   => 'Backup_Guard_Storage_Webdav',
			's3'       => 'Backup_Guard_Storage_S3',
			'gdrive'   => 'Backup_Guard_Storage_Gdrive',
			'onedrive' => 'Backup_Guard_Storage_Onedrive',
			'dropbox'  => 'Backup_Guard_Storage_Dropbox',
			'pcloud'   => 'Backup_Guard_Storage_Pcloud',
		);
		if (empty($map[$protocol])) {
			throw new RuntimeException(sprintf(__('Type de stockage inconnu : %s', 'backup-guard'), $protocol));
		}
		$file = in_array($protocol, array('gdrive', 'onedrive', 'dropbox', 'pcloud'), true) ? 'oauth' : ($protocol === 'ftps' ? 'ftp' : $protocol);
		require_once __DIR__ . '/class-' . $file . '.php';
		$class = $map[$protocol];
		return new $class(Backup_Guard_Locations::config_of($location));
	}
}

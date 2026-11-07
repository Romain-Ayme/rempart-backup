<?php
if (!defined('ABSPATH')) {
	exit;
}

/** The saved places backups can go to. Secret fields are kept encrypted. */
class Backup_Guard_Locations {
	const OPTION = 'backup_guard_locations';

	/** Describes every storage type: its label and the fields of its form. */
	public static function protocols() {
		$folder = array('label' => __('Dossier de sauvegarde', 'backup-guard'), 'help' => __('Facultatif, par exemple backup-guard-backups.', 'backup-guard'), 'default' => 'backup-guard-backups');
		$server = array(
			'host' => array('label' => __('Serveur', 'backup-guard'), 'help' => __('Par exemple ftp.mondomaine.fr', 'backup-guard'), 'required' => true),
			'port' => array('label' => 'Port', 'type' => 'number'),
			'user' => array('label' => __('Identifiant', 'backup-guard'), 'required' => true),
			'pass' => array('label' => __('Mot de passe', 'backup-guard'), 'type' => 'password', 'secret' => true),
		);
		$oauth = array(
			'client_id'     => array('label' => __('Identifiant de l\'application', 'backup-guard'), 'required' => true),
			'client_secret' => array('label' => __('Secret de l\'application', 'backup-guard'), 'type' => 'password', 'secret' => true, 'required' => true),
		);
		return array(
			'ftp'      => array('label' => 'FTP', 'fields' => $server + array('folder' => $folder), 'default_port' => 21),
			'ftps'     => array('label' => 'FTPS', 'default_port' => 21, 'fields' => $server + array(
				'insecure_tls' => array('label' => __('Accepter un certificat non vérifié', 'backup-guard'), 'type' => 'checkbox', 'help' => __('Seulement pour un serveur que vous connaissez et dont le certificat est auto-signé : la connexion reste chiffrée, mais l\'identité du serveur n\'est plus contrôlée.', 'backup-guard')),
				'folder'       => $folder,
			)),
			'sftp'     => array('label' => 'SFTP', 'default_port' => 22, 'fields' => $server + array(
				'private_key' => array('label' => __('Clé privée (facultatif)', 'backup-guard'), 'type' => 'textarea', 'secret' => true, 'help' => __('Si vous en utilisez une, le mot de passe sert de phrase secrète.', 'backup-guard')),
				'folder'      => $folder,
			)),
			'webdav'   => array('label' => 'WebDAV', 'fields' => array(
				'url'    => array('label' => __('Adresse WebDAV', 'backup-guard'), 'help' => __('Par exemple https://cloud.mondomaine.fr/remote.php/dav/files/moi', 'backup-guard'), 'required' => true),
				'user'   => array('label' => __('Identifiant', 'backup-guard'), 'required' => true),
				'pass'   => array('label' => __('Mot de passe', 'backup-guard'), 'type' => 'password', 'secret' => true),
				'folder' => $folder,
			)),
			's3'       => array('label' => __('Amazon S3 et compatibles', 'backup-guard'), 'fields' => array(
				'service'    => array('label' => __('Service', 'backup-guard'), 'type' => 'select', 'options' => self::s3_services()),
				'endpoint'   => array('label' => __('Adresse du service', 'backup-guard'), 'help' => __('Laissez vide : elle est déduite de la région pour les services de la liste.', 'backup-guard')),
				'region'     => array('label' => __('Région', 'backup-guard')),
				'access_key' => array('label' => __('Clé d\'accès', 'backup-guard'), 'required' => true),
				'secret_key' => array('label' => __('Clé secrète', 'backup-guard'), 'type' => 'password', 'secret' => true, 'required' => true),
				'bucket'     => array('label' => 'Bucket', 'required' => true),
				'sse'        => array('label' => __('Chiffrer côté serveur', 'backup-guard'), 'type' => 'checkbox', 'help' => __('Le service chiffre les fichiers reçus (AES-256).', 'backup-guard')),
				'folder'     => $folder,
			)),
			'gdrive'   => array('label' => 'Google Drive', 'oauth' => true, 'fields' => $oauth + array('folder' => array('label' => __('Dossier', 'backup-guard'), 'default' => 'Backup Guard'))),
			'onedrive' => array('label' => 'Microsoft OneDrive', 'oauth' => true, 'fields' => $oauth + array('folder' => array('label' => __('Dossier', 'backup-guard'), 'default' => 'Backup Guard'))),
			'dropbox'  => array('label' => 'Dropbox', 'oauth' => true, 'fields' => $oauth + array('folder' => array('label' => __('Dossier', 'backup-guard'), 'default' => 'Backup Guard'))),
			'pcloud'   => array('label' => 'pCloud', 'oauth' => true, 'fields' => $oauth + array('folder' => array('label' => __('Dossier', 'backup-guard'), 'default' => 'Backup Guard'))),
		);
	}

	/**
	 * What each S3-compatible service needs: how its address follows from the region (when it
	 * does), an example region, and a hint shown under the form. Services without a template
	 * need their address typed in.
	 */
	public static function s3_presets() {
		return array(
			'aws'          => array('endpoint' => '', 'region' => 'eu-west-3', 'example' => __('Laissez vide', 'backup-guard'), 'hint' => __('Laissez l\'adresse vide : elle est déduite de la région.', 'backup-guard')),
			'hetzner'      => array('endpoint' => 'https://{region}.your-objectstorage.com', 'region' => 'fsn1', 'example' => __('Laissez vide', 'backup-guard'), 'hint' => __('Régions : fsn1 (Falkenstein), nbg1 (Nuremberg), hel1 (Helsinki). Clés créées dans la console Hetzner, section Object Storage.', 'backup-guard')),
			'backblaze'    => array('endpoint' => 'https://s3.{region}.backblazeb2.com', 'region' => 'us-west-004', 'example' => __('Laissez vide', 'backup-guard'), 'hint' => __('La région est dans l\'adresse S3 de votre bucket (s3.us-west-004.backblazeb2.com, région us-west-004). Clé d\'accès = keyID, clé secrète = applicationKey. Le bucket doit déjà exister si votre clé est limitée à un bucket.', 'backup-guard')),
			'digitalocean' => array('endpoint' => 'https://{region}.digitaloceanspaces.com', 'region' => 'nyc3', 'example' => __('Laissez vide', 'backup-guard'), 'hint' => __('Région : nyc3, ams3, sgp1, fra1...', 'backup-guard')),
			'linode'       => array('endpoint' => 'https://{region}.linodeobjects.com', 'region' => 'fr-par-1', 'example' => __('Laissez vide', 'backup-guard'), 'hint' => __('Région : l\'identifiant du cluster, par exemple fr-par-1 ou us-east-1.', 'backup-guard')),
			'vultr'        => array('endpoint' => 'https://{region}.vultrobjects.com', 'region' => 'ams1', 'example' => __('Laissez vide', 'backup-guard'), 'hint' => __('Région : ams1, ewr1, sjc1...', 'backup-guard')),
			'wasabi'       => array('endpoint' => 'https://s3.{region}.wasabisys.com', 'region' => 'eu-central-1', 'example' => __('Laissez vide', 'backup-guard'), 'hint' => __('Région : eu-central-1, us-east-1...', 'backup-guard')),
			'cloudflare'   => array('endpoint' => '', 'region' => 'auto', 'example' => __('https://IDENTIFIANT.r2.cloudflarestorage.com', 'backup-guard'), 'hint' => __('Adresse : https://IDENTIFIANT-DU-COMPTE.r2.cloudflarestorage.com. Région : auto.', 'backup-guard')),
			'custom'       => array('endpoint' => '', 'region' => 'us-east-1', 'example' => 'https://s3.mondomaine.fr', 'hint' => __('Indiquez l\'adresse fournie par votre service.', 'backup-guard')),
		);
	}

	public static function s3_services() {
		return array(
			'aws'          => 'Amazon S3',
			'hetzner'      => 'Hetzner Object Storage',
			'backblaze'    => 'Backblaze B2',
			'digitalocean' => 'DigitalOcean Spaces',
			'linode'       => 'Linode Object Storage',
			'vultr'        => 'Vultr Object Storage',
			'cloudflare'   => 'Cloudflare R2',
			'wasabi'       => 'Wasabi',
			'custom'       => __('Autre service compatible S3', 'backup-guard'),
		);
	}

	public static function all() {
		return (array) get_option(self::OPTION, array());
	}

	public static function get($id) {
		$all = self::all();
		return isset($all[$id]) ? $all[$id] : null;
	}

	/** The location's plain and decrypted settings, as a storage driver wants them. */
	public static function config_of(array $location) {
		$config = (array) ($location['config'] ?? array());
		$secrets = array();
		if (!empty($location['secrets'])) {
			$json = backup_guard_decrypt($location['secrets']);
			if ($json === false) {
				throw new RuntimeException(sprintf(__('Les identifiants de « %s » ne sont plus lisibles (site déplacé ?). Modifiez cet emplacement et saisissez-les de nouveau.', 'backup-guard'), $location['name']));
			}
			$secrets = (array) json_decode($json, true);
		}
		return $config + $secrets + array('id' => $location['id'], 'protocol' => $location['protocol']);
	}

	/** Save a location from submitted values. Secrets left empty keep their old value. */
	public static function save(array $values, $id = null) {
		$protocols = self::protocols();
		$protocol = $values['protocol'] ?? '';
		if (empty($protocols[$protocol])) {
			throw new RuntimeException(__('Type de stockage inconnu.', 'backup-guard'));
		}
		$all = self::all();
		$existing = ($id !== null && isset($all[$id])) ? $all[$id] : null;
		$config = array();
		$secrets = $existing ? self::existing_secrets($existing) : array();

		foreach ($protocols[$protocol]['fields'] as $key => $field) {
			$value = isset($values[$key]) ? (is_string($values[$key]) ? trim($values[$key]) : $values[$key]) : '';
			if (($field['type'] ?? '') === 'checkbox') {
				$config[$key] = $value ? 1 : 0;
				continue;
			}
			if (!empty($field['secret'])) {
				if ($value !== '') {
					$secrets[$key] = $value;
				}
				if (!empty($field['required']) && empty($secrets[$key])) {
					throw new RuntimeException(sprintf(__('Champ obligatoire manquant : %s', 'backup-guard'), $field['label']));
				}
				continue;
			}
			if (!empty($field['required']) && $value === '') {
				throw new RuntimeException(sprintf(__('Champ obligatoire manquant : %s', 'backup-guard'), $field['label']));
			}
			$config[$key] = sanitize_text_field((string) $value);
		}

		if ($protocol === 's3') {
			$service = $config['service'] ?? 'custom';
			$preset = self::s3_presets()[$service] ?? self::s3_presets()['custom'];
			if (($config['endpoint'] ?? '') === '') {
				if (strpos($preset['endpoint'], '{region}') !== false && ($config['region'] ?? '') === '') {
					throw new RuntimeException(sprintf(__('Indiquez la région : elle sert à calculer l\'adresse du service (par exemple %s).', 'backup-guard'), $preset['region']));
				}
				if ($preset['endpoint'] === '' && !in_array($service, array('aws'), true)) {
					throw new RuntimeException(sprintf(__('Indiquez l\'adresse du service, par exemple %s.', 'backup-guard'), $preset['example']));
				}
			}
		}
		if (!empty($config['url']) && !wp_http_validate_url($config['url']) && !preg_match('#^https?://#i', $config['url'])) {
			throw new RuntimeException(__('L\'adresse WebDAV doit commencer par http:// ou https://', 'backup-guard'));
		}

		$name = sanitize_text_field((string) ($values['name'] ?? ''));
		if ($name === '') {
			throw new RuntimeException(__('Donnez un nom à cet emplacement.', 'backup-guard'));
		}

		// Values only the code writes (tokens, remembered host key) survive an edit.
		if ($existing) {
			if (!empty($existing['config']['api_host'])) {
				$config['api_host'] = $existing['config']['api_host'];
			}
		}

		if ($id === null) {
			$id = $all ? max(array_map('intval', array_keys($all))) + 1 : 1;
		}
		$location = array(
			'id'       => (int) $id,
			'name'     => $name,
			'protocol' => $protocol,
			'config'   => $config,
			'secrets'  => $secrets ? backup_guard_encrypt(wp_json_encode($secrets)) : '',
			'quota'    => $existing['quota'] ?? null,
		);
		$all[$id] = $location;
		update_option(self::OPTION, $all, false);
		return $location;
	}

	private static function existing_secrets(array $location) {
		if (empty($location['secrets'])) {
			return array();
		}
		$json = backup_guard_decrypt($location['secrets']);
		return $json === false ? array() : (array) json_decode($json, true);
	}

	/** Store a value a driver learned (a new token, the server's identity). */
	public static function remember($id, array $values) {
		$all = self::all();
		if (empty($all[$id])) {
			return;
		}
		$secrets = self::existing_secrets($all[$id]);
		foreach ($values as $key => $value) {
			if ($key === 'api_host') {
				$all[$id]['config'][$key] = $value;
			} else {
				$secrets[$key] = $value;
			}
		}
		$all[$id]['secrets'] = backup_guard_encrypt(wp_json_encode($secrets));
		update_option(self::OPTION, $all, false);
	}

	public static function delete($id) {
		$all = self::all();
		unset($all[$id]);
		update_option(self::OPTION, $all, false);

		// A schedule or default pointing at the removed location falls back to the local folder.
		$settings = backup_guard_settings();
		$changed = false;
		foreach (array('location', 'auto_location') as $key) {
			if ((string) $settings[$key] === (string) $id) {
				$settings[$key] = '';
				$changed = true;
			}
		}
		if ($changed) {
			update_option('backup_guard_settings', $settings, false);
		}
	}

	public static function set_quota($id, $quota) {
		$all = self::all();
		if (!empty($all[$id])) {
			$all[$id]['quota'] = $quota;
			update_option(self::OPTION, $all, false);
		}
	}

	/** Start connecting a cloud account: returns the address to send the person to. */
	public static function oauth_start(array $location) {
		$driver = Backup_Guard_Storage::make($location); // Loads the class.
		unset($driver);
		$state = wp_generate_password(32, false);
		set_transient('backup_guard_oauth_' . $state, array('id' => $location['id'], 'user' => get_current_user_id()), HOUR_IN_SECONDS);
		$class = self::oauth_class($location['protocol']);
		return call_user_func(array($class, 'authorize_url'), self::config_of($location), $state);
	}

	public static function oauth_class($protocol) {
		$map = array('gdrive' => 'Backup_Guard_Storage_Gdrive', 'onedrive' => 'Backup_Guard_Storage_Onedrive', 'dropbox' => 'Backup_Guard_Storage_Dropbox', 'pcloud' => 'Backup_Guard_Storage_Pcloud');
		return $map[$protocol];
	}

	/** Finish connecting after the provider sent the person back. */
	public static function oauth_finish($state, $code, array $query) {
		$pending = get_transient('backup_guard_oauth_' . $state);
		if (!$pending || (int) $pending['user'] !== get_current_user_id()) {
			throw new RuntimeException(__('Cette autorisation a expiré. Recommencez depuis la liste des emplacements.', 'backup-guard'));
		}
		delete_transient('backup_guard_oauth_' . $state);
		$location = self::get($pending['id']);
		if (!$location) {
			throw new RuntimeException(__('Cet emplacement n\'existe plus.', 'backup-guard'));
		}
		$driver = Backup_Guard_Storage::make($location);
		unset($driver);
		$class = self::oauth_class($location['protocol']);
		$tokens = call_user_func(array($class, 'exchange_code'), self::config_of($location), $code, $query);
		self::remember($location['id'], $tokens);
		$location = self::get($location['id']);
		Backup_Guard_Storage::make($location)->test();
		return $location;
	}
}

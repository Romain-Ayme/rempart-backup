<?php
if (!defined('ABSPATH')) {
	exit;
}

/** The saved places backups can go to. Secret fields are kept encrypted. */
class Rempart_Locations {
	const OPTION = 'rempart_locations';

	/** Describes every storage type: its label and the fields of its form. */
	public static function protocols() {
		$folder = array('label' => 'Dossier de sauvegarde', 'help' => 'Facultatif, par exemple rempart-backups.', 'default' => 'rempart-backups');
		$server = array(
			'host' => array('label' => 'Serveur', 'help' => 'Par exemple ftp.mondomaine.fr', 'required' => true),
			'port' => array('label' => 'Port', 'type' => 'number'),
			'user' => array('label' => 'Identifiant', 'required' => true),
			'pass' => array('label' => 'Mot de passe', 'type' => 'password', 'secret' => true),
		);
		$oauth = array(
			'client_id'     => array('label' => 'Identifiant de l\'application', 'required' => true),
			'client_secret' => array('label' => 'Secret de l\'application', 'type' => 'password', 'secret' => true, 'required' => true),
		);
		return array(
			'ftp'      => array('label' => 'FTP', 'fields' => $server + array('folder' => $folder), 'default_port' => 21),
			'ftps'     => array('label' => 'FTPS', 'default_port' => 21, 'fields' => $server + array(
				'insecure_tls' => array('label' => 'Accepter un certificat non vérifié', 'type' => 'checkbox', 'help' => 'Seulement pour un serveur que vous connaissez et dont le certificat est auto-signé : la connexion reste chiffrée, mais l\'identité du serveur n\'est plus contrôlée.'),
				'folder'       => $folder,
			)),
			'sftp'     => array('label' => 'SFTP', 'default_port' => 22, 'fields' => $server + array(
				'private_key' => array('label' => 'Clé privée (facultatif)', 'type' => 'textarea', 'secret' => true, 'help' => 'Si vous en utilisez une, le mot de passe sert de phrase secrète.'),
				'folder'      => $folder,
			)),
			'webdav'   => array('label' => 'WebDAV', 'fields' => array(
				'url'    => array('label' => 'Adresse WebDAV', 'help' => 'Par exemple https://cloud.mondomaine.fr/remote.php/dav/files/moi', 'required' => true),
				'user'   => array('label' => 'Identifiant', 'required' => true),
				'pass'   => array('label' => 'Mot de passe', 'type' => 'password', 'secret' => true),
				'folder' => $folder,
			)),
			's3'       => array('label' => 'Amazon S3 et compatibles', 'fields' => array(
				'service'    => array('label' => 'Service', 'type' => 'select', 'options' => self::s3_services()),
				'endpoint'   => array('label' => 'Adresse du service', 'help' => 'Laissez vide : elle est déduite de la région pour les services de la liste.'),
				'region'     => array('label' => 'Région'),
				'access_key' => array('label' => 'Clé d\'accès', 'required' => true),
				'secret_key' => array('label' => 'Clé secrète', 'type' => 'password', 'secret' => true, 'required' => true),
				'bucket'     => array('label' => 'Bucket', 'required' => true),
				'sse'        => array('label' => 'Chiffrer côté serveur', 'type' => 'checkbox', 'help' => 'Le service chiffre les fichiers reçus (AES-256).'),
				'folder'     => $folder,
			)),
			'gdrive'   => array('label' => 'Google Drive', 'oauth' => true, 'fields' => $oauth + array('folder' => array('label' => 'Dossier', 'default' => 'Rempart Backup'))),
			'onedrive' => array('label' => 'Microsoft OneDrive', 'oauth' => true, 'fields' => $oauth + array('folder' => array('label' => 'Dossier', 'default' => 'Rempart Backup'))),
			'dropbox'  => array('label' => 'Dropbox', 'oauth' => true, 'fields' => $oauth + array('folder' => array('label' => 'Dossier', 'default' => 'Rempart Backup'))),
			'pcloud'   => array('label' => 'pCloud', 'oauth' => true, 'fields' => $oauth + array('folder' => array('label' => 'Dossier', 'default' => 'Rempart Backup'))),
		);
	}

	/**
	 * What each S3-compatible service needs: how its address follows from the region (when it
	 * does), an example region, and a hint shown under the form. Services without a template
	 * need their address typed in.
	 */
	public static function s3_presets() {
		return array(
			'aws'          => array('endpoint' => '', 'region' => 'eu-west-3', 'example' => 'Laissez vide', 'hint' => 'Laissez l\'adresse vide : elle est déduite de la région.'),
			'hetzner'      => array('endpoint' => 'https://{region}.your-objectstorage.com', 'region' => 'fsn1', 'example' => 'Laissez vide', 'hint' => 'Régions : fsn1 (Falkenstein), nbg1 (Nuremberg), hel1 (Helsinki). Clés créées dans la console Hetzner, section Object Storage.'),
			'backblaze'    => array('endpoint' => 'https://s3.{region}.backblazeb2.com', 'region' => 'us-west-004', 'example' => 'Laissez vide', 'hint' => 'La région est dans l\'adresse S3 de votre bucket (s3.us-west-004.backblazeb2.com, région us-west-004). Clé d\'accès = keyID, clé secrète = applicationKey. Le bucket doit déjà exister si votre clé est limitée à un bucket.'),
			'digitalocean' => array('endpoint' => 'https://{region}.digitaloceanspaces.com', 'region' => 'nyc3', 'example' => 'Laissez vide', 'hint' => 'Région : nyc3, ams3, sgp1, fra1...'),
			'linode'       => array('endpoint' => 'https://{region}.linodeobjects.com', 'region' => 'fr-par-1', 'example' => 'Laissez vide', 'hint' => 'Région : l\'identifiant du cluster, par exemple fr-par-1 ou us-east-1.'),
			'vultr'        => array('endpoint' => 'https://{region}.vultrobjects.com', 'region' => 'ams1', 'example' => 'Laissez vide', 'hint' => 'Région : ams1, ewr1, sjc1...'),
			'wasabi'       => array('endpoint' => 'https://s3.{region}.wasabisys.com', 'region' => 'eu-central-1', 'example' => 'Laissez vide', 'hint' => 'Région : eu-central-1, us-east-1...'),
			'cloudflare'   => array('endpoint' => '', 'region' => 'auto', 'example' => 'https://IDENTIFIANT.r2.cloudflarestorage.com', 'hint' => 'Adresse : https://IDENTIFIANT-DU-COMPTE.r2.cloudflarestorage.com. Région : auto.'),
			'custom'       => array('endpoint' => '', 'region' => 'us-east-1', 'example' => 'https://s3.mondomaine.fr', 'hint' => 'Indiquez l\'adresse fournie par votre service.'),
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
			'custom'       => 'Autre service compatible S3',
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
			$json = rempart_decrypt($location['secrets']);
			if ($json === false) {
				throw new RuntimeException('Les identifiants de « ' . $location['name'] . ' » ne sont plus lisibles (site déplacé ?). Modifiez cet emplacement et saisissez-les de nouveau.');
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
			throw new RuntimeException('Type de stockage inconnu.');
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
					throw new RuntimeException('Champ obligatoire manquant : ' . $field['label']);
				}
				continue;
			}
			if (!empty($field['required']) && $value === '') {
				throw new RuntimeException('Champ obligatoire manquant : ' . $field['label']);
			}
			$config[$key] = sanitize_text_field((string) $value);
		}

		if ($protocol === 's3') {
			$service = $config['service'] ?? 'custom';
			$preset = self::s3_presets()[$service] ?? self::s3_presets()['custom'];
			if (($config['endpoint'] ?? '') === '') {
				if (strpos($preset['endpoint'], '{region}') !== false && ($config['region'] ?? '') === '') {
					throw new RuntimeException('Indiquez la région : elle sert à calculer l\'adresse du service (par exemple ' . $preset['region'] . ').');
				}
				if ($preset['endpoint'] === '' && !in_array($service, array('aws'), true)) {
					throw new RuntimeException('Indiquez l\'adresse du service, par exemple ' . $preset['example'] . '.');
				}
			}
		}
		if (!empty($config['url']) && !wp_http_validate_url($config['url']) && !preg_match('#^https?://#i', $config['url'])) {
			throw new RuntimeException('L\'adresse WebDAV doit commencer par http:// ou https://');
		}

		$name = sanitize_text_field((string) ($values['name'] ?? ''));
		if ($name === '') {
			throw new RuntimeException('Donnez un nom à cet emplacement.');
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
			'secrets'  => $secrets ? rempart_encrypt(wp_json_encode($secrets)) : '',
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
		$json = rempart_decrypt($location['secrets']);
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
		$all[$id]['secrets'] = rempart_encrypt(wp_json_encode($secrets));
		update_option(self::OPTION, $all, false);
	}

	public static function delete($id) {
		$all = self::all();
		unset($all[$id]);
		update_option(self::OPTION, $all, false);

		// A schedule or default pointing at the removed location falls back to the local folder.
		$settings = rempart_settings();
		$changed = false;
		foreach (array('location', 'auto_location') as $key) {
			if ((string) $settings[$key] === (string) $id) {
				$settings[$key] = '';
				$changed = true;
			}
		}
		if ($changed) {
			update_option('rempart_settings', $settings, false);
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
		$driver = Rempart_Storage::make($location); // Loads the class.
		unset($driver);
		$state = wp_generate_password(32, false);
		set_transient('rempart_oauth_' . $state, array('id' => $location['id'], 'user' => get_current_user_id()), HOUR_IN_SECONDS);
		$class = self::oauth_class($location['protocol']);
		return call_user_func(array($class, 'authorize_url'), self::config_of($location), $state);
	}

	public static function oauth_class($protocol) {
		$map = array('gdrive' => 'Rempart_Storage_Gdrive', 'onedrive' => 'Rempart_Storage_Onedrive', 'dropbox' => 'Rempart_Storage_Dropbox', 'pcloud' => 'Rempart_Storage_Pcloud');
		return $map[$protocol];
	}

	/** Finish connecting after the provider sent the person back. */
	public static function oauth_finish($state, $code, array $query) {
		$pending = get_transient('rempart_oauth_' . $state);
		if (!$pending || (int) $pending['user'] !== get_current_user_id()) {
			throw new RuntimeException('Cette autorisation a expiré. Recommencez depuis la liste des emplacements.');
		}
		delete_transient('rempart_oauth_' . $state);
		$location = self::get($pending['id']);
		if (!$location) {
			throw new RuntimeException('Cet emplacement n\'existe plus.');
		}
		$driver = Rempart_Storage::make($location);
		unset($driver);
		$class = self::oauth_class($location['protocol']);
		$tokens = call_user_func(array($class, 'exchange_code'), self::config_of($location), $code, $query);
		self::remember($location['id'], $tokens);
		$location = self::get($location['id']);
		Rempart_Storage::make($location)->test();
		return $location;
	}
}

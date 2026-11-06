<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Cloud accounts reached with OAuth. Each uses the person's own application credentials,
 * so no third-party relay ever sees their tokens.
 */
abstract class Rempart_Storage_Oauth extends Rempart_Storage {
	const CHUNK = 8388608;

	/** The page the provider sends the person back to after they accept. */
	public static function redirect_uri() {
		return admin_url('admin.php?page=rempart-backup');
	}

	abstract public static function authorize_url(array $config, $state);

	/** Turn the code from the provider's redirect into the tokens to store. */
	abstract public static function exchange_code(array $config, $code, array $query);

	/** @return array ['token' => access token, 'expires' => unix time, 'refresh' => optional new refresh token] */
	abstract protected function refresh_access_token();

	protected function access_token() {
		$id = $this->config['id'] ?? 'new';
		$cache = 'rempart_token_' . md5($id . '|' . ($this->config['refresh_token'] ?? ''));
		$token = get_transient($cache);
		if ($token) {
			return $token;
		}
		$fresh = $this->refresh_access_token();
		if (!empty($fresh['refresh']) && $fresh['refresh'] !== ($this->config['refresh_token'] ?? '')) {
			$this->config['refresh_token'] = $fresh['refresh'];
			if (!empty($this->config['id'])) {
				Rempart_Locations::remember($this->config['id'], array('refresh_token' => $fresh['refresh']));
			}
			$cache = 'rempart_token_' . md5($id . '|' . $fresh['refresh']);
		}
		set_transient($cache, $fresh['token'], max(60, (int) $fresh['expires'] - time() - 120));
		return $fresh['token'];
	}

	protected function bearer() {
		return array('Authorization' => 'Bearer ' . $this->access_token());
	}

	protected static function post_form($url, array $fields) {
		$response = wp_remote_post($url, array('body' => $fields, 'timeout' => 60));
		if (is_wp_error($response)) {
			throw new RuntimeException('Connexion impossible : ' . $response->get_error_message());
		}
		$data = json_decode(wp_remote_retrieve_body($response), true);
		$code = (int) wp_remote_retrieve_response_code($response);
		if (!is_array($data) || $code >= 400) {
			$why = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? 'code ' . $code) : 'code ' . $code;
			throw new RuntimeException('Le service refuse l\'autorisation : ' . $why);
		}
		return $data;
	}

	protected function json($method, $url, $body = null, array $headers = array()) {
		$headers += $this->bearer();
		$payload = null;
		if ($body !== null) {
			$payload = is_string($body) ? $body : wp_json_encode($body);
			$headers += array('Content-Type' => 'application/json');
		}
		$response = $this->http($method, $url, $headers, $payload);
		$response['json'] = json_decode($response['body'], true);
		return $response;
	}

	protected static function why($response) {
		$json = isset($response['json']) ? $response['json'] : json_decode($response['body'], true);
		if (is_array($json)) {
			if (isset($json['error']['message'])) {
				return $json['error']['message'];
			}
			if (isset($json['error_summary'])) {
				return $json['error_summary'];
			}
			if (isset($json['error']) && is_string($json['error'])) {
				return $json['error'];
			}
		}
		return 'code ' . $response['code'];
	}

	public function test() {
		$probe = '.rempart-test-' . wp_generate_password(6, false);
		$this->put_string($probe, 'ok');
		$this->delete($probe);
	}
}

/** Google Drive, limited to the files this plugin creates (scope drive.file). */
class Rempart_Storage_Gdrive extends Rempart_Storage_Oauth {
	// Must be a multiple of 256 KiB.
	const CHUNK = 2097152;
	const API = 'https://www.googleapis.com/drive/v3';
	const UPLOAD = 'https://www.googleapis.com/upload/drive/v3';

	public static function authorize_url(array $config, $state) {
		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array(
			'client_id'     => $config['client_id'],
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/drive.file',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		));
	}

	public static function exchange_code(array $config, $code, array $query) {
		$data = self::post_form('https://oauth2.googleapis.com/token', array(
			'code'          => $code,
			'client_id'     => $config['client_id'],
			'client_secret' => $config['client_secret'],
			'redirect_uri'  => self::redirect_uri(),
			'grant_type'    => 'authorization_code',
		));
		if (empty($data['refresh_token'])) {
			throw new RuntimeException('Google n\'a pas donné d\'autorisation durable. Retirez l\'accès dans votre compte Google puis recommencez.');
		}
		return array('refresh_token' => $data['refresh_token']);
	}

	protected function refresh_access_token() {
		$data = self::post_form('https://oauth2.googleapis.com/token', array(
			'refresh_token' => $this->config['refresh_token'],
			'client_id'     => $this->config['client_id'],
			'client_secret' => $this->config['client_secret'],
			'grant_type'    => 'refresh_token',
		));
		return array('token' => $data['access_token'], 'expires' => time() + (int) $data['expires_in']);
	}

	private $folder_id = null;

	private function folder_id() {
		if ($this->folder_id !== null) {
			return $this->folder_id;
		}
		$parent = 'root';
		$folder = $this->folder() === '' ? 'Rempart Backup' : $this->folder();
		foreach (explode('/', $folder) as $name) {
			$q = "name = '" . addcslashes($name, "'\\") . "' and mimeType = 'application/vnd.google-apps.folder' and '" . $parent . "' in parents and trashed = false";
			$found = $this->json('GET', self::API . '/files?' . http_build_query(array('q' => $q, 'fields' => 'files(id)')));
			if ($found['code'] !== 200) {
				throw new RuntimeException('Google Drive : ' . self::why($found));
			}
			if (!empty($found['json']['files'][0]['id'])) {
				$parent = $found['json']['files'][0]['id'];
				continue;
			}
			$made = $this->json('POST', self::API . '/files', array('name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => array($parent)));
			if ($made['code'] !== 200 || empty($made['json']['id'])) {
				throw new RuntimeException('Google Drive : création du dossier impossible (' . self::why($made) . ').');
			}
			$parent = $made['json']['id'];
		}
		$this->folder_id = $parent;
		return $parent;
	}

	private function file_id($name) {
		$q = "name = '" . addcslashes($name, "'\\") . "' and '" . $this->folder_id() . "' in parents and trashed = false";
		$found = $this->json('GET', self::API . '/files?' . http_build_query(array('q' => $q, 'fields' => 'files(id,size)')));
		return !empty($found['json']['files'][0]['id']) ? $found['json']['files'][0]['id'] : null;
	}

	public function list_files() {
		$files = array();
		$page = null;
		do {
			$query = array('q' => "'" . $this->folder_id() . "' in parents and trashed = false", 'fields' => 'nextPageToken,files(name,size)', 'pageSize' => 1000);
			if ($page) {
				$query['pageToken'] = $page;
			}
			$response = $this->json('GET', self::API . '/files?' . http_build_query($query));
			if ($response['code'] !== 200) {
				throw new RuntimeException('Google Drive : ' . self::why($response));
			}
			foreach ((array) ($response['json']['files'] ?? array()) as $file) {
				$files[] = array('name' => $file['name'], 'size' => (float) ($file['size'] ?? 0));
			}
			$page = $response['json']['nextPageToken'] ?? null;
		} while ($page);
		return $files;
	}

	public function upload_step($local, $remote, array &$state, $deadline, $beat) {
		$total = filesize($local);
		if (empty($state['session'])) {
			$response = $this->json('POST', self::UPLOAD . '/files?uploadType=resumable', array('name' => $remote, 'parents' => array($this->folder_id())), array('X-Upload-Content-Length' => (string) $total, 'X-Upload-Content-Type' => 'application/gzip'));
			if ($response['code'] !== 200 || empty($response['headers']['location'])) {
				throw new RuntimeException('Google Drive : envoi refusé (' . self::why($response) . ').');
			}
			$state['session'] = (string) $response['headers']['location'];
			$state['offset'] = 0;
		}
		while ($state['offset'] < $total) {
			if (time() >= $deadline) {
				return false;
			}
			self::keep_alive();
			$chunk = self::read_range($local, (int) $state['offset'], self::CHUNK);
			$end = $state['offset'] + strlen($chunk) - 1;
			$response = $this->http('PUT', $state['session'], array('Content-Range' => "bytes {$state['offset']}-{$end}/{$total}"), $chunk);
			if ($response['code'] === 308) {
				$range = (string) $response['headers']['range'];
				$state['offset'] = preg_match('/-(\d+)$/', $range, $m) ? (float) $m[1] + 1 : 0;
			} elseif ($response['code'] === 200 || $response['code'] === 201) {
				$state['offset'] = $total;
			} else {
				throw new RuntimeException('Google Drive : envoi interrompu (' . self::why($response) . ').');
			}
			call_user_func($beat, $state['offset'], $total);
		}
		return true;
	}

	public function upload_abort($remote, array $state) {
		if (!empty($state['session'])) {
			try {
				$this->http('DELETE', $state['session'], array('Content-Length' => '0'));
			} catch (Exception $e) {
				rempart_debug($e->getMessage());
			}
		}
	}

	public function download_step($remote, $local, array &$state, $deadline, $beat) {
		if (empty($state['file_id'])) {
			$state['file_id'] = $this->file_id($remote);
			if (!$state['file_id']) {
				throw new RuntimeException('Fichier introuvable sur Google Drive : ' . $remote);
			}
		}
		$offset = (int) ($state['offset'] ?? 0);
		while (time() < $deadline) {
			self::keep_alive();
			$end = $offset + self::CHUNK - 1;
			$response = $this->http('GET', self::API . '/files/' . $state['file_id'] . '?alt=media', $this->bearer() + array('Range' => "bytes={$offset}-{$end}"));
			if ($response['code'] === 416) {
				return true;
			}
			if ($response['code'] !== 206 && $response['code'] !== 200) {
				throw new RuntimeException('Google Drive : téléchargement refusé (' . self::why($response) . ').');
			}
			self::append_local($local, $offset, $response['body']);
			$offset += strlen($response['body']);
			$state['offset'] = $offset;
			$total = preg_match('#/(\d+)$#', (string) $response['headers']['content-range'], $m) ? (float) $m[1] : $offset;
			call_user_func($beat, $offset, $total);
			if ($offset >= $total) {
				return true;
			}
		}
		return false;
	}

	public function put_string($remote, $content) {
		$boundary = 'rempart' . wp_generate_password(16, false);
		$existing = $this->file_id($remote);
		$meta = $existing ? array('name' => $remote) : array('name' => $remote, 'parents' => array($this->folder_id()));
		$body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . wp_json_encode($meta)
			. "\r\n--{$boundary}\r\nContent-Type: application/octet-stream\r\n\r\n" . $content . "\r\n--{$boundary}--";
		$url = $existing ? self::UPLOAD . '/files/' . $existing . '?uploadType=multipart' : self::UPLOAD . '/files?uploadType=multipart';
		$response = $this->http($existing ? 'PATCH' : 'POST', $url, $this->bearer() + array('Content-Type' => 'multipart/related; boundary=' . $boundary), $body);
		if ($response['code'] !== 200) {
			throw new RuntimeException('Google Drive : écriture refusée (' . self::why($response) . ').');
		}
	}

	public function get_string($remote) {
		$id = $this->file_id($remote);
		if (!$id) {
			throw new RuntimeException('Fichier introuvable sur Google Drive : ' . $remote);
		}
		$response = $this->http('GET', self::API . '/files/' . $id . '?alt=media', $this->bearer());
		if ($response['code'] !== 200) {
			throw new RuntimeException('Google Drive : lecture refusée (' . self::why($response) . ').');
		}
		return $response['body'];
	}

	public function delete($remote) {
		$id = $this->file_id($remote);
		if ($id) {
			$this->http('DELETE', self::API . '/files/' . $id, $this->bearer());
		}
	}

	public function quota() {
		$response = $this->json('GET', self::API . '/about?fields=storageQuota');
		if ($response['code'] !== 200) {
			return null;
		}
		$quota = $response['json']['storageQuota'] ?? array();
		return array('used' => (float) ($quota['usage'] ?? 0), 'total' => (float) ($quota['limit'] ?? 0));
	}
}

/** Microsoft OneDrive through Microsoft Graph. */
class Rempart_Storage_Onedrive extends Rempart_Storage_Oauth {
	const API = 'https://graph.microsoft.com/v1.0/me/drive';
	const LOGIN = 'https://login.microsoftonline.com/common/oauth2/v2.0';
	const SCOPE = 'offline_access Files.ReadWrite';
	// Upload pieces must be a multiple of 320 KiB.
	const PIECE = 10485760;

	public static function authorize_url(array $config, $state) {
		return self::LOGIN . '/authorize?' . http_build_query(array(
			'client_id'     => $config['client_id'],
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'state'         => $state,
		));
	}

	public static function exchange_code(array $config, $code, array $query) {
		$data = self::post_form(self::LOGIN . '/token', array(
			'code'          => $code,
			'client_id'     => $config['client_id'],
			'client_secret' => $config['client_secret'],
			'redirect_uri'  => self::redirect_uri(),
			'grant_type'    => 'authorization_code',
			'scope'         => self::SCOPE,
		));
		return array('refresh_token' => $data['refresh_token']);
	}

	protected function refresh_access_token() {
		$data = self::post_form(self::LOGIN . '/token', array(
			'refresh_token' => $this->config['refresh_token'],
			'client_id'     => $this->config['client_id'],
			'client_secret' => $this->config['client_secret'],
			'grant_type'    => 'refresh_token',
			'scope'         => self::SCOPE,
		));
		return array('token' => $data['access_token'], 'expires' => time() + (int) $data['expires_in'], 'refresh' => $data['refresh_token'] ?? '');
	}

	private function drive_path($name = '') {
		$folder = $this->folder() === '' ? 'Rempart Backup' : $this->folder();
		$path = $folder . ($name === '' ? '' : '/' . $name);
		return '/root:/' . implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	public function list_files() {
		$files = array();
		$url = self::API . $this->drive_path() . ':/children?$select=name,size,file&$top=500';
		while ($url) {
			$response = $this->json('GET', $url);
			if ($response['code'] === 404) {
				return array();
			}
			if ($response['code'] !== 200) {
				throw new RuntimeException('OneDrive : ' . self::why($response));
			}
			foreach ((array) ($response['json']['value'] ?? array()) as $item) {
				if (isset($item['file'])) {
					$files[] = array('name' => $item['name'], 'size' => (float) ($item['size'] ?? 0));
				}
			}
			$url = $response['json']['@odata.nextLink'] ?? null;
		}
		return $files;
	}

	public function upload_step($local, $remote, array &$state, $deadline, $beat) {
		$total = filesize($local);
		if (empty($state['session'])) {
			$response = $this->json('POST', self::API . $this->drive_path($remote) . ':/createUploadSession', array('item' => array('@microsoft.graph.conflictBehavior' => 'replace')));
			if ($response['code'] !== 200 || empty($response['json']['uploadUrl'])) {
				throw new RuntimeException('OneDrive : envoi refusé (' . self::why($response) . ').');
			}
			$state['session'] = $response['json']['uploadUrl'];
			$state['offset'] = 0;
		}
		while ($state['offset'] < $total) {
			if (time() >= $deadline) {
				return false;
			}
			self::keep_alive();
			$chunk = self::read_range($local, (int) $state['offset'], self::PIECE);
			$end = $state['offset'] + strlen($chunk) - 1;
			$response = $this->http('PUT', $state['session'], array('Content-Range' => "bytes {$state['offset']}-{$end}/{$total}"), $chunk);
			if (!in_array($response['code'], array(200, 201, 202), true)) {
				throw new RuntimeException('OneDrive : envoi interrompu (' . self::why($response) . ').');
			}
			$state['offset'] += strlen($chunk);
			call_user_func($beat, $state['offset'], $total);
		}
		return true;
	}

	public function upload_abort($remote, array $state) {
		if (!empty($state['session'])) {
			try {
				$this->http('DELETE', $state['session']);
			} catch (Exception $e) {
				rempart_debug($e->getMessage());
			}
		}
	}

	public function download_step($remote, $local, array &$state, $deadline, $beat) {
		$offset = (int) ($state['offset'] ?? 0);
		while (time() < $deadline) {
			self::keep_alive();
			$end = $offset + self::CHUNK - 1;
			$response = $this->http('GET', self::API . $this->drive_path($remote) . ':/content', $this->bearer() + array('Range' => "bytes={$offset}-{$end}"));
			if ($response['code'] === 416) {
				return true;
			}
			if ($response['code'] !== 206 && $response['code'] !== 200) {
				throw new RuntimeException('OneDrive : téléchargement refusé (' . self::why($response) . ').');
			}
			self::append_local($local, $offset, $response['body']);
			$offset += strlen($response['body']);
			$state['offset'] = $offset;
			$total = preg_match('#/(\d+)$#', (string) $response['headers']['content-range'], $m) ? (float) $m[1] : $offset;
			call_user_func($beat, $offset, $total);
			if ($offset >= $total) {
				return true;
			}
		}
		return false;
	}

	public function put_string($remote, $content) {
		$response = $this->http('PUT', self::API . $this->drive_path($remote) . ':/content', $this->bearer() + array('Content-Type' => 'application/octet-stream'), $content);
		if ($response['code'] !== 200 && $response['code'] !== 201) {
			throw new RuntimeException('OneDrive : écriture refusée (' . self::why($response) . ').');
		}
	}

	public function get_string($remote) {
		$response = $this->http('GET', self::API . $this->drive_path($remote) . ':/content', $this->bearer());
		if ($response['code'] !== 200) {
			throw new RuntimeException('OneDrive : lecture refusée (' . self::why($response) . ').');
		}
		return $response['body'];
	}

	public function delete($remote) {
		$this->http('DELETE', self::API . $this->drive_path($remote), $this->bearer());
	}

	public function quota() {
		$response = $this->json('GET', self::API . '?$select=quota');
		if ($response['code'] !== 200) {
			return null;
		}
		$quota = $response['json']['quota'] ?? array();
		return array('used' => (float) ($quota['used'] ?? 0), 'total' => (float) ($quota['total'] ?? 0));
	}
}

/** Dropbox. */
class Rempart_Storage_Dropbox extends Rempart_Storage_Oauth {
	const API = 'https://api.dropboxapi.com/2';
	const CONTENT = 'https://content.dropboxapi.com/2';

	public static function authorize_url(array $config, $state) {
		return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query(array(
			'client_id'         => $config['client_id'],
			'redirect_uri'      => self::redirect_uri(),
			'response_type'     => 'code',
			'token_access_type' => 'offline',
			'state'             => $state,
		));
	}

	public static function exchange_code(array $config, $code, array $query) {
		$data = self::post_form(self::API_TOKEN, array(
			'code'          => $code,
			'client_id'     => $config['client_id'],
			'client_secret' => $config['client_secret'],
			'redirect_uri'  => self::redirect_uri(),
			'grant_type'    => 'authorization_code',
		));
		return array('refresh_token' => $data['refresh_token']);
	}

	const API_TOKEN = 'https://api.dropboxapi.com/oauth2/token';

	protected function refresh_access_token() {
		$data = self::post_form(self::API_TOKEN, array(
			'refresh_token' => $this->config['refresh_token'],
			'client_id'     => $this->config['client_id'],
			'client_secret' => $this->config['client_secret'],
			'grant_type'    => 'refresh_token',
		));
		return array('token' => $data['access_token'], 'expires' => time() + (int) $data['expires_in']);
	}

	private function drive_path($name = '') {
		$folder = $this->folder() === '' ? 'Rempart Backup' : $this->folder();
		return '/' . $folder . ($name === '' ? '' : '/' . $name);
	}

	/** Dropbox wants non-ASCII characters of its JSON header escaped. */
	private static function arg(array $value) {
		return str_replace('/', '\/', preg_replace_callback('/[\x80-\x{10FFFF}]/u', function ($m) {
			$code = mb_convert_encoding($m[0], 'UTF-16BE', 'UTF-8');
			return '\\u' . bin2hex($code);
		}, wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
	}

	private function content_call($path, array $arg, $body, array $extra = array()) {
		return $this->http('POST', self::CONTENT . $path, $this->bearer() + $extra + array(
			'Dropbox-API-Arg' => self::arg($arg),
			'Content-Type'    => 'application/octet-stream',
		), $body);
	}

	public function list_files() {
		$files = array();
		$response = $this->json('POST', self::API . '/files/list_folder', array('path' => $this->drive_path(), 'limit' => 2000));
		if ($response['code'] === 409) {
			return array(); // The folder doesn't exist yet.
		}
		while (true) {
			if ($response['code'] !== 200) {
				throw new RuntimeException('Dropbox : ' . self::why($response));
			}
			foreach ((array) ($response['json']['entries'] ?? array()) as $entry) {
				if (($entry['.tag'] ?? '') === 'file') {
					$files[] = array('name' => $entry['name'], 'size' => (float) ($entry['size'] ?? 0));
				}
			}
			if (empty($response['json']['has_more'])) {
				break;
			}
			$response = $this->json('POST', self::API . '/files/list_folder/continue', array('cursor' => $response['json']['cursor']));
		}
		return $files;
	}

	public function upload_step($local, $remote, array &$state, $deadline, $beat) {
		$total = filesize($local);
		if (empty($state['session'])) {
			$response = $this->content_call('/files/upload_session/start', array('close' => false), '');
			$json = json_decode($response['body'], true);
			if ($response['code'] !== 200 || empty($json['session_id'])) {
				throw new RuntimeException('Dropbox : envoi refusé (' . self::why($response) . ').');
			}
			$state['session'] = $json['session_id'];
			$state['offset'] = 0;
		}
		while ($state['offset'] < $total) {
			if (time() >= $deadline) {
				return false;
			}
			self::keep_alive();
			$chunk = self::read_range($local, (int) $state['offset'], self::CHUNK);
			$response = $this->content_call('/files/upload_session/append_v2', array('cursor' => array('session_id' => $state['session'], 'offset' => (int) $state['offset']), 'close' => false), $chunk);
			if ($response['code'] !== 200) {
				throw new RuntimeException('Dropbox : envoi interrompu (' . self::why($response) . ').');
			}
			$state['offset'] += strlen($chunk);
			call_user_func($beat, $state['offset'], $total);
		}
		$response = $this->content_call('/files/upload_session/finish', array(
			'cursor' => array('session_id' => $state['session'], 'offset' => (int) $total),
			'commit' => array('path' => $this->drive_path($remote), 'mode' => 'overwrite', 'mute' => true),
		), '');
		if ($response['code'] !== 200) {
			throw new RuntimeException('Dropbox : fin de l\'envoi refusée (' . self::why($response) . ').');
		}
		return true;
	}

	public function upload_abort($remote, array $state) {
		// Unfinished Dropbox upload sessions expire on their own.
	}

	public function download_step($remote, $local, array &$state, $deadline, $beat) {
		$offset = (int) ($state['offset'] ?? 0);
		while (time() < $deadline) {
			self::keep_alive();
			$end = $offset + self::CHUNK - 1;
			$response = $this->http('POST', self::CONTENT . '/files/download', $this->bearer() + array(
				'Dropbox-API-Arg' => self::arg(array('path' => $this->drive_path($remote))),
				'Content-Type'    => 'text/plain',
				'Range'           => "bytes={$offset}-{$end}",
			), '');
			if ($response['code'] === 416) {
				return true;
			}
			if ($response['code'] !== 206 && $response['code'] !== 200) {
				throw new RuntimeException('Dropbox : téléchargement refusé (' . self::why($response) . ').');
			}
			self::append_local($local, $offset, $response['body']);
			$offset += strlen($response['body']);
			$state['offset'] = $offset;
			$total = preg_match('#/(\d+)$#', (string) $response['headers']['content-range'], $m) ? (float) $m[1] : $offset;
			call_user_func($beat, $offset, $total);
			if ($offset >= $total) {
				return true;
			}
		}
		return false;
	}

	public function put_string($remote, $content) {
		$response = $this->content_call('/files/upload', array('path' => $this->drive_path($remote), 'mode' => 'overwrite', 'mute' => true), $content);
		if ($response['code'] !== 200) {
			throw new RuntimeException('Dropbox : écriture refusée (' . self::why($response) . ').');
		}
	}

	public function get_string($remote) {
		$response = $this->http('POST', self::CONTENT . '/files/download', $this->bearer() + array(
			'Dropbox-API-Arg' => self::arg(array('path' => $this->drive_path($remote))),
			'Content-Type'    => 'text/plain',
		), '');
		if ($response['code'] !== 200) {
			throw new RuntimeException('Dropbox : lecture refusée (' . self::why($response) . ').');
		}
		return $response['body'];
	}

	public function delete($remote) {
		$this->json('POST', self::API . '/files/delete_v2', array('path' => $this->drive_path($remote)));
	}

	public function quota() {
		$response = $this->json('POST', self::API . '/users/get_space_usage', 'null');
		if ($response['code'] !== 200) {
			return null;
		}
		return array('used' => (float) ($response['json']['used'] ?? 0), 'total' => (float) ($response['json']['allocation']['allocated'] ?? 0));
	}
}

/** pCloud. Its tokens don't expire; the API host depends on the account's region. */
class Rempart_Storage_Pcloud extends Rempart_Storage_Oauth {
	public static function authorize_url(array $config, $state) {
		return 'https://my.pcloud.com/oauth2/authorize?' . http_build_query(array(
			'client_id'     => $config['client_id'],
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'state'         => $state,
		));
	}

	public static function exchange_code(array $config, $code, array $query) {
		$host = !empty($query['hostname']) && preg_match('/^[a-z]*api\.pcloud\.com$/', $query['hostname']) ? $query['hostname'] : 'api.pcloud.com';
		$response = wp_remote_get('https://' . $host . '/oauth2_token?' . http_build_query(array(
			'client_id'     => $config['client_id'],
			'client_secret' => $config['client_secret'],
			'code'          => $code,
		)), array('timeout' => 60));
		if (is_wp_error($response)) {
			throw new RuntimeException('Connexion impossible : ' . $response->get_error_message());
		}
		$data = json_decode(wp_remote_retrieve_body($response), true);
		if (empty($data['access_token'])) {
			throw new RuntimeException('pCloud refuse l\'autorisation : ' . ($data['error'] ?? 'réponse inconnue'));
		}
		return array('refresh_token' => $data['access_token'], 'api_host' => $host);
	}

	protected function refresh_access_token() {
		return array('token' => $this->config['refresh_token'], 'expires' => time() + 86400);
	}

	private function call($method, array $query = array(), $body = null) {
		$host = !empty($this->config['api_host']) ? $this->config['api_host'] : 'api.pcloud.com';
		$url = 'https://' . $host . '/' . $method . ($query ? '?' . http_build_query($query) : '');
		$response = $this->http($body === null ? 'GET' : 'PUT', $url, $this->bearer(), $body);
		$json = json_decode($response['body'], true);
		if (!is_array($json) || !empty($json['result'])) {
			throw new RuntimeException('pCloud : ' . (is_array($json) ? ($json['error'] ?? 'erreur ' . $json['result']) : 'réponse illisible'));
		}
		return $json;
	}

	private function path($name = '') {
		$folder = $this->folder() === '' ? 'Rempart Backup' : $this->folder();
		return '/' . $folder . ($name === '' ? '' : '/' . $name);
	}

	private function ensure_folder() {
		$path = '';
		foreach (explode('/', trim($this->path(), '/')) as $part) {
			$path .= '/' . $part;
			$made = $this->call('createfolderifnotexists', array('path' => $path));
		}
		return $made['metadata']['folderid'];
	}

	public function list_files() {
		try {
			$json = $this->call('listfolder', array('path' => $this->path()));
		} catch (RuntimeException $e) {
			return array();
		}
		$files = array();
		foreach ((array) ($json['metadata']['contents'] ?? array()) as $item) {
			if (empty($item['isfolder'])) {
				$files[] = array('name' => $item['name'], 'size' => (float) ($item['size'] ?? 0));
			}
		}
		return $files;
	}

	public function upload_step($local, $remote, array &$state, $deadline, $beat) {
		$total = filesize($local);
		if (empty($state['session'])) {
			$state['folder_id'] = $this->ensure_folder();
			$made = $this->call('upload_create');
			$state['session'] = $made['uploadid'];
			$state['offset'] = 0;
		}
		while ($state['offset'] < $total) {
			if (time() >= $deadline) {
				return false;
			}
			self::keep_alive();
			$chunk = self::read_range($local, (int) $state['offset'], self::CHUNK);
			$this->call('upload_write', array('uploadid' => $state['session'], 'uploadoffset' => (int) $state['offset']), $chunk);
			$state['offset'] += strlen($chunk);
			call_user_func($beat, $state['offset'], $total);
		}
		$this->call('upload_save', array('uploadid' => $state['session'], 'name' => $remote, 'folderid' => $state['folder_id']));
		return true;
	}

	public function upload_abort($remote, array $state) {
		// pCloud removes unfinished upload sessions on its own.
	}

	private function file_link($remote) {
		$json = $this->call('getfilelink', array('path' => $this->path($remote)));
		return 'https://' . $json['hosts'][0] . $json['path'];
	}

	public function download_step($remote, $local, array &$state, $deadline, $beat) {
		$link = $this->file_link($remote);
		$offset = (int) ($state['offset'] ?? 0);
		while (time() < $deadline) {
			self::keep_alive();
			$end = $offset + self::CHUNK - 1;
			$response = $this->http('GET', $link, array('Range' => "bytes={$offset}-{$end}"));
			if ($response['code'] === 416) {
				return true;
			}
			if ($response['code'] !== 206 && $response['code'] !== 200) {
				throw new RuntimeException('pCloud : téléchargement refusé (code ' . $response['code'] . ').');
			}
			self::append_local($local, $offset, $response['body']);
			$offset += strlen($response['body']);
			$state['offset'] = $offset;
			$total = preg_match('#/(\d+)$#', (string) $response['headers']['content-range'], $m) ? (float) $m[1] : $offset;
			call_user_func($beat, $offset, $total);
			if ($offset >= $total) {
				return true;
			}
		}
		return false;
	}

	public function put_string($remote, $content) {
		$folder = $this->ensure_folder();
		$made = $this->call('upload_create');
		$this->call('upload_write', array('uploadid' => $made['uploadid'], 'uploadoffset' => 0), $content);
		$this->call('upload_save', array('uploadid' => $made['uploadid'], 'name' => $remote, 'folderid' => $folder));
	}

	public function get_string($remote) {
		$response = $this->http('GET', $this->file_link($remote));
		if ($response['code'] !== 200) {
			throw new RuntimeException('pCloud : lecture refusée (code ' . $response['code'] . ').');
		}
		return $response['body'];
	}

	public function delete($remote) {
		try {
			$this->call('deletefile', array('path' => $this->path($remote)));
		} catch (RuntimeException $e) {
			rempart_debug($e->getMessage());
		}
	}

	public function quota() {
		try {
			$json = $this->call('userinfo');
		} catch (RuntimeException $e) {
			return null;
		}
		return array('used' => (float) ($json['usedquota'] ?? 0), 'total' => (float) ($json['quota'] ?? 0));
	}
}

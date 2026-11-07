<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * WebDAV. The standard has no resumable upload, so a backup is sent in one long request
 * with the time limit lifted and a heartbeat that keeps the job alive meanwhile.
 */
class Rempart_Storage_Webdav extends Rempart_Storage {
	const DOWNLOAD_CHUNK = 4194304;

	private function base_url() {
		$url = rtrim($this->config['url'], '/');
		$folder = $this->folder();
		if ($folder !== '') {
			$url .= '/' . implode('/', array_map('rawurlencode', explode('/', $folder)));
		}
		return $url;
	}

	private function auth() {
		return array('Authorization' => 'Basic ' . base64_encode($this->config['user'] . ':' . $this->config['pass']));
	}

	private function file_url($name) {
		return $this->base_url() . '/' . rawurlencode($name);
	}

	private function ensure_folder() {
		$url = rtrim($this->config['url'], '/');
		$folder = $this->folder();
		if ($folder === '') {
			return;
		}
		foreach (explode('/', $folder) as $part) {
			$url .= '/' . rawurlencode($part);
			$response = $this->http('MKCOL', $url . '/', $this->auth());
			if (in_array($response['code'], array(401, 403), true)) {
				throw new RuntimeException(sprintf(__('Le serveur WebDAV refuse l\'accès (code %s).', 'rempart-backup'), $response['code']));
			}
		}
	}

	public function test() {
		$this->ensure_folder();
		$probe = '.rempart-test-' . wp_generate_password(6, false);
		$this->put_string($probe, 'ok');
		$this->delete($probe);
	}

	public function list_files() {
		$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:resourcetype/></d:prop></d:propfind>';
		$response = $this->http('PROPFIND', $this->base_url() . '/', $this->auth() + array('Depth' => '1', 'Content-Type' => 'application/xml'), $body);
		if ($response['code'] === 404) {
			return array();
		}
		if ($response['code'] !== 207) {
			throw new RuntimeException(sprintf(__('Liste WebDAV refusée (code %s).', 'rempart-backup'), $response['code']));
		}
		$files = array();
		if (preg_match_all('#<(?:\w+:)?response\b.*?</(?:\w+:)?response>#si', $response['body'], $blocks)) {
			foreach ($blocks[0] as $block) {
				if (preg_match('#<(?:\w+:)?collection\b#i', $block) || !preg_match('#<(?:\w+:)?href[^>]*>([^<]+)<#i', $block, $href)) {
					continue;
				}
				$name = rawurldecode(basename(html_entity_decode($href[1])));
				$size = preg_match('#<(?:\w+:)?getcontentlength[^>]*>(\d+)<#i', $block, $m) ? (float) $m[1] : 0;
				$files[] = array('name' => $name, 'size' => $size);
			}
		}
		return $files;
	}

	public function upload_step($local, $remote, array &$state, $deadline, $beat) {
		if (!function_exists('curl_init')) {
			throw new RuntimeException(__('L\'envoi WebDAV demande le module PHP cURL.', 'rempart-backup'));
		}
		$this->ensure_folder();
		@set_time_limit(0);
		$total = filesize($local);
		$source = fopen($local, 'rb');
		$curl = curl_init($this->file_url($remote));
		$last_beat = 0;
		curl_setopt_array($curl, array(
			CURLOPT_PUT              => true,
			CURLOPT_INFILE           => $source,
			CURLOPT_INFILESIZE       => $total,
			CURLOPT_RETURNTRANSFER   => true,
			CURLOPT_HTTPAUTH         => CURLAUTH_BASIC, // Sent from the start: a retry after a refusal can't rewind the file.
			CURLOPT_USERPWD          => $this->config['user'] . ':' . $this->config['pass'],
			CURLOPT_NOPROGRESS       => false,
			CURLOPT_HTTPHEADER       => array('Expect:'),
			CURLOPT_PROGRESSFUNCTION => function ($handle, $down_total, $down_now, $up_total, $up_now) use ($beat, $total, &$last_beat) {
				if (time() - $last_beat >= 5) {
					$last_beat = time();
					call_user_func($beat, $up_now, $total);
				}
				return 0;
			},
		));
		curl_exec($curl);
		$code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error = curl_error($curl);
		fclose($source);
		if ($error !== '') {
			throw new RuntimeException(__('Envoi WebDAV interrompu : ', 'rempart-backup') . $error);
		}
		if ($code < 200 || $code >= 300) {
			throw new RuntimeException(sprintf(__('Envoi WebDAV refusé (code %s).', 'rempart-backup'), $code));
		}
		$state['offset'] = $total;
		return true;
	}

	public function download_step($remote, $local, array &$state, $deadline, $beat) {
		$offset = (int) ($state['offset'] ?? 0);
		while (time() < $deadline) {
			self::keep_alive();
			$end = $offset + self::DOWNLOAD_CHUNK - 1;
			$response = $this->http('GET', $this->file_url($remote), $this->auth() + array('Range' => "bytes={$offset}-{$end}"));
			if ($response['code'] === 416) {
				return true;
			}
			if ($response['code'] === 200) {
				// The server ignored the range and sent the whole file.
				self::append_local($local, 0, $response['body']);
				$state['offset'] = strlen($response['body']);
				return true;
			}
			if ($response['code'] !== 206) {
				throw new RuntimeException(sprintf(__('Téléchargement WebDAV refusé (code %s).', 'rempart-backup'), $response['code']));
			}
			self::append_local($local, $offset, $response['body']);
			$offset += strlen($response['body']);
			$state['offset'] = $offset;
			$range = (string) $response['headers']['content-range'];
			$total = preg_match('#/(\d+)$#', $range, $m) ? (float) $m[1] : 0;
			call_user_func($beat, $offset, $total);
			if ($total && $offset >= $total) {
				return true;
			}
		}
		return false;
	}

	public function put_string($remote, $content) {
		$response = $this->http('PUT', $this->file_url($remote), $this->auth(), $content);
		if ($response['code'] < 200 || $response['code'] >= 300) {
			throw new RuntimeException(sprintf(__('Écriture WebDAV refusée (code %s).', 'rempart-backup'), $response['code']));
		}
	}

	public function get_string($remote) {
		$response = $this->http('GET', $this->file_url($remote), $this->auth());
		if ($response['code'] !== 200) {
			throw new RuntimeException(sprintf(__('Lecture WebDAV refusée (code %s).', 'rempart-backup'), $response['code']));
		}
		return $response['body'];
	}

	public function delete($remote) {
		$this->http('DELETE', $this->file_url($remote), $this->auth());
	}
}

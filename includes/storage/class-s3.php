<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Amazon S3 and services that speak its protocol (DigitalOcean Spaces, Linode, Vultr,
 * Cloudflare R2, Wasabi, MinIO...). Requests are signed with AWS Signature Version 4.
 */
class Rempart_Storage_S3 extends Rempart_Storage {
	const MIN_PART = 5242880;
	const MAX_PARTS = 9000;
	const DOWNLOAD_CHUNK = 5242880;

	private function endpoint() {
		$endpoint = trim((string) ($this->config['endpoint'] ?? ''));
		if ($endpoint === '') {
			$endpoint = 's3.' . $this->region() . '.amazonaws.com';
		}
		if (!preg_match('#^https?://#i', $endpoint)) {
			$endpoint = 'https://' . $endpoint;
		}
		return rtrim($endpoint, '/');
	}

	private function region() {
		$region = trim((string) ($this->config['region'] ?? ''));
		return $region === '' ? 'us-east-1' : $region;
	}

	private function key_for($name) {
		$folder = $this->folder();
		return ($folder === '' ? '' : $folder . '/') . $name;
	}

	/** Amazon routes by bucket host name; other services are safest with the bucket in the path. */
	private function url_parts($key) {
		$endpoint = $this->endpoint();
		$bucket = $this->config['bucket'];
		$encoded = implode('/', array_map('rawurlencode', explode('/', $key)));
		if (preg_match('#amazonaws\.com$#i', $endpoint)) {
			$scheme_host = preg_replace('#^(https?://)#i', '$1' . $bucket . '.', $endpoint);
			$path = '/' . $encoded;
		} else {
			$scheme_host = $endpoint;
			$path = '/' . rawurlencode($bucket) . ($key === '' ? '' : '/' . $encoded);
		}
		return array($scheme_host, $path);
	}

	/** Send one signed request. */
	private function request($method, $key, array $query = array(), $body = '', array $headers = array()) {
		list($scheme_host, $path) = $this->url_parts($key);
		$host = preg_replace('#^https?://#i', '', $scheme_host);

		ksort($query);
		$parts = array();
		foreach ($query as $k => $v) {
			$parts[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
		}
		$canonical_query = implode('&', $parts);

		$now = time();
		$amz_date = gmdate('Ymd\THis\Z', $now);
		$date = gmdate('Ymd', $now);
		$payload_hash = hash('sha256', (string) $body);

		$sign = array('host' => $host, 'x-amz-content-sha256' => $payload_hash, 'x-amz-date' => $amz_date);
		foreach ($headers as $k => $v) {
			$sign[strtolower($k)] = trim((string) $v);
		}
		ksort($sign);
		$canonical_headers = '';
		foreach ($sign as $k => $v) {
			$canonical_headers .= $k . ':' . $v . "\n";
		}
		$signed_headers = implode(';', array_keys($sign));

		$canonical = implode("\n", array($method, $path, $canonical_query, $canonical_headers, $signed_headers, $payload_hash));
		$scope = $date . '/' . $this->region() . '/s3/aws4_request';
		$to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash('sha256', $canonical);

		$k = hash_hmac('sha256', $date, 'AWS4' . $this->config['secret_key'], true);
		$k = hash_hmac('sha256', $this->region(), $k, true);
		$k = hash_hmac('sha256', 's3', $k, true);
		$k = hash_hmac('sha256', 'aws4_request', $k, true);
		$signature = hash_hmac('sha256', $to_sign, $k);

		$send = array();
		foreach ($sign as $name => $value) {
			if ($name !== 'host') {
				$send[$name] = $value;
			}
		}
		$send['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->config['access_key'] . '/' . $scope
			. ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

		$url = $scheme_host . $path . ($canonical_query === '' ? '' : '?' . $canonical_query);
		return $this->http($method, $url, $send, $body === '' ? null : $body);
	}

	/** What the service said, with the HTTP code, so "AccessDenied" alone never has to be guessed at. */
	private static function error_text($response) {
		$http = 'HTTP ' . $response['code'];
		if (preg_match('#<Code>([^<]+)</Code>#', $response['body'], $code)) {
			$message = preg_match('#<Message>([^<]+)</Message>#', $response['body'], $m) ? ' : ' . $m[1] : '';
			$resource = preg_match('#<Resource>([^<]+)</Resource>#', $response['body'], $r) ? ', ressource ' . $r[1] : '';
			return $code[1] . $message . ' (' . $http . $resource . ')';
		}
		return $http;
	}

	public function test() {
		$head = $this->request('HEAD', '');
		if ($head['code'] === 404) {
			$body = '';
			if ($this->region() !== 'us-east-1' && preg_match('#amazonaws\.com$#i', $this->endpoint())) {
				$body = '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>' . esc_html($this->region()) . '</LocationConstraint></CreateBucketConfiguration>';
			}
			$made = $this->request('PUT', '', array(), $body);
			if ($made['code'] < 200 || $made['code'] >= 300) {
				throw new RuntimeException('Impossible de créer le bucket : ' . self::error_text($made));
			}
		} elseif ($head['code'] === 403) {
			throw new RuntimeException('Accès refusé au bucket : vérifiez la clé d\'accès, la clé secrète et la région.');
		} elseif ($head['code'] >= 300) {
			throw new RuntimeException('Le service S3 répond : code ' . $head['code'] . '. Vérifiez l\'adresse et la région.');
		}
		$this->test_operations();
	}

	/**
	 * Tries everything a backup does, so a key that can write one small file but not the rest
	 * is caught when the location is added, with the name of the operation that was refused.
	 */
	private function test_operations() {
		$id = wp_generate_password(6, false);
		$names = array('.rempart-test-' . $id, 'rempart-test-' . $id . '.info');
		$content = '{"test":true}';
		$made = array();
		try {
			foreach ($names as $name) {
				$this->step('écriture de « ' . $name . ' »', function () use ($name, $content) {
					$this->put_string($name, $content);
				});
				$made[] = $name;
				$this->step('lecture de « ' . $name . ' »', function () use ($name, $content) {
					if ($this->get_string($name) !== $content) {
						throw new RuntimeException('le contenu relu est différent');
					}
				});
			}
			$this->step('liste des fichiers du dossier', function () use ($names) {
				$found = array_column($this->list_files(), 'name');
				if (!in_array($names[0], $found, true)) {
					throw new RuntimeException('le fichier envoyé n\'apparaît pas dans la liste');
				}
			});
			$this->step('envoi en plusieurs parties', function () use ($id) {
				$key = $this->key_for('.rempart-multipart-' . $id);
				$started = $this->request('POST', $key, array('uploads' => ''), '', $this->encryption_headers());
				if ($started['code'] !== 200 || !preg_match('#<UploadId>([^<]+)</UploadId>#', $started['body'], $m)) {
					throw new RuntimeException(self::error_text($started));
				}
				$aborted = $this->request('DELETE', $key, array('uploadId' => html_entity_decode($m[1])));
				if ($aborted['code'] >= 300) {
					throw new RuntimeException(self::error_text($aborted));
				}
			});
		} finally {
			foreach ($made as $name) {
				try {
					$this->delete($name);
				} catch (RuntimeException $e) {
					rempart_debug('Nettoyage du test S3 : ' . $e->getMessage());
				}
			}
		}
	}

	private function step($label, callable $action) {
		try {
			call_user_func($action);
		} catch (RuntimeException $e) {
			throw new RuntimeException('Le test a échoué à l\'étape « ' . $label . ' » : ' . $e->getMessage()
				. ' Vérifiez que la clé d\'accès a les droits de lecture et d\'écriture sur ce bucket.');
		}
	}

	public function list_files() {
		$prefix = $this->folder() === '' ? '' : $this->folder() . '/';
		$files = array();
		$token = null;
		do {
			$query = array('list-type' => '2', 'prefix' => $prefix, 'delimiter' => '/', 'max-keys' => '1000');
			if ($token !== null) {
				$query['continuation-token'] = $token;
			}
			$response = $this->request('GET', '', $query);
			if ($response['code'] !== 200) {
				throw new RuntimeException('Liste S3 refusée : ' . self::error_text($response));
			}
			if (preg_match_all('#<Contents>(.*?)</Contents>#s', $response['body'], $blocks)) {
				foreach ($blocks[1] as $block) {
					preg_match('#<Key>([^<]*)</Key>#', $block, $key);
					preg_match('#<Size>(\d+)</Size>#', $block, $size);
					$name = substr(html_entity_decode($key[1], ENT_QUOTES | ENT_XML1), strlen($prefix));
					if ($name !== '') {
						$files[] = array('name' => $name, 'size' => (float) ($size[1] ?? 0));
					}
				}
			}
			$token = preg_match('#<NextContinuationToken>([^<]+)</NextContinuationToken>#', $response['body'], $m) ? html_entity_decode($m[1]) : null;
		} while ($token !== null);
		return $files;
	}

	private function encryption_headers() {
		return !empty($this->config['sse']) ? array('x-amz-server-side-encryption' => 'AES256') : array();
	}

	public function upload_step($local, $remote, array &$state, $deadline, $beat) {
		$total = filesize($local);
		$key = $this->key_for($remote);

		// Small files don't need the multipart dance.
		if ($total < self::MIN_PART) {
			$response = $this->request('PUT', $key, array(), (string) file_get_contents($local), $this->encryption_headers());
			if ($response['code'] < 200 || $response['code'] >= 300) {
				throw new RuntimeException('Envoi S3 refusé : ' . self::error_text($response));
			}
			return true;
		}

		if (empty($state['upload_id'])) {
			$response = $this->request('POST', $key, array('uploads' => ''), '', $this->encryption_headers());
			if ($response['code'] !== 200 || !preg_match('#<UploadId>([^<]+)</UploadId>#', $response['body'], $m)) {
				throw new RuntimeException('Envoi S3 refusé : ' . self::error_text($response));
			}
			$state['upload_id'] = html_entity_decode($m[1]);
			$state['offset'] = 0;
			$state['parts'] = array();
			$state['part_size'] = (int) max(self::MIN_PART, ceil($total / self::MAX_PARTS));
		}

		while ($state['offset'] < $total) {
			if (time() >= $deadline) {
				return false;
			}
			self::keep_alive();
			$chunk = self::read_range($local, (int) $state['offset'], $state['part_size']);
			$number = count($state['parts']) + 1;
			$response = $this->request('PUT', $key, array('partNumber' => $number, 'uploadId' => $state['upload_id']), $chunk);
			if ($response['code'] !== 200) {
				throw new RuntimeException('Envoi S3 interrompu : ' . self::error_text($response));
			}
			$state['parts'][] = trim((string) $response['headers']['etag'], '"');
			$state['offset'] += strlen($chunk);
			call_user_func($beat, $state['offset'], $total);
		}

		$xml = '<CompleteMultipartUpload>';
		foreach ($state['parts'] as $i => $etag) {
			$xml .= '<Part><PartNumber>' . ($i + 1) . '</PartNumber><ETag>"' . esc_html($etag) . '"</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';
		$response = $this->request('POST', $key, array('uploadId' => $state['upload_id']), $xml);
		if ($response['code'] !== 200 || strpos($response['body'], '<Error>') !== false) {
			throw new RuntimeException('Fin de l\'envoi S3 refusée : ' . self::error_text($response));
		}
		return true;
	}

	public function upload_abort($remote, array $state) {
		if (!empty($state['upload_id'])) {
			try {
				$this->request('DELETE', $this->key_for($remote), array('uploadId' => $state['upload_id']));
			} catch (Exception $e) {
				rempart_debug('Annulation S3 : ' . $e->getMessage());
			}
		}
	}

	public function download_step($remote, $local, array &$state, $deadline, $beat) {
		$offset = (int) ($state['offset'] ?? 0);
		$key = $this->key_for($remote);
		while (time() < $deadline) {
			self::keep_alive();
			$end = $offset + self::DOWNLOAD_CHUNK - 1;
			$response = $this->request_range($key, "bytes={$offset}-{$end}");
			if ($response['code'] === 416) {
				return true;
			}
			if ($response['code'] !== 206 && $response['code'] !== 200) {
				throw new RuntimeException('Téléchargement S3 refusé : ' . self::error_text($response));
			}
			self::append_local($local, $offset, $response['body']);
			$offset += strlen($response['body']);
			$state['offset'] = $offset;
			$range = (string) $response['headers']['content-range'];
			$total = preg_match('#/(\d+)$#', $range, $m) ? (float) $m[1] : $offset;
			call_user_func($beat, $offset, $total);
			if ($offset >= $total) {
				return true;
			}
		}
		return false;
	}

	private function request_range($key, $range) {
		return $this->request('GET', $key, array(), '', array('Range' => $range));
	}

	public function put_string($remote, $content) {
		$response = $this->request('PUT', $this->key_for($remote), array(), $content, $this->encryption_headers());
		if ($response['code'] < 200 || $response['code'] >= 300) {
			throw new RuntimeException('Écriture S3 refusée : ' . self::error_text($response));
		}
	}

	public function get_string($remote) {
		$response = $this->request('GET', $this->key_for($remote));
		if ($response['code'] !== 200) {
			throw new RuntimeException('Lecture S3 refusée : ' . self::error_text($response));
		}
		return $response['body'];
	}

	public function delete($remote) {
		$response = $this->request('DELETE', $this->key_for($remote));
		// A file that is already gone is what was wanted.
		if ($response['code'] >= 300 && $response['code'] !== 404) {
			throw new RuntimeException('Suppression S3 refusée : ' . self::error_text($response));
		}
	}
}

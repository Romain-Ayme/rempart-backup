<?php
if (!defined('ABSPATH')) {
	exit;
}

/** SFTP through the bundled phpseclib library: password or private key. */
class Rempart_Storage_Sftp extends Rempart_Storage {
	const CHUNK = 2097152;

	/** @var \phpseclib3\Net\SFTP|null */
	private $sftp = null;

	private function connect() {
		if ($this->sftp) {
			return;
		}
		if (!class_exists('\phpseclib3\Net\SFTP')) {
			require_once REMPART_DIR . '/vendor/autoload.php';
		}
		$port = (int) ($this->config['port'] ?? 0);
		$sftp = new \phpseclib3\Net\SFTP($this->config['host'], $port ? $port : 22, 30);

		// Remember the server's identity on first contact and refuse a change afterwards.
		$host_key = $sftp->getServerPublicHostKey();
		if ($host_key === false) {
			throw new RuntimeException('Connexion SFTP à ' . $this->config['host'] . ' impossible.');
		}
		$fingerprint = hash('sha256', $host_key);
		if (!empty($this->config['host_key']) && !hash_equals($this->config['host_key'], $fingerprint)) {
			throw new RuntimeException('L\'identité du serveur SFTP a changé. Si c\'est normal, modifiez l\'emplacement et enregistrez-le de nouveau.');
		}
		if (empty($this->config['host_key']) && !empty($this->config['id'])) {
			Rempart_Locations::remember($this->config['id'], array('host_key' => $fingerprint));
		}
		$this->config['host_key'] = $fingerprint;

		if (!empty($this->config['private_key'])) {
			$passphrase = !empty($this->config['pass']) ? $this->config['pass'] : false;
			$credential = \phpseclib3\Crypt\PublicKeyLoader::load($this->config['private_key'], $passphrase);
		} else {
			$credential = $this->config['pass'];
		}
		if (!$sftp->login($this->config['user'], $credential)) {
			throw new RuntimeException('Identifiant, mot de passe ou clé SFTP refusé.');
		}
		$this->sftp = $sftp;
	}

	private function base() {
		$folder = trim((string) ($this->config['folder'] ?? ''), '/');
		$absolute = substr((string) ($this->config['folder'] ?? ''), 0, 1) === '/';
		return $folder === '' ? '.' : ($absolute ? '/' : '') . $folder;
	}

	private function path($name) {
		return $this->base() . '/' . $name;
	}

	public function test() {
		$this->connect();
		$base = $this->base();
		if ($base !== '.' && !$this->sftp->is_dir($base) && !$this->sftp->mkdir($base, -1, true)) {
			throw new RuntimeException('Impossible de créer le dossier ' . $base . ' sur le serveur SFTP.');
		}
		$probe = '.rempart-test-' . wp_generate_password(6, false);
		$this->put_string($probe, 'ok');
		$this->delete($probe);
	}

	public function list_files() {
		$this->connect();
		$raw = $this->sftp->rawlist($this->base());
		$files = array();
		foreach ((array) $raw as $name => $attr) {
			if ($name === '.' || $name === '..' || (isset($attr['type']) && (int) $attr['type'] !== 1)) {
				continue;
			}
			$files[] = array('name' => $name, 'size' => isset($attr['size']) ? (float) $attr['size'] : 0);
		}
		return $files;
	}

	public function upload_step($local, $remote, array &$state, $deadline, $beat) {
		$this->connect();
		$total = filesize($local);
		if (empty($state['started'])) {
			$base = $this->base();
			if ($base !== '.' && !$this->sftp->is_dir($base)) {
				$this->sftp->mkdir($base, -1, true);
			}
			$this->sftp->delete($this->path($remote));
			$state['started'] = 1;
			$state['offset'] = 0;
		}
		$source = fopen($local, 'rb');
		fseek($source, (int) $state['offset']);
		while ($state['offset'] < $total && time() < $deadline) {
			self::keep_alive();
			$chunk = fread($source, self::CHUNK);
			if (!$this->sftp->put($this->path($remote), $chunk, \phpseclib3\Net\SFTP::SOURCE_STRING, (int) $state['offset'])) {
				fclose($source);
				throw new RuntimeException('Envoi SFTP interrompu : ' . $this->sftp->getLastSFTPError());
			}
			$state['offset'] += strlen($chunk);
			call_user_func($beat, $state['offset'], $total);
		}
		fclose($source);
		return $state['offset'] >= $total;
	}

	public function download_step($remote, $local, array &$state, $deadline, $beat) {
		$this->connect();
		$offset = (int) ($state['offset'] ?? 0);
		if (empty($state['total'])) {
			$state['total'] = $this->sftp->filesize($this->path($remote));
			if (!$state['total']) {
				throw new RuntimeException('Fichier introuvable sur le serveur SFTP : ' . $remote);
			}
		}
		while ($offset < $state['total'] && time() < $deadline) {
			self::keep_alive();
			$chunk = $this->sftp->get($this->path($remote), false, $offset, self::CHUNK);
			if ($chunk === false || $chunk === '') {
				throw new RuntimeException('Lecture SFTP interrompue.');
			}
			self::append_local($local, $offset, $chunk);
			$offset += strlen($chunk);
			$state['offset'] = $offset;
			call_user_func($beat, $offset, $state['total']);
		}
		return $offset >= $state['total'];
	}

	public function put_string($remote, $content) {
		$this->connect();
		if (!$this->sftp->put($this->path($remote), $content)) {
			throw new RuntimeException('Écriture refusée par le serveur SFTP : ' . $this->sftp->getLastSFTPError());
		}
	}

	public function get_string($remote) {
		$this->connect();
		$content = $this->sftp->get($this->path($remote));
		if ($content === false) {
			throw new RuntimeException('Fichier illisible sur le serveur SFTP : ' . $remote);
		}
		return $content;
	}

	public function delete($remote) {
		$this->connect();
		$this->sftp->delete($this->path($remote));
	}
}

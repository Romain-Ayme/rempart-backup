<?php
if (!defined('ABSPATH')) {
	exit;
}

/** SFTP through the bundled phpseclib library: password or private key. */
class Rempart_Storage_Sftp extends Rempart_Storage {
	const CHUNK = 2097152;

	/** @var \phpseclib4\Net\SFTP|null */
	private $sftp = null;

	private function connect() {
		if ($this->sftp) {
			return;
		}
		if (!class_exists('\phpseclib4\Net\SFTP')) {
			require_once REMPART_DIR . '/vendor/autoload.php';
		}
		$port = (int) ($this->config['port'] ?? 0);
		try {
			$sftp = new \phpseclib4\Net\SFTP($this->config['host'], $port ? $port : 22, 30);
			// Remember the server's identity on first contact and refuse a change afterwards.
			$host_key = $sftp->getServerPublicHostKey();
		} catch (Exception $e) {
			throw new RuntimeException(sprintf(__('Connexion SFTP à %1$s impossible : %2$s', 'rempart-backup'), $this->config['host'], $e->getMessage()));
		}
		if ($host_key === false) {
			throw new RuntimeException(sprintf(__('Connexion SFTP à %s impossible.', 'rempart-backup'), $this->config['host']));
		}
		$fingerprint = hash('sha256', $host_key);
		if (!empty($this->config['host_key']) && !hash_equals($this->config['host_key'], $fingerprint)) {
			throw new RuntimeException(__('L\'identité du serveur SFTP a changé. Si c\'est normal, modifiez l\'emplacement et enregistrez-le de nouveau.', 'rempart-backup'));
		}
		if (empty($this->config['host_key']) && !empty($this->config['id'])) {
			Rempart_Locations::remember($this->config['id'], array('host_key' => $fingerprint));
		}
		$this->config['host_key'] = $fingerprint;

		if (!empty($this->config['private_key'])) {
			$passphrase = !empty($this->config['pass']) ? $this->config['pass'] : false;
			try {
				$credential = \phpseclib4\Crypt\PublicKeyLoader::load($this->config['private_key'], $passphrase);
			} catch (Exception $e) {
				throw new RuntimeException(sprintf(__('Clé SFTP illisible : %s', 'rempart-backup'), $e->getMessage()));
			}
		} else {
			$credential = $this->config['pass'];
		}
		try {
			$logged = $sftp->login($this->config['user'], $credential);
		} catch (Exception $e) {
			throw new RuntimeException(sprintf(__('Connexion SFTP refusée : %s', 'rempart-backup'), $e->getMessage()));
		}
		if (!$logged) {
			throw new RuntimeException(__('Identifiant, mot de passe ou clé SFTP refusé.', 'rempart-backup'));
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
		if ($base !== '.' && !$this->sftp->is_dir($base)) {
			try {
				$this->sftp->mkdir($base, -1, true);
			} catch (Exception $e) {
				throw new RuntimeException(sprintf(__('Impossible de créer le dossier %1$s sur le serveur SFTP : %2$s', 'rempart-backup'), $base, $e->getMessage()));
			}
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
			try {
				$this->sftp->delete($this->path($remote));
			} catch (Exception $e) {
				// Nothing to remove: the file is not there yet.
			}
			$state['started'] = 1;
			$state['offset'] = 0;
		}
		$source = fopen($local, 'rb');
		fseek($source, (int) $state['offset']);
		while ($state['offset'] < $total && time() < $deadline) {
			self::keep_alive();
			$chunk = fread($source, self::CHUNK);
			try {
				$this->sftp->put($this->path($remote), $chunk, \phpseclib4\Net\SFTP::SOURCE_STRING, (int) $state['offset']);
			} catch (Exception $e) {
				fclose($source);
				throw new RuntimeException(__('Envoi SFTP interrompu : ', 'rempart-backup') . $e->getMessage());
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
			try {
				$state['total'] = $this->sftp->filesize($this->path($remote));
			} catch (Exception $e) {
				$state['total'] = 0;
			}
			if (!$state['total']) {
				throw new RuntimeException(sprintf(__('Fichier introuvable sur le serveur SFTP : %s', 'rempart-backup'), $remote));
			}
		}
		while ($offset < $state['total'] && time() < $deadline) {
			self::keep_alive();
			try {
				$chunk = $this->sftp->get($this->path($remote), null, $offset, self::CHUNK);
			} catch (Exception $e) {
				$chunk = false;
			}
			if ($chunk === null || $chunk === false || $chunk === '') {
				throw new RuntimeException(__('Lecture SFTP interrompue.', 'rempart-backup'));
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
		try {
			$this->sftp->put($this->path($remote), $content);
		} catch (Exception $e) {
			throw new RuntimeException(sprintf(__('Écriture refusée par le serveur SFTP : %s', 'rempart-backup'), $e->getMessage()));
		}
	}

	public function get_string($remote) {
		$this->connect();
		try {
			$content = $this->sftp->get($this->path($remote));
		} catch (Exception $e) {
			$content = false;
		}
		if ($content === false || $content === null) {
			throw new RuntimeException(sprintf(__('Fichier illisible sur le serveur SFTP : %s', 'rempart-backup'), $remote));
		}
		return $content;
	}

	public function delete($remote) {
		$this->connect();
		try {
			$this->sftp->delete($this->path($remote));
		} catch (Exception $e) {
			// Already gone or not removable: nothing more to do here.
		}
	}
}

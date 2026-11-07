<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * FTP and FTPS (explicit TLS) over plain sockets, so PHP's optional FTP module isn't needed.
 */
class Rempart_Storage_Ftp extends Rempart_Storage {
	const BLOCK = 262144;

	private $control = null;

	public function __destruct() {
		$this->disconnect();
	}

	private function is_tls() {
		return ($this->config['protocol'] ?? '') === 'ftps';
	}

	private function connect() {
		if ($this->control) {
			return;
		}
		$host = $this->config['host'];
		$port = (int) ($this->config['port'] ?? 0);
		$port = $port ? $port : 21;
		$errno = 0;
		$errstr = '';
		$this->control = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 20);
		if (!$this->control) {
			throw new RuntimeException(sprintf(__('Connexion à %1$s:%2$s impossible : %3$s', 'rempart-backup'), $host, $port, $errstr));
		}
		stream_set_timeout($this->control, 60);
		$this->expect(array(220));

		if ($this->is_tls()) {
			$this->command('AUTH TLS', array(234));
			$this->apply_tls_options($this->control);
			// TLS 1.2 first: many servers insist the data connection reuses this connection's TLS
			// session, and PHP can only do that with TLS 1.2 sessions.
			$context_ok = @stream_socket_enable_crypto($this->control, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
			if (!$context_ok) {
				$context_ok = @stream_socket_enable_crypto($this->control, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			}
			if (!$context_ok) {
				$why = error_get_last();
				$hint = empty($this->config['insecure_tls']) ? ' ' . __('Si le serveur utilise un certificat auto-signé, cochez « Accepter un certificat non vérifié » dans cet emplacement.', 'rempart-backup') : '';
				throw new RuntimeException(sprintf(__('La connexion chiffrée (FTPS) a échoué : %s.', 'rempart-backup'), $why['message'] ?? __('raison inconnue', 'rempart-backup')) . $hint);
			}
		}
		$reply = $this->send('USER ' . $this->config['user']);
		if ($reply[0] === 331) {
			$reply = $this->send('PASS ' . $this->config['pass']);
		}
		if ($reply[0] !== 230) {
			throw new RuntimeException(__('Identifiant ou mot de passe FTP refusé.', 'rempart-backup'));
		}
		if ($this->is_tls()) {
			$this->command('PBSZ 0', array(200));
			$this->command('PROT P', array(200));
		}
		$this->command('TYPE I', array(200));
	}

	/** Certificates are checked unless the person explicitly accepted an unverified one. */
	private function apply_tls_options($stream) {
		$insecure = !empty($this->config['insecure_tls']);
		stream_context_set_option($stream, 'ssl', 'verify_peer', !$insecure);
		stream_context_set_option($stream, 'ssl', 'verify_peer_name', !$insecure);
		stream_context_set_option($stream, 'ssl', 'allow_self_signed', $insecure);
		stream_context_set_option($stream, 'ssl', 'peer_name', $this->config['host']);
	}

	private function disconnect() {
		if ($this->control) {
			@fwrite($this->control, "QUIT\r\n");
			@fclose($this->control);
			$this->control = null;
		}
	}

	private function read_reply() {
		$first = fgets($this->control, 8192);
		if ($first === false) {
			throw new RuntimeException(__('Le serveur FTP a coupé la connexion.', 'rempart-backup'));
		}
		$code = (int) substr($first, 0, 3);
		$text = $first;
		if (isset($first[3]) && $first[3] === '-') {
			while (($line = fgets($this->control, 8192)) !== false) {
				$text .= $line;
				if ((int) substr($line, 0, 3) === $code && isset($line[3]) && $line[3] === ' ') {
					break;
				}
			}
		}
		return array($code, trim($text));
	}

	private function expect(array $codes) {
		$reply = $this->read_reply();
		if (!in_array($reply[0], $codes, true)) {
			throw new RuntimeException(sprintf(__('Réponse inattendue du serveur FTP : %s', 'rempart-backup'), $reply[1]));
		}
		return $reply;
	}

	private function send($command) {
		if (strpbrk($command, "\r\n") !== false) {
			throw new RuntimeException(__('Nom de fichier ou identifiant invalide.', 'rempart-backup'));
		}
		fwrite($this->control, $command . "\r\n");
		return $this->read_reply();
	}

	private function command($command, array $codes) {
		$reply = $this->send($command);
		if (!in_array($reply[0], $codes, true)) {
			throw new RuntimeException(sprintf(__('Le serveur FTP a refusé « %1$s » : %2$s', 'rempart-backup'), strtok($command, ' '), $reply[1]));
		}
		return $reply;
	}

	/** Open a passive data connection. */
	private function open_data() {
		$reply = $this->send('EPSV');
		if ($reply[0] === 229 && preg_match('/\(\|\|\|(\d+)\|\)/', $reply[1], $m)) {
			$host = $this->config['host'];
			$port = (int) $m[1];
		} else {
			$reply = $this->command('PASV', array(227));
			if (!preg_match('/(\d+),(\d+),(\d+),(\d+),(\d+),(\d+)/', $reply[1], $m)) {
				throw new RuntimeException(sprintf(__('Réponse PASV illisible : %s', 'rempart-backup'), $reply[1]));
			}
			$port = ((int) $m[5] << 8) + (int) $m[6];
			$host = "{$m[1]}.{$m[2]}.{$m[3]}.{$m[4]}";
			// Servers behind a router often announce their private address.
			if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				$host = $this->config['host'];
			}
		}
		$errno = 0;
		$errstr = '';
		$data = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 20);
		if (!$data) {
			throw new RuntimeException(sprintf(__('Connexion de données FTP impossible : %s', 'rempart-backup'), $errstr));
		}
		stream_set_timeout($data, 120);
		return $data;
	}

	/** The data channel's TLS starts after the server accepted the transfer command. */
	private function secure_data($data) {
		if ($this->is_tls()) {
			$this->apply_tls_options($data);
			// Passing the control connection lets servers that insist on it reuse the TLS session.
			$ok = @stream_socket_enable_crypto($data, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, $this->control);
			if (!$ok) {
				$ok = @stream_socket_enable_crypto($data, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			}
			if (!$ok) {
				throw new RuntimeException(__('La connexion de données FTPS n\'a pas pu être chiffrée.', 'rempart-backup'));
			}
		}
	}

	/** Paths are relative to the account's own folder unless the person starts one with a slash. */
	private function path($name = '') {
		$raw = (string) ($this->config['folder'] ?? '');
		$folder = $this->folder();
		$lead = substr($raw, 0, 1) === '/' ? '/' : '';
		$base = $folder === '' ? $lead : $lead . $folder . '/';
		return $base . $name;
	}

	private function ensure_folder() {
		$folder = $this->folder();
		if ($folder === '') {
			return;
		}
		$lead = substr((string) ($this->config['folder'] ?? ''), 0, 1) === '/' ? '/' : '';
		$reply = $this->send('PWD');
		$home = preg_match('/"([^"]+)"/', $reply[1], $m) ? $m[1] : '/';
		$path = '';
		foreach (explode('/', $folder) as $i => $part) {
			$path .= ($i ? '/' : $lead) . $part;
			$check = $this->send('CWD ' . $path);
			if ($check[0] === 250) {
				$this->send('CWD ' . $home);
			} else {
				$this->command('MKD ' . $path, array(257));
			}
		}
	}

	public function test() {
		$this->connect();
		$this->ensure_folder();
		$probe = '.rempart-test-' . wp_generate_password(6, false);
		$this->put_string($probe, 'ok');
		$this->delete($probe);
		$this->disconnect();
	}

	public function list_files() {
		$this->connect();
		$data = $this->open_data();
		$listed = rtrim($this->path(), '/');
		$reply = $this->send($listed === '' ? 'NLST' : 'NLST ' . $listed);
		if (!in_array($reply[0], array(125, 150), true)) {
			fclose($data);
			if ($reply[0] === 550 || $reply[0] === 450) {
				return array(); // Empty or missing folder.
			}
			throw new RuntimeException(sprintf(__('Liste des fichiers refusée : %s', 'rempart-backup'), $reply[1]));
		}
		$this->secure_data($data);
		$raw = stream_get_contents($data);
		fclose($data);
		$this->read_reply();

		$files = array();
		foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
			$name = basename(trim($line));
			if ($name === '' || $name === '.' || $name === '..') {
				continue;
			}
			$size_reply = $this->send('SIZE ' . $this->path($name));
			$files[] = array('name' => $name, 'size' => $size_reply[0] === 213 ? (float) substr($size_reply[1], 4) : 0);
		}
		return $files;
	}

	private function remote_size($remote) {
		$reply = $this->send('SIZE ' . $this->path($remote));
		return $reply[0] === 213 ? (float) substr($reply[1], 4) : -1;
	}

	public function upload_step($local, $remote, array &$state, $deadline, $beat) {
		$this->connect();
		if (empty($state['started'])) {
			$this->ensure_folder();
			$state['started'] = 1;
			$state['offset'] = 0;
			if ($this->remote_size($remote) >= 0) {
				$this->send('DELE ' . $this->path($remote));
			}
		} else {
			// Trust the server about what actually arrived, after a slice cut the connection.
			$size = $this->remote_size($remote);
			$state['offset'] = max(0, $size);
		}

		$total = filesize($local);
		$source = fopen($local, 'rb');
		fseek($source, (int) $state['offset']);

		$data = $this->open_data();
		$verb = $state['offset'] > 0 ? 'APPE ' : 'STOR ';
		$reply = $this->send($verb . $this->path($remote));
		if (!in_array($reply[0], array(125, 150), true)) {
			fclose($data);
			fclose($source);
			throw new RuntimeException(sprintf(__('Envoi refusé par le serveur FTP : %s', 'rempart-backup'), $reply[1]));
		}
		$this->secure_data($data);

		$finished = false;
		while (true) {
			if ($state['offset'] >= $total) {
				$finished = true;
				break;
			}
			if (time() >= $deadline) {
				break;
			}
			self::keep_alive();
			$block = fread($source, self::BLOCK);
			$length = strlen($block);
			$sent = 0;
			while ($sent < $length) {
				$n = @fwrite($data, $sent ? substr($block, $sent) : $block);
				if ($n === false || $n === 0) {
					throw new RuntimeException(__('La connexion FTP a été coupée pendant l\'envoi.', 'rempart-backup'));
				}
				$sent += $n;
			}
			$state['offset'] += $length;
			call_user_func($beat, $state['offset'], $total);
		}
		fclose($source);
		fclose($data);
		$this->read_reply();

		$this->disconnect();
		return $finished;
	}

	public function download_step($remote, $local, array &$state, $deadline, $beat) {
		$this->connect();
		$offset = (int) ($state['offset'] ?? 0);
		if (empty($state['total'])) {
			$state['total'] = $this->remote_size($remote);
			if ($state['total'] < 0) {
				throw new RuntimeException(sprintf(__('Fichier introuvable sur le serveur FTP : %s', 'rempart-backup'), $remote));
			}
		}
		$target = fopen($local, 'c+b');
		ftruncate($target, $offset);
		fseek($target, $offset);

		$data = $this->open_data();
		if ($offset > 0) {
			$this->command('REST ' . $offset, array(350));
		}
		$reply = $this->send('RETR ' . $this->path($remote));
		if (!in_array($reply[0], array(125, 150), true)) {
			fclose($data);
			fclose($target);
			throw new RuntimeException(sprintf(__('Téléchargement refusé : %s', 'rempart-backup'), $reply[1]));
		}
		$this->secure_data($data);

		$finished = false;
		while (time() < $deadline) {
			self::keep_alive();
			$block = fread($data, self::BLOCK);
			if ($block === '' || $block === false) {
				if (feof($data)) {
					$finished = true;
				}
				break;
			}
			fwrite($target, $block);
			$offset += strlen($block);
			$state['offset'] = $offset;
			call_user_func($beat, $offset, $state['total']);
		}
		fclose($target);
		fclose($data);
		if ($finished) {
			$this->read_reply();
		}
		$this->disconnect();
		return $finished || $offset >= $state['total'];
	}

	public function put_string($remote, $content) {
		$this->connect();
		$data = $this->open_data();
		$reply = $this->send('STOR ' . $this->path($remote));
		if (!in_array($reply[0], array(125, 150), true)) {
			fclose($data);
			throw new RuntimeException(sprintf(__('Envoi refusé par le serveur FTP : %s', 'rempart-backup'), $reply[1]));
		}
		$this->secure_data($data);
		fwrite($data, $content);
		fclose($data);
		$this->read_reply();
	}

	public function get_string($remote) {
		$this->connect();
		$data = $this->open_data();
		$reply = $this->send('RETR ' . $this->path($remote));
		if (!in_array($reply[0], array(125, 150), true)) {
			fclose($data);
			throw new RuntimeException(sprintf(__('Fichier illisible sur le serveur FTP : %s', 'rempart-backup'), $reply[1]));
		}
		$this->secure_data($data);
		$content = stream_get_contents($data);
		fclose($data);
		$this->read_reply();
		return $content;
	}

	public function delete($remote) {
		$this->connect();
		$this->send('DELE ' . $this->path($remote));
	}
}

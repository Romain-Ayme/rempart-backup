<?php
/**
 * Restores a backup without loading WordPress: its files and tables are being replaced.
 * A copy of this file is written under a random name for each restore, answers only to the
 * secret key of that restore, and is removed when the restore ends.
 */
define('REMPART_RUNNER', 1);

/**
 * WordPress is not loaded here, so there is no gettext: the plugin writes the translations of the
 * messages below into the restore state when it starts the restore, and this looks them up.
 * The messages are in French, which is also the fallback.
 */
if (!function_exists('__')) {
	function __($text, $domain = '') {
		return isset($GLOBALS['rempart_i18n'][$text]) ? $GLOBALS['rempart_i18n'][$text] : $text;
	}
}

/*REMPART_TAR_CLASSES*/

class Rempart_Runner {
	const SLICE = 25;
	const LONG_SLICE = 60;
	const LONG_SCRIPT_ABOVE = 180;
	const MARGIN = 5;
	const BATCH_ROWS = 200;
	const MAX_SQL_ERRORS = 25;

	private $state;
	private $state_file;
	private $deadline;
	private $db = null;

	public function __construct($state_file, array $state) {
		$this->state_file = $state_file;
		$this->state = $state;
		$GLOBALS['rempart_i18n'] = isset($state['i18n']) ? (array) $state['i18n'] : array();
		// Servers that allow long scripts get longer slices.
		$limit = (int) ini_get('max_execution_time');
		$slice = $limit > self::LONG_SCRIPT_ABOVE ? self::LONG_SLICE : self::SLICE;
		$this->deadline = time() + $slice - self::MARGIN;
	}

	private function log($message, $level = 'info') {
		$line = json_encode(array('t' => time(), 'l' => $level, 'm' => (string) $message), JSON_PARTIAL_OUTPUT_ON_ERROR);
		@file_put_contents($this->state['log_file'], $line . "\n", FILE_APPEND);
	}

	private function save() {
		$this->state['heartbeat'] = time();
		$tmp = $this->state_file . '.' . getmypid() . '.tmp';
		$json = json_encode($this->state, JSON_PARTIAL_OUTPUT_ON_ERROR);
		if (@file_put_contents($tmp, "<?php exit;?>\n" . $json) !== false) {
			if (!@rename($tmp, $this->state_file)) {
				@unlink($this->state_file);
				@rename($tmp, $this->state_file);
			}
		}
	}

	public function state() {
		return $this->state;
	}

	/** Remember that nothing but the watcher can be counted on to start the next slice. */
	public function mark_direct() {
		$this->state['direct'] = 1;
	}

	public function run() {
		$lock = @fopen($this->state['lock_file'], 'c');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			return;
		}
		$fresh = @file_get_contents($this->state_file);
		$fresh = $fresh === false ? null : json_decode(preg_replace('/^<\?php exit;\?>\n/', '', $fresh), true);
		if (is_array($fresh)) {
			$fresh['direct'] = !empty($fresh['direct']) || !empty($this->state['direct']);
			$this->state = $fresh;
		}
		if (!empty($this->state['finished'])) {
			flock($lock, LOCK_UN);
			return;
		}
		$this->state['loop']++;
		$this->state['stalls'] = 0;
		$this->save();

		try {
			$this->maintenance(true);
			while (empty($this->state['finished']) && time() < $this->deadline) {
				$phase = $this->state['phase'];
				$this->{'phase_' . $phase}();
				$this->save();
				if ($phase === $this->state['phase']) {
					break;
				}
			}
		} catch (Exception $e) {
			$this->fail($e->getMessage());
		} catch (Error $e) {
			$this->fail(sprintf(__('Erreur PHP : %s', 'rempart-backup'), $e->getMessage()));
		}
		$this->save();
		flock($lock, LOCK_UN);
		fclose($lock);
	}

	private function fail($message) {
		$this->log($message, 'error');
		$this->state['errors'][] = $message;
		$this->state['finished'] = 1;
		$this->state['status'] = 'error';
		$this->maintenance(false);
		@unlink($this->state['tmp_sql']);
	}

	/** Visitors see WordPress's "briefly unavailable" page while tables and files are replaced. */
	private function maintenance($on) {
		$file = $this->state['root'] . '/.maintenance';
		if ($on) {
			@file_put_contents($file, '<?php $upgrading = ' . time() . '; ?>');
		} else {
			@unlink($file);
		}
	}

	/* ----- Files ----- */

	private function phase_extract() {
		$this->extract(false);
	}

	/** Only the database is wanted: stop reading as soon as it has been found. */
	private function phase_extract_db() {
		$this->extract(true);
	}

	private function safe_target($name) {
		$name = str_replace('\\', '/', $name);
		$name = ltrim(preg_replace('#^\./#', '', $name), '/');
		if ($name === '' || preg_match('#(^|/)\.\.(/|$)#', $name) || strpos($name, "\0") !== false || preg_match('#^[A-Za-z]:#', $name)) {
			return null;
		}
		return $name;
	}

	private function skip_on_migration($name) {
		// The destination keeps its own database settings and its own web server rules. When the
		// backup doesn't say where it came from, that may be another site: same precaution.
		$unknown_origin = (string) $this->state['source']['url'] === '';
		return (!empty($this->state['migration']) || $unknown_origin) && ($name === 'wp-config.php' || $name === '.htaccess');
	}

	private function is_protected($name) {
		foreach ($this->state['protected'] as $path) {
			if ($path !== '' && ($name === $path || strpos($name, $path . '/') === 0)) {
				return true;
			}
		}
		return false;
	}

	private function extract($db_only) {
		$read = &$this->state['read'];
		$reader = new Rempart_Tar_Reader($this->state['archive'], (int) $read['member'], (int) $read['skip']);
		$root = $this->state['root'];
		$archive_size = max(1, $reader->file_size);
		$low = 3;
		$high = $this->state['db'] ? 55 : 95;

		$entry = $read['entry'];
		while (true) {
			if (time() >= $this->deadline) {
				break;
			}
			if ($entry === null) {
				$position = $reader->position();
				$read['member'] = $position[0];
				$read['skip'] = $position[1];
				$entry = $reader->next_entry();
				if ($entry === null) {
					$this->log(sprintf(__('%d éléments restaurés', 'rempart-backup'), $this->state['extracted']));
					$reader->close();
					$this->next_after_extract();
					return;
				}
				$entry['written'] = 0;
				$entry['padded'] = (float) (ceil($entry['size'] / 512) * 512);
			}

			$name = $this->safe_target($entry['name']);
			$is_sql = $name === '.rempart/database.sql' || $name === 'softsql.sql';
			$is_meta = $name !== null && (strpos($name, '.rempart/') === 0 || $name === 'softver.txt' || $name === 'softperms.txt'
				|| preg_match('#^[^/]+\.php$#', $name) && !$this->is_root_php($name));

			$target = null;
			if ($is_sql && $this->state['db']) {
				$target = $this->state['tmp_sql'];
				$this->state['sql_found'] = 1;
			} elseif (!$db_only && !$is_sql && !$is_meta && $name !== null && !$this->is_protected($name) && !$this->skip_on_migration(rtrim($name, '/'))) {
				$target = $root . '/' . rtrim($name, '/');
			}

			if ($entry['type'] === '5') {
				if ($target && !is_dir($target)) {
					@mkdir($target, 0755, true);
				}
				$entry = null;
				$this->state['extracted']++;
				continue;
			}
			if ($entry['type'] === '2') {
				if ($target && $entry['link'] !== '') {
					$this->ensure_parent($target);
					if (file_exists($target) || is_link($target)) {
						@unlink($target);
					}
					@symlink($entry['link'], $target);
				}
				$entry = null;
				continue;
			}
			if ($entry['type'] !== '0') {
				$entry = null;
				continue;
			}

			// A regular file, maybe spanning slices.
			$handle = null;
			if ($target) {
				$this->ensure_parent($target);
				$handle = @fopen($target, $entry['written'] > 0 ? 'c+b' : 'wb');
				if ($handle) {
					if ($entry['written'] > 0) {
						ftruncate($handle, (int) min($entry['written'], $entry['size']));
					}
					fseek($handle, (int) min($entry['written'], $entry['size']));
				} else {
					$this->log(sprintf(__('Écriture impossible, ignoré : %s', 'rempart-backup'), $name), 'warning');
					$this->state['warnings']++;
				}
			}
			$finished_file = true;
			$remaining = $entry['padded'] - $entry['written'];
			while ($remaining > 0) {
				if (time() >= $this->deadline) {
					$finished_file = false;
					break;
				}
				$block = $reader->read((int) min($remaining, 1048576));
				if ($block === '') {
					throw new RuntimeException(sprintf(__('Archive incomplète : elle se termine au milieu de %s', 'rempart-backup'), $entry['name']));
				}
				if ($handle) {
					$payload_left = $entry['size'] - $entry['written'];
					if ($payload_left > 0) {
						$piece = strlen($block) > $payload_left ? substr($block, 0, (int) $payload_left) : $block;
						fwrite($handle, $piece);
					}
				}
				$entry['written'] += strlen($block);
				$remaining -= strlen($block);
			}
			if ($handle) {
				fclose($handle);
			}
			if (!$finished_file) {
				// Resume at the exact byte: the header has been read, the payload is underway.
				$position = $reader->position();
				$read['member'] = $position[0];
				$read['skip'] = $position[1];
				break;
			}
			if ($target && $target !== $this->state['tmp_sql']) {
				if ($entry['mtime']) {
					@touch($target, $entry['mtime']);
				}
				$this->state['extracted']++;
			}
			$entry = null;

			if ($db_only && !empty($this->state['sql_found'])) {
				$reader->close();
				$this->state['read']['entry'] = null;
				$this->next_after_extract();
				return;
			}
		}

		$read['entry'] = $entry;
		$progress = min(1, $reader->compressed_offset() / $archive_size);
		$this->state['percent'] = $low + (int) (($high - $low) * $progress);
		if ($entry === null) {
			$position = $reader->position();
			$read['member'] = $position[0];
			$read['skip'] = $position[1];
		}
		$reader->close();
		$this->log(sprintf(__('Fichiers : %d éléments restaurés', 'rempart-backup'), $this->state['extracted']));
	}

	private function is_root_php($name) {
		return in_array($name, array('index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-config-sample.php',
			'wp-config.php', 'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php', 'wp-mail.php', 'wp-settings.php',
			'wp-signup.php', 'wp-trackback.php', 'xmlrpc.php'), true) || !preg_match('/^wp_.*\d{2}-\d{2}-\d{2}/', $name);
	}

	private function ensure_parent($path) {
		$parent = dirname($path);
		if (!is_dir($parent)) {
			@mkdir($parent, 0755, true);
		}
	}

	private function next_after_extract() {
		if ($this->state['db']) {
			if (empty($this->state['sql_found'])) {
				throw new RuntimeException(__('Cette sauvegarde ne contient pas de base de données.', 'rempart-backup'));
			}
			$this->state['phase'] = 'import';
			$this->state['percent'] = max($this->state['percent'], 55);
			$this->log(__('Fichiers terminés. Import de la base de données.', 'rempart-backup'));
		} else {
			$this->state['phase'] = 'finish';
		}
	}

	/* ----- Database ----- */

	private function db() {
		if ($this->db) {
			return $this->db;
		}
		$conn = $this->state['db_conn'];
		$host = $conn['host'];
		$port = null;
		$socket = null;
		if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
			list($host, $extra) = explode(':', $host, 2);
			if (is_numeric($extra)) {
				$port = (int) $extra;
			} else {
				$socket = $extra;
			}
		}
		mysqli_report(MYSQLI_REPORT_OFF);
		$db = @mysqli_connect($host, $conn['user'], $conn['pass'], $conn['name'], $port, $socket);
		if (!$db) {
			throw new RuntimeException(sprintf(__('Connexion à la base de données impossible : %s', 'rempart-backup'), mysqli_connect_error()));
		}
		mysqli_set_charset($db, 'utf8mb4');
		mysqli_query($db, 'SET FOREIGN_KEY_CHECKS=0');
		mysqli_query($db, 'SET UNIQUE_CHECKS=0');
		mysqli_query($db, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
		@mysqli_query($db, 'SET SESSION innodb_strict_mode=0');
		$this->db = $db;
		return $db;
	}

	/** Backups may name a table prefix other than this site's. */
	private function rewrite_prefix($sql) {
		$source = $this->state['source']['prefix'];
		$target = $this->state['prefix'];
		if ($source === '' || $source === $target) {
			return $sql;
		}
		return str_replace('`' . $source, '`' . $target, substr($sql, 0, 8192)) . substr($sql, 8192);
	}

	/** Dumps made on a newer server can name a collation this one doesn't know. */
	private function soften_collations($sql) {
		return preg_replace(array('/utf8mb4_0900_[a-z_]+/', '/utf8mb4_uca1400_[a-z_]+/', '/utf8mb3_uca1400_[a-z_]+/'), array('utf8mb4_unicode_520_ci', 'utf8mb4_unicode_520_ci', 'utf8_unicode_ci'), $sql);
	}

	private function phase_import() {
		$file = $this->state['tmp_sql'];
		$size = filesize($file);
		if (!$size) {
			throw new RuntimeException(__('Le fichier de la base de données est vide.', 'rempart-backup'));
		}
		$db = $this->db();
		$handle = fopen($file, 'rb');
		$import = &$this->state['import'];
		$delimiter = $import['delimiter'] ?? ';';
		$offset = (float) $import['offset'];
		fseek($handle, (int) $offset);

		// Before the first statement, learn the backup's table prefix if the backup didn't say.
		if ($offset === 0.0 && $this->state['source']['prefix'] === '') {
			$this->state['source']['prefix'] = $this->detect_prefix($handle);
			fseek($handle, 0);
		}

		$buffer = '';
		$pos = 0;
		$errors = (int) ($import['errors'] ?? 0);
		$finished = false;

		while (true) {
			if (time() >= $this->deadline) {
				break;
			}
			list($statement, $end, $delimiter, $need_more) = $this->next_statement($buffer, $pos, $delimiter, feof($handle));
			if ($need_more) {
				$buffer = substr($buffer, $pos);
				$offset += $pos;
				$pos = 0;
				$more = fread($handle, 2097152);
				if ($more !== '' && $more !== false) {
					$buffer .= $more;
					continue;
				}
				if (feof($handle)) {
					if (trim($buffer) === '') {
						$finished = true;
						break;
					}
					continue;
				}
			}
			$pos = $end;
			if ($statement === null || trim($statement) === '') {
				if ($end >= strlen($buffer) && feof($handle)) {
					$offset += $pos;
					$buffer = '';
					$pos = 0;
					$finished = true;
					break;
				}
				continue;
			}
			$statement = preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|\S+)/i', ' ', $statement);
			$statement = $this->rewrite_prefix($statement);

			// Session settings are renewed by db(); a backup's own SET NAMES can stay out of the way.
			if (!@mysqli_query($db, $statement)) {
				$errno = mysqli_errno($db);
				if ($errno === 1273 || $errno === 1115) {
					$statement = $this->soften_collations($statement);
					if (@mysqli_query($db, $statement)) {
						continue;
					}
					$errno = mysqli_errno($db);
				}
				if ($errno === 2006 || $errno === 2013) {
					throw new RuntimeException(__('Le serveur de base de données a coupé la connexion. Une requête dépasse peut-être max_allowed_packet.', 'rempart-backup'));
				}
				$errors++;
				$this->state['warnings']++;
				$this->log(sprintf(__('Requête refusée : %1$s — %2$s', 'rempart-backup'), mysqli_error($db), substr($statement, 0, 120)), 'warning');
				if ($errors > self::MAX_SQL_ERRORS) {
					throw new RuntimeException(__('Trop d\'erreurs pendant l\'import de la base de données.', 'rempart-backup'));
				}
			}
		}
		$offset += $pos;
		fclose($handle);
		$import['errors'] = $errors;
		$import['offset'] = $offset;
		$import['delimiter'] = $delimiter;

		$low = $this->state['files'] ? 55 : 10;
		$this->state['percent'] = $low + (int) (25 * min(1, $offset / $size));

		if ($finished) {
			@unlink($file);
			$this->log(__('Base de données importée.', 'rempart-backup'));
			$this->state['phase'] = 'prefix';
		}
	}

	private function detect_prefix($handle) {
		$head = fread($handle, 4194304);
		if (preg_match('/CREATE TABLE `([^`]*?)options`/', $head, $m)) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Find the next statement in the buffer.
	 * Returns [statement or null, position after it, delimiter, whether more input is needed].
	 */
	private function next_statement($buffer, $pos, $delimiter, $at_eof) {
		$length = strlen($buffer);

		// Skip white space and comments, and notice DELIMITER lines.
		while ($pos < $length) {
			$pos += strspn($buffer, " \t\r\n", $pos);
			if ($pos >= $length) {
				break;
			}
			$two = substr($buffer, $pos, 2);
			if ($two === '--' || $buffer[$pos] === '#') {
				$eol = strpos($buffer, "\n", $pos);
				if ($eol === false) {
					return $at_eof ? array(null, $length, $delimiter, false) : array(null, $pos, $delimiter, true);
				}
				$pos = $eol + 1;
				continue;
			}
			if ($two === '/*' && substr($buffer, $pos, 3) !== '/*!') {
				$end = strpos($buffer, '*/', $pos + 2);
				if ($end === false) {
					return $at_eof ? array(null, $length, $delimiter, false) : array(null, $pos, $delimiter, true);
				}
				$pos = $end + 2;
				continue;
			}
			if (strncasecmp(substr($buffer, $pos, 10), 'DELIMITER ', 10) === 0) {
				$eol = strpos($buffer, "\n", $pos);
				if ($eol === false) {
					if (!$at_eof) {
						return array(null, $pos, $delimiter, true);
					}
					$eol = $length;
				}
				$delimiter = trim(substr($buffer, $pos + 10, $eol - $pos - 10));
				$pos = min($length, $eol + 1);
				continue;
			}
			break;
		}
		if ($pos >= $length) {
			return array(null, $pos, $delimiter, !$at_eof);
		}

		// Walk to the delimiter, skipping over quoted text.
		$start = $pos;
		$first = $delimiter[0];
		$stops = "'\"`" . $first;
		while (true) {
			$pos += strcspn($buffer, $stops, $pos);
			if ($pos >= $length) {
				break;
			}
			$char = $buffer[$pos];
			if ($char === $first && substr($buffer, $pos, strlen($delimiter)) === $delimiter) {
				return array(substr($buffer, $start, $pos - $start), $pos + strlen($delimiter), $delimiter, false);
			}
			if ($char === "'" || $char === '"' || $char === '`') {
				$pos++;
				while (true) {
					$pos += strcspn($buffer, $char . '\\', $pos);
					if ($pos >= $length) {
						break 2;
					}
					if ($buffer[$pos] === '\\' && $char !== '`') {
						$pos += 2;
						continue;
					}
					if ($buffer[$pos] === '\\') {
						$pos++;
						continue;
					}
					$pos++;
					if ($pos < $length && $buffer[$pos] === $char) { // A doubled quote stays inside the text.
						$pos++;
						continue;
					}
					break;
				}
				continue;
			}
			$pos++;
		}
		if (!$at_eof) {
			return array(null, $start, $delimiter, true);
		}
		return array(substr($buffer, $start), $length, $delimiter, false);
	}

	/* ----- Table prefix ----- */

	private function phase_prefix() {
		$source = $this->state['source']['prefix'];
		$target = $this->state['prefix'];
		if ($source !== '' && $source !== $target) {
			$db = $this->db();
			$esc_source = mysqli_real_escape_string($db, str_replace('_', '\\_', $source));
			$src = mysqli_real_escape_string($db, $source);
			$tgt = mysqli_real_escape_string($db, $target);
			$length = strlen($source) + 1;
			mysqli_query($db, "UPDATE `{$target}options` SET option_name = CONCAT('{$tgt}', SUBSTRING(option_name, {$length})) WHERE option_name LIKE '{$esc_source}%' AND option_name IN ('{$src}user_roles')");
			mysqli_query($db, "UPDATE `{$target}usermeta` SET meta_key = CONCAT('{$tgt}', SUBSTRING(meta_key, {$length})) WHERE meta_key LIKE '{$esc_source}%'");
			$this->log(sprintf(__('Préfixe des tables adapté : %1$s → %2$s', 'rempart-backup'), $source, $target));
		}
		$this->detect_origin_from_database();
		$this->state['phase'] = !empty($this->state['migration']) ? 'replace' : 'finish';
		$this->state['percent'] = max($this->state['percent'], 80);
	}

	/**
	 * The restored tables say which address they belong to, whatever the backup's info file said.
	 * Without this, a site whose address differs would end up redirecting to the old one.
	 */
	private function detect_origin_from_database() {
		$db = $this->db();
		$prefix = $this->state['prefix'];
		$values = array();
		$rows = mysqli_query($db, "SELECT option_name, option_value FROM `{$prefix}options` WHERE option_name IN ('siteurl', 'home')");
		while ($rows && ($row = mysqli_fetch_row($rows))) {
			$values[$row[0]] = rtrim($row[1], '/');
		}
		if (empty($values['siteurl'])) {
			return;
		}
		$home = !empty($values['home']) ? $values['home'] : $values['siteurl'];
		if ($values['siteurl'] !== rtrim($this->state['target']['url'], '/') || $home !== rtrim($this->state['target']['home'], '/')) {
			$this->state['migration'] = true;
			$this->state['source']['url'] = $values['siteurl'];
			$this->state['source']['home'] = $home;
			$this->log(sprintf(__('Migration détectée : %1$s → %2$s', 'rempart-backup'), $home, $this->state['target']['home']));
		}
	}

	/* ----- Addresses, for a migration or a clone ----- */

	/** Every form an old address can take in the database, longest first. */
	private function replacement_pairs() {
		$pairs = array();
		$add = function ($from, $to) use (&$pairs) {
			if ($from === '' || $from === $to) {
				return;
			}
			$pairs[$from] = $to;
			$pairs[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to); // JSON
			$pairs[rawurlencode($from)] = rawurlencode($to);
		};
		$sources = array(
			array($this->state['source']['home'], $this->state['target']['home']),
			array($this->state['source']['url'], $this->state['target']['url']),
		);
		foreach ($sources as $pair) {
			$from = rtrim($pair[0], '/');
			$to = rtrim($pair[1], '/');
			if ($from === '') {
				continue;
			}
			$bare = preg_replace('#^https?://(www\.)?#i', '', $from);
			$to_bare = preg_replace('#^https?://#i', '', $to);
			foreach (array('https://www.', 'http://www.', 'https://', 'http://') as $scheme) {
				$add($scheme . $bare, $to);
			}
			foreach (array('//www.', '//') as $scheme) {
				$add($scheme . $bare, '//' . $to_bare);
			}
		}
		$add(rtrim($this->state['source']['path'], '/'), rtrim($this->state['target']['path'], '/'));
		uksort($pairs, function ($a, $b) {
			return strlen($b) - strlen($a);
		});
		return $pairs;
	}

	private function phase_replace() {
		$db = $this->db();
		if (empty($this->state['replace'])) {
			$like = addcslashes($this->state['prefix'], '\\%_') . '%';
			$tables = array();
			$found = mysqli_query($db, "SHOW FULL TABLES LIKE '" . mysqli_real_escape_string($db, $like) . "'");
			while ($found && ($row = mysqli_fetch_row($found))) {
				if (strtoupper($row[1]) !== 'VIEW') {
					$tables[] = $row[0];
				}
			}
			$this->state['replace'] = array('tables' => $tables, 'index' => 0, 'offset' => 0, 'changed' => 0);
			$this->log(__('Mise à jour de l\'adresse du site dans la base de données.', 'rempart-backup'));
		}
		$progress = &$this->state['replace'];
		$pairs = $this->replacement_pairs();
		$search = array_keys($pairs);
		$replace = array_values($pairs);

		while ($progress['index'] < count($progress['tables'])) {
			if (time() >= $this->deadline) {
				break;
			}
			$table = $progress['tables'][$progress['index']];
			$shape = $this->table_shape($table);
			if (!$shape['text'] || !$shape['keys']) {
				if ($shape['text'] && !$shape['keys']) {
					$this->log(sprintf(__('Table sans clé primaire, adresses non mises à jour : %s', 'rempart-backup'), $table), 'warning');
				}
				$progress['index']++;
				$progress['offset'] = 0;
				continue;
			}
			$cols = '`' . implode('`,`', array_merge($shape['keys'], $shape['text'])) . '`';
			$order = '`' . implode('`,`', $shape['keys']) . '`';
			$rows = mysqli_query($db, "SELECT {$cols} FROM `{$table}` ORDER BY {$order} LIMIT " . (int) $progress['offset'] . ', ' . self::BATCH_ROWS);
			if (!$rows) {
				$this->log(sprintf(__('Lecture impossible de %1$s : %2$s', 'rempart-backup'), $table, mysqli_error($db)), 'warning');
				$progress['index']++;
				$progress['offset'] = 0;
				continue;
			}
			$count = 0;
			while ($row = mysqli_fetch_assoc($rows)) {
				$count++;
				$sets = array();
				foreach ($shape['text'] as $column) {
					$value = $row[$column];
					if ($value === null || $value === '') {
						continue;
					}
					$new = Rempart_Serialized::replace($value, $search, $replace);
					if ($new !== $value) {
						$sets[] = "`{$column}` = '" . mysqli_real_escape_string($db, $new) . "'";
					}
				}
				if ($sets) {
					$where = array();
					foreach ($shape['keys'] as $key) {
						$where[] = "`{$key}` = '" . mysqli_real_escape_string($db, (string) $row[$key]) . "'";
					}
					if (mysqli_query($db, "UPDATE `{$table}` SET " . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $where))) {
						$progress['changed']++;
					}
				}
			}
			mysqli_free_result($rows);
			$progress['offset'] += $count;
			if ($count < self::BATCH_ROWS) {
				$progress['index']++;
				$progress['offset'] = 0;
			}
		}
		$total = max(1, count($progress['tables']));
		$this->state['percent'] = 80 + (int) (17 * min(1, $progress['index'] / $total));

		if ($progress['index'] >= count($progress['tables'])) {
			$prefix = $this->state['prefix'];
			$home = mysqli_real_escape_string($db, $this->state['target']['home']);
			$url = mysqli_real_escape_string($db, $this->state['target']['url']);
			mysqli_query($db, "UPDATE `{$prefix}options` SET option_value = '{$home}' WHERE option_name = 'home'");
			mysqli_query($db, "UPDATE `{$prefix}options` SET option_value = '{$url}' WHERE option_name = 'siteurl'");
			mysqli_query($db, "DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'");
			$this->log(sprintf(__('%d lignes mises à jour avec la nouvelle adresse.', 'rempart-backup'), $progress['changed']));
			$this->state['phase'] = 'finish';
		}
	}

	private function table_shape($table) {
		$db = $this->db();
		$shape = array('text' => array(), 'keys' => array());
		$columns = mysqli_query($db, "SHOW COLUMNS FROM `{$table}`");
		while ($columns && ($column = mysqli_fetch_assoc($columns))) {
			if ($column['Key'] === 'PRI') {
				$shape['keys'][] = $column['Field'];
			}
			if (preg_match('/char|text|json|enum|set/i', $column['Type']) && !preg_match('/^(enum|set)/i', $column['Type'])) {
				$shape['text'][] = $column['Field'];
			}
		}
		return $shape;
	}

	/* ----- Ending ----- */

	private function phase_finish() {
		if ($this->state['db']) {
			$db = $this->db();
			$prefix = $this->state['prefix'];
			$now = time();
			mysqli_query($db, "INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('rempart_last_restore', '{$now}', 'no') ON DUPLICATE KEY UPDATE option_value = '{$now}'");
			$this->keep_plugin_active($db, $prefix);
		}
		$this->maintenance(false);
		@unlink($this->state['tmp_sql']);
		if (function_exists('opcache_reset')) {
			@opcache_reset();
		}
		$warnings = (int) $this->state['warnings'];
		$this->log($warnings ? sprintf(__('Restauration terminée, avec %d avertissements. Consultez le journal.', 'rempart-backup'), $warnings) : __('Restauration terminée.', 'rempart-backup'), 'success');
		$this->state['finished'] = 1;
		$this->state['status'] = 'success';
		$this->state['percent'] = 100;
	}

	/**
	 * A backup from another site may not list this plugin as active. It stays on, so the
	 * person still has their backups and this screen after the restore.
	 */
	private function keep_plugin_active($db, $prefix) {
		$plugin = (string) ($this->state['plugin'] ?? '');
		if ($plugin === '') {
			return;
		}
		$result = mysqli_query($db, "SELECT option_value FROM `{$prefix}options` WHERE option_name = 'active_plugins'");
		$row = $result ? mysqli_fetch_row($result) : null;
		$active = $row ? @unserialize($row[0], array('allowed_classes' => false)) : array();
		if (!is_array($active)) {
			$active = array();
		}
		if (!in_array($plugin, $active, true)) {
			$active[] = $plugin;
			sort($active);
			$value = mysqli_real_escape_string($db, serialize(array_values($active)));
			mysqli_query($db, "INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('active_plugins', '{$value}', 'yes') ON DUPLICATE KEY UPDATE option_value = '{$value}'");
		}
	}

	/** Remove every trace of this restore: the key and the database password live in its files. */
	public function remove_traces() {
		$logs = dirname($this->state['log_file']);
		@copy($this->state['log_file'], $logs . '/last-restore.log.php');
		if (!empty($this->state['delete_after']) && !empty($this->state['archive'])) {
			@unlink($this->state['archive']);
		}
		@unlink($this->state['tmp_sql']);
		@unlink($this->state_file);
		@unlink($this->state['runner']);
	}

	public function read_log($from) {
		$lines = @file($this->state['log_file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!$lines) {
			return array(array(), 0);
		}
		array_shift($lines);
		$out = array();
		for ($i = max(0, (int) $from), $n = count($lines); $i < $n; $i++) {
			$row = json_decode($lines[$i], true);
			if (is_array($row)) {
				$out[] = $row;
			}
		}
		return array($out, count($lines));
	}
}

/**
 * Replaces text in values that may be PHP serialized data, keeping the length prefixes right.
 * The data is only ever walked as text: nothing is unserialized, so no object from a backup can run code.
 */
class Rempart_Serialized {
	public static function replace($value, array $search, array $replace) {
		$hit = false;
		foreach ($search as $needle) {
			if (strpos($value, $needle) !== false) {
				$hit = true;
				break;
			}
		}
		if (!$hit) {
			return $value;
		}
		if (self::looks_serialized($value)) {
			$pos = 0;
			$out = self::walk($value, $pos, $search, $replace);
			if ($out !== null && $pos === strlen($value)) {
				return $out;
			}
		}
		return str_replace($search, $replace, $value);
	}

	private static function looks_serialized($value) {
		return (bool) preg_match('/^(a:\d+:\{|O:\d+:"|s:\d+:"|C:\d+:"|i:-?\d+;|b:[01];|d:|N;)/', $value);
	}

	/** Copy one serialized value starting at $pos, rewriting its strings. Null when it isn't valid. */
	private static function walk($data, &$pos, array $search, array $replace) {
		$length = strlen($data);
		if ($pos >= $length) {
			return null;
		}
		$type = $data[$pos];
		switch ($type) {
			case 'N':
				if (substr($data, $pos, 2) !== 'N;') {
					return null;
				}
				$pos += 2;
				return 'N;';
			case 'i':
			case 'b':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos($data, ';', $pos);
				if ($end === false) {
					return null;
				}
				$piece = substr($data, $pos, $end - $pos + 1);
				$pos = $end + 1;
				return $piece;
			case 's':
				$colon = strpos($data, ':', $pos + 2);
				if ($colon === false) {
					return null;
				}
				$n = (int) substr($data, $pos + 2, $colon - $pos - 2);
				if (substr($data, $colon + 1, 1) !== '"' || substr($data, $colon + 2 + $n, 2) !== '";') {
					return null;
				}
				$inner = substr($data, $colon + 2, $n);
				$pos = $colon + 2 + $n + 2;
				$new = self::replace($inner, $search, $replace); // A string may itself hold serialized data.
				return 's:' . strlen($new) . ':"' . $new . '";';
			case 'a':
				$open = strpos($data, ':{', $pos + 2);
				if ($open === false) {
					return null;
				}
				$count = (int) substr($data, $pos + 2, $open - $pos - 2);
				$pos = $open + 2;
				$out = 'a:' . $count . ':{';
				for ($i = 0; $i < $count * 2; $i++) {
					$item = self::walk($data, $pos, $search, $replace);
					if ($item === null) {
						return null;
					}
					$out .= $item;
				}
				if (substr($data, $pos, 1) !== '}') {
					return null;
				}
				$pos++;
				return $out . '}';
			case 'O':
				$colon = strpos($data, ':', $pos + 2);
				if ($colon === false) {
					return null;
				}
				$n = (int) substr($data, $pos + 2, $colon - $pos - 2);
				$class = substr($data, $colon + 2, $n);
				$after = $colon + 2 + $n; // Just past the class name's closing quote position.
				if (substr($data, $after, 2) !== '":') {
					return null;
				}
				$open = strpos($data, ':{', $after + 2);
				if ($open === false) {
					return null;
				}
				$count = (int) substr($data, $after + 2, $open - $after - 2);
				$pos = $open + 2;
				$out = 'O:' . $n . ':"' . $class . '":' . $count . ':{';
				for ($i = 0; $i < $count * 2; $i++) {
					$item = self::walk($data, $pos, $search, $replace);
					if ($item === null) {
						return null;
					}
					$out .= $item;
				}
				if (substr($data, $pos, 1) !== '}') {
					return null;
				}
				$pos++;
				return $out . '}';
		}
		return null; // Custom-serialized objects and anything unknown: leave to the plain replace.
	}
}

/* ----- Entry point ----- */

// From the command line, the arguments come as one query string.
if (PHP_SAPI === 'cli') {
	parse_str(isset($argv[1]) ? $argv[1] : '', $_REQUEST);
}
$rempart_key = isset($_REQUEST['key']) ? (string) $_REQUEST['key'] : '';
$rempart_state_file = __DIR__ . '/rempart/state/restore.php';
$rempart_raw = @file_get_contents($rempart_state_file);
$rempart_state = $rempart_raw === false ? null : json_decode(preg_replace('/^<\?php exit;\?>\n/', '', $rempart_raw), true);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (!is_array($rempart_state) || empty($rempart_state['key']) || !hash_equals($rempart_state['key'], $rempart_key)) {
	http_response_code(403);
	echo json_encode(array('error' => __('Accès refusé.', 'rempart-backup')));
	exit;
}

$rempart_op = isset($_REQUEST['op']) ? (string) $_REQUEST['op'] : 'status';
$rempart_runner = new Rempart_Runner($rempart_state_file, $rempart_state);

if ($rempart_op === 'run' && PHP_SAPI === 'cli') {
	$rempart_runner->run();
	exit;
}

if ($rempart_op === 'run') {
	ignore_user_abort(true);
	@set_time_limit(120);
	echo json_encode(array('ok' => 1));
	// Answer right away and keep working: the caller only needs to know the work has begun.
	if (function_exists('fastcgi_finish_request')) {
		fastcgi_finish_request();
	} else {
		header('Connection: close');
		header('Content-Length: ' . ob_get_length());
		@ob_end_flush();
		@flush();
	}
	$rempart_runner->run();
	$rempart_after = $rempart_runner->state();
	if (empty($rempart_after['finished'])) {
		// On to the next slice.
		$rempart_url = parse_url($rempart_after['runner_url']);
		$rempart_scheme = ($rempart_url['scheme'] ?? 'http') === 'https' ? 'ssl://' : 'tcp://';
		$rempart_port = $rempart_url['port'] ?? (($rempart_url['scheme'] ?? 'http') === 'https' ? 443 : 80);
		$rempart_context = stream_context_create(array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
		$rempart_sock = @stream_socket_client($rempart_scheme . $rempart_url['host'] . ':' . $rempart_port, $rempart_errno, $rempart_errstr, 5, STREAM_CLIENT_CONNECT, $rempart_context);
		if ($rempart_sock) {
			$rempart_body = http_build_query(array('key' => $rempart_key, 'op' => 'run'));
			fwrite($rempart_sock, 'POST ' . $rempart_url['path'] . " HTTP/1.1\r\nHost: " . $rempart_url['host'] . (isset($rempart_url['port']) ? ':' . $rempart_url['port'] : '')
				. "\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($rempart_body) . "\r\nConnection: close\r\n\r\n" . $rempart_body);
			fclose($rempart_sock);
		}
	}
	exit;
}

// Status: also pushes a restore that went quiet, so the open page can finish it alone.
$rempart_from = isset($_REQUEST['from']) ? (int) $_REQUEST['from'] : 0;
// No first slice yet, or calls to itself already failed: the watching page does the driving.
$rempart_patience = !empty($rempart_state['direct']) ? 3 : (empty($rempart_state['loop']) ? 8 : 45);
if (empty($rempart_state['finished']) && time() - (int) $rempart_state['heartbeat'] >= $rempart_patience) {
	@set_time_limit(120);
	$rempart_runner->mark_direct();
	$rempart_runner->run();
	$rempart_state = $rempart_runner->state();
}
list($rempart_lines, $rempart_next) = $rempart_runner->read_log($rempart_from);
echo json_encode(array(
	'status'   => $rempart_state['status'],
	'finished' => (int) $rempart_state['finished'],
	'percent'  => (int) $rempart_state['percent'],
	'phase'    => $rempart_state['phase'],
	'errors'   => $rempart_state['errors'],
	'warnings' => (int) $rempart_state['warnings'],
	'log'      => $rempart_lines,
	'next'     => $rempart_next,
), JSON_PARTIAL_OUTPUT_ON_ERROR);

// The watcher now knows how it ended. Nothing needs this program any more, and the watcher's
// session may be gone (the accounts are those of the backup), so it can't be left to clean up.
if (!empty($rempart_state['finished'])) {
	$rempart_runner->remove_traces();
}

<?php
if (!defined('ABSPATH')) {
	exit;
}

/** The list of existing backups: their info files, deletion, rotation and synchronising. */
class Rempart_Backups {
	public static function info_file($name) {
		$dir = rempart_folder('info');
		return $dir ? $dir . '/' . rempart_safe_backup_name($name) . '.php' : false;
	}

	public static function archive_path($name) {
		$dir = rempart_folder('backups');
		return $dir ? $dir . '/' . rempart_safe_backup_name($name) . '.tar.gz' : false;
	}

	public static function save_info(array $info) {
		$file = self::info_file($info['name']);
		return $file ? rempart_write_state($file, $info) : false;
	}

	public static function get($name) {
		$file = self::info_file($name);
		return $file ? rempart_read_state($file) : null;
	}

	/** All backups, newest first. */
	public static function all() {
		$dir = rempart_folder('info');
		$out = array();
		if ($dir) {
			foreach ((array) glob($dir . '/*.php') as $file) {
				$info = rempart_read_state($file);
				if (is_array($info) && !empty($info['name'])) {
					$out[] = $info;
				}
			}
		}
		usort($out, function ($a, $b) {
			return (int) ($b['btime'] ?? 0) - (int) ($a['btime'] ?? 0);
		});
		return $out;
	}

	/** Delete a backup: its archive where it is kept, its info and its log. */
	public static function delete($name) {
		$name = rempart_safe_backup_name($name);
		$info = self::get($name);
		$location_id = $info['location'] ?? '';

		if ($location_id !== '' && $location_id !== null) {
			$location = Rempart_Locations::get($location_id);
			if ($location) {
				$storage = Rempart_Storage::make($location);
				$storage->delete($name . '.tar.gz');
				$storage->delete($name . '.info');
				$storage->delete($name . '.log');
			}
		}
		$archive = self::archive_path($name);
		if ($archive && file_exists($archive)) {
			@unlink($archive);
		}
		$file = self::info_file($name);
		if ($file && file_exists($file)) {
			@unlink($file);
		}
		@unlink(REMPART_ROOT . 'logs/' . $name . '.log.php');
		return true;
	}

	/** Keep only the newest $keep automatic backups of a location. */
	public static function rotate($location_id, $keep) {
		$keep = (int) $keep;
		if ($keep < 1) {
			return 0;
		}
		$same = array_filter(self::all(), function ($info) use ($location_id) {
			return !empty($info['auto_backup']) && (string) ($info['location'] ?? '') === (string) $location_id;
		});
		$extra = array_slice(array_values($same), $keep);
		foreach ($extra as $info) {
			rempart_log('Rotation : suppression de l\'ancienne sauvegarde ' . $info['name']);
			try {
				self::delete($info['name']);
			} catch (Exception $e) {
				rempart_log('Rotation : ' . $e->getMessage(), 'warning');
			}
		}
		return count($extra);
	}

	/** Add archives found in the local folder (copied or uploaded there) to the list. */
	public static function sync_local() {
		$dir = rempart_folder('backups');
		$added = 0;
		if (!$dir) {
			return 0;
		}
		foreach ((array) glob($dir . '/*.tar.gz') as $archive) {
			$name = rempart_safe_backup_name(basename($archive));
			if ($name === '' || self::get($name)) {
				continue;
			}
			$info = self::read_archive_info($archive, $name);
			if ($info) {
				self::save_info($info);
				$added++;
			}
		}
		return $added;
	}

	/**
	 * Read what a backup says about itself from its first entries.
	 * Also understands archives of the plugin this one replaces, which keep a
	 * <name>.php info file and softsql.sql at the archive's root.
	 */
	public static function read_archive_info($archive, $name) {
		$info = null;
		$has_db = false;
		$has_files = false;
		try {
			$reader = new Rempart_Tar_Reader($archive);
			$seen = 0;
			while ($seen < 12 && ($entry = $reader->next_entry())) {
				$seen++;
				$size = (int) $entry['size'];
				$padded = (int) (ceil($size / 512) * 512);
				// The other plugin names its info file after the archive, and the archive may have
				// been renamed since. So any small root .php that isn't WordPress's own is looked at.
				$is_candidate = $entry['name'] === '.rempart/info.json'
					|| (strpos($entry['name'], '/') === false && substr($entry['name'], -4) === '.php' && !in_array($entry['name'], rempart_core_entries(), true));
				if ($is_candidate && $size < 1048576) {
					$raw = substr($reader->read($padded), 0, $size);
					if (preg_match('/\{.*\}/s', $raw, $m)) {
						$decoded = json_decode($m[0], true);
						if (is_array($decoded) && (isset($decoded['site_url']) || isset($decoded['backup_site_url']))) {
							$info = $decoded;
							continue;
						}
					}
					$has_files = true;
					continue;
				}
				if ($entry['name'] === '.rempart/database.sql' || $entry['name'] === 'softsql.sql') {
					$has_db = true;
				} elseif (strpos($entry['name'], '.rempart/') !== 0 && $entry['name'] !== 'softver.txt') {
					$has_files = true;
				}
				// Skipping costs little: no need to read the rest once the facts are known.
				if ($info && $has_db && $has_files) {
					break;
				}
				while ($padded > 0) {
					$got = $reader->read(min($padded, 1048576));
					if ($got === '') {
						break;
					}
					$padded -= strlen($got);
				}
			}
			$reader->close();
		} catch (Exception $e) {
			rempart_debug('Archive illisible : ' . $e->getMessage());
			return null;
		}
		if (!is_array($info)) {
			$info = array();
		}
		$info['name'] = $name;
		$info['size'] = filesize($archive);
		$info['btime'] = !empty($info['btime']) ? (int) $info['btime'] : filemtime($archive);
		$info['backup_dir'] = isset($info['backup_dir']) ? $info['backup_dir'] : ($has_files ? 1 : 0);
		$info['backup_db'] = isset($info['backup_db']) ? $info['backup_db'] : ($has_db ? 1 : 0);
		$info['ext'] = 'tar.gz';
		$info['location'] = '';
		unset($info['backup_location']);
		$info['auto_backup'] = !empty($info['auto_backup']);
		return $info;
	}

	/** Compare a storage location's backups with the list: add the new ones, drop the gone ones. */
	public static function sync_location($location_id) {
		$location = Rempart_Locations::get($location_id);
		if (!$location) {
			throw new RuntimeException('Cet emplacement n\'existe plus.');
		}
		$storage = Rempart_Storage::make($location);
		$files = $storage->list_files();
		$sizes = array();
		foreach ($files as $file) {
			$sizes[$file['name']] = $file['size'];
		}
		$added = 0;
		$removed = 0;
		foreach ($sizes as $file_name => $size) {
			if (substr($file_name, -5) !== '.info') {
				continue;
			}
			$name = rempart_safe_backup_name(substr($file_name, 0, -5));
			if ($name === '' || self::get($name) || !isset($sizes[$name . '.tar.gz'])) {
				continue;
			}
			$raw = $storage->get_string($file_name);
			if (!preg_match('/\{.*\}/s', (string) $raw, $m)) {
				continue;
			}
			$info = json_decode($m[0], true);
			if (is_array($info)) {
				$info['name'] = $name;
				$info['location'] = $location_id;
				$info['size'] = $sizes[$name . '.tar.gz'];
				self::save_info($info);
				$added++;
			}
		}
		foreach (self::all() as $info) {
			if ((string) ($info['location'] ?? '') === (string) $location_id && !isset($sizes[$info['name'] . '.tar.gz'])) {
				@unlink(self::info_file($info['name']));
				$removed++;
			}
		}
		return array('added' => $added, 'removed' => $removed);
	}
}

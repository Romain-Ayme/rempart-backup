<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Backups from the command line.
 *
 * ## EXAMPLES
 *
 *     wp backup-guard backup
 *     wp backup-guard backup --db-only --location=2 --note="Avant la refonte"
 *     wp backup-guard list
 *     wp backup-guard restore wp_exemple_2026-01-01_10-00-00_abc123
 */
class Backup_Guard_CLI {
	/**
	 * Create a backup and wait for it to finish.
	 *
	 * ## OPTIONS
	 *
	 * [--files-only]
	 * : Only the files.
	 *
	 * [--db-only]
	 * : Only the database.
	 *
	 * [--scope=<scope>]
	 * : Which files to keep. all (default), content (only the site's own files) or modified (those plus the edited WordPress files).
	 *
	 * [--location=<id>]
	 * : A storage location's id (see `wp backup-guard locations`). Default: the local folder.
	 *
	 * [--note=<text>]
	 * : A note kept with the backup.
	 */
	public function backup($args, $assoc) {
		$files = empty($assoc['db-only']);
		$db = empty($assoc['files-only']);
		try {
			Backup_Guard_Job::start_backup(array(
				'files'    => $files,
				'db'       => $db,
				'location' => $assoc['location'] ?? '',
				'scope'    => $assoc['scope'] ?? 'content',
				'note'     => $assoc['note'] ?? '',
			));
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
		}
		$this->follow_job();
	}

	private function follow_job() {
		$printed = 0;
		do {
			$state = Backup_Guard_Job::run_slice(false);
			list($lines, $printed_next) = backup_guard_log_read($printed);
			foreach ($lines as $line) {
				WP_CLI::log($line['m']);
			}
			$printed = $printed_next;
			if ($state && empty($state['finished'])) {
				usleep(200000);
			}
		} while ($state && empty($state['finished']));

		if (!$state || $state['status'] !== 'success') {
			WP_CLI::error(sprintf(__('La tâche n\'a pas abouti : %s', 'backup-guard'), implode(' ', (array) ($state['errors'] ?? array()))));
		}
		WP_CLI::success(__('Terminé.', 'backup-guard'));
	}

	/**
	 * List backups.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 */
	public function list_($args, $assoc) {
		$rows = array();
		foreach (Backup_Guard_Backups::all() as $info) {
			$rows[] = array(
				'name'     => $info['name'],
				'date'     => wp_date('Y-m-d H:i', (int) $info['btime']),
				'size'     => backup_guard_format_size($info['size'] ?? 0),
				'files'    => !empty($info['backup_dir']) ? 'oui' : 'non',
				'db'       => !empty($info['backup_db']) ? 'oui' : 'non',
				'location' => !empty($info['location']) ? (Backup_Guard_Locations::get($info['location'])['name'] ?? '?') : 'local',
				'note'     => $info['note'] ?? ($info['backup_note'] ?? ''),
			);
		}
		WP_CLI\Utils\format_items($assoc['format'], $rows, array('name', 'date', 'size', 'files', 'db', 'location', 'note'));
	}

	/**
	 * List storage locations.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 */
	public function locations($args, $assoc) {
		$rows = array();
		$protocols = Backup_Guard_Locations::protocols();
		foreach (Backup_Guard_Locations::all() as $location) {
			$rows[] = array('id' => $location['id'], 'name' => $location['name'], 'type' => $protocols[$location['protocol']]['label'] ?? $location['protocol']);
		}
		WP_CLI\Utils\format_items($assoc['format'], $rows, array('id', 'name', 'type'));
	}

	/**
	 * Delete a backup.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The backup's name.
	 */
	public function delete($args) {
		try {
			Backup_Guard_Backups::delete($args[0]);
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
		}
		WP_CLI::success(__('Sauvegarde supprimée.', 'backup-guard'));
	}

	/**
	 * Lock a backup: it can't be deleted, by hand or by the rotation, until it is unlocked.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The backup's name.
	 */
	public function lock($args) {
		try {
			Backup_Guard_Backups::set_locked($args[0], true);
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
		}
		WP_CLI::success(__('Sauvegarde verrouillée.', 'backup-guard'));
	}

	/**
	 * Unlock a backup.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The backup's name.
	 */
	public function unlock($args) {
		try {
			Backup_Guard_Backups::set_locked($args[0], false);
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
		}
		WP_CLI::success(__('Sauvegarde déverrouillée.', 'backup-guard'));
	}

	/**
	 * Restore a backup over this site. This replaces the site's current content.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The backup's name.
	 *
	 * [--files-only]
	 * : Only the files.
	 *
	 * [--db-only]
	 * : Only the database.
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 */
	public function restore($args, $assoc) {
		WP_CLI::confirm(__('Le contenu actuel du site sera remplacé. Continuer ?', 'backup-guard'), $assoc);
		try {
			$result = Backup_Guard_Restore::start($args[0], array('files' => empty($assoc['db-only']), 'db' => empty($assoc['files-only'])));
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
			return;
		}
		if ($result['mode'] === 'fetch') {
			$printed = 0;
			do {
				$state = Backup_Guard_Job::run_slice(false);
				$finished = $state && !empty($state['finished']);
				if ($state && !$finished) {
					usleep(200000);
				}
			} while ($state && !$finished);
			$restore = Backup_Guard_Restore::current();
			if (!$restore) {
				WP_CLI::error(__('La sauvegarde n\'a pas pu être récupérée.', 'backup-guard'));
			}
			$result = array('url' => $restore['runner_url'], 'key' => $restore['key']);
		}
		WP_CLI::log(__('Restauration en cours…', 'backup-guard'));
		$restore = Backup_Guard_Restore::current();
		$from = 0;
		// The program runs right here: it needs no web server, and its progress is read from
		// files because WordPress can't be relied on while its own tables are being replaced.
		$query = http_build_query(array('key' => $restore['key'], 'op' => 'run'));
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($restore['runner']) . ' ' . escapeshellarg($query);
		do {
			$exit = 0;
			$output = array();
			exec($command, $output, $exit);
			$current = backup_guard_read_state(Backup_Guard_Restore::state_file());
			list($lines, $from) = backup_guard_log_read($from, backup_guard_log_file('restore'));
			foreach ($lines as $line) {
				WP_CLI::log($line['m']);
			}
			if (!$current) {
				WP_CLI::error(__('Le programme de restauration a disparu.', 'backup-guard'));
			}
			if ($exit !== 0 && empty($current['finished'])) {
				WP_CLI::error(sprintf(__('Le programme de restauration s\'est interrompu : %s', 'backup-guard'), implode(' ', $output)));
			}
		} while (empty($current['finished']));
		$status = $current['status'];
		$restore = Backup_Guard_Restore::current();
		if ($restore) {
			Backup_Guard_Restore::acknowledge();
		}
		if ($status === 'success') {
			WP_CLI::success(__('Restauration terminée.', 'backup-guard'));
		} else {
			WP_CLI::error(__('La restauration n\'a pas abouti.', 'backup-guard'));
		}
	}
}

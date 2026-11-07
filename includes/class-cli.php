<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Backups from the command line.
 *
 * ## EXAMPLES
 *
 *     wp rempart backup
 *     wp rempart backup --db-only --location=2 --note="Avant la refonte"
 *     wp rempart list
 *     wp rempart restore wp_exemple_2026-01-01_10-00-00_abc123
 */
class Rempart_CLI {
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
	 * : A storage location's id (see `wp rempart locations`). Default: the local folder.
	 *
	 * [--note=<text>]
	 * : A note kept with the backup.
	 */
	public function backup($args, $assoc) {
		$files = empty($assoc['db-only']);
		$db = empty($assoc['files-only']);
		try {
			Rempart_Job::start_backup(array(
				'files'    => $files,
				'db'       => $db,
				'location' => $assoc['location'] ?? '',
				'scope'    => $assoc['scope'] ?? 'all',
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
			$state = Rempart_Job::run_slice(false);
			list($lines, $printed_next) = rempart_log_read($printed);
			foreach ($lines as $line) {
				WP_CLI::log($line['m']);
			}
			$printed = $printed_next;
			if ($state && empty($state['finished'])) {
				usleep(200000);
			}
		} while ($state && empty($state['finished']));

		if (!$state || $state['status'] !== 'success') {
			WP_CLI::error(sprintf(__('La tâche n\'a pas abouti : %s', 'rempart-backup'), implode(' ', (array) ($state['errors'] ?? array()))));
		}
		WP_CLI::success(__('Terminé.', 'rempart-backup'));
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
		foreach (Rempart_Backups::all() as $info) {
			$rows[] = array(
				'name'     => $info['name'],
				'date'     => wp_date('Y-m-d H:i', (int) $info['btime']),
				'size'     => rempart_format_size($info['size'] ?? 0),
				'files'    => !empty($info['backup_dir']) ? 'oui' : 'non',
				'db'       => !empty($info['backup_db']) ? 'oui' : 'non',
				'location' => !empty($info['location']) ? (Rempart_Locations::get($info['location'])['name'] ?? '?') : 'local',
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
		$protocols = Rempart_Locations::protocols();
		foreach (Rempart_Locations::all() as $location) {
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
			Rempart_Backups::delete($args[0]);
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
		}
		WP_CLI::success(__('Sauvegarde supprimée.', 'rempart-backup'));
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
		WP_CLI::confirm(__('Le contenu actuel du site sera remplacé. Continuer ?', 'rempart-backup'), $assoc);
		try {
			$result = Rempart_Restore::start($args[0], array('files' => empty($assoc['db-only']), 'db' => empty($assoc['files-only'])));
		} catch (Exception $e) {
			WP_CLI::error($e->getMessage());
			return;
		}
		if ($result['mode'] === 'fetch') {
			$printed = 0;
			do {
				$state = Rempart_Job::run_slice(false);
				$finished = $state && !empty($state['finished']);
				if ($state && !$finished) {
					usleep(200000);
				}
			} while ($state && !$finished);
			$restore = Rempart_Restore::current();
			if (!$restore) {
				WP_CLI::error(__('La sauvegarde n\'a pas pu être récupérée.', 'rempart-backup'));
			}
			$result = array('url' => $restore['runner_url'], 'key' => $restore['key']);
		}
		WP_CLI::log(__('Restauration en cours…', 'rempart-backup'));
		$restore = Rempart_Restore::current();
		$from = 0;
		// The program runs right here: it needs no web server, and its progress is read from
		// files because WordPress can't be relied on while its own tables are being replaced.
		$query = http_build_query(array('key' => $restore['key'], 'op' => 'run'));
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($restore['runner']) . ' ' . escapeshellarg($query);
		do {
			$exit = 0;
			$output = array();
			exec($command, $output, $exit);
			$current = rempart_read_state(Rempart_Restore::state_file());
			list($lines, $from) = rempart_log_read($from, rempart_log_file('restore'));
			foreach ($lines as $line) {
				WP_CLI::log($line['m']);
			}
			if (!$current) {
				WP_CLI::error(__('Le programme de restauration a disparu.', 'rempart-backup'));
			}
			if ($exit !== 0 && empty($current['finished'])) {
				WP_CLI::error(sprintf(__('Le programme de restauration s\'est interrompu : %s', 'rempart-backup'), implode(' ', $output)));
			}
		} while (empty($current['finished']));
		$status = $current['status'];
		$restore = Rempart_Restore::current();
		if ($restore) {
			Rempart_Restore::acknowledge();
		}
		if ($status === 'success') {
			WP_CLI::success(__('Restauration terminée.', 'rempart-backup'));
		} else {
			WP_CLI::error(__('La restauration n\'a pas abouti.', 'rempart-backup'));
		}
	}
}

<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Dumps the database to an SQL file, a little at a time.
 * Progress lives in the $cursor array so the next slice can carry on.
 */
class Backup_Guard_Database {
	const ROWS_PER_BATCH = 500;
	const MAX_STATEMENT_BYTES = 524288;

	/** Start a dump: list what's to export and write the file's header. */
	public static function begin($file, array $exclude_tables) {
		global $wpdb;
		$tables = array();
		$views = array();
		foreach ((array) $wpdb->get_results('SHOW FULL TABLES', ARRAY_N) as $row) {
			if (in_array($row[0], $exclude_tables, true)) {
				continue;
			}
			if (strtoupper($row[1]) === 'VIEW') {
				$views[] = $row[0];
			} else {
				$tables[] = $row[0];
			}
		}
		$header = "-- Backup Guard SQL dump\n"
			. '-- Date: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
			. "SET NAMES utf8mb4;\n"
			. "SET FOREIGN_KEY_CHECKS=0;\n"
			. "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
		if (file_put_contents($file, $header) === false) {
			throw new RuntimeException(__('Impossible de créer le fichier de la base de données.', 'backup-guard'));
		}
		return array(
			'tables'    => $tables,
			'views'     => $views,
			'index'     => 0,
			'stage'     => 'structure',
			'after'     => null,
			'offset'    => 0,
			'committed' => strlen($header),
			'rows'      => 0,
		);
	}

	/**
	 * Export until the deadline. Returns true when finished.
	 * The file is cut back to the last committed size first, in case the last run died mid-write.
	 */
	public static function step($file, array &$cursor, $deadline, $on_progress = null) {
		global $wpdb;
		$wpdb->query('SET NAMES utf8mb4');
		$handle = @fopen($file, 'c+b');
		if (!$handle) {
			throw new RuntimeException(__('Impossible d\'ouvrir le fichier de la base de données.', 'backup-guard'));
		}
		ftruncate($handle, $cursor['committed']);
		fseek($handle, 0, SEEK_END);

		while (time() < $deadline) {
			if ($cursor['index'] >= count($cursor['tables'])) {
				self::write_extras($handle, $cursor);
				$cursor['committed'] = ftell($handle);
				fclose($handle);
				return true;
			}
			$table = $cursor['tables'][$cursor['index']];

			if ($cursor['stage'] === 'structure') {
				$create = $wpdb->get_row('SHOW CREATE TABLE `' . self::ident($table) . '`', ARRAY_N);
				if (empty($create[1])) {
					throw new RuntimeException(sprintf(__('Lecture impossible de la table %1$s : %2$s', 'backup-guard'), $table, $wpdb->last_error));
				}
				fwrite($handle, 'DROP TABLE IF EXISTS `' . self::ident($table) . "`;\n" . $create[1] . ";\n\n");
				$cursor['stage'] = 'rows';
				$cursor['after'] = null;
				$cursor['offset'] = 0;
				$cursor['committed'] = ftell($handle);
				continue;
			}

			$done = self::export_rows($handle, $table, $cursor);
			$cursor['committed'] = ftell($handle);
			if ($done) {
				fwrite($handle, "\n");
				$cursor['committed'] = ftell($handle);
				$cursor['index']++;
				$cursor['stage'] = 'structure';
				if ($on_progress) {
					call_user_func($on_progress, $cursor);
				}
			}
		}
		fclose($handle);
		return false;
	}

	private static function ident($name) {
		return str_replace('`', '``', $name);
	}

	/** Which columns hold binary data, and which single numeric key can page the table. */
	private static function table_shape($table) {
		global $wpdb;
		$columns = $wpdb->get_results('SHOW COLUMNS FROM `' . self::ident($table) . '`', ARRAY_A);
		$shape = array('names' => array(), 'kinds' => array(), 'key' => null);
		$primary = array();
		foreach ((array) $columns as $column) {
			$type = strtolower($column['Type']);
			$kind = 'text';
			if (preg_match('/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit|geometry|point|linestring|polygon)/', $type)) {
				$kind = 'binary';
			} elseif (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|float|double|decimal|numeric|year)/', $type)) {
				$kind = 'number';
			}
			$shape['names'][] = $column['Field'];
			$shape['kinds'][] = $kind;
			if ($column['Key'] === 'PRI') {
				$primary[] = array($column['Field'], $kind);
			}
		}
		if (count($primary) === 1 && $primary[0][1] === 'number') {
			$shape['key'] = $primary[0][0];
		}
		return $shape;
	}

	/** Export one batch of rows. Returns true when the table has no more. */
	private static function export_rows($handle, $table, array &$cursor) {
		global $wpdb;
		$shape = self::table_shape($table);
		if (!$shape['names']) {
			return true;
		}
		$quoted = '`' . self::ident($table) . '`';

		// Paging by a numeric key stays fast on huge tables; LIMIT with an offset gets slower with depth.
		if ($shape['key']) {
			$key = '`' . self::ident($shape['key']) . '`';
			$where = $cursor['after'] === null ? '' : ' WHERE ' . $key . ' > ' . $cursor['after'];
			$sql = 'SELECT * FROM ' . $quoted . $where . ' ORDER BY ' . $key . ' LIMIT ' . self::ROWS_PER_BATCH;
		} else {
			$sql = 'SELECT * FROM ' . $quoted . ' LIMIT ' . (int) $cursor['offset'] . ', ' . self::ROWS_PER_BATCH;
		}
		$rows = $wpdb->get_results($sql, ARRAY_N);
		if ($wpdb->last_error) {
			throw new RuntimeException(sprintf(__('Lecture impossible de la table %1$s : %2$s', 'backup-guard'), $table, $wpdb->last_error));
		}
		if (!$rows) {
			return true;
		}

		$column_list = '(`' . implode('`,`', array_map(array(__CLASS__, 'ident'), $shape['names'])) . '`)';
		$prefix = 'INSERT INTO ' . $quoted . ' ' . $column_list . " VALUES\n";
		$statement = '';
		$key_index = $shape['key'] ? array_search($shape['key'], $shape['names'], true) : null;

		foreach ($rows as $row) {
			$values = array();
			foreach ($row as $i => $value) {
				$values[] = self::literal($value, $shape['kinds'][$i]);
			}
			$tuple = '(' . implode(',', $values) . ')';
			if ($statement !== '' && strlen($statement) + strlen($tuple) > self::MAX_STATEMENT_BYTES) {
				fwrite($handle, $prefix . $statement . ";\n");
				$statement = '';
			}
			$statement .= ($statement === '' ? '' : ",\n") . $tuple;
			if ($key_index !== null) {
				$cursor['after'] = $row[$key_index];
			}
			$cursor['rows']++;
		}
		if ($statement !== '') {
			fwrite($handle, $prefix . $statement . ";\n");
		}
		$cursor['offset'] += count($rows);
		return count($rows) < self::ROWS_PER_BATCH;
	}

	private static function literal($value, $kind) {
		if ($value === null) {
			return 'NULL';
		}
		if ($kind === 'binary') {
			return $value === '' ? "''" : '0x' . bin2hex($value);
		}
		if ($kind === 'number' && is_numeric($value)) {
			return $value;
		}
		$escaped = str_replace(
			array('\\', "\0", "\n", "\r", "'", "\x1a"),
			array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\Z'),
			$value
		);
		return "'" . $escaped . "'";
	}

	/** Views, triggers and stored routines come last, once every table exists. */
	private static function write_extras($handle, array $cursor) {
		global $wpdb;
		foreach ($cursor['views'] as $view) {
			$row = $wpdb->get_row('SHOW CREATE VIEW `' . self::ident($view) . '`', ARRAY_N);
			if (!empty($row[1])) {
				fwrite($handle, 'DROP VIEW IF EXISTS `' . self::ident($view) . "`;\n" . self::strip_definer($row[1]) . ";\n\n");
			}
		}
		foreach ($cursor['tables'] as $table) {
			$triggers = $wpdb->get_results($wpdb->prepare('SHOW TRIGGERS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
			foreach ((array) $triggers as $trigger) {
				$row = $wpdb->get_row('SHOW CREATE TRIGGER `' . self::ident($trigger['Trigger']) . '`', ARRAY_A);
				if (!empty($row['SQL Original Statement'])) {
					fwrite($handle, 'DROP TRIGGER IF EXISTS `' . self::ident($trigger['Trigger']) . "`;\nDELIMITER ;;\n"
						. self::strip_definer($row['SQL Original Statement']) . ";;\nDELIMITER ;\n\n");
				}
			}
		}
		foreach (array('PROCEDURE', 'FUNCTION') as $kind) {
			$routines = @$wpdb->get_results($wpdb->prepare("SHOW {$kind} STATUS WHERE Db = %s", DB_NAME), ARRAY_A);
			foreach ((array) $routines as $routine) {
				$row = @$wpdb->get_row("SHOW CREATE {$kind} `" . self::ident($routine['Name']) . '`', ARRAY_A);
				$body = $row ? ($kind === 'PROCEDURE' ? ($row['Create Procedure'] ?? '') : ($row['Create Function'] ?? '')) : '';
				if ($body !== '') {
					fwrite($handle, "DROP {$kind} IF EXISTS `" . self::ident($routine['Name']) . "`;\nDELIMITER ;;\n"
						. self::strip_definer($body) . ";;\nDELIMITER ;\n\n");
				}
			}
		}
		$events = @$wpdb->get_results($wpdb->prepare('SHOW EVENTS WHERE Db = %s', DB_NAME), ARRAY_A);
		foreach ((array) $events as $event) {
			$row = @$wpdb->get_row('SHOW CREATE EVENT `' . self::ident($event['Name']) . '`', ARRAY_A);
			if (!empty($row['Create Event'])) {
				fwrite($handle, 'DROP EVENT IF EXISTS `' . self::ident($event['Name']) . "`;\nDELIMITER ;;\n"
					. self::strip_definer($row['Create Event']) . ";;\nDELIMITER ;\n\n");
			}
		}
	}

	/** The account that created a view or trigger may not exist on the site restored to. */
	private static function strip_definer($sql) {
		return preg_replace('/\s*DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|[^\s@]+)@(`[^`]*`|\'[^\']*\'|[^\s]+)/i', ' ', $sql);
	}
}

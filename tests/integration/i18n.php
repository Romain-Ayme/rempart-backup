<?php
/**
 * Checks the translations: the plugin is written in French, so French sites see the code's own texts
 * and every other site sees the English translation. Also checks that the translation files are
 * complete and in step with the code.
 *
 *   npx @wordpress/env@12 run cli wp eval-file wp-content/plugins/backup-guard/tests/integration/i18n.php
 */
if (!defined('ABSPATH') || !class_exists('Backup_Guard_Job')) {
	fwrite(STDERR, "Ce script doit être lancé dans WordPress avec le plugin actif (wp eval-file).\n");
	exit(1);
}

require_once ABSPATH . WPINC . '/pomo/po.php';
require_once BACKUP_GUARD_DIR . '/includes/class-admin.php';

function backup_guard_it_expect($condition, $text) {
	if (!$condition) {
		throw new RuntimeException($text);
	}
}

/** Pretend the site speaks this language. */
function backup_guard_it_locale($locale) {
	remove_all_filters('determine_locale', 100);
	add_filter('determine_locale', function () use ($locale) {
		return $locale;
	}, 100);
	backup_guard_load_translations();
}

/** The %s, %1$s, %d placeholders of a text, sorted. */
function backup_guard_it_placeholders($text) {
	preg_match_all('/%(?:\d+\$)?[sd]/', $text, $m);
	$found = $m[0];
	sort($found);
	return $found;
}

$files = BACKUP_GUARD_DIR . '/languages/';
$cases = array(

	'anglais pour un site en anglais' => function () {
		backup_guard_it_locale('en_US');
		backup_guard_it_expect(backup_guard_scopes()['content']['label'] === 'My files only', 'Libellé anglais attendu : ' . backup_guard_scopes()['content']['label']);
		try {
			Backup_Guard_Job::start_backup(array('files' => true, 'db' => false, 'location' => '999'));
		} catch (RuntimeException $e) {
			backup_guard_it_expect($e->getMessage() === 'The chosen location no longer exists.', 'Message anglais attendu : ' . $e->getMessage());
			return;
		}
		throw new RuntimeException('La sauvegarde aurait dû être refusée.');
	},

	'anglais pour les variantes de l\'anglais et les autres langues' => function () {
		foreach (array('en_GB', 'en_AU', 'es_ES', 'de_DE', 'ja') as $locale) {
			backup_guard_it_locale($locale);
			backup_guard_it_expect(backup_guard_scopes()['content']['label'] === 'My files only', $locale . ' devrait recevoir l\'anglais, obtenu : ' . backup_guard_scopes()['content']['label']);
		}
	},

	'français (texte du code) pour un site en français' => function () {
		foreach (array('fr_FR', 'fr_CA', 'fr_BE') as $locale) {
			backup_guard_it_locale($locale);
			backup_guard_it_expect(backup_guard_scopes()['content']['label'] === 'Mes fichiers seulement', $locale . ' devrait recevoir le français, obtenu : ' . backup_guard_scopes()['content']['label']);
		}
	},

	'unités de taille et format de date' => function () {
		backup_guard_it_locale('en_US');
		backup_guard_it_expect(backup_guard_format_size(1536) === '1.50 KB', 'Unité anglaise attendue : ' . backup_guard_format_size(1536));
		backup_guard_it_locale('fr_FR');
		backup_guard_it_expect(strpos(backup_guard_format_size(1536), 'Ko') !== false, 'Unité française attendue : ' . backup_guard_format_size(1536));
	},

	'textes du script de l\'administration' => function () {
		$method = new ReflectionMethod('Backup_Guard_Admin', 'js_strings');
		$method->setAccessible(true);
		backup_guard_it_locale('en_US');
		$map = $method->invoke(null);
		backup_guard_it_expect(($map['Sauvegarde terminée'] ?? '') === 'Backup finished', 'Texte du script non traduit.');
		backup_guard_it_locale('fr_FR');
		$map = $method->invoke(null);
		backup_guard_it_expect(($map['Sauvegarde terminée'] ?? '') === 'Sauvegarde terminée', 'Texte du script en français attendu.');
	},

	"page d'administration, dans la langue du site" => function () {
		wp_set_current_user(1);
		$render = function () {
			ob_start();
			Backup_Guard_Admin::page();
			return ob_get_clean();
		};
		backup_guard_it_locale('en_US');
		$english = $render();
		foreach (array('Create a backup', 'Dashboard', 'Backup locations', 'Space used', 'Files to keep') as $expected) {
			backup_guard_it_expect(strpos($english, $expected) !== false, '« ' . $expected . ' » absent de la page en anglais.');
		}
		backup_guard_it_expect(strpos($english, 'Créer une sauvegarde') === false, 'Du français reste dans la page en anglais.');
		backup_guard_it_locale('fr_FR');
		$french = $render();
		foreach (array('Créer une sauvegarde', 'Tableau de bord', 'Emplacements de sauvegarde', 'Espace utilisé') as $expected) {
			backup_guard_it_expect(strpos($french, $expected) !== false, '« ' . $expected . ' » absent de la page en français.');
		}
		backup_guard_it_expect(strpos($french, 'Create a backup') === false, "De l'anglais reste dans la page en français.");
		wp_set_current_user(0);
	},

	'journal du programme de restauration, dans la langue du site' => function () {
		global $wpdb;
		Backup_Guard_Job::force_stop();
		foreach (array('en_US' => 'Database imported.', 'fr_FR' => 'Base de données importée.') as $locale => $expected) {
			backup_guard_it_locale($locale);
			$job = Backup_Guard_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
			do {
				$state = Backup_Guard_Job::run_slice(false);
			} while ($state && empty($state['finished']));
			backup_guard_it_expect($state['status'] === 'success', 'La sauvegarde de départ a échoué.');
			$result = WP_CLI::runcommand('backup-guard restore ' . $job['name'] . ' --db-only --yes', array('return' => 'all', 'launch' => false, 'exit_error' => false));
			Backup_Guard_Backups::delete($job['name']);
			if (Backup_Guard_Restore::current()) {
				Backup_Guard_Restore::acknowledge();
			}
			backup_guard_it_expect((int) $result->return_code === 0, 'La restauration a échoué : ' . $result->stderr);
			backup_guard_it_expect(strpos($result->stdout, $expected) !== false, $locale . ' : « ' . $expected . ' » absent du journal de restauration.');
		}
	},

	'fichier de traduction complet' => function () use ($files) {
		$po = new PO();
		backup_guard_it_expect($po->import_from_file($files . 'backup-guard-en_US.po'), 'Le fichier .po est illisible.');
		$empty = array();
		$wrong = array();
		foreach ($po->entries as $entry) {
			$translation = $entry->translations[0] ?? '';
			if ($translation === '') {
				$empty[] = $entry->singular;
				continue;
			}
			if (backup_guard_it_placeholders($entry->singular) !== backup_guard_it_placeholders($translation)) {
				$wrong[] = $entry->singular;
			}
		}
		backup_guard_it_expect(!$empty, count($empty) . ' texte(s) sans traduction, par exemple : ' . substr((string) reset($empty), 0, 80));
		backup_guard_it_expect(!$wrong, 'Les %s de ces textes ne correspondent pas à la traduction : ' . substr((string) reset($wrong), 0, 80));
		backup_guard_it_expect(is_file($files . 'backup-guard-en_US.mo') && filemtime($files . 'backup-guard-en_US.mo') >= filemtime($files . 'backup-guard-en_US.po') - 5, 'Le fichier .mo est plus ancien que le .po : relancer npm run i18n:build.');
	},

	'fichiers de traduction à jour avec le code' => function () use ($files) {
		$fresh = sys_get_temp_dir() . '/backup-guard-check.pot';
		WP_CLI::runcommand('i18n make-pot ' . escapeshellarg(BACKUP_GUARD_DIR) . ' ' . escapeshellarg($fresh) . ' --slug=backup-guard --domain=backup-guard --exclude=vendor,tests,tools,dist,node_modules,languages --quiet', array('launch' => false, 'exit_error' => false));
		$ids = function ($file) {
			$po = new PO();
			$po->import_from_file($file);
			$out = array();
			foreach ($po->entries as $entry) {
				$out[] = ($entry->context !== null ? $entry->context . '|' : '') . $entry->singular;
			}
			sort($out);
			return $out;
		};
		$code = $ids($fresh);
		unlink($fresh);
		foreach (array('backup-guard.pot', 'backup-guard-en_US.po') as $name) {
			$saved = $ids($files . $name);
			$added = array_diff($code, $saved);
			$removed = array_diff($saved, $code);
			backup_guard_it_expect(!$added && !$removed, $name . ' n\'est plus à jour avec le code (npm run i18n:build). Ajouté : ' . substr((string) reset($added), 0, 70) . ' | Retiré : ' . substr((string) reset($removed), 0, 70));
		}
	},
);

$failed = 0;
foreach ($cases as $name => $case) {
	try {
		$case();
		WP_CLI::success($name . ' : OK');
	} catch (Throwable $e) {
		$failed++;
		WP_CLI::warning($name . ' : ÉCHEC : ' . $e->getMessage());
	}
	Backup_Guard_Job::force_stop();
}
backup_guard_it_locale('en_US');

if ($failed > 0) {
	WP_CLI::error($failed . ' scénario(s) en échec.');
}

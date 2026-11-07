<?php
/**
 * Checks the translations: the plugin is written in French, so French sites see the code's own texts
 * and every other site sees the English translation. Also checks that the translation files are
 * complete and in step with the code.
 *
 *   npx @wordpress/env@12 run cli wp eval-file wp-content/plugins/rempart-backup/tests/integration/i18n.php
 */
if (!defined('ABSPATH') || !class_exists('Rempart_Job')) {
	fwrite(STDERR, "Ce script doit être lancé dans WordPress avec le plugin actif (wp eval-file).\n");
	exit(1);
}

require_once ABSPATH . WPINC . '/pomo/po.php';
require_once REMPART_DIR . '/includes/class-admin.php';

function rempart_it_expect($condition, $text) {
	if (!$condition) {
		throw new RuntimeException($text);
	}
}

/** Pretend the site speaks this language. */
function rempart_it_locale($locale) {
	remove_all_filters('determine_locale', 100);
	add_filter('determine_locale', function () use ($locale) {
		return $locale;
	}, 100);
	rempart_load_translations();
}

/** The %s, %1$s, %d placeholders of a text, sorted. */
function rempart_it_placeholders($text) {
	preg_match_all('/%(?:\d+\$)?[sd]/', $text, $m);
	$found = $m[0];
	sort($found);
	return $found;
}

$files = REMPART_DIR . '/languages/';
$cases = array(

	'anglais pour un site en anglais' => function () {
		rempart_it_locale('en_US');
		rempart_it_expect(rempart_scopes()['content']['label'] === 'My files only', 'Libellé anglais attendu : ' . rempart_scopes()['content']['label']);
		try {
			Rempart_Job::start_backup(array('files' => true, 'db' => false, 'location' => '999'));
		} catch (RuntimeException $e) {
			rempart_it_expect($e->getMessage() === 'The chosen location no longer exists.', 'Message anglais attendu : ' . $e->getMessage());
			return;
		}
		throw new RuntimeException('La sauvegarde aurait dû être refusée.');
	},

	'anglais pour les variantes de l\'anglais et les autres langues' => function () {
		foreach (array('en_GB', 'en_AU', 'es_ES', 'de_DE', 'ja') as $locale) {
			rempart_it_locale($locale);
			rempart_it_expect(rempart_scopes()['content']['label'] === 'My files only', $locale . ' devrait recevoir l\'anglais, obtenu : ' . rempart_scopes()['content']['label']);
		}
	},

	'français (texte du code) pour un site en français' => function () {
		foreach (array('fr_FR', 'fr_CA', 'fr_BE') as $locale) {
			rempart_it_locale($locale);
			rempart_it_expect(rempart_scopes()['content']['label'] === 'Mes fichiers seulement', $locale . ' devrait recevoir le français, obtenu : ' . rempart_scopes()['content']['label']);
		}
	},

	'unités de taille et format de date' => function () {
		rempart_it_locale('en_US');
		rempart_it_expect(rempart_format_size(1536) === '1.50 KB', 'Unité anglaise attendue : ' . rempart_format_size(1536));
		rempart_it_locale('fr_FR');
		rempart_it_expect(strpos(rempart_format_size(1536), 'Ko') !== false, 'Unité française attendue : ' . rempart_format_size(1536));
	},

	'textes du script de l\'administration' => function () {
		$method = new ReflectionMethod('Rempart_Admin', 'js_strings');
		$method->setAccessible(true);
		rempart_it_locale('en_US');
		$map = $method->invoke(null);
		rempart_it_expect(($map['Sauvegarde terminée'] ?? '') === 'Backup finished', 'Texte du script non traduit.');
		rempart_it_locale('fr_FR');
		$map = $method->invoke(null);
		rempart_it_expect(($map['Sauvegarde terminée'] ?? '') === 'Sauvegarde terminée', 'Texte du script en français attendu.');
	},

	"page d'administration, dans la langue du site" => function () {
		wp_set_current_user(1);
		$render = function () {
			ob_start();
			Rempart_Admin::page();
			return ob_get_clean();
		};
		rempart_it_locale('en_US');
		$english = $render();
		foreach (array('Create a backup', 'Dashboard', 'Backup locations', 'Space used', 'Files to keep') as $expected) {
			rempart_it_expect(strpos($english, $expected) !== false, '« ' . $expected . ' » absent de la page en anglais.');
		}
		rempart_it_expect(strpos($english, 'Créer une sauvegarde') === false, 'Du français reste dans la page en anglais.');
		rempart_it_locale('fr_FR');
		$french = $render();
		foreach (array('Créer une sauvegarde', 'Tableau de bord', 'Emplacements de sauvegarde', 'Espace utilisé') as $expected) {
			rempart_it_expect(strpos($french, $expected) !== false, '« ' . $expected . ' » absent de la page en français.');
		}
		rempart_it_expect(strpos($french, 'Create a backup') === false, "De l'anglais reste dans la page en français.");
		wp_set_current_user(0);
	},

	'journal du programme de restauration, dans la langue du site' => function () {
		global $wpdb;
		Rempart_Job::force_stop();
		foreach (array('en_US' => 'Database imported.', 'fr_FR' => 'Base de données importée.') as $locale => $expected) {
			rempart_it_locale($locale);
			$job = Rempart_Job::start_backup(array('files' => false, 'db' => true, 'location' => ''));
			do {
				$state = Rempart_Job::run_slice(false);
			} while ($state && empty($state['finished']));
			rempart_it_expect($state['status'] === 'success', 'La sauvegarde de départ a échoué.');
			$result = WP_CLI::runcommand('rempart restore ' . $job['name'] . ' --db-only --yes', array('return' => 'all', 'launch' => false, 'exit_error' => false));
			Rempart_Backups::delete($job['name']);
			if (Rempart_Restore::current()) {
				Rempart_Restore::acknowledge();
			}
			rempart_it_expect((int) $result->return_code === 0, 'La restauration a échoué : ' . $result->stderr);
			rempart_it_expect(strpos($result->stdout, $expected) !== false, $locale . ' : « ' . $expected . ' » absent du journal de restauration.');
		}
	},

	'fichier de traduction complet' => function () use ($files) {
		$po = new PO();
		rempart_it_expect($po->import_from_file($files . 'rempart-backup-en_US.po'), 'Le fichier .po est illisible.');
		$empty = array();
		$wrong = array();
		foreach ($po->entries as $entry) {
			$translation = $entry->translations[0] ?? '';
			if ($translation === '') {
				$empty[] = $entry->singular;
				continue;
			}
			if (rempart_it_placeholders($entry->singular) !== rempart_it_placeholders($translation)) {
				$wrong[] = $entry->singular;
			}
		}
		rempart_it_expect(!$empty, count($empty) . ' texte(s) sans traduction, par exemple : ' . substr((string) reset($empty), 0, 80));
		rempart_it_expect(!$wrong, 'Les %s de ces textes ne correspondent pas à la traduction : ' . substr((string) reset($wrong), 0, 80));
		rempart_it_expect(is_file($files . 'rempart-backup-en_US.mo') && filemtime($files . 'rempart-backup-en_US.mo') >= filemtime($files . 'rempart-backup-en_US.po') - 5, 'Le fichier .mo est plus ancien que le .po : relancer npm run i18n:build.');
	},

	'fichiers de traduction à jour avec le code' => function () use ($files) {
		$fresh = sys_get_temp_dir() . '/rempart-check.pot';
		WP_CLI::runcommand('i18n make-pot ' . escapeshellarg(REMPART_DIR) . ' ' . escapeshellarg($fresh) . ' --slug=rempart-backup --domain=rempart-backup --exclude=vendor,tests,tools,dist,node_modules,languages --quiet', array('launch' => false, 'exit_error' => false));
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
		foreach (array('rempart-backup.pot', 'rempart-backup-en_US.po') as $name) {
			$saved = $ids($files . $name);
			$added = array_diff($code, $saved);
			$removed = array_diff($saved, $code);
			rempart_it_expect(!$added && !$removed, $name . ' n\'est plus à jour avec le code (npm run i18n:build). Ajouté : ' . substr((string) reset($added), 0, 70) . ' | Retiré : ' . substr((string) reset($removed), 0, 70));
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
	Rempart_Job::force_stop();
}
rempart_it_locale('en_US');

if ($failed > 0) {
	WP_CLI::error($failed . ' scénario(s) en échec.');
}

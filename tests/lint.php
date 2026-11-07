<?php
/**
 * Checks the PHP syntax of every plugin file, with the PHP that runs this script.
 * vendor/ and tests/ are not checked here: they're not written by the plugin's authors.
 */
$root = dirname(__DIR__);
$skip = array();
foreach (array('vendor', 'tests', '.git') as $dir) {
	$real = realpath($root . '/' . $dir);
	if ($real !== false) {
		$skip[] = $real . DIRECTORY_SEPARATOR;
	}
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$checked = 0;
$failed = 0;
foreach ($files as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}
	$real = $file->getRealPath();
	$skipped = false;
	foreach ($skip as $prefix) {
		if (strpos($real, $prefix) === 0) {
			$skipped = true;
			break;
		}
	}
	if ($skipped) {
		continue;
	}
	$checked++;
	$output = array();
	$code = 0;
	exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($real) . ' 2>&1', $output, $code);
	if ($code !== 0) {
		$failed++;
		fwrite(STDERR, implode("\n", $output) . "\n");
	}
}

echo "Syntaxe : $checked fichiers, $failed erreur(s).\n";
exit($failed > 0 ? 1 : 0);

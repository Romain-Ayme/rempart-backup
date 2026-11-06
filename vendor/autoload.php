<?php
/**
 * Loads the bundled SFTP library (phpseclib, MIT) and its helper (constant_time_encoding, MIT)
 * only when an SFTP location is used. Their licences are next to their code.
 */
spl_autoload_register(function ($class) {
	$roots = array(
		'phpseclib3\\'          => __DIR__ . '/phpseclib/phpseclib/',
		'ParagonIE\\ConstantTime\\' => __DIR__ . '/constant_time_encoding/src/',
	);
	foreach ($roots as $prefix => $dir) {
		if (strpos($class, $prefix) === 0) {
			$file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	}
});

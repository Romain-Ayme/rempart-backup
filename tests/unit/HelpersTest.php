<?php

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase {
	public function testFormatSizeUsesTheRightUnit(): void {
		$this->assertSame('0 o', backup_guard_format_size(0));
		$this->assertSame('1,00 Ko', backup_guard_format_size(1024));
		$this->assertSame('1,50 Ko', backup_guard_format_size(1536));
		$this->assertSame('1,00 Mo', backup_guard_format_size(1048576));
	}

	public function testCleanPathNormalizesSeparators(): void {
		$this->assertSame('C:/a/b/c', backup_guard_clean_path('C:\\a\\\\b//c/'));
		$this->assertSame('/', backup_guard_clean_path('/'));
	}

	public function testExcludeRules(): void {
		$excludes = array(
			'exact'     => array('wp-content/cache'),
			'extension' => array('log'),
			'beginning' => array('tmp_'),
			'end'       => array('.bak'),
			'anywhere'  => array('node_modules'),
		);

		$this->assertTrue(backup_guard_is_excluded('wp-content/cache/x.php', $excludes), 'exact folder');
		$this->assertTrue(backup_guard_is_excluded('wp-content/cache', $excludes), 'exact folder itself');
		$this->assertTrue(backup_guard_is_excluded('a/debug.log', $excludes), 'extension');
		$this->assertTrue(backup_guard_is_excluded('tmp_file', $excludes), 'beginning');
		$this->assertTrue(backup_guard_is_excluded('old/index.php.bak', $excludes), 'end');
		$this->assertTrue(backup_guard_is_excluded('app/node_modules/x.js', $excludes), 'anywhere');

		$this->assertFalse(backup_guard_is_excluded('wp-content/cachex.php', $excludes), 'not the same folder');
		$this->assertFalse(backup_guard_is_excluded('wp-content/themes/style.css', $excludes), 'untouched file');
	}

	public function testScopeSkipsOnlyWhatItShould(): void {
		$file = tempnam(sys_get_temp_dir(), 'backup-guard-scope-');
		file_put_contents($file, 'original');
		$core = array('index.php' => md5('original'), 'wp-cron.php' => md5('autre contenu'));

		// "all" keeps everything.
		$this->assertFalse(backup_guard_skips_core_file('all', 'index.php', $file, $core));
		// "content" drops every WordPress file, edited or not.
		$this->assertTrue(backup_guard_skips_core_file('content', 'index.php', $file, $core));
		$this->assertTrue(backup_guard_skips_core_file('content', 'wp-cron.php', $file, $core));
		// "modified" drops the untouched ones and keeps the edited ones.
		$this->assertTrue(backup_guard_skips_core_file('modified', 'index.php', $file, $core));
		$this->assertFalse(backup_guard_skips_core_file('modified', 'wp-cron.php', $file, $core));
		// A file WordPress does not ship is always kept.
		$this->assertFalse(backup_guard_skips_core_file('content', 'mon-fichier.php', $file, $core));
		$this->assertFalse(backup_guard_skips_core_file('modified', 'mon-fichier.php', $file, $core));
		unlink($file);
	}

	public function testUnknownScopeFallsBackToAll(): void {
		$this->assertSame('all', backup_guard_valid_scope('nimporte quoi'));
		$this->assertSame('content', backup_guard_valid_scope('content'));
	}
}

<?php

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase {
	public function testFormatSizeUsesTheRightUnit(): void {
		$this->assertSame('0 o', rempart_format_size(0));
		$this->assertSame('1,00 Ko', rempart_format_size(1024));
		$this->assertSame('1,50 Ko', rempart_format_size(1536));
		$this->assertSame('1,00 Mo', rempart_format_size(1048576));
	}

	public function testCleanPathNormalizesSeparators(): void {
		$this->assertSame('C:/a/b/c', rempart_clean_path('C:\\a\\\\b//c/'));
		$this->assertSame('/', rempart_clean_path('/'));
	}

	public function testExcludeRules(): void {
		$excludes = array(
			'exact'     => array('wp-content/cache'),
			'extension' => array('log'),
			'beginning' => array('tmp_'),
			'end'       => array('.bak'),
			'anywhere'  => array('node_modules'),
		);

		$this->assertTrue(rempart_is_excluded('wp-content/cache/x.php', $excludes), 'exact folder');
		$this->assertTrue(rempart_is_excluded('wp-content/cache', $excludes), 'exact folder itself');
		$this->assertTrue(rempart_is_excluded('a/debug.log', $excludes), 'extension');
		$this->assertTrue(rempart_is_excluded('tmp_file', $excludes), 'beginning');
		$this->assertTrue(rempart_is_excluded('old/index.php.bak', $excludes), 'end');
		$this->assertTrue(rempart_is_excluded('app/node_modules/x.js', $excludes), 'anywhere');

		$this->assertFalse(rempart_is_excluded('wp-content/cachex.php', $excludes), 'not the same folder');
		$this->assertFalse(rempart_is_excluded('wp-content/themes/style.css', $excludes), 'untouched file');
	}
}

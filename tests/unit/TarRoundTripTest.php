<?php

use PHPUnit\Framework\TestCase;

final class TarRoundTripTest extends TestCase {
	private $path;

	protected function setUp(): void {
		$this->path = tempnam(sys_get_temp_dir(), 'backup-guard-tar-');
		unlink($this->path);
	}

	protected function tearDown(): void {
		if (is_file($this->path)) {
			unlink($this->path);
		}
	}

	public function testSmallFileComesBackIntact(): void {
		$writer = new Backup_Guard_Tar_Writer($this->path);
		$writer->header('hello.txt', 11, time(), 0644);
		$writer->write('hello world');
		$writer->pad_to_block(11);
		$writer->finish();

		$reader = new Backup_Guard_Tar_Reader($this->path);
		$entry = $reader->next_entry();
		$this->assertSame('hello.txt', $entry['name']);
		$this->assertSame(11, (int) $entry['size']);
		$this->assertSame('hello world', $reader->read(11));
		$this->assertNull($reader->next_entry());
	}

	public function testLongNameIsKept(): void {
		$name = str_repeat('folder/', 20) . 'file.txt';
		$writer = new Backup_Guard_Tar_Writer($this->path);
		$writer->header($name, 2, time(), 0644);
		$writer->write('ok');
		$writer->pad_to_block(2);
		$writer->finish();

		$reader = new Backup_Guard_Tar_Reader($this->path);
		$this->assertSame($name, $reader->next_entry()['name']);
	}

	public function testLargeFileSpanningSeveralGzipMembersComesBackIntact(): void {
		$data = random_bytes(3 * 1024 * 1024 + 5);
		$writer = new Backup_Guard_Tar_Writer($this->path);
		$writer->header('big.bin', strlen($data), time(), 0644);
		for ($offset = 0; $offset < strlen($data); $offset += 1048576) {
			$writer->write(substr($data, $offset, 1048576));
			$writer->flush();
		}
		$writer->pad_to_block(strlen($data));
		$writer->finish();

		$reader = new Backup_Guard_Tar_Reader($this->path);
		$entry = $reader->next_entry();
		$this->assertSame(strlen($data), (int) $entry['size']);
		$read = '';
		while (strlen($read) < strlen($data)) {
			$part = $reader->read(min(1048576, strlen($data) - strlen($read)));
			if ($part === '') {
				break;
			}
			$read .= $part;
		}
		$this->assertTrue(hash_equals(hash('sha256', $data), hash('sha256', $read)), 'content differs');
	}
}

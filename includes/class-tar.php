<?php
if (!defined('ABSPATH') && !defined('REMPART_RUNNER')) {
	exit;
}

/**
 * Writes a .tar.gz piece by piece.
 *
 * The archive is a chain of complete gzip members, which every gzip tool reads as one
 * stream. After each member the file on disk is valid up to that point, so a killed
 * process can resume: cut the file back to the last recorded size and carry on.
 */
class Rempart_Tar_Writer {
	private $handle;
	private $buffer = '';
	private $level;

	public function __construct($path, $committed_size = 0, $level = 9) {
		$this->level = $level;
		$this->handle = @fopen($path, 'c+b');
		if (!$this->handle) {
			throw new RuntimeException('Impossible d\'ouvrir l\'archive en écriture : ' . $path);
		}
		ftruncate($this->handle, $committed_size);
		fseek($this->handle, 0, SEEK_END);
	}

	public function buffered() {
		return strlen($this->buffer);
	}

	public function write($bytes) {
		$this->buffer .= $bytes;
	}

	/** Finish the current gzip member. Returns the archive's valid size. */
	public function flush() {
		if ($this->buffer !== '') {
			$compressed = gzencode($this->buffer, $this->level);
			$written = fwrite($this->handle, $compressed);
			if ($written !== strlen($compressed)) {
				throw new RuntimeException('Écriture de l\'archive impossible : le disque est peut-être plein.');
			}
			fflush($this->handle);
			$this->buffer = '';
		}
		return ftell($this->handle);
	}

	/** Write a tar header (with a long-name record first when the name needs one). */
	public function header($name, $size, $mtime, $mode, $type = '0', $link = '') {
		if (strlen($name) > 100) {
			$this->write(self::raw_header('././@LongLink', strlen($name) + 1, 0, 0, 'L', ''));
			$this->write(self::pad($name . "\0"));
		}
		if (strlen($link) > 100) {
			$this->write(self::raw_header('././@LongLink', strlen($link) + 1, 0, 0, 'K', ''));
			$this->write(self::pad($link . "\0"));
		}
		$this->write(self::raw_header($name, $size, $mtime, $mode, $type, $link));
	}

	/** Zero bytes that fill a payload of $size bytes up to the next 512-byte block. */
	public function pad_to_block($size) {
		$rest = $size % 512;
		if ($rest) {
			$this->write(str_repeat("\0", 512 - $rest));
		}
	}

	/** The two empty blocks that end a tar file. */
	public function finish() {
		$this->write(str_repeat("\0", 1024));
		$size = $this->flush();
		fclose($this->handle);
		$this->handle = null;
		return $size;
	}

	public function close() {
		if ($this->handle) {
			fclose($this->handle);
			$this->handle = null;
		}
	}

	private static function pad($bytes) {
		$rest = strlen($bytes) % 512;
		return $rest ? $bytes . str_repeat("\0", 512 - $rest) : $bytes;
	}

	private static function octal_field($value, $width) {
		// Values too large for octal digits use the base-256 form GNU tar reads (files over 8 GB).
		if ($value >= pow(8, $width - 1)) {
			$out = '';
			for ($i = 0; $i < $width - 1; $i++) {
				$out = chr((int) fmod($value, 256)) . $out;
				$value = floor($value / 256);
			}
			return chr(0x80) . $out;
		}
		return str_pad(decoct((int) $value), $width - 1, '0', STR_PAD_LEFT) . "\0";
	}

	private static function raw_header($name, $size, $mtime, $mode, $type, $link) {
		$fields = str_pad(substr($name, 0, 100), 100, "\0")
			. self::octal_field($mode & 07777, 8)
			. self::octal_field(0, 8)
			. self::octal_field(0, 8)
			. self::octal_field($size, 12)
			. self::octal_field($mtime, 12);
		$tail = $type
			. str_pad(substr($link, 0, 100), 100, "\0")
			. "ustar  \0"
			. str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
		$sum = 0;
		$probe = $fields . '        ' . $tail;
		for ($i = 0, $n = strlen($probe); $i < $n; $i++) {
			$sum += ord($probe[$i]);
		}
		$checksum = str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ";
		return $fields . $checksum . $tail;
	}
}

/**
 * Reads a .tar.gz piece by piece, and can resume in a later request.
 *
 * Its position is (offset of a gzip member in the file, bytes to skip after inflating that
 * member's start). With archives from this plugin, members are small so resuming is quick;
 * an archive from elsewhere made of one big member still works but inflates from the start.
 */
class Rempart_Tar_Reader {
	private $handle;
	private $inflate = null;
	private $member_start = 0;
	private $member_produced = 0;
	private $buffer = '';
	private $buffer_pos = 0;
	private $eof = false;
	/** Size of the tar entry being read, for skip logic. */
	public $file_size = 0;

	public function __construct($path, $member_start = 0, $skip = 0) {
		$this->handle = @fopen($path, 'rb');
		if (!$this->handle) {
			throw new RuntimeException('Impossible d\'ouvrir l\'archive : ' . $path);
		}
		$this->file_size = filesize($path);
		$this->member_start = $member_start;
		fseek($this->handle, $member_start);
		$this->start_member();
		while ($skip > 0 && !$this->eof) {
			$got = $this->read(min($skip, 1048576));
			if ($got === '') {
				break;
			}
			$skip -= strlen($got);
		}
	}

	private function start_member() {
		$this->inflate = inflate_init(ZLIB_ENCODING_GZIP);
		$this->member_produced = 0;
	}

	/** Where to come back to: [member offset, bytes into it]. */
	public function position() {
		return array($this->member_start, $this->member_produced - (strlen($this->buffer) - $this->buffer_pos));
	}

	public function compressed_offset() {
		return $this->member_start;
	}

	/** Inflate the next piece of the archive. Returns false at its end. */
	private function advance() {
		$this->buffer = '';
		$this->buffer_pos = 0;

		if ($this->pending_member !== null) {
			$this->member_start = $this->pending_member;
			$this->pending_member = null;
			if ($this->member_start >= $this->file_size) {
				$this->eof = true;
				return false;
			}
			fseek($this->handle, $this->member_start);
			$this->start_member();
		}
		if ($this->eof) {
			return false;
		}

		$chunk_start = ftell($this->handle);
		$chunk = fread($this->handle, 262144);
		if ($chunk === '' || $chunk === false) {
			$this->eof = true;
			return false;
		}
		$out = @inflate_add($this->inflate, $chunk, ZLIB_SYNC_FLUSH);
		if ($out === false) {
			throw new RuntimeException('Archive endommagée (décompression impossible près de l\'octet ' . $chunk_start . ').');
		}
		$this->buffer = $out;
		$this->member_produced += strlen($out);

		// The chunk may also hold the start of the next member, so note where this one ended.
		if (inflate_get_status($this->inflate) === ZLIB_STREAM_END) {
			$this->pending_member = $this->member_start + inflate_get_read_len($this->inflate);
		}
		return true;
	}

	private $pending_member = null;

	public function read($length) {
		$out = '';
		while (strlen($out) < $length) {
			if ($this->buffer_pos < strlen($this->buffer)) {
				$take = substr($this->buffer, $this->buffer_pos, $length - strlen($out));
				$this->buffer_pos += strlen($take);
				$out .= $take;
				continue;
			}
			if (!$this->advance()) {
				break;
			}
		}
		return $out;
	}

	/** Read the next tar header. Returns the entry, or null at the end of the archive. */
	public function next_entry() {
		$long_name = null;
		$long_link = null;
		while (true) {
			$block = $this->read(512);
			if (strlen($block) < 512 || trim($block, "\0") === '') {
				return null;
			}
			$entry = self::parse_header($block);
			if ($entry === null) {
				throw new RuntimeException('Archive endommagée : en-tête de fichier invalide.');
			}
			if ($entry['type'] === 'L' || $entry['type'] === 'K') {
				$value = rtrim($this->read((int) (ceil($entry['size'] / 512) * 512)), "\0");
				if ($entry['type'] === 'L') {
					$long_name = substr($value, 0, $entry['size']);
				} else {
					$long_link = substr($value, 0, $entry['size']);
				}
				continue;
			}
			if ($entry['type'] === 'x' || $entry['type'] === 'g') {
				$pax = $this->read((int) (ceil($entry['size'] / 512) * 512));
				if ($entry['type'] === 'x' && preg_match('/\d+ path=([^\n]+)\n/', $pax, $m)) {
					$long_name = $m[1];
				}
				continue;
			}
			if ($long_name !== null) {
				$entry['name'] = $long_name;
			}
			if ($long_link !== null) {
				$entry['link'] = $long_link;
			}
			return $entry;
		}
	}

	public function close() {
		if ($this->handle) {
			fclose($this->handle);
			$this->handle = null;
		}
	}

	private static function parse_number($field) {
		if (ord($field[0]) & 0x80) {
			$value = 0.0;
			for ($i = 1, $n = strlen($field); $i < $n; $i++) {
				$value = $value * 256 + ord($field[$i]);
			}
			return $value;
		}
		$field = trim($field, " \0");
		return $field === '' ? 0 : octdec($field);
	}

	private static function parse_header($block) {
		$sum = 0;
		for ($i = 0; $i < 512; $i++) {
			$sum += ($i >= 148 && $i < 156) ? 32 : ord($block[$i]);
		}
		if ($sum !== (int) self::parse_number(substr($block, 148, 8))) {
			return null;
		}
		$name = rtrim(substr($block, 0, 100), "\0");
		$prefix = rtrim(substr($block, 345, 155), "\0");
		if (substr($block, 257, 6) === "ustar\0" && $prefix !== '') {
			$name = $prefix . '/' . $name;
		}
		$type = $block[156];
		return array(
			'name'  => $name,
			'mode'  => (int) self::parse_number(substr($block, 100, 8)),
			'size'  => self::parse_number(substr($block, 124, 12)),
			'mtime' => (int) self::parse_number(substr($block, 136, 12)),
			'type'  => $type === "\0" ? '0' : $type,
			'link'  => rtrim(substr($block, 157, 100), "\0"),
		);
	}
}

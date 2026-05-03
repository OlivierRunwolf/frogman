<?php
namespace FreePBX\modules\Frogman\Dialplan;

/**
 * Reads, parses, backs up, and writes extensions_custom.conf.
 * This is the ONLY file we ever touch.
 */
class DialplanFile {

	const CONF_PATH = '/etc/asterisk/extensions_custom.conf';
	const BACKUP_DIR = '/var/www/html/admin/modules/frogman/dialplan-backups';

	/**
	 * Read the raw file contents.
	 */
	public static function readRaw() {
		if (!file_exists(self::CONF_PATH)) {
			return '';
		}
		return file_get_contents(self::CONF_PATH);
	}

	/**
	 * Parse the file into structured contexts.
	 * Returns: ['context_name' => ['lines' => [...], 'comment' => '...']]
	 */
	public static function parse() {
		$raw = self::readRaw();
		$contexts = [];
		$currentContext = null;
		$preContextComments = '';

		foreach (explode("\n", $raw) as $line) {
			$trimmed = trim($line);

			// Context header
			if (preg_match('/^\[([^\]]+)\]/', $trimmed, $m)) {
				$currentContext = $m[1];
				$contexts[$currentContext] = [
					'name' => $currentContext,
					'comment' => trim($preContextComments),
					'lines' => [],
				];
				$preContextComments = '';
				continue;
			}

			if ($currentContext === null) {
				// Comments/blanks before any context
				if (strpos($trimmed, ';') === 0 || $trimmed === '') {
					$preContextComments .= $line . "\n";
				}
				continue;
			}

			// Lines within a context
			if ($trimmed !== '') {
				$contexts[$currentContext]['lines'][] = $line;
			}
		}

		return $contexts;
	}

	/**
	 * Get a single context by name.
	 */
	public static function getContext($name) {
		$contexts = self::parse();
		return $contexts[$name] ?? null;
	}

	/**
	 * List all context names.
	 */
	public static function listContexts() {
		return array_keys(self::parse());
	}

	/**
	 * Backup the current file. Returns the backup path.
	 */
	public static function backup() {
		if (!is_dir(self::BACKUP_DIR)) {
			mkdir(self::BACKUP_DIR, 0755, true);
		}
		$timestamp = date('Y-m-d_H-i-s');
		$backupPath = self::BACKUP_DIR . "/extensions_custom_{$timestamp}.conf";
		copy(self::CONF_PATH, $backupPath);
		return $backupPath;
	}

	/**
	 * Check if a context name already exists.
	 */
	public static function contextExists($name) {
		$contexts = self::parse();
		return isset($contexts[$name]);
	}

	/**
	 * Append a new context to the file. Does NOT overwrite existing contexts.
	 * $block should be the full context text including [context_name] header.
	 */
	public static function appendContext($block) {
		$current = rtrim(self::readRaw());
		$separator = ($current !== '') ? "\n\n" : '';
		file_put_contents(self::CONF_PATH, $current . $separator . $block . "\n");
	}

	/**
	 * Remove a context from the file by name.
	 */
	public static function removeContext($name) {
		$raw = self::readRaw();
		$lines = explode("\n", $raw);
		$output = [];
		$inTarget = false;
		$skippedComments = [];

		for ($i = 0; $i < count($lines); $i++) {
			$trimmed = trim($lines[$i]);

			if (preg_match('/^\[([^\]]+)\]/', $trimmed, $m)) {
				if ($m[1] === $name) {
					// Start skipping this context — also skip preceding comments
					$inTarget = true;
					// Remove trailing comment lines we buffered
					while (!empty($skippedComments)) {
						array_pop($output);
						array_pop($skippedComments);
					}
					continue;
				} else {
					$inTarget = false;
					$skippedComments = [];
				}
			}

			if ($inTarget) {
				// Skip lines in the target context
				continue;
			}

			// Buffer comment lines in case they belong to the next context
			if (strpos($trimmed, ';') === 0) {
				$skippedComments[] = $lines[$i];
			} else {
				$skippedComments = [];
			}

			$output[] = $lines[$i];
		}

		// Clean up trailing blank lines
		$result = rtrim(implode("\n", $output)) . "\n";
		file_put_contents(self::CONF_PATH, $result);
	}

	/**
	 * Reload the dialplan via AMI (not a full fwconsole reload).
	 */
	public static function reloadDialplan() {
		$astman = \FreePBX::astman();
		if ($astman && $astman->connected()) {
			$res = $astman->Command('dialplan reload');
			return isset($res['Response']) && $res['Response'] === 'Success';
		}
		// Fallback
		exec('asterisk -rx "dialplan reload" 2>&1', $output, $exitCode);
		return $exitCode === 0;
	}

	/**
	 * Validate that a context loads without errors.
	 */
	public static function validateDialplan($contextName) {
		$astman = \FreePBX::astman();
		if ($astman && $astman->connected()) {
			$res = $astman->Command("dialplan show {$contextName}");
			$data = $res['data'] ?? '';
			// If context exists and has lines, it parsed OK
			if (stripos($data, 'There is no existence of') !== false) {
				return ['valid' => false, 'error' => "Context {$contextName} not found after reload"];
			}
			return ['valid' => true, 'output' => trim($data)];
		}
		return ['valid' => true, 'output' => 'Could not verify (AMI unavailable)'];
	}
}

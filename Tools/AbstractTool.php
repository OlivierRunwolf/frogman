<?php
namespace FreePBX\modules\Frogman\Tools;

abstract class AbstractTool {

	protected $freepbx;
	protected $frogman;

	// Permission levels
	const PERM_READ = 'read';       // List/show/get operations
	const PERM_WRITE = 'write';     // Create/update/delete PBX objects
	const PERM_ADMIN = 'admin';     // Module management, firewall, advanced settings, fwconsole

	public function __construct($freepbx, $frogman) {
		$this->freepbx = $freepbx;
		$this->frogman = $frogman;
	}

	abstract public function name();
	abstract public function description();
	abstract public function validate($params);
	abstract public function execute($params, $context);

	/**
	 * Permission level required: 'read', 'write', or 'admin'.
	 * Override in subclass. Default is 'read'.
	 */
	public function permissionLevel() {
		return self::PERM_READ;
	}

	/**
	 * Legacy method — still used by some tools.
	 * Returns null (no specific FreePBX permission needed beyond the level).
	 */
	public function requiredPermission() {
		return null;
	}

	/**
	 * Check if sudo is available for fwconsole.
	 * Returns true if available, false if not.
	 */
	protected function canSudo() {
		exec('sudo -n /usr/sbin/fwconsole --version 2>&1', $out, $ec);
		return $ec === 0;
	}

	/**
	 * Run a fwconsole command with sudo if available.
	 * Returns ['output' => string, 'exit_code' => int] or the root-required message.
	 */
	protected function runAsRoot($cmd, $confirm = true) {
		if (!$confirm) {
			return null; // dry-run, don't check yet
		}
		if (!$this->canSudo()) {
			return [
				'needs_root' => true,
				'message' => "This command requires root access.",
			];
		}
		$output = [];
		$ec = 0;
		exec("sudo /usr/sbin/fwconsole {$cmd} 2>&1", $output, $ec);
		$raw = implode("\n", $output);
		// Strip ANSI codes
		$raw = preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $raw);
		return ['output' => trim($raw), 'exit_code' => $ec];
	}
}

<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class FwconsoleCmd extends AbstractTool {
	public function name() { return 'oc_fwconsole'; }
	public function description() { return 'Run an fwconsole command. Params: args (required, e.g. "ma list" or "sa info"). Requires confirm:true for non-read commands.'; }
	public function validate($params) {
		if (empty($params['args'])) return 'Parameter "args" is required';
		// Block dangerous commands
		$args = strtolower($params['args']);
		if (preg_match('/rm\s|del\s|drop\s|truncate/i', $args)) {
			return 'Dangerous command blocked';
		}
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_ADMIN; }
	public function execute($params, $context) {
		$args = $params['args'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		// Read-only commands don't need confirm
		$readOnly = preg_match('/^(ma\s+list|sa\s+info|pm2\s+--list|status|--version|-V)/i', $args);
		if (!$readOnly && !$confirm) {
			return ['dry_run' => true, 'message' => "Would run: fwconsole {$args}. Reply yes to confirm."];
		}
		$output = []; $exitCode = 0;
		exec('/usr/sbin/fwconsole ' . $args . ' 2>&1', $output, $exitCode);
		return ['command' => "fwconsole {$args}", 'exit_code' => $exitCode, 'output' => implode("\n", $output)];
	}
}

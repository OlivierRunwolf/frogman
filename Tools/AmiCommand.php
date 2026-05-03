<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AmiCommand extends AbstractTool {
	public function name() { return 'oc_ami_command'; }
	public function description() { return 'Run an Asterisk CLI command via AMI. Params: command (required). Read-only commands only.'; }
	public function validate($params) { if (empty($params['command'])) return 'Parameter "command" is required';
		return true; }
	public function execute($params, $context) {
		$cmd = $params['command']; $blocked = ['restart', 'stop', 'shutdown', 'module unload', 'module load', 'database del', 'channel request hangup']; foreach($blocked as $b) { if(stripos($cmd, $b) !== false) throw new \Exception('Blocked command: ' . $b); } $astman = $this->freepbx->astman; if(!$astman || !$astman->connected()) throw new \Exception('Cannot connect to Asterisk Manager'); $res = $astman->Command($cmd); $output = trim($res['data'] ?? ''); $output = preg_replace('/^Privilege:\s+\w+\s*/i', '', $output); return ['command' => $cmd, 'output' => $output];
	}
}

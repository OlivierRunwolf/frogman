<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class AddInboundRoute extends AbstractTool {
	public function name() { return 'fm_add_inbound_route'; }
	public function description() { return 'Add an inbound route (DID). Params: extension (DID number, required), destination (required — an extension number, "vm <ext>", "rg <id>", "ivr <id>", "tc <id>", or a full destination string like "from-did-direct,1001,1"), description (optional), cidnum (optional CID match). Requires confirm:true.'; }
	public function validate($params) {
		if (empty($params['extension'])) return 'Parameter "extension" is required';
		if (empty($params['destination'])) return 'Parameter "destination" is required';
		return true;
	}
	public function requiredPermission() { return null; }
	public function permissionLevel() { return self::PERM_WRITE; }

	// Resolve a friendly destination shorthand to a full FreePBX destination string.
	// Supported inputs:
	//   "1001"             → from-did-direct,1001,1   (extension; respects DND/follow-me)
	//   "vm 1001"          → ext-local,vmu1001,1      (voicemail unavailable greeting)
	//   "rg 600"           → ext-group,600,1          (ring group)
	//   "ringgroup 600"    → ext-group,600,1
	//   "ivr 1"            → ivr-1,s,1
	//   "tc 1"             → timeconditions,1,1
	//   "timecondition 1"  → timeconditions,1,1
	// Anything containing a comma is treated as already-formatted and returned as-is.
	private function resolveDestination($input) {
		$dest = trim((string)$input);
		if ($dest === '') return $dest;
		if (strpos($dest, ',') !== false) return $dest; // already a full destination
		if (preg_match('/^\d+$/', $dest)) {
			return "from-did-direct,{$dest},1";
		}
		if (preg_match('/^(vm|voicemail)\s+(\d+)$/i', $dest, $m)) {
			return "ext-local,vmu{$m[2]},1";
		}
		if (preg_match('/^(rg|ringgroup)\s+(\d+)$/i', $dest, $m)) {
			return "ext-group,{$m[2]},1";
		}
		if (preg_match('/^ivr\s+(\d+)$/i', $dest, $m)) {
			return "ivr-{$m[1]},s,1";
		}
		if (preg_match('/^(tc|timecondition)\s+(\d+)$/i', $dest, $m)) {
			return "timeconditions,{$m[2]},1";
		}
		return $dest; // unknown shape — let Core validate
	}

	// Classify an extension destination by which BMO half is present.
	// FreePBX's Inbound Route GUI destination dropdown is built from core_destinations(),
	// which only registers extensions that have a row in the `users` table. Device-only
	// extensions are dialable but won't appear in the dropdown, so any route pointing at
	// one shows red ("unknown destination") when edited in the GUI even though the
	// destination string itself is in the canonical from-did-direct,<ext>,1 format.
	// Surface this to the caller so they know what to expect.
	private function classifyExtensionDestination($ext) {
		$user = $this->freepbx->Core->getUser((string)$ext);
		if (!empty($user)) return 'user';
		$device = $this->freepbx->Core->getDevice((string)$ext);
		if (!empty($device)) return 'device-only';
		return 'none';
	}

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$dest = $this->resolveDestination($params['destination']);

		// Extension-destination existence/visibility check. Only applies when the resolved
		// destination is the from-did-direct,<ext>,1 form — i.e. caller passed a bare
		// extension number, not a pre-formatted string or a different destination type.
		$advisory = '';
		if (preg_match('/^from-did-direct,(\d+),1$/', $dest, $m)) {
			$ext = $m[1];
			$kind = $this->classifyExtensionDestination($ext);
			if ($kind === 'none') {
				return ['error' => "Extension {$ext} does not exist (no user or device record). Route not created."];
			}
			if ($kind === 'device-only') {
				$advisory = " ⚠️ Extension {$ext} is device-only (no user record). The route will work, but the FreePBX GUI Inbound Routes editor will show this destination as unknown/red. To fix: create a user-mode Extension {$ext} via Applications → Extensions.";
			}
		}

		$incoming = [
			'extension' => $params['extension'],
			'cidnum' => $params['cidnum'] ?? '',
			'destination' => $dest,
			'description' => $params['description'] ?? '',
			'privacyman' => 0, 'alertinfo' => '', 'ringing' => '', 'fanswer' => '',
			'mohclass' => 'default', 'grppre' => '', 'delay_answer' => 0, 'pricid' => '',
			'pmmaxretries' => '', 'pmminlength' => '', 'reversal' => '', 'rvolume' => '',
		];
		$descNote = !empty($params['description']) ? " — _{$params['description']}_" : '';
		if (!$confirm) {
			$cidNote = !empty($params['cidnum']) ? " (CID match: {$params['cidnum']})" : '';
			return ['dry_run' => true, 'message' => "Would add inbound route: DID `{$params['extension']}`{$cidNote} → `{$dest}`{$descNote}.{$advisory} Reply yes to confirm.", 'route' => $incoming];
		}
		\FreePBX::Core()->addDID($incoming);
		return ['dry_run' => false, 'message' => "✅ Inbound route added: DID `{$params['extension']}` → `{$dest}`{$descNote}.{$advisory}", 'needs_reload' => true];
	}
}

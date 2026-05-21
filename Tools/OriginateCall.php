<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class OriginateCall extends AbstractTool {
	public function name() { return 'fm_originate_call'; }

	public function description() {
		return 'Click-to-call: ring an extension first, then on answer dial out to a destination. '
			. 'Params: ext (required, must be an existing extension), dest (required, 2-18 digits with '
			. 'optional + prefix or feature-code chars). Outbound leg runs through the extension\'s own '
			. 'from-internal path, so the same outbound-route ACL that gates the deskphone gates this. '
			. 'Requires confirm:true.';
	}

	public function permissionLevel() { return self::PERM_WRITE; }

	public function validate($params) {
		if (empty($params['ext']) || !ctype_digit((string)$params['ext'])) {
			return 'Parameter "ext" must be digits';
		}
		if (empty($params['dest'])) {
			return 'Parameter "dest" is required';
		}
		// AMI-framing injection defense: reject CR/LF/NUL/semicolons before they reach Originate.
		if (preg_match('/[\r\n\0;,]/', (string)$params['dest'])) {
			return 'Parameter "dest" contains illegal characters';
		}
		// PSTN digits with optional + prefix, plus feature-code chars (*97 voicemail, etc.).
		if (!preg_match('/^\+?[0-9*#]{2,18}$/', (string)$params['dest'])) {
			return 'Parameter "dest" must be 2-18 digits, optionally + prefixed or feature-code chars';
		}
		return true;
	}

	public function execute($params, $context) {
		$ext  = (string)$params['ext'];
		$dest = (string)$params['dest'];

		// Existence check via BMO. getDevice is the AMI-target half; without a device
		// there's nothing to ring. getUser is the FreePBX-Extension-object half — when
		// present, the from-internal dialplan handles DND/FMFM/ring-groups for this ext.
		$device = $this->freepbx->Core->getDevice($ext);
		if (empty($device)) {
			return ['error' => "Extension {$ext} does not exist"];
		}
		$user = $this->freepbx->Core->getUser($ext);

		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would ring extension {$ext} and dial {$dest} on answer. Reply yes to confirm.",
			];
		}

		$astman = $this->freepbx->astman;
		if (!$astman || !$astman->connected()) {
			throw new \Exception('Cannot connect to Asterisk Manager');
		}

		// Channel selection:
		//   user+device present → Local/<ext>@from-internal — runs the FreePBX-generated
		//     extension dialplan for ext, so DND, FMFM, ring groups behave the same as a
		//     normal incoming call.
		//   device-only (no user) → device's own dial string (e.g. PJSIP/101). The user-side
		//     ring-this-device dialplan doesn't exist for these, so from-internal,<ext>
		//     would land on the "cannot complete as dialed" catch-all. Dial the device
		//     directly instead. Outbound leg still routes through from-internal so the
		//     outbound-route ACL is unchanged.
		$channel = !empty($user) ? "Local/{$ext}@from-internal" : $device['dial'];
		$res = $astman->Originate([
			'Channel'  => $channel,
			'Context'  => 'from-internal',
			'Exten'    => $dest,
			'Priority' => '1',
			'Timeout'  => 30000,
			// What shows on the ringing extension's screen — surface the destination so the user
			// knows what they're picking up to dial. dest already passed validate's char whitelist.
			'CallerID' => "\"Call {$dest}\" <{$dest}>",
			'Async'    => 'true',
		]);

		$ok = is_array($res) && (($res['Response'] ?? '') === 'Success');
		return [
			'dry_run' => false,
			'message' => $ok
				? "Ringing extension {$ext} — will dial {$dest} on answer."
				: 'Originate failed: ' . ($res['Message'] ?? 'unknown error'),
			'result'  => $res,
		];
	}
}

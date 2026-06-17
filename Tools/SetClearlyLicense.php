<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

/**
 * Assign or unassign a Clearly Anywhere (clearlysp) softphone license for an
 * extension's User Manager user.
 *
 * A "Clearly license" is, mechanically, the clearlysp per-user enabled flag:
 * the module's permission lottery hands a seat to each User Manager user whose
 * Clearly state is "enabled". So assigning = set the user enabled, unassigning =
 * set it disabled. We drive the module's own BMO method (updateUserSettings),
 * which is exactly what the User Manager "Clearly Anywhere" tab calls on save —
 * it re-runs the permission lottery so seat accounting stays correct. No DB
 * writes to the clearlysp tables ourselves.
 *
 * Extension → user: clearlysp keys on the User Manager uid, and an extension's
 * user is the Userman user whose default_extension is that extension (the one
 * fm_add_extension creates).
 */
class SetClearlyLicense extends AbstractTool {

	const STATES = ['enabled', 'disabled', 'inherit'];

	public function name() { return 'fm_set_clearly_license'; }

	public function description() {
		return 'Assign or unassign a Clearly Anywhere softphone license for an extension. Params: ext (required, the extension number), action (required, "assign" to grant the license / "unassign" to remove it; "inherit" is also accepted to defer to the group default). Resolves the extension\'s User Manager user and flips its Clearly Anywhere state via the clearlysp module. Requires confirm:true.';
	}

	public function validate($params) {
		if (empty($params['ext'])) return 'Parameter "ext" is required';
		if (!preg_match('/^\d+$/', (string)$params['ext'])) return 'Parameter "ext" must be numeric';
		$action = $params['action'] ?? '';
		if (!in_array($action, ['assign', 'unassign', 'enabled', 'disabled', 'inherit'], true)) {
			return 'Parameter "action" must be "assign" or "unassign"';
		}
		return true;
	}

	public function requiredPermission() { return 'write:extension'; }

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$ext = (string)$params['ext'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		// Normalise the verb to the clearlysp state value.
		$map = ['assign' => 'enabled', 'unassign' => 'disabled', 'enabled' => 'enabled', 'disabled' => 'disabled', 'inherit' => 'inherit'];
		$state = $map[$params['action']];
		$verb = ($state === 'enabled') ? 'assign' : (($state === 'disabled') ? 'unassign' : 'set to inherit');

		// clearlysp must be present and active.
		if (!$this->freepbx->Modules->checkStatus('clearlysp')) {
			throw new \Exception("Clearly Anywhere (clearlysp) is not installed/enabled on this system");
		}
		$cly = $this->freepbx->Clearlysp;
		if (empty($cly)) {
			throw new \Exception("Could not load the Clearly Anywhere module");
		}

		// Resolve extension -> Userman user.
		$umUser = null;
		try { $umUser = $this->freepbx->Userman->getUserByDefaultExtension($ext); } catch (\Throwable $e) {}
		if (empty($umUser) || empty($umUser['id'])) {
			throw new \Exception("Extension {$ext} has no linked User Manager user — create the extension with fm_add_extension (which creates the user) first, then assign the license");
		}
		$uid = (int)$umUser['id'];
		$username = $umUser['username'] ?? $ext;

		// Current Clearly state for this user (so we can show before/after and skip no-ops).
		$current = $this->currentState($cly, $uid);

		if ($current === $state) {
			return [
				'dry_run' => false,
				'message' => "Clearly Anywhere is already \"{$state}\" for extension {$ext} (user `{$username}`). No change.",
				'ext' => $ext, 'uid' => $uid, 'state' => $state,
			];
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would {$verb} the Clearly Anywhere license for extension {$ext} (user `{$username}`, uid {$uid}); current state \"{$current}\" → \"{$state}\". Reply yes to confirm.",
				'ext' => $ext, 'uid' => $uid, 'from' => $current, 'to' => $state,
			];
		}

		// Drive the module's own per-user update (re-runs the seat lottery).
		// Signature: updateUserSettings($id, $enabled, $messaging, $did, ...defaults).
		$cly->updateUserSettings($uid, $state, 'inherit', '');

		$after = $this->currentState($cly, $uid);
		return [
			'dry_run' => false,
			'message' => "Clearly Anywhere license for extension {$ext} (user `{$username}`) set to \"{$after}\".",
			'ext' => $ext, 'uid' => $uid, 'from' => $current, 'to' => $after,
		];
	}

	/**
	 * Resolve a user's current Clearly state via the module's own getter so we
	 * read whatever it considers authoritative (enabled / disabled / inherit).
	 */
	private function currentState($cly, $uid) {
		try {
			if (method_exists($cly, 'getUserSettings')) {
				$s = $cly->getUserSettings($uid);
				if (is_array($s) && isset($s['enabled']) && $s['enabled'] !== '') {
					return $s['enabled'];
				}
			}
		} catch (\Throwable $e) {}
		return 'inherit';
	}
}

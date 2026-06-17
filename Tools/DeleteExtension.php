<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

/**
 * Delete an extension and, by default, its linked User Manager user.
 *
 * The counterpart to fm_add_extension (which creates both the SIP extension and
 * the Userman user). A plain Core delete leaves the Userman row orphaned — the
 * very orphan fm_delete_userman_user exists to mop up — so this tool tears down
 * both sides together unless keep_userman:true is passed.
 *
 * EPM-provisioned phones: deleting the extension via Core fires the endpoint
 * module's delDevice hook, which unassigns the handset. The dry-run preview
 * lists any provisioned phones so the operator knows they'll be freed.
 */
class DeleteExtension extends AbstractTool {

	public function name() {
		return 'fm_delete_extension';
	}

	public function description() {
		return 'Delete an extension. By default the linked User Manager user is deleted too (so nothing is left orphaned); pass keep_userman:true to keep it. Any EPM-provisioned phone is unassigned. Params: ext (required, numeric), keep_userman (optional bool, default false). Requires confirm:true to execute.';
	}

	public function validate($params) {
		if (empty($params['ext'])) {
			return 'Parameter "ext" is required';
		}
		if (!preg_match('/^\d+$/', $params['ext'])) {
			return 'Parameter "ext" must be numeric';
		}
		return true;
	}

	public function requiredPermission() {
		return 'write:extension';
	}

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$ext = $params['ext'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$keepUserman = !empty($params['keep_userman']) && $params['keep_userman'] === true;

		$device = $this->freepbx->Core->getDevice($ext);
		$user = $this->freepbx->Core->getUser($ext);
		if (empty($device) && empty($user)) {
			throw new \Exception("Extension {$ext} not found");
		}

		$name = $user['name'] ?? ($device['description'] ?? $ext);

		// Linked Userman user (matched by default extension).
		$umUser = null;
		try { $umUser = $this->freepbx->Userman->getUserByDefaultExtension($ext); } catch (\Throwable $e) {}
		$hasUm = !empty($umUser) && !empty($umUser['id']);
		$umNote = '';
		if ($hasUm) {
			$umNote = $keepUserman
				? " The linked User Manager user `{$umUser['username']}` (uid {$umUser['id']}) will be KEPT."
				: " The linked User Manager user `{$umUser['username']}` (uid {$umUser['id']}) will also be deleted.";
		}

		$phones = $this->getEpmPhones($ext);
		$phoneNote = '';
		if (!empty($phones)) {
			$labels = array_map([$this, 'phoneLabel'], $phones);
			$phoneNote = ' ' . count($phones) . ' provisioned phone(s) will be unassigned: ' . implode(', ', $labels) . '.';
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would delete extension {$ext} (\"{$name}\").{$umNote}{$phoneNote} Pass confirm:true to execute.",
				'extension' => $ext,
				'name' => $name,
				'userman_user' => $hasUm ? ['id' => (int)$umUser['id'], 'username' => $umUser['username'] ?? ''] : null,
				'delete_userman' => !$keepUserman && $hasUm,
				'phones' => $phones,
			];
		}

		// FreePBX's own extension delete: remove the user record then the device.
		// Both fire framework hooks (voicemail teardown, EPM unassign, etc.).
		$this->freepbx->Core->delUser($ext);
		$this->freepbx->Core->delDevice($ext);

		$result = [
			'dry_run' => false,
			'message' => "Extension {$ext} (\"{$name}\") deleted.",
			'extension' => $ext,
			'name' => $name,
			'needs_reload' => true,
		];

		// Tear down the Userman side too, unless told to keep it.
		if (!$keepUserman && $hasUm) {
			try {
				$res = $this->freepbx->Userman->deleteUserByID((int)$umUser['id']);
				if (is_array($res) && isset($res['status']) && !$res['status']) {
					$result['userman_delete_error'] = $res['message'] ?? 'unknown error';
				} else {
					$result['userman_deleted'] = ['id' => (int)$umUser['id'], 'username' => $umUser['username'] ?? ''];
					$result['message'] .= " User Manager user `{$umUser['username']}` deleted.";
				}
			} catch (\Throwable $e) {
				$result['userman_delete_error'] = $e->getMessage();
			}
		} elseif ($keepUserman && $hasUm) {
			$result['userman_kept'] = ['id' => (int)$umUser['id'], 'username' => $umUser['username'] ?? ''];
		}

		return $result;
	}

	/**
	 * EPM phones provisioned for this extension. endpoint_extensions maps each
	 * handset to "<ext>-<n>" (e.g. 24-1, 24-2). Returns [] when EPM isn't
	 * installed or the extension has no provisioned phones (softphone only).
	 */
	private function getEpmPhones($ext) {
		try {
			$db = $this->freepbx->Database();
			$sth = $db->prepare("SELECT ext, mac, brand, model FROM endpoint_extensions WHERE ext LIKE ?");
			$sth->execute([$ext . '-%']);
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
			return is_array($rows) ? $rows : [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	private function phoneLabel($p) {
		$model = trim(($p['brand'] ?? '') . ' ' . ($p['model'] ?? ''));
		return $p['ext'] . ($model !== '' ? " ({$model})" : '');
	}
}

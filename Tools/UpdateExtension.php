<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class UpdateExtension extends AbstractTool {

	public function name() {
		return 'fm_update_extension';
	}

	public function description() {
		return 'Update an existing extension in place. Params: ext (required), plus any fields to change: name, secret, outboundcid. A NAME change is propagated EVERYWHERE the name is shown — the FreePBX display name, the User Manager display name, the device description, and the physical phone(s): their provisioning config is rebuilt from the new name and pushed to the handset (resync) so the new name appears on screen. Voicemail, follow-me, Userman links, and every other extension setting are preserved. Requires confirm:true to execute.';
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

		$device = $this->freepbx->Core->getDevice($ext);
		if (empty($device)) {
			throw new \Exception("Extension {$ext} not found");
		}
		$user = $this->freepbx->Core->getUser($ext);
		if (empty($user)) {
			throw new \Exception("Extension {$ext} has a device but no user record — inconsistent state, edit aborted");
		}

		$userChanges = [];
		$deviceChanges = [];
		if (isset($params['name']) && $params['name'] !== ($user['name'] ?? '')) {
			$userChanges['name'] = ['from' => $user['name'] ?? '', 'to' => $params['name']];
		}
		if (isset($params['outboundcid']) && $params['outboundcid'] !== ($user['outboundcid'] ?? '')) {
			$userChanges['outboundcid'] = ['from' => $user['outboundcid'] ?? '', 'to' => $params['outboundcid']];
		}
		if (isset($params['secret']) && $params['secret'] !== ($device['secret'] ?? '')) {
			$deviceChanges['secret'] = ['from' => '***', 'to' => '***'];
		}

		$allChanges = array_merge($userChanges, $deviceChanges);
		if (empty($allChanges)) {
			return [
				'dry_run' => false,
				'message' => "No changes detected for extension {$ext}",
			];
		}

		// A name change ripples out to the phones; look them up so the preview can
		// warn the admin that the handset(s) will resync.
		$nameChanged = isset($userChanges['name']);
		$phones = $nameChanged ? $this->getEpmPhones($ext) : [];

		if (!$confirm) {
			$extra = '';
			if ($nameChanged) {
				$extra = ' The new name will also be applied to the User Manager display name and the device description.';
				if (!empty($phones)) {
					$labels = array_map([$this, 'phoneLabel'], $phones);
					$extra .= ' ' . count($phones) . ' phone(s) will be reprovisioned and will briefly resync: '
						. implode(', ', $labels) . '.';
				}
			}
			return [
				'dry_run' => true,
				'message' => "Would update extension {$ext}. Pass confirm:true to execute." . $extra,
				'changes' => $allChanges,
				'preserved' => 'Voicemail, follow-me, Userman, recording prefs, and every unchanged setting will be preserved.',
			];
		}

		// FreePBX's own edit flow is delete-with-editmode + add-with-editmode. The
		// editmode flag tells Core to skip the AstDB teardown that a real delete
		// would do, so registrations, hint state, and device→user links survive
		// the cycle. We seed the add from get*() output so every field we didn't
		// touch carries through untouched.
		if (!empty($userChanges)) {
			$userVars = $user;
			if (isset($userChanges['name'])) {
				$userVars['name'] = $params['name'];
			}
			if (isset($userChanges['outboundcid'])) {
				$userVars['outboundcid'] = $params['outboundcid'];
			}
			$userVars['extension'] = $ext;

			$this->freepbx->Core->delUser($ext, true);
			$this->freepbx->Core->addUser($ext, $userVars, true);
		}

		// addDevice expects the wrapped ['key' => ['value' => x]] shape that
		// generateDefaultDeviceSettings produces; getDevice returns flat. Wrap
		// before calling.
		if (!empty($deviceChanges)) {
			$deviceVars = [];
			foreach ($device as $k => $v) {
				$deviceVars[$k] = ['value' => $v];
			}
			if (isset($deviceChanges['secret'])) {
				$deviceVars['secret']['value'] = $params['secret'];
			}

			$this->freepbx->Core->delDevice($ext, true);
			$this->freepbx->Core->addDevice($ext, $device['tech'], $deviceVars, true);
		}

		$result = [
			'dry_run' => false,
			'message' => "Extension {$ext} updated successfully",
			'changes' => $allChanges,
			'needs_reload' => true,
		];

		// A name change must show up EVERYWHERE the old name lived, not just in
		// users.name (which Core->addUser above handles). The PJSIP connected-line
		// caller-ID name is regenerated from users.name on reload, but these are
		// NOT: the User Manager display name (UCP), the device description (GUI),
		// and — most visibly to the user — the screen of the physical phone(s),
		// whose label only changes when the EPM provisioning file is rebuilt from
		// the new name and pushed to the handset (endpoint update = resync).
		if ($nameChanged) {
			$newName = $params['name'];
			$propagated = ['users.name'];

			try {
				$db = $this->freepbx->Database();
				$sth = $db->prepare("UPDATE devices SET description = ? WHERE id = ?");
				$sth->execute([$newName, $ext]);
				$propagated[] = 'devices.description';
			} catch (\Throwable $e) {
				$result['device_description_error'] = $e->getMessage();
			}

			try {
				$db = $this->freepbx->Database();
				$sth = $db->prepare("UPDATE userman_users SET displayname = ? WHERE default_extension = ?");
				$sth->execute([$newName, $ext]);
				if ($sth->rowCount() > 0) {
					$propagated[] = 'userman.displayname';
				}
			} catch (\Throwable $e) {
				$result['userman_error'] = $e->getMessage();
			}

			// Reprovision the physical phone(s): rebuild the config from the new
			// name, then push it so the handset re-pulls and shows the new label.
			$reprovisioned = [];
			foreach ($phones as $p) {
				$epmExt = $p['ext'];
				$rebuild = $this->runFwconsole('endpoint rebuild ' . $epmExt);
				$update  = $this->runFwconsole('endpoint update ' . $epmExt);
				$reprovisioned[] = [
					'phone' => $epmExt,
					'mac' => $p['mac'] ?? '',
					'model' => $this->phoneModel($p),
					'ok' => ($rebuild['exit_code'] === 0 && $update['exit_code'] === 0),
				];
			}
			if (!empty($reprovisioned)) {
				$result['reprovisioned'] = $reprovisioned;
				$okCount = count(array_filter($reprovisioned, function ($r) { return $r['ok']; }));
				$propagated[] = "{$okCount}/" . count($reprovisioned) . ' phone(s) reprovisioned';
			}

			$result['propagated'] = $propagated;
			$result['message'] = "Extension {$ext} renamed to \"{$newName}\" everywhere"
				. (!empty($reprovisioned)
					? ' (display name, User Manager, device, and ' . count($reprovisioned) . ' phone(s))'
					: ' (display name, User Manager, device)')
				. '. Reload to finish applying.';
		}

		return $result;
	}

	/**
	 * EPM phones provisioned for this extension. endpoint_extensions maps each
	 * handset to "<ext>-<n>" (e.g. 24-1, 24-2). Returns [] when EPM isn't
	 * installed or the extension has no provisioned phones (softphone only) — in
	 * either case the table query fails or returns nothing and we skip silently.
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

	private function phoneModel($p) {
		return trim(($p['brand'] ?? '') . ' ' . ($p['model'] ?? ''));
	}

	private function phoneLabel($p) {
		$model = $this->phoneModel($p);
		return $p['ext'] . ($model !== '' ? " ({$model})" : '');
	}
}

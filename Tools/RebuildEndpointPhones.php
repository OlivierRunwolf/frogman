<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

/**
 * Rebuild Endpoint Manager provisioning configs and push the update to phones.
 *
 * Use after editing a template or extension in the EPM GUI: this regenerates
 * /tftpboot/<mac>.cfg from the current settings and (by default) NOTIFYs the
 * phone(s) to re-pull — so the change actually reaches the handset.
 *
 * Why this and not a template *editor*: EPM is IonCube-encoded with no public
 * template-save method, and writing its template columns directly does NOT
 * reliably flow into the generated config (verified: a column write produced no
 * cfg change). Rebuild/push, by contrast, is a first-class EPM CLI action that
 * works reliably. So template *field* edits stay in the GUI; the AI drives the
 * rebuild + push.
 *
 * push=true  -> `endpoint rebuildupdate <target>` : rebuild + tell phones to
 *               re-pull (most phones reboot).
 * push=false -> `endpoint rebuild <target>`       : regenerate config files only;
 *               phones pick them up on their next scheduled check.
 *
 * The dry-run reports the blast radius — every phone the action will rebuild
 * (and, with push, reboot) — because a template target hits every phone on it.
 */
class RebuildEndpointPhones extends AbstractTool {

	public function name() { return 'fm_rebuild_phones'; }

	public function description() {
		return 'Rebuild Endpoint Manager phone configs and push the update so phones re-pull — use after editing a template or extension in the EPM GUI so the change reaches the handsets. Params: target (required — a template name like "yealink_default", a single extension number like "24", or "all"), push (optional bool, default true: true = phones reboot/reprovision to pull the new config now; false = only regenerate config files on the server, phones pick them up on their next check). The dry-run lists every phone that will be rebuilt (and rebooted if push). Requires confirm:true.';
	}

	public function validate($params) {
		if (empty($params['target'])) return 'Parameter "target" is required (template name, extension number, or "all")';
		if (preg_match('/[\r\n\0;|&$`]/', (string)$params['target'])) return 'Parameter "target" contains disallowed characters';
		return true;
	}

	public function requiredPermission() { return 'write:endpoint'; }

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$target  = trim((string)$params['target']);
		// push defaults to true ("update phones"); accept explicit false.
		$push = array_key_exists('push', $params) ? ($params['push'] === true || $params['push'] === 'true' || $params['push'] === 1) : true;

		// Resolve what the target is, validate it exists, and compute blast radius.
		[$kind, $phones, $resolved] = $this->resolveTarget($target);
		if ($kind === null) {
			throw new \Exception("Target \"{$target}\" is not a known template, a mapped extension, or \"all\"");
		}

		$count = count($phones);
		$phoneList = array_map(function ($p) {
			$m = trim(($p['brand'] ?? '') . ' ' . ($p['model'] ?? ''));
			return $p['ext'] . ($m !== '' ? " ({$m})" : '');
		}, $phones);
		$radius = $count
			? "{$count} phone(s): " . implode(', ', $phoneList)
			: 'no phones are currently mapped to this target';

		$action = $push ? 'rebuild the config AND tell the phone(s) to reprovision (most will reboot)' : 'regenerate the config file(s) only (no reboot; phones pick it up on their next check)';

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would {$action} for {$kind} \"{$resolved}\" — {$radius}. Reply yes to confirm.",
				'target' => $resolved,
				'target_kind' => $kind,
				'push' => $push,
				'affected_phones' => $phones,
			];
		}

		$sub = $push ? 'rebuildupdate' : 'rebuild';
		$r = $this->runFwconsole(['endpoint', $sub, $resolved]);
		$ok = ($r['exit_code'] === 0);

		return [
			'dry_run' => false,
			'message' => ($ok
				? "Done — {$kind} \"{$resolved}\": configs rebuilt" . ($push ? " and {$count} phone(s) told to reprovision." : " ({$count} phone(s); not pushed — they update on next check).")
				: "Rebuild of {$kind} \"{$resolved}\" reported a non-zero exit — see output."),
			'target' => $resolved,
			'target_kind' => $kind,
			'push' => $push,
			'affected_phones' => $phones,
			'rebuild_ok' => $ok,
			'output' => substr($r['output'] ?? '', 0, 600),
		];
	}

	/**
	 * Classify the target and list the phones it covers.
	 * Returns [kind, phones[], resolvedTargetString] or [null, [], target].
	 */
	private function resolveTarget($target) {
		$db = $this->freepbx->Database();

		if (strcasecmp($target, 'all') === 0) {
			$rows = $this->q($db, "SELECT ext, mac, brand, model FROM endpoint_extensions");
			return ['all', $rows, 'all'];
		}

		// Template name?
		$t = $this->q($db, "SELECT template_name FROM endpoint_templates WHERE template_name = ?", [$target]);
		if (!empty($t)) {
			$rows = $this->q($db, "SELECT ext, mac, brand, model FROM endpoint_extensions WHERE template = ?", [$target]);
			return ['template', $rows, $target];
		}

		// Bare extension number? Match its EPM phone rows (ext stored as "<n>-<k>").
		if (preg_match('/^\d+$/', $target)) {
			$rows = $this->q($db, "SELECT ext, mac, brand, model FROM endpoint_extensions WHERE ext = ? OR ext LIKE ?", [$target, $target . '-%']);
			if (!empty($rows)) return ['extension', $rows, $target];
		}

		return [null, [], $target];
	}

	private function q($db, $sql, $args = []) {
		try {
			$sth = $db->prepare($sql);
			$sth->execute($args);
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
			return is_array($rows) ? $rows : [];
		} catch (\Throwable $e) {
			return [];
		}
	}
}

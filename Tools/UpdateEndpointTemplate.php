<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

/**
 * Edit an Endpoint Manager (EPM) provisioning template, then rebuild the config
 * files and push the change to every phone on that template.
 *
 * EPM is an IonCube-encoded commercial module with no public template-save BMO
 * method (saves go through the GUI form handler doConfigPageInit, which isn't
 * safe to drive headless). So we write the scalar template columns directly and
 * then regenerate + push via `fwconsole endpoint rebuildupdate <template>` —
 * which reads the template row and rebuilds /tftpboot/<mac>.cfg for each phone,
 * then NOTIFYs them to re-pull.
 *
 * SCALAR ALLOWLIST ONLY. Line keys / BLF / softkeys live in endpoint_buttons as
 * model-specific encoded key/value pairs, and codec lists aren't plain template
 * columns — writing those by hand would corrupt provisioning. Those are rejected
 * here and must be done in the EPM GUI.
 *
 * BLAST RADIUS: a template is shared by every phone mapped to it. The dry-run
 * lists those phones so the operator knows a confirm rebuilds and reboots/reprovisions
 * all of them — not just one extension.
 */
class UpdateEndpointTemplate extends AbstractTool {

	// Allowlisted scalar columns, grouped by the categories we expose.
	const ALLOWED = [
		// Time & locale
		'timezone' => 'IANA timezone string',
		'time_server' => 'use time server (1/0)',
		'time_server_1' => 'primary NTP server',
		'time_server_2' => 'secondary NTP server',
		'time_server_3' => 'tertiary NTP server',
		'toneset' => 'tone region',
		'country' => 'country code',
		'language' => 'web UI language',
		'lcdLanguage' => 'phone display language',
		'timeFormat' => 'time format',
		'dateFormat' => 'date format',
		'daylight' => 'daylight saving mode',
		// Display & sound
		'backlightActive' => 'active backlight level',
		'backlightInActive' => 'inactive backlight level',
		'backlightTimeout' => 'backlight timeout (s)',
		'brightness' => 'screen brightness',
		'contrast' => 'screen contrast',
		'dim_backlight' => 'dim backlight (1/0)',
		'backlight_dim_level' => 'dim level',
		'labelScroll' => 'scroll long labels (1/0)',
		'fontSize' => 'font size',
		'setringtone' => 'default ring tone',
		'keypressTone' => 'key press tone (1/0)',
		'voicemailTone' => 'voicemail tone (1/0)',
		'holdTone' => 'hold reminder tone (1/0)',
		'holdToneDelay' => 'hold tone delay (s)',
		'callwaiting' => 'call waiting tone',
		'volume' => 'default volume',
		'ssText' => 'screensaver text',
	];

	// Known blob / per-model columns and concepts we explicitly refuse, with a hint.
	const REFUSED = [
		'features' => 'line keys / softkeys', 'dialpattern' => 'dial plan',
		'ssSchedule' => 'screensaver schedule', 'ldapDialStringsConfig' => 'LDAP dial strings',
		'blf' => 'BLF buttons', 'linekey' => 'line keys', 'codec' => 'codecs', 'button' => 'buttons',
	];

	public function name() { return 'fm_update_endpoint_template'; }

	public function description() {
		return 'Edit an Endpoint Manager phone template, then rebuild configs and push the change to every phone on that template. Params: template (required — template name like "yealink_default" or its numeric id), fields (required — object of setting:value to change). Allowed settings: time/locale (timezone, time_server_1/2/3, toneset, country, language, lcdLanguage, timeFormat, dateFormat, daylight) and display/sound (backlightTimeout, brightness, contrast, labelScroll, setringtone, keypressTone, voicemailTone, holdTone, callwaiting, volume, ssText). Line keys / BLF / codecs are NOT supported here (encoded per-model config — use the EPM GUI). The dry-run lists every phone that will be rebuilt + rebooted. Requires confirm:true.';
	}

	public function validate($params) {
		if (empty($params['template'])) return 'Parameter "template" is required (template name or id)';
		if (empty($params['fields']) || !is_array($params['fields'])) {
			return 'Parameter "fields" is required (object of setting:value pairs to change)';
		}
		foreach ($params['fields'] as $k => $v) {
			if (!array_key_exists($k, self::ALLOWED)) {
				// Give a targeted hint when they're reaching for a refused concept.
				$lk = strtolower($k);
				foreach (self::REFUSED as $needle => $label) {
					if (strpos($lk, $needle) !== false) {
						return "Setting \"{$k}\" ({$label}) can't be edited through this tool — it's an encoded per-model config. Use the Endpoint Manager GUI for line keys / BLF / codecs.";
					}
				}
				return "Setting \"{$k}\" is not an allowed template field. Allowed: " . implode(', ', array_keys(self::ALLOWED));
			}
			if (is_array($v)) return "Setting \"{$k}\" must be a scalar value, not an object/array";
			if (preg_match('/[\r\n\0]/', (string)$v)) return "Setting \"{$k}\" contains disallowed control characters";
		}
		return true;
	}

	public function requiredPermission() { return 'write:endpoint'; }

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$fields = $params['fields'];

		// Resolve the template by id or name. Read-only; endpoint_templates is the row
		// `fwconsole endpoint rebuild` itself reads, so this is the canonical source.
		$db = $this->freepbx->Database();
		if (preg_match('/^\d+$/', (string)$params['template'])) {
			$sth = $db->prepare("SELECT * FROM endpoint_templates WHERE id = ?");
			$sth->execute([(int)$params['template']]);
		} else {
			$sth = $db->prepare("SELECT * FROM endpoint_templates WHERE template_name = ?");
			$sth->execute([(string)$params['template']]);
		}
		$tpl = $sth->fetch(\PDO::FETCH_ASSOC);
		if (empty($tpl)) throw new \Exception("Endpoint template \"{$params['template']}\" not found");

		$tplName = $tpl['template_name'];
		$tplId   = (int)$tpl['id'];

		// Compute the actual changes (skip no-ops).
		$changes = [];
		foreach ($fields as $k => $v) {
			$cur = (string)($tpl[$k] ?? '');
			if ($cur !== (string)$v) $changes[$k] = ['from' => $cur, 'to' => (string)$v];
		}
		if (empty($changes)) {
			return ['dry_run' => false, 'message' => "No changes — template \"{$tplName}\" already has those values."];
		}

		// Blast radius: every phone mapped to this template.
		$phones = $this->phonesOnTemplate($tplName);

		if (!$confirm) {
			$phoneList = array_map(function ($p) { return $p['ext'] . ' (' . trim(($p['brand'] ?? '') . ' ' . ($p['model'] ?? '')) . ')'; }, $phones);
			$radius = empty($phones)
				? 'No phones are currently mapped to this template.'
				: count($phones) . ' phone(s) will be rebuilt and told to reboot/reprovision: ' . implode(', ', $phoneList) . '.';
			return [
				'dry_run' => true,
				'message' => "Would update template \"{$tplName}\" (id {$tplId}) and rebuild+push. {$radius} Reply yes to confirm.",
				'template' => $tplName,
				'changes' => $changes,
				'affected_phones' => $phones,
			];
		}

		// Write only the allowlisted, changed scalar columns. Column names are
		// allowlist keys (never caller-controlled identifiers), values bound.
		$sets = [];
		$vals = [];
		foreach ($changes as $k => $c) { $sets[] = "`{$k}` = ?"; $vals[] = $c['to']; }
		$vals[] = $tplId;
		$upd = $db->prepare("UPDATE endpoint_templates SET " . implode(', ', $sets) . " WHERE id = ?");
		$upd->execute($vals);

		// Regenerate /tftpboot/<mac>.cfg from the new row AND notify phones to re-pull.
		$r = $this->runFwconsole(['endpoint', 'rebuildupdate', $tplName]);
		$ok = ($r['exit_code'] === 0);

		return [
			'dry_run' => false,
			'message' => "Template \"{$tplName}\" updated (" . count($changes) . " setting(s)); configs rebuilt and "
				. count($phones) . " phone(s) told to reprovision" . ($ok ? '.' : ' — but the rebuild reported a non-zero exit, check below.'),
			'template' => $tplName,
			'changes' => $changes,
			'affected_phones' => $phones,
			'rebuild_ok' => $ok,
			'rebuild_output' => $ok ? null : substr($r['output'] ?? '', 0, 500),
			'needs_reload' => false,
		];
	}

	/** Phones mapped to a template. endpoint_extensions.template holds the name. */
	private function phonesOnTemplate($tplName) {
		try {
			$db = $this->freepbx->Database();
			$sth = $db->prepare("SELECT ext, mac, brand, model FROM endpoint_extensions WHERE template = ?");
			$sth->execute([$tplName]);
			$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
			return is_array($rows) ? $rows : [];
		} catch (\Throwable $e) {
			return [];
		}
	}
}

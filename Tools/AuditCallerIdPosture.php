<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

class AuditCallerIdPosture extends AbstractTool {

	public function name() {
		return 'fm_audit_caller_id_posture';
	}

	public function description() {
		return 'Audit outbound Caller ID posture across trunks, outbound routes, and extensions: placeholder/garbage CID strings, extension overrides that don\'t match any owned DID, trunks with no outbound CID, malformed Caller ID numbers, and routes that force-override extension CID. Toll-fraud / STIR-SHAKEN reachability check — read-only.';
	}

	public function validate($params) {
		return true;
	}

	public function requiredPermission() {
		return null;
	}

	// Outbound CID posture exposes operationally sensitive context (which
	// trunks/routes/extensions emit which numbers). Same admin-only tier as
	// the rest of the audit family.
	public function permissionLevel() { return self::PERM_ADMIN; }

	public function execute($params, $context) {
		$findings = [];
		$counts = ['high' => 0, 'medium' => 0, 'info' => 0];

		$ownedDids = $this->buildOwnedDidSet();

		// Trunks
		$trunks = $this->freepbx->Core->listTrunks();
		foreach ($trunks as $t) {
			if (($t['disabled'] ?? 'off') === 'on') continue;
			$id = $t['trunkid'] ?? null;
			if ($id === null) continue;

			try {
				$details = $this->freepbx->Core->getTrunkDetails($id);
			} catch (\Throwable $e) {
				continue;
			}
			$cid = (string)($details['outcid'] ?? '');

			foreach ($this->classifyCid($cid, 'trunk', $ownedDids) as $issue) {
				$findings[] = [
					'ref_type' => 'trunk',
					'ref_id' => (string)$id,
					'ref_name' => (string)($t['name'] ?? ''),
					'cid_value' => $cid,
					'severity' => $issue['severity'],
					'issue' => $issue['issue'],
					'recommendation' => $issue['recommendation'],
				];
				$counts[$issue['severity']]++;
			}
		}

		// Outbound routes
		$routes = $this->freepbx->Core->getAllRoutes();
		foreach ($routes as $r) {
			if (($r['disabled'] ?? 'off') === 'on') continue;
			$rid = $r['route_id'] ?? $r['routeid'] ?? null;
			if ($rid === null) continue;
			$name = (string)($r['name'] ?? '');
			$cid = (string)($r['outcid'] ?? '');
			$mode = (string)($r['outcid_mode'] ?? '');

			foreach ($this->classifyCid($cid, 'route', $ownedDids) as $issue) {
				$findings[] = [
					'ref_type' => 'route',
					'ref_id' => (string)$rid,
					'ref_name' => $name,
					'cid_value' => $cid,
					'severity' => $issue['severity'],
					'issue' => $issue['issue'],
					'recommendation' => $issue['recommendation'],
				];
				$counts[$issue['severity']]++;
			}

			// Route has no CID AND every trunk it uses also has no CID →
			// calls on this route leave the system blank. Sibling signal to
			// the per-trunk findings (single trunk feeding N routes wants the
			// route-level callout too).
			if ($cid === '' && $this->routeTrunksAllBlank($rid)) {
				$findings[] = [
					'ref_type' => 'route',
					'ref_id' => (string)$rid,
					'ref_name' => $name,
					'cid_value' => '',
					'severity' => 'medium',
					'issue' => 'Route has no Caller ID and none of its trunks set one either — calls on this route leave blank',
					'recommendation' => 'Set an Outbound CID on the route, or on at least one trunk in its trunk priority.',
				];
				$counts['medium']++;
			}

			// Route force-overrides extension CID. Surfaces precedence so
			// admins know extension-level Caller IDs are ignored here.
			if ($mode === 'on' && $cid !== '') {
				$findings[] = [
					'ref_type' => 'route',
					'ref_id' => (string)$rid,
					'ref_name' => $name,
					'cid_value' => $cid,
					'severity' => 'info',
					'issue' => 'Route force-overrides extension Caller ID (outcid_mode=on)',
					'recommendation' => 'Confirm this is intended — extension-level Caller IDs are ignored on this route.',
				];
				$counts['info']++;
			}
		}

		// Extensions
		$devices = $this->freepbx->Core->getAllDevicesByType('');
		foreach ($devices as $dev) {
			$ext = (string)($dev['id'] ?? '');
			if ($ext === '') continue;
			$full = $this->freepbx->Core->getUser($ext);
			if (empty($full)) continue;
			$cid = (string)($full['outboundcid'] ?? '');
			if ($cid === '') continue; // empty = inherit; not a finding

			foreach ($this->classifyCid($cid, 'extension', $ownedDids) as $issue) {
				$findings[] = [
					'ref_type' => 'extension',
					'ref_id' => $ext,
					'ref_name' => (string)($dev['description'] ?? ''),
					'cid_value' => $cid,
					'severity' => $issue['severity'],
					'issue' => $issue['issue'],
					'recommendation' => $issue['recommendation'],
				];
				$counts[$issue['severity']]++;
			}
		}

		$order = ['high' => 0, 'medium' => 1, 'info' => 2];
		usort($findings, function ($a, $b) use ($order) {
			$sevDiff = $order[$a['severity']] - $order[$b['severity']];
			if ($sevDiff !== 0) return $sevDiff;
			$typeDiff = strcmp($a['ref_type'], $b['ref_type']);
			if ($typeDiff !== 0) return $typeDiff;
			return strnatcmp($a['ref_id'], $b['ref_id']);
		});

		return [
			'count' => count($findings),
			'severity_counts' => $counts,
			'findings' => $findings,
			'summary' => $this->summary($findings, $counts),
		];
	}

	/**
	 * Classify a CID string into 0+ issues. $where is 'trunk', 'route', or
	 * 'extension' and affects severity wording and empty-string treatment.
	 */
	private function classifyCid($cid, $where, $ownedDids) {
		$issues = [];
		$number = $this->extractCidNumber($cid);
		$normalized = $this->normalizeNumber($number);

		if ($cid === '' || $number === '') {
			// Trunk with no CID is a finding (calls leave blank).
			// Route/extension with empty CID = inherit from upstream; not a finding here.
			if ($where === 'trunk') {
				return [[
					'severity' => 'medium',
					'issue' => 'Trunk has no outbound Caller ID set — calls may leave without a CID',
					'recommendation' => 'Set the trunk Outbound CID to a DID you own, in E.164 (e.g. +15551234567).',
				]];
			}
			return [];
		}

		// Placeholder/garbage strings
		$placeholders = [
			'default', 'freepbx', 'asterisk', 'sangoma',
			'unknown', 'anonymous', 'none', 'changeme', 'test',
			'1234567890', '5555555555', '0000000000', '0123456789', '1111111111',
		];
		$lowered = strtolower(trim($cid));
		$loweredNum = strtolower($normalized);
		if (in_array($lowered, $placeholders, true) || in_array($loweredNum, $placeholders, true)) {
			return [[
				'severity' => 'high',
				'issue' => ucfirst($where) . ' Caller ID is a placeholder/test string',
				'recommendation' => 'Set the ' . $where . ' Caller ID to a DID you own, in E.164.',
			]];
		}

		// Malformed: not a 7-15 digit number after normalization
		if (!$this->looksLikePhoneNumber($normalized)) {
			return [[
				'severity' => 'medium',
				'issue' => ucfirst($where) . ' Caller ID number is malformed (not a 7–15 digit phone number)',
				'recommendation' => 'Correct the Caller ID to a real DID in E.164 format.',
			]];
		}

		// Extension override that doesn't match any owned DID = spoof risk.
		// Only meaningful if we actually have DIDs to compare against; on a
		// fresh / DID-less PBX the unmatched check would false-positive every
		// extension override.
		if ($where === 'extension' && !empty($ownedDids) && !isset($ownedDids[$normalized])) {
			$issues[] = [
				'severity' => 'high',
				'issue' => 'Extension Caller ID number is not a DID owned by this PBX (potential spoof / STIR-SHAKEN attestation drop)',
				'recommendation' => 'Change to a DID configured on this PBX, or clear the override to inherit from the route/trunk.',
			];
		}

		return $issues;
	}

	/**
	 * Return true if every trunk in this route's trunk-priority list has a
	 * blank outcid (or the list is empty). Used to flag routes whose calls
	 * would leave the system with no CID at all.
	 */
	private function routeTrunksAllBlank($routeId) {
		try {
			$trunkIds = $this->freepbx->Core->getRouteTrunksByID($routeId);
		} catch (\Throwable $e) {
			return false;
		}
		if (empty($trunkIds) || !is_array($trunkIds)) return false;
		foreach ($trunkIds as $tid) {
			try {
				$d = $this->freepbx->Core->getTrunkDetails($tid);
			} catch (\Throwable $e) {
				continue;
			}
			$tcid = (string)($d['outcid'] ?? '');
			if ($tcid !== '') return false;
		}
		return true;
	}

	/**
	 * Extract the number portion from `"Name" <num>` or just `num`.
	 */
	private function extractCidNumber($cid) {
		if ($cid === '') return '';
		if (preg_match('/<([^>]+)>/', $cid, $m)) {
			return trim($m[1]);
		}
		return trim($cid);
	}

	/**
	 * Strip non-digits and trim a leading NANP 1 so `+1 555 123 4567` and
	 * `5551234567` compare equal against the owned-DID set.
	 */
	private function normalizeNumber($num) {
		$digits = preg_replace('/\D+/', '', (string)$num);
		if ($digits === null || $digits === '') return '';
		if (strlen($digits) === 11 && $digits[0] === '1') {
			$digits = substr($digits, 1);
		}
		return $digits;
	}

	private function looksLikePhoneNumber($normalized) {
		if ($normalized === '') return false;
		$len = strlen($normalized);
		return $len >= 7 && $len <= 15;
	}

	private function buildOwnedDidSet() {
		$set = [];
		$dids = $this->freepbx->Core->getAllDIDs();
		if (!is_array($dids)) return $set;
		foreach ($dids as $d) {
			$ext = (string)($d['extension'] ?? '');
			// FreePBX uses '_.' as the any-DID catchall and 's' as the
			// no-CID-supplied marker. Neither is an owned number.
			if ($ext === '' || $ext === '_.' || $ext === 's') continue;
			$norm = $this->normalizeNumber($ext);
			if ($norm === '') continue;
			$set[$norm] = true;
		}
		return $set;
	}

	private function summary($findings, $counts) {
		if (count($findings) === 0) {
			return 'Caller ID posture clean: no issues across trunks, routes, or extensions.';
		}
		$parts = [];
		foreach (['high', 'medium', 'info'] as $sev) {
			if ($counts[$sev] > 0) $parts[] = "{$counts[$sev]} {$sev}";
		}
		return count($findings) . ' Caller ID issue(s) found: ' . implode(', ', $parts) . '.';
	}
}

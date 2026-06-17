<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

/**
 * Delete a time group (schedule).
 *
 * A time group is the schedule one or more time conditions evaluate against.
 * Deleting one that's still referenced leaves those conditions pointing at a
 * missing group — they then always take the unmatched branch, silently breaking
 * call routing. So we pre-scan time conditions for references and refuse unless
 * force:true is also set.
 *
 * Counterpart to fm_add_timegroup / fm_edit_time_group, which had no delete.
 */
class DeleteTimegroup extends AbstractTool {

	public function name() { return 'fm_delete_timegroup'; }

	public function description() {
		return 'Delete a time group (schedule). Params: id (required, numeric). Refuses if any time condition still references the group (deleting it would break that condition\'s routing) unless force:true is also passed. Requires confirm:true.';
	}

	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		if (!preg_match('/^\d+$/', (string)$params['id'])) return 'Parameter "id" must be numeric';
		return true;
	}

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$id = (string)$params['id'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;
		$force = !empty($params['force']) && $params['force'] === true;

		// Read the group directly: framework getTimeGroup() does $results[0] on a
		// false fetch when the id is missing, so it fatals instead of reporting
		// "not found". A plain read gives us the name and a clean existence check.
		$db = $this->freepbx->Database();
		$sth = $db->prepare("SELECT description FROM timegroups_groups WHERE id = ?");
		$sth->execute([$id]);
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if (empty($row)) throw new \Exception("Time group {$id} not found");
		$name = !empty($row['description']) ? $row['description'] : $id;

		$refs = $this->referencingTimeConditions($id);

		// Hard stop: referenced and not forced. Returned as a dry-run (blocked) so
		// the caller never silently breaks routing.
		if (!empty($refs) && !$force) {
			$names = array_map(function ($t) { return "\"{$t['displayname']}\" (TC {$t['id']})"; }, $refs);
			return [
				'dry_run' => true,
				'blocked' => true,
				'message' => "Time group \"{$name}\" (ID: {$id}) is still used by " . count($refs)
					. " time condition(s): " . implode(', ', $names)
					. ". Deleting it would break their routing. Re-point or delete those conditions first, or pass force:true to delete anyway.",
				'referenced_by' => $refs,
			];
		}

		if (!$confirm) {
			$warn = !empty($refs)
				? " WARNING: force delete — " . count($refs) . " time condition(s) reference this group and will break."
				: '';
			return [
				'dry_run' => true,
				'message' => "Would delete time group \"{$name}\" (ID: {$id}).{$warn} Reply yes to confirm.",
				'referenced_by' => $refs,
			];
		}

		$ok = $this->freepbx->Timeconditions->delTimeGroup($id);
		if ($ok === false) throw new \Exception("Failed to delete time group {$id}");

		return [
			'dry_run' => false,
			'message' => "Time group \"{$name}\" (ID: {$id}) deleted."
				. (!empty($refs) ? ' ' . count($refs) . ' referencing time condition(s) now point at a missing group — fix them.' : ''),
			'needs_reload' => true,
		];
	}

	/**
	 * Time conditions that evaluate against this group. timeconditions.time holds
	 * the time group id. Read-only cross-module query — no BMO method exposes
	 * "conditions using group X" directly.
	 */
	private function referencingTimeConditions($tgid) {
		$out = [];
		try {
			$db = $this->freepbx->Database();
			$sth = $db->prepare("SELECT timeconditions_id, displayname FROM timeconditions WHERE time = ?");
			$sth->execute([$tgid]);
			foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $r) {
				$out[] = ['id' => (int)$r['timeconditions_id'], 'displayname' => $r['displayname'] ?? ''];
			}
		} catch (\Throwable $e) {}
		return $out;
	}
}

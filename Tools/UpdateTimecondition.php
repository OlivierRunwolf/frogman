<?php
namespace FreePBX\modules\Frogman\Tools;

require_once __DIR__ . '/AbstractTool.php';

/**
 * Update an existing time condition in place.
 *
 * The toggle tool (fm_toggle_time_condition) only flips override state; this
 * edits the actual fields — display name, the time group it evaluates, and the
 * matched / unmatched destinations. Any field left out keeps its current value
 * (read back from getTimeCondition).
 *
 * editTimeCondition() reads destinations through FreePBX's destination-widget
 * convention: it pulls the matched dest from $post[$post['goto0'] . '0']. So we
 * set goto0='dest' and dest0=<truegoto> (and goto1='dest', dest1=<falsegoto>).
 */
class UpdateTimecondition extends AbstractTool {

	public function name() { return 'fm_update_time_condition'; }

	public function description() {
		return 'Update an existing time condition in place. Params: id (required, numeric). Optional, any subset (unspecified keep their current value): name (display name), timegroup (time group ID it evaluates against), truegoto (destination when time matches), falsegoto (destination when it does not). Requires confirm:true.';
	}

	public function validate($params) {
		if (empty($params['id'])) return 'Parameter "id" is required';
		if (!preg_match('/^\d+$/', (string)$params['id'])) return 'Parameter "id" must be numeric';
		if (isset($params['timegroup']) && $params['timegroup'] !== '' && !preg_match('/^\d+$/', (string)$params['timegroup'])) {
			return 'Parameter "timegroup" must be a numeric time group ID';
		}
		return true;
	}

	public function permissionLevel() { return self::PERM_WRITE; }

	public function execute($params, $context) {
		$id = (string)$params['id'];
		$confirm = !empty($params['confirm']) && $params['confirm'] === true;

		$tc = $this->freepbx->Timeconditions->getTimeCondition($id);
		if (empty($tc)) throw new \Exception("Time condition {$id} not found");

		// Start from current values; overlay only what was passed.
		$newName  = array_key_exists('name', $params)      ? $params['name']      : ($tc['displayname'] ?? '');
		$newTime  = (array_key_exists('timegroup', $params) && $params['timegroup'] !== '') ? $params['timegroup'] : ($tc['time'] ?? 0);
		$newTrue  = array_key_exists('truegoto', $params)  ? $params['truegoto']  : ($tc['truegoto'] ?? '');
		$newFalse = array_key_exists('falsegoto', $params) ? $params['falsegoto'] : ($tc['falsegoto'] ?? '');

		$changes = [];
		if (($tc['displayname'] ?? '') !== $newName)            $changes['name']      = ['from' => $tc['displayname'] ?? '', 'to' => $newName];
		if ((string)($tc['time'] ?? '') !== (string)$newTime)  $changes['timegroup'] = ['from' => $tc['time'] ?? '', 'to' => $newTime];
		if (($tc['truegoto'] ?? '') !== $newTrue)              $changes['truegoto']  = ['from' => $tc['truegoto'] ?? '', 'to' => $newTrue];
		if (($tc['falsegoto'] ?? '') !== $newFalse)            $changes['falsegoto'] = ['from' => $tc['falsegoto'] ?? '', 'to' => $newFalse];

		if (empty($changes)) {
			return ['dry_run' => false, 'message' => "No changes detected for time condition {$id}."];
		}

		if (!$confirm) {
			return [
				'dry_run' => true,
				'message' => "Would update time condition \"" . ($tc['displayname'] ?? $id) . "\" (ID: {$id}). Reply yes to confirm.",
				'changes' => $changes,
			];
		}

		// Rebuild the full $post editTimeCondition expects, preserving every field
		// we didn't change (mode/calendar/invert/timezone) so the edit is surgical.
		$post = [
			'displayname'    => $newName,
			'time'           => $newTime,
			'timezone'       => $tc['timezone'] ?? '',
			'goto0'          => 'dest', 'dest0' => $newTrue,
			'goto1'          => 'dest', 'dest1' => $newFalse,
			'invert_hint'    => (string)($tc['invert_hint'] ?? '0'),
			'fcc_password'   => $tc['fcc_password'] ?? '',
			'deptname'       => $tc['deptname'] ?? '',
			'mode'           => $tc['mode'] ?? 'time-group',
			'calendar-id'    => $tc['calendar_id'] ?? '',
			'calendar-group' => $tc['calendar_group_id'] ?? '',
			'tcstate_new'    => 'unchanged',
		];

		$this->freepbx->Timeconditions->editTimeCondition($id, $post);

		return [
			'dry_run' => false,
			'message' => "Time condition \"{$newName}\" (ID: {$id}) updated.",
			'changes' => $changes,
			'needs_reload' => true,
		];
	}
}

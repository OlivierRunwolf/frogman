<?php

namespace FreePBX\modules\frogman\Api\Gql;

use GraphQLRelay\Relay;
use GraphQL\Type\Definition\Type;
use FreePBX\modules\Api\Gql\Base;

class Frogman extends Base {
	protected $module = 'frogman';
	protected $description = 'Frogman chat-driven PBX control — audit log, saved queries, sessions, and aliases';

	public static function getScopes() {
		return [
			'read:frogman' => [
				'description' => 'Read Frogman data (audit log, sessions, saved queries, aliases)',
			],
			'write:frogman' => [
				'description' => 'Write Frogman data (manage saved queries and aliases)',
			],
		];
	}

	public function initializeTypes() {
		// ── Audit Log Entry type ──
		$auditLog = $this->typeContainer->create('frogman_audit');
		$auditLog->setDescription('An Frogman audit log entry');
		$auditLog->addInterfaceCallback(fn() => [$this->getNodeDefinition()['nodeInterface']]);
		$auditLog->setGetNodeCallback(function($id) {
			$db = $this->freepbx->Database;
			$sth = $db->prepare("SELECT * FROM oc_audit_log WHERE id = ?");
			$sth->execute([$id]);
			return $sth->fetch(\PDO::FETCH_ASSOC) ?: null;
		});
		$auditLog->addFieldCallback(fn() => [
			'id' => Relay::globalIdField('frogman_audit', fn($row) => $row['id']),
			'audit_id' => [
				'type' => Type::int(),
				'description' => 'Audit log entry ID',
				'resolve' => fn($row) => (int) $row['id'],
			],
			'tool' => [
				'type' => Type::string(),
				'description' => 'Tool name that was executed',
			],
			'params' => [
				'type' => Type::string(),
				'description' => 'JSON-encoded parameters',
			],
			'user_id' => [
				'type' => Type::int(),
				'description' => 'User who executed the tool',
			],
			'session_id' => [
				'type' => Type::string(),
				'description' => 'Session ID',
			],
			'intent' => [
				'type' => Type::string(),
				'description' => 'Intent description',
			],
			'status' => [
				'type' => Type::string(),
				'description' => 'Execution status: pending, success, error',
			],
			'detail' => [
				'type' => Type::string(),
				'description' => 'Execution result or error detail',
			],
			'created_at' => [
				'type' => Type::int(),
				'description' => 'Unix timestamp of when the tool was invoked',
			],
			'completed_at' => [
				'type' => Type::int(),
				'description' => 'Unix timestamp of when execution completed',
			],
		]);
		$auditLog->setConnectionResolveNode(fn($edge) => $edge['node']);
		$auditLog->setConnectionFields(fn() => [
			'totalCount' => [
				'type' => Type::int(),
				'resolve' => function($value) {
					$db = $this->freepbx->Database;
					$sth = $db->query("SELECT COUNT(*) FROM oc_audit_log");
					return (int) $sth->fetchColumn();
				},
			],
		]);

		// ── Saved Query type ──
		$savedQuery = $this->typeContainer->create('frogman_saved_query');
		$savedQuery->setDescription('An Frogman saved GraphQL query');
		$savedQuery->addInterfaceCallback(fn() => [$this->getNodeDefinition()['nodeInterface']]);
		$savedQuery->setGetNodeCallback(function($id) {
			$db = $this->freepbx->Database;
			$sth = $db->prepare("SELECT * FROM oc_saved_queries WHERE id = ?");
			$sth->execute([$id]);
			return $sth->fetch(\PDO::FETCH_ASSOC) ?: null;
		});
		$savedQuery->addFieldCallback(fn() => [
			'id' => Relay::globalIdField('frogman_saved_query', fn($row) => $row['id']),
			'saved_query_id' => [
				'type' => Type::int(),
				'description' => 'Saved query ID',
				'resolve' => fn($row) => (int) $row['id'],
			],
			'name' => [
				'type' => Type::string(),
				'description' => 'Query name',
			],
			'query' => [
				'type' => Type::string(),
				'description' => 'GraphQL query string',
			],
			'param_spec' => [
				'type' => Type::string(),
				'description' => 'JSON-encoded parameter specification',
			],
			'created_by' => [
				'type' => Type::int(),
				'description' => 'User ID who created this query',
			],
			'created_at' => [
				'type' => Type::int(),
				'description' => 'Unix timestamp',
			],
		]);
		$savedQuery->setConnectionResolveNode(fn($edge) => $edge['node']);
		$savedQuery->setConnectionFields(fn() => [
			'totalCount' => [
				'type' => Type::int(),
				'resolve' => function($value) {
					$db = $this->freepbx->Database;
					$sth = $db->query("SELECT COUNT(*) FROM oc_saved_queries");
					return (int) $sth->fetchColumn();
				},
			],
		]);

		// ── Session type ──
		$session = $this->typeContainer->create('frogman_session');
		$session->setDescription('An Frogman chat session');
		$session->addInterfaceCallback(fn() => [$this->getNodeDefinition()['nodeInterface']]);
		$session->setGetNodeCallback(function($id) {
			$db = $this->freepbx->Database;
			$sth = $db->prepare("SELECT * FROM oc_sessions WHERE id = ?");
			$sth->execute([$id]);
			return $sth->fetch(\PDO::FETCH_ASSOC) ?: null;
		});
		$session->addFieldCallback(fn() => [
			'id' => Relay::globalIdField('frogman_session', fn($row) => $row['id']),
			'session_id' => [
				'type' => Type::string(),
				'description' => 'Session identifier',
				'resolve' => fn($row) => $row['id'],
			],
			'user_id' => [
				'type' => Type::int(),
				'description' => 'User ID',
			],
			'started_at' => [
				'type' => Type::int(),
				'description' => 'Unix timestamp of session start',
			],
			'last_activity' => [
				'type' => Type::int(),
				'description' => 'Unix timestamp of last activity',
			],
			'context' => [
				'type' => Type::string(),
				'description' => 'Session context data (JSON)',
			],
			'status' => [
				'type' => Type::string(),
				'description' => 'Session status: active, closed',
			],
		]);
		$session->setConnectionResolveNode(fn($edge) => $edge['node']);
		$session->setConnectionFields(fn() => [
			'totalCount' => [
				'type' => Type::int(),
				'resolve' => function($value) {
					$db = $this->freepbx->Database;
					$sth = $db->query("SELECT COUNT(*) FROM oc_sessions");
					return (int) $sth->fetchColumn();
				},
			],
		]);

		// ── Alias type ──
		$alias = $this->typeContainer->create('frogman_alias');
		$alias->setDescription('An Frogman command alias');
		$alias->addInterfaceCallback(fn() => [$this->getNodeDefinition()['nodeInterface']]);
		$alias->setGetNodeCallback(function($id) {
			$db = $this->freepbx->Database;
			$sth = $db->prepare("SELECT * FROM oc_aliases WHERE id = ?");
			$sth->execute([$id]);
			return $sth->fetch(\PDO::FETCH_ASSOC) ?: null;
		});
		$alias->addFieldCallback(fn() => [
			'id' => Relay::globalIdField('frogman_alias', fn($row) => $row['id']),
			'alias_id' => [
				'type' => Type::int(),
				'description' => 'Alias ID',
				'resolve' => fn($row) => (int) $row['id'],
			],
			'alias' => [
				'type' => Type::string(),
				'description' => 'Alias name',
			],
			'tool' => [
				'type' => Type::string(),
				'description' => 'Target tool name',
			],
			'default_params' => [
				'type' => Type::string(),
				'description' => 'JSON-encoded default parameters',
			],
			'created_by' => [
				'type' => Type::int(),
				'description' => 'User ID who created this alias',
			],
		]);
		$alias->setConnectionResolveNode(fn($edge) => $edge['node']);
		$alias->setConnectionFields(fn() => [
			'totalCount' => [
				'type' => Type::int(),
				'resolve' => function($value) {
					$db = $this->freepbx->Database;
					$sth = $db->query("SELECT COUNT(*) FROM oc_aliases");
					return (int) $sth->fetchColumn();
				},
			],
		]);
	}

	public function queryCallback() {
		if ($this->checkAllReadScope()) {
			return fn() => [
				'frogmanAuditLog' => [
					'type' => $this->typeContainer->get('frogman_audit')->getConnectionType(),
					'description' => 'Query the Frogman audit log',
					'args' => array_merge(Relay::connectionArgs(), [
						'tool' => [
							'type' => Type::string(),
							'description' => 'Filter by tool name',
						],
						'status' => [
							'type' => Type::string(),
							'description' => 'Filter by status: pending, success, error',
						],
					]),
					'resolve' => function($root, $args) {
						$db = $this->freepbx->Database;
						$conditions = [];
						$binds = [];
						if (!empty($args['tool'])) {
							$conditions[] = "tool = ?";
							$binds[] = $args['tool'];
						}
						if (!empty($args['status'])) {
							$conditions[] = "status = ?";
							$binds[] = $args['status'];
						}
						$where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';
						$sth = $db->prepare("SELECT * FROM oc_audit_log {$where} ORDER BY created_at DESC LIMIT 100");
						$sth->execute($binds);
						$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
						return Relay::connectionFromArray($rows, $args);
					},
				],
				'frogmanSavedQueries' => [
					'type' => $this->typeContainer->get('frogman_saved_query')->getConnectionType(),
					'description' => 'List saved queries',
					'args' => Relay::connectionArgs(),
					'resolve' => function($root, $args) {
						$db = $this->freepbx->Database;
						$sth = $db->query("SELECT * FROM oc_saved_queries ORDER BY name");
						return Relay::connectionFromArray($sth->fetchAll(\PDO::FETCH_ASSOC), $args);
					},
				],
				'frogmanSessions' => [
					'type' => $this->typeContainer->get('frogman_session')->getConnectionType(),
					'description' => 'List Frogman sessions',
					'args' => array_merge(Relay::connectionArgs(), [
						'status' => [
							'type' => Type::string(),
							'description' => 'Filter by status: active, closed',
						],
					]),
					'resolve' => function($root, $args) {
						$db = $this->freepbx->Database;
						$sql = "SELECT * FROM oc_sessions";
						$binds = [];
						if (!empty($args['status'])) {
							$sql .= " WHERE status = ?";
							$binds[] = $args['status'];
						}
						$sql .= " ORDER BY last_activity DESC";
						$sth = $db->prepare($sql);
						$sth->execute($binds);
						return Relay::connectionFromArray($sth->fetchAll(\PDO::FETCH_ASSOC), $args);
					},
				],
				'frogmanAliases' => [
					'type' => $this->typeContainer->get('frogman_alias')->getConnectionType(),
					'description' => 'List command aliases',
					'args' => Relay::connectionArgs(),
					'resolve' => function($root, $args) {
						$db = $this->freepbx->Database;
						$sth = $db->query("SELECT * FROM oc_aliases ORDER BY alias");
						return Relay::connectionFromArray($sth->fetchAll(\PDO::FETCH_ASSOC), $args);
					},
				],
			];
		}
	}

	public function mutationCallback() {
		if ($this->checkAllWriteScope()) {
			return fn() => [
				'addFrogmanAlias' => Relay::mutationWithClientMutationId([
					'name' => 'addFrogmanAlias',
					'description' => 'Create a new command alias',
					'inputFields' => [
						'alias' => [
							'type' => Type::nonNull(Type::string()),
							'description' => 'Alias name',
						],
						'tool' => [
							'type' => Type::nonNull(Type::string()),
							'description' => 'Target tool name',
						],
						'default_params' => [
							'type' => Type::string(),
							'description' => 'JSON-encoded default parameters',
						],
					],
					'outputFields' => [
						'alias' => [
							'type' => $this->typeContainer->get('frogman_alias')->getObject(),
							'resolve' => fn($payload) => $payload,
						],
					],
					'mutateAndGetPayload' => function($input) {
						$db = $this->freepbx->Database;
						$sth = $db->prepare("INSERT INTO oc_aliases (alias, tool, default_params, created_by) VALUES (?, ?, ?, ?)");
						$sth->execute([
							$input['alias'],
							$input['tool'],
							$input['default_params'] ?? '{}',
							0,
						]);
						$id = $db->lastInsertId();
						$sth = $db->prepare("SELECT * FROM oc_aliases WHERE id = ?");
						$sth->execute([$id]);
						return $sth->fetch(\PDO::FETCH_ASSOC);
					},
				]),
				'removeFrogmanAlias' => Relay::mutationWithClientMutationId([
					'name' => 'removeFrogmanAlias',
					'description' => 'Delete a command alias',
					'inputFields' => [
						'alias' => [
							'type' => Type::nonNull(Type::string()),
							'description' => 'Alias name to delete',
						],
					],
					'outputFields' => [
						'deletedAlias' => [
							'type' => Type::string(),
							'resolve' => fn($payload) => $payload['alias'],
						],
					],
					'mutateAndGetPayload' => function($input) {
						$db = $this->freepbx->Database;
						$sth = $db->prepare("DELETE FROM oc_aliases WHERE alias = ?");
						$sth->execute([$input['alias']]);
						return ['alias' => $input['alias']];
					},
				]),
				'addFrogmanSavedQuery' => Relay::mutationWithClientMutationId([
					'name' => 'addFrogmanSavedQuery',
					'description' => 'Save a named GraphQL query',
					'inputFields' => [
						'name' => [
							'type' => Type::nonNull(Type::string()),
							'description' => 'Query name',
						],
						'query' => [
							'type' => Type::nonNull(Type::string()),
							'description' => 'GraphQL query string',
						],
						'param_spec' => [
							'type' => Type::string(),
							'description' => 'JSON-encoded parameter specification',
						],
					],
					'outputFields' => [
						'savedQuery' => [
							'type' => $this->typeContainer->get('frogman_saved_query')->getObject(),
							'resolve' => fn($payload) => $payload,
						],
					],
					'mutateAndGetPayload' => function($input) {
						$db = $this->freepbx->Database;
						$sth = $db->prepare("INSERT INTO oc_saved_queries (name, query, param_spec, created_by, created_at) VALUES (?, ?, ?, ?, ?)");
						$sth->execute([
							$input['name'],
							$input['query'],
							$input['param_spec'] ?? '{}',
							0,
							time(),
						]);
						$id = $db->lastInsertId();
						$sth = $db->prepare("SELECT * FROM oc_saved_queries WHERE id = ?");
						$sth->execute([$id]);
						return $sth->fetch(\PDO::FETCH_ASSOC);
					},
				]),
				'removeFrogmanSavedQuery' => Relay::mutationWithClientMutationId([
					'name' => 'removeFrogmanSavedQuery',
					'description' => 'Delete a saved query by name',
					'inputFields' => [
						'name' => [
							'type' => Type::nonNull(Type::string()),
							'description' => 'Saved query name to delete',
						],
					],
					'outputFields' => [
						'deletedName' => [
							'type' => Type::string(),
							'resolve' => fn($payload) => $payload['name'],
						],
					],
					'mutateAndGetPayload' => function($input) {
						$db = $this->freepbx->Database;
						$sth = $db->prepare("DELETE FROM oc_saved_queries WHERE name = ?");
						$sth->execute([$input['name']]);
						return ['name' => $input['name']];
					},
				]),
			];
		}
	}
}

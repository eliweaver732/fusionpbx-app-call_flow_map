<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.
*/

class call_flow_map {

	const app_name = 'call_flow_map';
	const app_uuid = 'd7c4f2a1-8b3e-4f9d-bc12-5a6e7890abcd';

	private $database;
	public $domain_uuid;
	public $domain_name;

	private $nodes = [];
	private $edges = [];
	private $visited = [];
	private $node_counter = 0;

	// Cached set of registered extensions: keys are "ext@domain_name", value true
	// null = not yet loaded, false = loading failed/no permission
	private $registered_set = null;

	// Max recursion depth to prevent infinite loops
	const MAX_DEPTH = 12;

	public function __construct(array $settings = []) {
		$this->domain_uuid = $settings['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? '';
		$this->domain_name = $settings['domain_name'] ?? $_SESSION['domain_name'] ?? '';
		$this->database    = $settings['database'] ?? database::new();
	}

	/**
	 * Build the diagram starting from the given type and UUID.
	 * Returns ['nodes' => [...], 'edges' => [...]]
	 */
	public function build(string $type, string $uuid): array {
		$this->nodes          = [];
		$this->edges          = [];
		$this->visited        = [];
		$this->node_counter   = 0;
		$this->registered_set = null;

		switch ($type) {
			case 'inbound':
				$this->build_inbound($uuid);
				break;
			case 'ivr':
				$this->build_ivr($uuid);
				break;
			case 'ring_group':
				$this->build_ring_group($uuid);
				break;
			case 'call_flow':
				$this->build_call_flow($uuid);
				break;
			case 'time_condition':
				$this->build_time_condition($uuid);
				break;
			case 'extension':
				$this->build_extension_reverse($uuid);
				break;
			case 'contact_center':
				$this->build_contact_center($uuid);
				break;
		}

		return ['nodes' => $this->nodes, 'edges' => $this->edges];
	}

	/**
	 * Return lists of available starting points grouped by type.
	 */
	public function get_starting_points(): array {
		$result = [];

		// Inbound routes
		$sql  = "select destination_uuid, destination_number, destination_description from v_destinations ";
		$sql .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
		$sql .= "and destination_type = 'inbound' and destination_enabled = 'true' ";
		$sql .= "order by destination_number asc";
		$rows = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid], 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$label = $row['destination_number'];
				if (!empty($row['destination_description'])) {
					$label .= ' — ' . $row['destination_description'];
				}
				$result['inbound'][] = ['uuid' => $row['destination_uuid'], 'label' => $label];
			}
		}

		// IVR Menus
		$sql  = "select ivr_menu_uuid, ivr_menu_name, ivr_menu_extension from v_ivr_menus ";
		$sql .= "where domain_uuid = :domain_uuid and ivr_menu_enabled = 'true' ";
		$sql .= "order by ivr_menu_name asc";
		$rows = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid], 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$result['ivr'][] = ['uuid' => $row['ivr_menu_uuid'], 'label' => $row['ivr_menu_name'] . ' (' . $row['ivr_menu_extension'] . ')'];
			}
		}

		// Ring Groups
		$sql  = "select ring_group_uuid, ring_group_name, ring_group_extension from v_ring_groups ";
		$sql .= "where domain_uuid = :domain_uuid and ring_group_enabled = 'true' ";
		$sql .= "order by ring_group_name asc";
		$rows = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid], 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$result['ring_group'][] = ['uuid' => $row['ring_group_uuid'], 'label' => $row['ring_group_name'] . ' (' . $row['ring_group_extension'] . ')'];
			}
		}

		// Call Flows
		$sql  = "select call_flow_uuid, call_flow_name, call_flow_extension from v_call_flows ";
		$sql .= "where domain_uuid = :domain_uuid and call_flow_enabled = 'true' ";
		$sql .= "order by call_flow_name asc";
		$rows = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid], 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$result['call_flow'][] = ['uuid' => $row['call_flow_uuid'], 'label' => $row['call_flow_name'] . ' (' . $row['call_flow_extension'] . ')'];
			}
		}

		// Time Conditions (dialplans with app_uuid = '4b821450-926b-175a-af93-a03c441818b1')
		$sql  = "select dialplan_uuid, dialplan_name, dialplan_number from v_dialplans ";
		$sql .= "where domain_uuid = :domain_uuid and dialplan_enabled = 'true' ";
		$sql .= "and app_uuid = '4b821450-926b-175a-af93-a03c441818b1' ";
		$sql .= "order by dialplan_name asc";
		$rows = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid], 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$result['time_condition'][] = ['uuid' => $row['dialplan_uuid'], 'label' => $row['dialplan_name'] . ' (' . $row['dialplan_number'] . ')'];
			}
		}

		// Extensions
		$sql  = "select extension_uuid, extension, effective_caller_id_name from v_extensions ";
		$sql .= "where domain_uuid = :domain_uuid and enabled = 'true' ";
		$sql .= "order by extension asc";
		$rows = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid], 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$label = $row['extension'];
				if (!empty($row['effective_caller_id_name'])) {
					$label .= ' — ' . $row['effective_caller_id_name'];
				}
				$result['extension'][] = ['uuid' => $row['extension_uuid'], 'label' => $label];
			}
		}

		// Contact Center Queues
		$sql  = "select call_center_queue_uuid, queue_name, queue_extension from v_call_center_queues ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "order by queue_name asc";
		$rows = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid], 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$result['contact_center'][] = ['uuid' => $row['call_center_queue_uuid'], 'label' => $row['queue_name'] . ' (' . $row['queue_extension'] . ')'];
			}
		}

		return $result;
	}

	// ─── Node builders ───────────────────────────────────────────────────────

	private function build_inbound(string $uuid, ?string $parent_id = null, string $edge_label = '', int $depth = 0): string {
		$node_id = 'inbound_' . $uuid;
		if (in_array($node_id, $this->visited)) {
			$this->add_edge_safe($parent_id, $node_id, $edge_label);
			return $node_id;
		}
		$this->visited[] = $node_id;

		$sql  = "select * from v_destinations ";
		$sql .= "where destination_uuid = :uuid ";
		$sql .= "and (domain_uuid = :domain_uuid or domain_uuid is null)";
		$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');

		if (empty($row)) return '';

		$number = $row['destination_number'] ?? 'DID';
		$desc   = !empty($row['destination_description']) ? "\n" . $this->truncate($row['destination_description'], 30) : '';
		$this->add_node($node_id, "📞 " . $number . $desc, 'inbound', 'Inbound Route: ' . $number, [], $depth);

		if ($parent_id !== null) {
			$this->add_edge($parent_id, $node_id, $edge_label);
		}

		if ($depth >= self::MAX_DEPTH) return $node_id;

		// Parse destination_actions (JSON array of {destination_app, destination_data})
		$actions = [];
		if (!empty($row['destination_actions'])) {
			$decoded = json_decode($row['destination_actions'], true);
			if (is_array($decoded)) {
				$actions = $decoded;
			}
		}
		// Fallback to legacy fields
		if (empty($actions) && !empty($row['destination_app'])) {
			$actions[] = ['destination_app' => $row['destination_app'], 'destination_data' => $row['destination_data'] ?? ''];
		}

		foreach ($actions as $i => $action) {
			$app  = $action['destination_app'] ?? '';
			$data = $action['destination_data'] ?? '';
			$lbl  = count($actions) > 1 ? 'Action ' . ($i + 1) : '';
			$this->resolve_destination($app, $data, $node_id, $lbl, $depth + 1);
		}

		return $node_id;
	}

	private function build_ivr(string $uuid, ?string $parent_id = null, string $edge_label = '', int $depth = 0, ?array $row = null): string {
		$node_id = 'ivr_' . $uuid;
		if (in_array($node_id, $this->visited)) {
			$this->add_edge_safe($parent_id, $node_id, $edge_label);
			return $node_id;
		}
		$this->visited[] = $node_id;

		if ($row === null) {
			$sql = "select * from v_ivr_menus where ivr_menu_uuid = :uuid and domain_uuid = :domain_uuid";
			$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');
		}
		if (empty($row)) return '';

		$name        = $row['ivr_menu_name'] ?? 'IVR';
		$ext         = !empty($row['ivr_menu_extension']) ? ' (' . $row['ivr_menu_extension'] . ')' : '';
		$direct_dial = $this->is_true($row['ivr_menu_direct_dial'] ?? null);
		$dd_line     = $direct_dial ? "\n☎ direct dial" : '';
		$title_dd    = $direct_dial ? ' — direct dial enabled' : '';
		$this->add_node($node_id, "🔀 " . $name . $ext . $dd_line, 'ivr', 'IVR Menu: ' . $name . $title_dd, $direct_dial ? ['direct_dial' => true] : [], $depth);

		if ($parent_id !== null) {
			$this->add_edge($parent_id, $node_id, $edge_label);
		}

		if ($depth >= self::MAX_DEPTH) return $node_id;

		// Get IVR options
		$sql  = "select * from v_ivr_menu_options ";
		$sql .= "where ivr_menu_uuid = :uuid and domain_uuid = :domain_uuid ";
		$sql .= "and ivr_menu_option_enabled = 'true' ";
		$sql .= "order by ivr_menu_option_digits, ivr_menu_option_order asc";
		$options = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'all');

		if (is_array($options)) {
			foreach ($options as $opt) {
				$digits = $opt['ivr_menu_option_digits'] ?? '?';
				$action = $opt['ivr_menu_option_action'] ?? '';
				$param  = $opt['ivr_menu_option_param'] ?? '';

				// Skip meta actions
				if (in_array($action, ['menu-top:', 'menu-back:', 'menu-exit:'])) continue;

				$digit_label = 'Press ' . $digits;

				// menu-sub:UUID → sub-IVR
				if ($action === 'menu-sub' && is_uuid($param)) {
					$this->build_ivr($param, $node_id, $digit_label, $depth + 1);
					continue;
				}
				// menu-exec-app:transfer EXT XML CTX
				if ($action === 'menu-exec-app' && preg_match('/^transfer\s+(\S+)\s+XML\s+(\S+)/i', $param, $m)) {
					$this->lookup_by_extension($m[1], $node_id, $digit_label, $depth + 1);
					continue;
				}

				// menu-exec-app:lua streamfile.lua FILENAME → play recording
				if ($action === 'menu-exec-app' && preg_match('/^lua\s+streamfile\.lua\s+(.+)$/i', $param, $m)) {
				    $filename = trim($m[1]);
				    $r_id = 'recording_' . ($this->node_counter++);
				    $this->add_node($r_id, "🔊 " . $this->truncate($filename, 30), 'recording', '', [], $depth + 1);
				    $this->add_edge($node_id, $r_id, $digit_label);
				    continue;
				}

				// menu-exec-app:playback tone_stream://...  → look up friendly name in v_vars
				if ($action === 'menu-exec-app' && preg_match('/^playback\s+tone_stream:\/\/(.+)$/i', $param, $m)) {
				    $tone_value = trim($m[1]);
				    $var_row = $this->database->select(
				        "select var_name from v_vars where var_value = :val limit 1",
				        ['val' => $tone_value],
				        'row'
				    );
				    $tone_name = $var_row['var_name'] ?? $this->truncate($tone_value, 30);
				    $t_id = 'tone_' . ($this->node_counter++);
				    $this->add_node($t_id, "🎵 tone: " . $tone_name, 'tone', '', [], $depth + 1);
				    $this->add_edge($node_id, $t_id, $digit_label);
				    continue;
				}

				// Plain transfer EXT XML CTX
				if ($action === 'transfer' && preg_match('/^(\S+)\s+XML\s+(\S+)/i', $param, $m)) {
					$this->lookup_by_extension($m[1], $node_id, $digit_label, $depth + 1);
					continue;
				}
				// hangup
				if ($action === 'hangup' || $param === 'hangup') {
					$h_id = 'hangup_' . ($this->node_counter++);
					$this->add_node($h_id, "✖ Hangup", 'hangup', '', [], $depth + 1);
					$this->add_edge($node_id, $h_id, $digit_label);
					continue;
				}
				// Anything else — show as unknown
				$u_id = 'unknown_' . ($this->node_counter++);
				$this->add_node($u_id, $this->truncate($action . ' ' . $param, 40), 'external', '', [], $depth + 1);
				$this->add_edge($node_id, $u_id, $digit_label);
			}
		}

		// Timeout / invalid destination (ivr_menu_timeout_app / ivr_menu_invalid_app)
		foreach ([
			['app' => $row['ivr_menu_timeout_app'] ?? '', 'data' => $row['ivr_menu_timeout_data'] ?? '', 'label' => 'Timeout'],
			['app' => $row['ivr_menu_invalid_app'] ?? '', 'data' => $row['ivr_menu_invalid_data'] ?? '', 'label' => 'Invalid'],
			['app' => $row['ivr_menu_exit_app']    ?? '', 'data' => $row['ivr_menu_exit_data']    ?? '', 'label' => 'Exit'],
		] as $fallback) {
			if (!empty($fallback['app'])) {
				$this->resolve_destination($fallback['app'], $fallback['data'], $node_id, $fallback['label'], $depth + 1);
			}
		}

		return $node_id;
	}

	private function build_ring_group(string $uuid, ?string $parent_id = null, string $edge_label = '', int $depth = 0, ?array $row = null): string {
		$node_id = 'rg_' . $uuid;
		if (in_array($node_id, $this->visited)) {
			$this->add_edge_safe($parent_id, $node_id, $edge_label);
			return $node_id;
		}
		$this->visited[] = $node_id;

		if ($row === null) {
			$sql = "select * from v_ring_groups where ring_group_uuid = :uuid and domain_uuid = :domain_uuid";
			$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');
		}
		if (empty($row)) return '';

		// Fetch member destinations first so we can embed plain extensions in the label
		$sql  = "select * from v_ring_group_destinations ";
		$sql .= "where ring_group_uuid = :uuid and domain_uuid = :domain_uuid ";
		$sql .= "and destination_enabled = 'true' ";
		$sql .= "order by destination_delay asc, destination_number asc";
		$dests = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'all');

		// Separate plain extensions (shown inline) from routable destinations (shown as edges)
		$inline_lines   = [];
		$routable_dests = [];
		if (is_array($dests)) {
			foreach ($dests as $dest) {
				$number = $dest['destination_number'] ?? '';
				if (empty($number)) continue;
				$ext_info = $this->resolve_plain_extension($number);
				if ($ext_info !== null) {
					// Known extensions get a registration status icon; unknown/external get ☎
					if (!empty($ext_info['known'])) {
						$registered = $this->extension_registered($number);
						if ($registered === true)      $icon = '🟢';
						elseif ($registered === false) $icon = '🔴';
						else                           $icon = '☎';
					} else {
						$icon = '☎';
					}
					$line = $icon . ' ' . $number;
					if (!empty($ext_info['name'])) $line .= ' ' . $ext_info['name'];
					$inline_lines[] = $line;
				} else {
					$routable_dests[] = $dest;
				}
			}
		}

		$name     = $row['ring_group_name'] ?? 'Ring Group';
		$ext      = !empty($row['ring_group_extension']) ? ' (' . $row['ring_group_extension'] . ')' : '';
		$strategy = !empty($row['ring_group_strategy']) ? "\n" . ucfirst($row['ring_group_strategy']) : '';
		$label    = "🔔 " . $name . $ext . $strategy;
		if (!empty($inline_lines)) {
			$label .= "\n----------\n" . implode("\n", $inline_lines);
		}
		$this->add_node($node_id, $label, 'ring_group', 'Ring Group: ' . $name, [], $depth);

		if ($parent_id !== null) {
			$this->add_edge($parent_id, $node_id, $edge_label);
		}

		if ($depth >= self::MAX_DEPTH) return $node_id;

		// Routable destinations (IVR, ring group, call flow, voicemail, external, etc.)
		foreach ($routable_dests as $dest) {
			$number    = $dest['destination_number'];
			$delay_lbl = !empty($dest['destination_delay']) && $dest['destination_delay'] > 0
				? 'Delay ' . $dest['destination_delay'] . 's'
				: 'Ring';
			$this->lookup_by_extension($number, $node_id, $delay_lbl, $depth + 1);
		}

		// Forward on no-answer
		$fwd = $row['ring_group_forward_destination'] ?? '';
		if (!empty($fwd)) {
			$fwd_arr = explode(':', $fwd, 2);
			$fwd_app  = $fwd_arr[0];
			$fwd_data = $fwd_arr[1] ?? '';
			$this->resolve_destination($fwd_app, $fwd_data, $node_id, 'No Answer', $depth + 1);
		}

		return $node_id;
	}

	/**
	 * If $number maps to a plain extension (not IVR/ring group/call flow/time condition),
	 * return ['number' => ..., 'name' => ...]. Otherwise return null.
	 */
	private function resolve_plain_extension(string $number): ?array {
		// Special prefixes are not plain extensions
		if (preg_match('/^\*/', $number)) return null;

		// If it matches an IVR, ring group, call flow, or time condition — not plain
		$sql = "select 1 from v_ivr_menus where ivr_menu_extension = :ext and domain_uuid = :d and ivr_menu_enabled = 'true' limit 1";
		if ($this->database->select($sql, ['ext' => $number, 'd' => $this->domain_uuid], 'column')) return null;

		$sql = "select 1 from v_ring_groups where ring_group_extension = :ext and domain_uuid = :d and ring_group_enabled = 'true' limit 1";
		if ($this->database->select($sql, ['ext' => $number, 'd' => $this->domain_uuid], 'column')) return null;

		$sql = "select 1 from v_call_flows where call_flow_extension = :ext and domain_uuid = :d limit 1";
		if ($this->database->select($sql, ['ext' => $number, 'd' => $this->domain_uuid], 'column')) return null;

		$sql = "select 1 from v_dialplans where dialplan_number = :ext and domain_uuid = :d and app_uuid = '4b821450-926b-175a-af93-a03c441818b1' and dialplan_enabled = 'true' limit 1";
		if ($this->database->select($sql, ['ext' => $number, 'd' => $this->domain_uuid], 'column')) return null;

		$sql = "select 1 from v_call_center_queues where queue_extension = :ext and domain_uuid = :d limit 1";
		if ($this->database->select($sql, ['ext' => $number, 'd' => $this->domain_uuid], 'column')) return null;

		// Look up as a plain extension — known = true means registration status can be shown
		$sql  = "select extension, effective_caller_id_name from v_extensions ";
		$sql .= "where extension = :ext and domain_uuid = :d and enabled = 'true' limit 1";
		$extn = $this->database->select($sql, ['ext' => $number, 'd' => $this->domain_uuid], 'row');
		if (!empty($extn)) {
			return ['number' => $number, 'name' => $extn['effective_caller_id_name'] ?? '', 'known' => true];
		}

		// Not a known extension — show inline but without registration icon
		return ['number' => $number, 'name' => '', 'known' => false];
	}

	private function build_contact_center(string $uuid, ?string $parent_id = null, string $edge_label = '', int $depth = 0, ?array $row = null): string {
		$node_id = 'cc_' . $uuid;
		if (in_array($node_id, $this->visited)) {
			$this->add_edge_safe($parent_id, $node_id, $edge_label);
			return $node_id;
		}
		$this->visited[] = $node_id;

		if ($row === null) {
			$sql = "select * from v_call_center_queues where call_center_queue_uuid = :uuid and domain_uuid = :domain_uuid";
			$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');
		}
		if (empty($row)) return '';

		// Fetch agents assigned to this queue via tiers
		$sql  = "select a.agent_name ";
		$sql .= "from v_call_center_tiers t ";
		$sql .= "inner join v_call_center_agents a on a.call_center_agent_uuid = t.call_center_agent_uuid ";
		$sql .= "where t.call_center_queue_uuid = :uuid and t.domain_uuid = :domain_uuid ";
		$sql .= "order by t.tier_level asc, t.tier_position asc, a.agent_name asc";
		$agents = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'all');

		$name     = $row['queue_name'] ?? 'Queue';
		$ext      = !empty($row['queue_extension']) ? ' (' . $row['queue_extension'] . ')' : '';
		$strategy = !empty($row['queue_strategy']) ? "\n" . ucfirst(str_replace('-', ' ', $row['queue_strategy'])) : '';
		$label    = "🎯 " . $name . $ext . $strategy;

		if (!empty($agents)) {
			$agent_lines = array_map(fn($a) => '👤 ' . ($a['agent_name'] ?? ''), $agents);
			$label .= "\n----------\n" . implode("\n", $agent_lines);
		}

		$this->add_node($node_id, $label, 'contact_center', 'Contact Center Queue: ' . $name, [], $depth);

		if ($parent_id !== null) {
			$this->add_edge($parent_id, $node_id, $edge_label);
		}

		if ($depth >= self::MAX_DEPTH) return $node_id;

		// Timeout action (stored as "app:data")
		$timeout_action = $row['queue_timeout_action'] ?? '';
		if (!empty($timeout_action)) {
			$parts    = explode(':', $timeout_action, 2);
			$t_app    = $parts[0];
			$t_data   = $parts[1] ?? '';
			$this->resolve_destination($t_app, $t_data, $node_id, 'Timeout', $depth + 1);
		}

		return $node_id;
	}

	private function build_call_flow(string $uuid, ?string $parent_id = null, string $edge_label = '', int $depth = 0, ?array $row = null): string {
		$node_id = 'cf_' . $uuid;
		if (in_array($node_id, $this->visited)) {
			$this->add_edge_safe($parent_id, $node_id, $edge_label);
			return $node_id;
		}
		$this->visited[] = $node_id;

		if ($row === null) {
			$sql = "select * from v_call_flows where call_flow_uuid = :uuid and domain_uuid = :domain_uuid";
			$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');
		}
		if (empty($row)) return '';

		$name   = $row['call_flow_name'] ?? 'Call Flow';
		$ext    = !empty($row['call_flow_extension']) ? ' (' . $row['call_flow_extension'] . ')' : '';
		$status = !empty($row['call_flow_status']) ? "\nStatus: " . $row['call_flow_status'] : '';
		$this->add_node($node_id, "🔄 " . $name . $ext . $status, 'call_flow', 'Call Flow: ' . $name, [], $depth);

		if ($parent_id !== null) {
			$this->add_edge($parent_id, $node_id, $edge_label);
		}

		if ($depth >= self::MAX_DEPTH) return $node_id;

		// Primary action
		$app  = $row['call_flow_app'] ?? '';
		$data = $row['call_flow_data'] ?? '';
		if (!empty($app)) {
			$label_primary = !empty($row['call_flow_label']) ? $row['call_flow_label'] : 'Active';
			$this->resolve_destination($app, $data, $node_id, $label_primary, $depth + 1);
		}

		// Alternate action
		$alt_app  = $row['call_flow_alternate_app'] ?? '';
		$alt_data = $row['call_flow_alternate_data'] ?? '';
		if (!empty($alt_app)) {
			$label_alt = !empty($row['call_flow_alternate_label']) ? $row['call_flow_alternate_label'] : 'Alternate';
			$this->resolve_destination($alt_app, $alt_data, $node_id, $label_alt, $depth + 1);
		}

		return $node_id;
	}

	private function build_time_condition(string $uuid, ?string $parent_id = null, string $edge_label = '', int $depth = 0, ?array $row = null): string {
		$node_id = 'tc_' . $uuid;
		if (in_array($node_id, $this->visited)) {
			$this->add_edge_safe($parent_id, $node_id, $edge_label);
			return $node_id;
		}
		$this->visited[] = $node_id;

		if ($row === null) {
			$sql = "select * from v_dialplans where dialplan_uuid = :uuid and domain_uuid = :domain_uuid";
			$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');
		}
		if (empty($row)) return '';

		$name = $row['dialplan_name'] ?? 'Time Condition';
		$num  = !empty($row['dialplan_number']) ? ' (' . $row['dialplan_number'] . ')' : '';
		$this->add_node($node_id, "⏰ " . $name . $num, 'time_condition', 'Time Condition: ' . $name, [], $depth);

		if ($parent_id !== null) {
			$this->add_edge($parent_id, $node_id, $edge_label);
		}

		if ($depth >= self::MAX_DEPTH) return $node_id;

		// Get dialplan details — actions per group
		$sql  = "select * from v_dialplan_details ";
		$sql .= "where dialplan_uuid = :uuid ";
		$sql .= "order by dialplan_detail_group asc, dialplan_detail_order asc";
		$details = $this->database->select($sql, ['uuid' => $uuid], 'all');

		$groups = [];
		if (is_array($details)) {
			foreach ($details as $d) {
				$grp = $d['dialplan_detail_group'] ?? 0;
				$tag = $d['dialplan_detail_tag'] ?? '';
				if ($tag === 'action') {
					$groups[$grp][] = $d;
				}
			}
		}

		// Each group = one branch (time match / anti-action)
		$group_count = count($groups);
		$i = 0;
		foreach ($groups as $actions) {
			$is_last  = ($i === $group_count - 1);
			$lbl_edge = $is_last && $group_count > 1 ? 'Default' : 'Matches';
			foreach ($actions as $action_row) {
				$detail_app  = $action_row['dialplan_detail_type'] ?? '';
				$detail_data = $action_row['dialplan_detail_data'] ?? '';
				if (in_array($detail_app, ['set', 'export', 'log', 'unset'])) continue;
				$this->resolve_destination($detail_app, $detail_data, $node_id, $lbl_edge, $depth + 1);
			}
			$i++;
		}

		return $node_id;
	}

	private function build_extension(string $uuid, ?string $parent_id = null, string $edge_label = ''): string {
		$node_id = 'ext_' . $uuid;
		if (in_array($node_id, $this->visited)) {
			$this->add_edge_safe($parent_id, $node_id, $edge_label);
			return $node_id;
		}
		$this->visited[] = $node_id;

		$sql = "select * from v_extensions where extension_uuid = :uuid and domain_uuid = :domain_uuid";
		$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');
		if (empty($row)) return '';

		$ext  = $row['extension'] ?? '';
		$name = $row['effective_caller_id_name'] ?? '';
		$label = "☎ " . $ext;
		if (!empty($name)) $label .= "\n" . $name;
		$this->add_node($node_id, $label, 'extension', 'Extension: ' . $ext . (!empty($name) ? ' — ' . $name : ''), [], 0);

		if ($parent_id !== null) {
			$this->add_edge($parent_id, $node_id, $edge_label);
		}

		return $node_id;
	}

	private function build_voicemail(string $uuid, ?string $parent_id = null, string $edge_label = '', int $depth = 0, ?array $row = null): string {
		$node_id = 'vm_' . $uuid;
		if (in_array($node_id, $this->visited)) {
			$this->add_edge_safe($parent_id, $node_id, $edge_label);
			return $node_id;
		}
		$this->visited[] = $node_id;

		if ($row === null) {
			$sql = "select * from v_voicemails where voicemail_uuid = :uuid and domain_uuid = :domain_uuid";
			$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');
		}
		if (empty($row)) return '';

		$vm_id = $row['voicemail_id'] ?? '';
		$desc  = !empty($row['voicemail_description']) ? "\n" . $this->truncate($row['voicemail_description'], 25) : '';
		$label = "📬 Voicemail\n" . $vm_id . $desc;

		$extra = ['edit_url' => '/app/voicemails/voicemail_edit.php?id=' . $uuid];
		$this->add_node($node_id, $label, 'voicemail', 'Voicemail: ' . $vm_id, $extra, $depth);

		if ($parent_id !== null) {
			$this->add_edge($parent_id, $node_id, $edge_label);
		}

		if ($depth >= self::MAX_DEPTH) return $node_id;

		// Voicemail key-press options
		$sql  = "select * from v_voicemail_options ";
		$sql .= "where voicemail_uuid = :uuid and domain_uuid = :domain_uuid ";
		$sql .= "order by voicemail_option_digits asc, voicemail_option_order asc";
		$options = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'all');

		if (is_array($options)) {
			foreach ($options as $opt) {
				$digits = $opt['voicemail_option_digits'] ?? '?';
				$action = $opt['voicemail_option_action'] ?? '';
				$param  = $opt['voicemail_option_param']  ?? '';
				if (empty($action)) continue;
				$this->resolve_destination($action, $param, $node_id, 'Press ' . $digits, $depth + 1);
			}
		}

		return $node_id;
	}

	/**
	 * Reverse lookup: build a diagram showing all sources that route TO this extension.
	 * Only goes one level up (direct parents of the extension node).
	 */
	private function build_extension_reverse(string $uuid): string {
		$sql = "select * from v_extensions where extension_uuid = :uuid and domain_uuid = :domain_uuid";
		$row = $this->database->select($sql, ['uuid' => $uuid, 'domain_uuid' => $this->domain_uuid], 'row');
		if (empty($row)) return '';

		$ext_number = $row['extension'] ?? '';
		$name       = $row['effective_caller_id_name'] ?? '';

		// Build the extension node (target) at level 1; parents will be level 0
		$ext_node_id = 'ext_' . $uuid;
		$label = "☎ " . $ext_number;
		if (!empty($name)) $label .= "\n" . $name;
		$this->add_node($ext_node_id, $label, 'extension', 'Extension: ' . $ext_number . (!empty($name) ? ' — ' . $name : ''), [], 1);
		$this->visited[] = $ext_node_id;

		// SQL LIKE pattern — narrows rows; PHP confirms exact match
		$like = '%' . $ext_number . ' XML%';
		$params_like = ['domain_uuid' => $this->domain_uuid, 'pattern' => $like];

		// ── 1. Ring groups that list this extension as a member ───────────────
		$sql  = "select rg.ring_group_uuid, rg.ring_group_name, rg.ring_group_extension, rg.ring_group_strategy ";
		$sql .= "from v_ring_groups rg ";
		$sql .= "inner join v_ring_group_destinations rgd on rg.ring_group_uuid = rgd.ring_group_uuid ";
		$sql .= "where rg.domain_uuid = :domain_uuid ";
		$sql .= "and rgd.destination_number = :ext ";
		$sql .= "and rg.ring_group_enabled = 'true' ";
		$sql .= "and rgd.destination_enabled = 'true'";
		$results = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid, 'ext' => $ext_number], 'all');
		if (is_array($results)) {
			foreach ($results as $rg) {
				$n_id = 'rg_' . $rg['ring_group_uuid'];
				if (!in_array($n_id, $this->visited)) {
					$this->visited[] = $n_id;
					$strategy = !empty($rg['ring_group_strategy']) ? "\n" . ucfirst($rg['ring_group_strategy']) : '';
					$this->add_node($n_id, "🔔 " . $rg['ring_group_name'] . "\n(" . $rg['ring_group_extension'] . ")" . $strategy, 'ring_group', 'Ring Group: ' . $rg['ring_group_name'], [], 0);
				}
				$this->add_edge($n_id, $ext_node_id, 'Member');
			}
		}

		// ── 2. IVR menu options routing to this extension ─────────────────────
		$sql  = "select imo.ivr_menu_option_digits, imo.ivr_menu_option_action, imo.ivr_menu_option_param, ";
		$sql .= "im.ivr_menu_uuid, im.ivr_menu_name, im.ivr_menu_extension, im.ivr_menu_direct_dial ";
		$sql .= "from v_ivr_menu_options imo ";
		$sql .= "inner join v_ivr_menus im on imo.ivr_menu_uuid = im.ivr_menu_uuid ";
		$sql .= "where imo.domain_uuid = :domain_uuid ";
		$sql .= "and imo.ivr_menu_option_enabled = 'true' ";
		$sql .= "and im.ivr_menu_enabled = 'true' ";
		$sql .= "and imo.ivr_menu_option_param like :pattern";
		$results = $this->database->select($sql, $params_like, 'all');
		if (is_array($results)) {
			foreach ($results as $opt) {
				$matched = $this->extract_extension($opt['ivr_menu_option_action'], $opt['ivr_menu_option_param']);
				if ($matched !== $ext_number) continue;

				$n_id = 'ivr_' . $opt['ivr_menu_uuid'];
				if (!in_array($n_id, $this->visited)) {
					$this->visited[] = $n_id;
					$direct_dial = $this->is_true($opt['ivr_menu_direct_dial'] ?? null);
					$dd_line  = $direct_dial ? "\n☎ direct dial" : '';
					$title_dd = $direct_dial ? ' — direct dial enabled' : '';
					$this->add_node($n_id, "🔀 " . $opt['ivr_menu_name'] . "\n(" . $opt['ivr_menu_extension'] . ")" . $dd_line, 'ivr', 'IVR Menu: ' . $opt['ivr_menu_name'] . $title_dd, $direct_dial ? ['direct_dial' => true] : [], 0);
				}
				$this->add_edge($n_id, $ext_node_id, 'Press ' . $opt['ivr_menu_option_digits']);
			}
		}

		// ── 3. Call flows routing to this extension ───────────────────────────
		$sql  = "select * from v_call_flows ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and (call_flow_data like :pattern or call_flow_alternate_data like :pattern)";
		$results = $this->database->select($sql, $params_like, 'all');
		if (is_array($results)) {
			foreach ($results as $cf) {
				$edge_label = null;
				if ($this->extract_extension($cf['call_flow_app'] ?? '', $cf['call_flow_data'] ?? '') === $ext_number) {
					$edge_label = !empty($cf['call_flow_label']) ? $cf['call_flow_label'] : 'Active';
				} elseif ($this->extract_extension($cf['call_flow_alternate_app'] ?? '', $cf['call_flow_alternate_data'] ?? '') === $ext_number) {
					$edge_label = !empty($cf['call_flow_alternate_label']) ? $cf['call_flow_alternate_label'] : 'Alternate';
				}
				if ($edge_label === null) continue;

				$n_id = 'cf_' . $cf['call_flow_uuid'];
				if (!in_array($n_id, $this->visited)) {
					$this->visited[] = $n_id;
					$this->add_node($n_id, "🔄 " . $cf['call_flow_name'] . "\n(" . $cf['call_flow_extension'] . ")", 'call_flow', 'Call Flow: ' . $cf['call_flow_name'], [], 0);
				}
				$this->add_edge($n_id, $ext_node_id, $edge_label);
			}
		}

		// ── 4. Inbound routes (v_destinations) routing to this extension ──────
		$sql  = "select * from v_destinations ";
		$sql .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
		$sql .= "and destination_type = 'inbound' ";
		$sql .= "and destination_enabled = 'true' ";
		$sql .= "and destination_actions like :pattern";
		$results = $this->database->select($sql, $params_like, 'all');
		if (is_array($results)) {
			foreach ($results as $dest) {
				$actions = json_decode($dest['destination_actions'] ?? '[]', true);
				if (!is_array($actions)) continue;
				$found = false;
				foreach ($actions as $action) {
					if ($this->extract_extension($action['destination_app'] ?? '', $action['destination_data'] ?? '') === $ext_number) {
						$found = true;
						break;
					}
				}
				if (!$found) continue;

				$n_id = 'inbound_' . $dest['destination_uuid'];
				if (!in_array($n_id, $this->visited)) {
					$this->visited[] = $n_id;
					$number = $dest['destination_number'] ?? 'DID';
					$desc   = !empty($dest['destination_description']) ? "\n" . $this->truncate($dest['destination_description'], 30) : '';
					$this->add_node($n_id, "📞 " . $number . $desc, 'inbound', 'Inbound Route: ' . $number, [], 0);
				}
				$this->add_edge($n_id, $ext_node_id, '');
			}
		}

		// ── 5. Time conditions routing to this extension ──────────────────────
		$sql  = "select dp.dialplan_uuid, dp.dialplan_name, dp.dialplan_number, ";
		$sql .= "dd.dialplan_detail_type, dd.dialplan_detail_data ";
		$sql .= "from v_dialplan_details dd ";
		$sql .= "inner join v_dialplans dp on dp.dialplan_uuid = dd.dialplan_uuid ";
		$sql .= "where dp.domain_uuid = :domain_uuid ";
		$sql .= "and dp.app_uuid = '4b821450-926b-175a-af93-a03c441818b1' ";
		$sql .= "and dp.dialplan_enabled = 'true' ";
		$sql .= "and dd.dialplan_detail_tag = 'action' ";
		$sql .= "and dd.dialplan_detail_data like :pattern";
		$results = $this->database->select($sql, $params_like, 'all');
		if (is_array($results)) {
			foreach ($results as $detail) {
				if ($this->extract_extension($detail['dialplan_detail_type'], $detail['dialplan_detail_data']) !== $ext_number) continue;

				$n_id = 'tc_' . $detail['dialplan_uuid'];
				if (!in_array($n_id, $this->visited)) {
					$this->visited[] = $n_id;
					$this->add_node($n_id, "⏰ " . $detail['dialplan_name'] . "\n(" . $detail['dialplan_number'] . ")", 'time_condition', 'Time Condition: ' . $detail['dialplan_name'], [], 0);
				}
				$this->add_edge($n_id, $ext_node_id, '');
			}
		}

		return $ext_node_id;
	}

	/**
	 * Extract the bare extension number from an app+data destination pair.
	 * Returns the extension string, or null if the pattern isn't recognised.
	 */
	private function extract_extension(string $app, string $data): ?string {
		$app  = trim($app);
		$data = trim($data);
		if ($app === 'transfer') {
			// "EXT XML CTX" → EXT
			if (preg_match('/^(\S+)\s+XML\s+/i', $data, $m)) return $m[1];
			return $data ?: null;
		}
		if ($app === 'menu-exec-app') {
			// "transfer EXT XML CTX" → EXT
			if (preg_match('/^transfer\s+(\S+)\s+XML\s+/i', $data, $m)) return $m[1];
		}
		return null;
	}

	// ─── Destination resolution ───────────────────────────────────────────────

	/**
	 * Resolve a destination string (app + data) and add the node/edge.
	 * Handles: transfer, menu-exec-app, menu-sub, hangup, bridge, etc.
	 */
	private function resolve_destination(string $app, string $data, string $parent_id, string $edge_label, int $depth): void {
		if ($depth > self::MAX_DEPTH) return;

		$app  = trim($app);
		$data = trim($data);

		// Hangup
		if ($app === 'hangup' || $data === 'hangup' || ($app === '' && $data === 'hangup')) {
			$h_id = 'hangup_' . ($this->node_counter++);
			$this->add_node($h_id, "✖ Hangup", 'hangup', '', [], $depth);
			$this->add_edge($parent_id, $h_id, $edge_label);
			return;
		}

		// lua streamfile.lua FILENAME  (play a recording — e.g. after-hours greeting)
		if (
		    ($app === 'lua' && preg_match('/^streamfile\.lua\s+(.+)$/i', $data, $m)) ||
		    ($app === 'menu-exec-app' && preg_match('/^lua\s+streamfile\.lua\s+(.+)$/i', $data, $m))
		) {
		    $filename = trim($m[1]);
		    $r_id = 'recording_' . ($this->node_counter++);
		    $this->add_node($r_id, "🔊 " . $this->truncate($filename, 30), 'recording', '', [], $depth);
		    $this->add_edge($parent_id, $r_id, $edge_label);
		    return;
		}

		// playback: tone_stream://...  → look up friendly name in v_vars
		if ($app === 'playback' && preg_match('/^tone_stream:\/\/(.+)$/i', $data, $m)) {
		    $tone_value = trim($m[1]);
		    $var_row = $this->database->select(
		        "select var_name from v_vars where var_value = :val limit 1",
		        ['val' => $tone_value],
		        'row'
		    );
		    $tone_name = $var_row['var_name'] ?? $this->truncate($tone_value, 30);
		    $t_id = 'tone_' . ($this->node_counter++);
		    $this->add_node($t_id, "🎵 tone stream " . $tone_name, 'tone', '', [], $depth);
		    $this->add_edge($parent_id, $t_id, $edge_label);
		    return;
		}

		// playback: /full/path/to/recording.ext  (play a recording)
		if ($app === 'playback') {
		    $filename = basename($data);
		    $r_id = 'recording_' . ($this->node_counter++);
		    $this->add_node($r_id, "🔊 " . $this->truncate($filename, 30), 'recording', '', [], $depth);
		    $this->add_edge($parent_id, $r_id, $edge_label);
		    return;
		}

		// menu-sub:UUID
		if ($app === 'menu-sub' && is_uuid($data)) {
			$this->build_ivr($data, $parent_id, $edge_label, $depth);
			return;
		}

		// transfer:EXT XML CTX  or  transfer EXT XML CTX
		if ($app === 'transfer') {
			if (preg_match('/^(\S+)\s+XML\s+(\S+)/i', $data, $m)) {
				$this->lookup_by_extension($m[1], $parent_id, $edge_label, $depth);
			} else {
				// Bare transfer (no XML context)
				$this->lookup_by_extension($data, $parent_id, $edge_label, $depth);
			}
			return;
		}

		// menu-exec-app:transfer EXT XML CTX
		if ($app === 'menu-exec-app' && preg_match('/^transfer\s+(\S+)\s+XML\s+(\S+)/i', $data, $m)) {
			$this->lookup_by_extension($m[1], $parent_id, $edge_label, $depth);
			return;
		}

		// bridge:sofia/... or bridge:user/...  (ring group member destination)
		if ($app === 'bridge') {
			if (preg_match('/user\/(\d+)@/i', $data, $m)) {
				$this->lookup_by_extension($m[1], $parent_id, $edge_label, $depth);
			} else {
				$u_id = 'external_' . ($this->node_counter++);
				$this->add_node($u_id, "↗ " . $this->truncate($data, 35), 'external', '', [], $depth);
				$this->add_edge($parent_id, $u_id, $edge_label);
			}
			return;
		}

		// voicemail:default $domain $ext
		if ($app === 'voicemail') {
			$parts     = explode(' ', $data);
			$vm_id_str = end($parts);
			$vm_row    = $this->database->select("select voicemail_uuid from v_voicemails where voicemail_id = :id and domain_uuid = :d", ['id' => $vm_id_str, 'd' => $this->domain_uuid], 'row');
			if (!empty($vm_row['voicemail_uuid'])) {
				$this->build_voicemail($vm_row['voicemail_uuid'], $parent_id, $edge_label, $depth);
			} else {
				// Voicemail box not found — show as terminal node
				$n_id = 'vm_unknown_' . $vm_id_str . '_' . ($this->node_counter++);
				$this->add_node($n_id, "📬 Voicemail\n" . $vm_id_str, 'voicemail', '', [], $depth);
				$this->add_edge($parent_id, $n_id, $edge_label);
			}
			return;
		}

		// check_voicemail (*98)
		if (strpos($data, '*98') === 0 || $app === 'check_voicemail') {
			$n_id = 'check_vm_' . ($this->node_counter++);
			$this->add_node($n_id, "📬 Check\nVoicemail", 'voicemail', '', [], $depth);
			$this->add_edge($parent_id, $n_id, $edge_label);
			return;
		}

		// Fallback — show as external/unknown
		$label = $this->truncate(trim($app . ' ' . $data), 45);
		if (!empty($label)) {
			$u_id = 'unknown_' . ($this->node_counter++);
			$this->add_node($u_id, "↗ " . $label, 'external', '', [], $depth);
			$this->add_edge($parent_id, $u_id, $edge_label);
		}
	}

	/**
	 * Look up what lives at $ext in the domain and build the correct node.
	 * Resolution order: IVR → Ring Group → Call Flow → Time Condition → Extension → Voicemail → External
	 */
	private function lookup_by_extension(string $ext, string $parent_id, string $edge_label, int $depth): void {
		if ($depth > self::MAX_DEPTH) return;

		// Voicemail box: *99EXT
		if (preg_match('/^\*99(.+)$/', $ext, $m)) {
			$vm_num = $m[1];
			$sql    = "select * from v_voicemails where voicemail_id = :id and domain_uuid = :domain_uuid";
			$vm     = $this->database->select($sql, ['id' => $vm_num, 'domain_uuid' => $this->domain_uuid], 'row');
			if (!empty($vm['voicemail_uuid'])) {
				$this->build_voicemail($vm['voicemail_uuid'], $parent_id, $edge_label, $depth, $vm);
			} else {
				$n_id = 'vm_unknown_' . $vm_num . '_' . ($this->node_counter++);
				$this->add_node($n_id, "📬 Voicemail\n" . $vm_num, 'voicemail', '', [], $depth);
				$this->add_edge($parent_id, $n_id, $edge_label);
			}
			return;
		}

		// Check voicemail (*98)
		if ($ext === '*98' || strpos($ext, '*98') === 0) {
			$n_id = 'check_vm_' . ($this->node_counter++);
			$this->add_node($n_id, "📬 Check\nVoicemail", 'voicemail', '', [], $depth);
			$this->add_edge($parent_id, $n_id, $edge_label);
			return;
		}

		// Company directory (*411)
		if ($ext === '*411') {
			$n_id = 'dir_' . ($this->node_counter++);
			$this->add_node($n_id, "📋 Company\nDirectory", 'external', '', [], $depth);
			$this->add_edge($parent_id, $n_id, $edge_label);
			return;
		}

		// IVR menu
		$sql  = "select * from v_ivr_menus ";
		$sql .= "where ivr_menu_extension = :ext and domain_uuid = :domain_uuid ";
		$sql .= "and ivr_menu_enabled = 'true' limit 1";
		$ivr  = $this->database->select($sql, ['ext' => $ext, 'domain_uuid' => $this->domain_uuid], 'row');
		if (!empty($ivr)) {
			$this->build_ivr($ivr['ivr_menu_uuid'], $parent_id, $edge_label, $depth, $ivr);
			return;
		}

		// Ring group
		$sql  = "select * from v_ring_groups ";
		$sql .= "where ring_group_extension = :ext and domain_uuid = :domain_uuid ";
		$sql .= "and ring_group_enabled = 'true' limit 1";
		$rg   = $this->database->select($sql, ['ext' => $ext, 'domain_uuid' => $this->domain_uuid], 'row');
		if (!empty($rg)) {
			$this->build_ring_group($rg['ring_group_uuid'], $parent_id, $edge_label, $depth, $rg);
			return;
		}

		// Call flow
		$sql  = "select * from v_call_flows ";
		$sql .= "where call_flow_extension = :ext and domain_uuid = :domain_uuid ";
		$sql .= "limit 1";
		$cf   = $this->database->select($sql, ['ext' => $ext, 'domain_uuid' => $this->domain_uuid], 'row');
		if (!empty($cf)) {
			$this->build_call_flow($cf['call_flow_uuid'], $parent_id, $edge_label, $depth, $cf);
			return;
		}

		// Time condition
		$sql  = "select * from v_dialplans ";
		$sql .= "where dialplan_number = :ext and domain_uuid = :domain_uuid ";
		$sql .= "and app_uuid = '4b821450-926b-175a-af93-a03c441818b1' ";
		$sql .= "and dialplan_enabled = 'true' limit 1";
		$tc   = $this->database->select($sql, ['ext' => $ext, 'domain_uuid' => $this->domain_uuid], 'row');
		if (!empty($tc)) {
			$this->build_time_condition($tc['dialplan_uuid'], $parent_id, $edge_label, $depth, $tc);
			return;
		}

		// Contact center queue
		$sql  = "select * from v_call_center_queues ";
		$sql .= "where queue_extension = :ext and domain_uuid = :domain_uuid limit 1";
		$cc   = $this->database->select($sql, ['ext' => $ext, 'domain_uuid' => $this->domain_uuid], 'row');
		if (!empty($cc)) {
			$this->build_contact_center($cc['call_center_queue_uuid'], $parent_id, $edge_label, $depth, $cc);
			return;
		}

		// Extension
		$sql  = "select * from v_extensions ";
		$sql .= "where extension = :ext and domain_uuid = :domain_uuid ";
		$sql .= "and enabled = 'true' limit 1";
		$extn = $this->database->select($sql, ['ext' => $ext, 'domain_uuid' => $this->domain_uuid], 'row');
		if (!empty($extn)) {
			$n_id = 'ext_' . $extn['extension_uuid'];
			if (!in_array($n_id, $this->visited)) {
				$this->visited[] = $n_id;
				$name  = $extn['effective_caller_id_name'] ?? '';
				$label = "☎ " . $ext;
				if (!empty($name)) $label .= "\n" . $name;
				$this->add_node($n_id, $label, 'extension', 'Extension: ' . $ext . (!empty($name) ? ' — ' . $name : ''), [], $depth);
			}
			$this->add_edge($parent_id, $n_id, $edge_label);
			return;
		}

		// External / unknown number
		$n_id = 'external_' . $ext . '_' . ($this->node_counter++);
		$this->add_node($n_id, "↗ External\n" . $ext, 'external', '', [], $depth);
		$this->add_edge($parent_id, $n_id, $edge_label);
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Return true/false/null for whether $ext is currently registered.
	 * null = registration status unavailable (no permission or event socket error).
	 * Results are cached for the lifetime of the build() call.
	 */
	private function extension_registered(string $ext): ?bool {
		if (!permission_exists('extension_registered')) return null;

		if ($this->registered_set === null) {
			try {
				$obj = new registrations([
					'database'    => $this->database,
					'domain_uuid' => $this->domain_uuid,
					'domain_name' => $this->domain_name,
				]);
				$list = $obj->get('all');
				$this->registered_set = [];
				if (is_array($list)) {
					foreach ($list as $reg) {
						if (!empty($reg['user'])) {
							$this->registered_set[$reg['user']] = true;
						}
					}
				}
			} catch (\Throwable $e) {
				$this->registered_set = [];
			}
		}

		$key = $ext . '@' . $this->domain_name;
		return isset($this->registered_set[$key]);
	}

	private function add_node(string $id, string $label, string $type, string $title = '', array $extra = [], int $level = 0): void {
		// Avoid duplicate node IDs
		foreach ($this->nodes as $n) {
			if ($n['id'] === $id) return;
		}
		$node = [
			'id'    => $id,
			'label' => $label,
			'type'  => $type,
			'title' => htmlspecialchars($title ?: $label, ENT_QUOTES),
			'level' => $level,
		];
		if (!empty($extra)) {
			$node = array_merge($node, $extra);
		}
		$this->nodes[] = $node;
	}

	private function add_edge(string $from, string $to, string $label = ''): void {
		if (empty($from) || empty($to)) return;
		// Avoid exact duplicate edges
		foreach ($this->edges as $e) {
			if ($e['from'] === $from && $e['to'] === $to && $e['label'] === $label) return;
		}
		$this->edges[] = ['from' => $from, 'to' => $to, 'label' => $label];
	}

	private function add_edge_safe(?string $from, string $to, string $label): void {
		if ($from !== null) {
			$this->add_edge($from, $to, $label);
		}
	}

	/**
	 * Robustly test a boolean-ish DB value as true.
	 * Handles: PHP bool true, strings 'true'/'t'/'1', integer 1.
	 * Explicitly treats 'false', 'f', '0', '', null, false as false.
	 */
	private function is_true(mixed $value): bool {
		if ($value === true || $value === 1) return true;
		if (is_string($value)) {
			return in_array(strtolower(trim($value)), ['true', 't', '1'], true);
		}
		return false;
	}

	private function truncate(string $str, int $max): string {
		return mb_strlen($str) > $max ? mb_substr($str, 0, $max - 1) . '…' : $str;
	}
}

?>

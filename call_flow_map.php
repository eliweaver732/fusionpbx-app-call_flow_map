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

	The Original Code is FusionPBX
	Contributor(s): FusionPBX Team
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!permission_exists('call_flow_map_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//load class
	require_once __DIR__ . "/resources/classes/call_flow_map.php";

//initialize the diagram builder
	$diagram = new call_flow_map([
		'domain_uuid' => $domain_uuid,
		'domain_name' => $_SESSION['domain_name'] ?? '',
		'database'    => $database,
	]);

//get available starting points
	$starting_points = $diagram->get_starting_points();

//handle AJAX data request
	if (!empty($_GET['ajax']) && !empty($_GET['type']) && !empty($_GET['id'])) {
		header('Content-Type: application/json');
		$type = preg_replace('/[^a-z_]/', '', $_GET['type']);
		$uuid = $_GET['id'];
		if (!is_uuid($uuid)) {
			echo json_encode(['error' => 'Invalid UUID']);
			exit;
		}
		$data = $diagram->build($type, $uuid);
		echo json_encode($data);
		exit;
	}

//selected type/uuid/layout from GET
	$selected_type = $_GET['type'] ?? '';
	$selected_uuid = $_GET['id'] ?? '';
	$selected_layout = strtoupper($_GET['layout'] ?? 'UD');

//validate
	if (!empty($selected_type)) {
		$selected_type = preg_replace('/[^a-z_]/', '', $selected_type);
	}
	if (!empty($selected_uuid) && !is_uuid($selected_uuid)) {
		$selected_uuid = '';
	}
	if ($selected_layout !== 'LR') {
		$selected_layout = 'UD';
	}

//pre-load diagram data if both type and uuid are set
	$diagram_json = 'null';
	if (!empty($selected_type) && !empty($selected_uuid)) {
		$flow_data    = $diagram->build($selected_type, $selected_uuid);
		$diagram_json = json_encode($flow_data);
	}

//page title
	$document['title'] = $text['title-call_flow_map'];
	require_once "resources/header.php";

?>

<!-- vis-network from CDN -->
<link  href="https://cdn.jsdelivr.net/npm/vis-network@9.1.9/dist/dist/vis-network.min.css" rel="stylesheet" type="text/css" />
<script src="https://cdn.jsdelivr.net/npm/vis-network@9.1.9/dist/vis-network.min.js"></script>

<style>
	#diagram-container {
		width: 100%;
		min-height: 320px;
		height: 600px; /* fallback until JS measures available viewport */
		border: 1px solid var(--container-border-color, #ccc);
		border-radius: 4px;
		background: var(--input-background-color, #fff);
		position: relative;
	}
	#diagram-placeholder {
		display: flex;
		align-items: center;
		justify-content: center;
		height: 100%;
		color: var(--text-muted-color, #888);
		font-size: 14px;
	}
	.legend-grid {
		display: flex;
		flex-wrap: wrap;
		gap: 8px 18px;
		margin: 10px 0 0 0;
	}
	.legend-item {
		display: flex;
		align-items: center;
		gap: 6px;
		font-size: 12px;
	}
	.legend-dot {
		width: 14px;
		height: 14px;
		border-radius: 3px;
		flex-shrink: 0;
		border: 1px solid rgba(0,0,0,0.15);
	}
	.diagram-toolbar {
		display: flex;
		gap: 8px;
		margin-bottom: 8px;
		align-items: center;
	}
	#diagram-loading {
		display: none;
		position: absolute;
		inset: 0;
		background: rgba(255,255,255,0.7);
		align-items: center;
		justify-content: center;
		font-size: 16px;
		color: #555;
		z-index: 10;
	}
	.layout-toggle {
		display: inline-flex;
		border: 1px solid var(--input-border-color, #ccc);
		border-radius: 4px;
		overflow: hidden;
		background: var(--input-background-color, #fff);
	}
	.layout-toggle-btn {
		appearance: none;
		border: 0;
		background: transparent;
		padding: 6px 12px;
		font-size: 13px;
		line-height: 1.2;
		cursor: pointer;
		color: var(--text-color, #444);
		display: inline-flex;
		align-items: center;
		gap: 6px;
	}
	.layout-toggle-btn + .layout-toggle-btn {
		border-left: 1px solid var(--input-border-color, #ccc);
	}
	.layout-toggle-btn.active {
		background: var(--button-background-color, #1565C0);
		color: var(--button-color, #fff);
	}
	.layout-toggle-btn:focus-visible {
		outline: 2px solid var(--button-background-color, #1565C0);
		outline-offset: -2px;
		z-index: 1;
	}

</style>

<?php

echo modal::create([
	'id'      => 'modal-png-export',
	'type'    => 'general',
	'title'   => $text['label-png_background'] ?? '',
	'actions' => button::create(['type'=>'button','label'=>$text['label-white'] ?? 'White','icon'=>'square','id'=>'btn-png-white','collapse'=>'never','onclick'=>"modal_close(); dodownload_png(true);"]).
	button::create(['type'=>'button','label'=>$text['label-transparent'] ?? 'Transparent','icon'=>'border-all','id'=>'btn-png-transparent','collapse'=>'never','onclick'=>"modal_close(); dodownload_png(false);"])
]);

echo "<div class='action_bar' id='action_bar'>\n";
echo "	<div class='heading'><b>".escape($text['title-call_flow_map'] ?? '')."</b></div>\n";
echo "	<div class='actions'>\n";
echo button::create(['type'=>'button','label'=>$text['label-fit_view'],       'icon'=>'compress-arrows-alt','id'=>'btn-fit','collapse'=>'hide-xs','style'=>'display: none;','onclick'=>'fit_diagram()']);
echo button::create(['type'=>'button','label'=>$text['label-download_png'],'icon'=>'download',           'id'=>'btn-png','collapse'=>'hide-xs','style'=>'display: none;','onclick'=>'download_png()']);
echo "	</div>\n";
echo "	<div style='clear:both;'></div>\n";
echo "</div>\n";

echo escape($text['description-call_flow_map'])."\n";
echo "<br /><br />\n";

echo "<form name='frm' id='picker-form' method='get'>\n";
echo "	<div class='card' style='margin-bottom: 16px;'>\n";

echo "		<div style='display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;'>\n";
echo "			<div>\n";
echo "				<label class='lbl' for='sel-type' style='display:block; margin-bottom:4px;'>".$text['label-starting_type']."</label>\n";
echo "				<select id='sel-type' name='type' class='formfld' style='min-width:170px;' onchange='populateDestinations(this.value);'>\n";
echo "					<option value=''>-- select type --</option>\n";
$types = [
	'inbound'        => $text['label-inbound_routes'],
	'ivr'            => $text['label-ivr_menus'],
	'ring_group'     => $text['label-ring_groups'],
	'call_flow'      => $text['label-call_flows'],
	'time_condition' => $text['label-time_conditions'],
	'extension'      => $text['label-extensions'],
	'contact_center' => $text['label-contact_centers'],
];
foreach ($types as $tkey => $tlabel) {
	$selected = ($selected_type === $tkey) ? ' selected' : '';
	echo "				<option value='".escape($tkey)."' $selected>".escape($tlabel)."</option>\n";
}
echo "				</select>\n";
echo "			</div>\n";

echo "			<div>\n";
echo "				<label class='lbl' for='sel-uuid' style='display:block; margin-bottom:4px;'>".$text['label-starting_destination']."</label>\n";
echo "				<select id='sel-uuid' name='id' class='formfld' style='min-width:280px;'>\n";
echo "					<option value=''>-- select destination --</option>\n";
// Pre-populate if type is already selected
if (!empty($selected_type) && !empty($starting_points[$selected_type])) {
	foreach ($starting_points[$selected_type] as $sp) {
		$selected2 = ($selected_uuid === $sp['uuid']) ? ' selected' : '';
		echo "<option value='".escape($sp['uuid'])."' $selected2>".escape($sp['label'])."</option>\n";

	}
}
echo "				</select>\n";
echo "			</div>\n";

echo "			<div>\n";
echo "				<label class='lbl' style='display:block; margin-bottom:4px;'>".($text['label-layout'] ?? 'Layout')."</label>\n";
echo "				<div class='layout-toggle' id='layout-toggle' role='group' aria-label='".escape($text['label-layout'] ?? 'Layout')."'>\n";
echo "					<button type='button' class='layout-toggle-btn".($selected_layout === 'UD' ? ' active' : '')."' data-layout='UD' onclick='set_layout(\"UD\");' title='".escape($text['label-layout_compact'] ?? $text['label-layout_top_down'] ?? 'Compact')."'>\n";
echo "						<i class='fas fa-bars'></i> ".escape($text['label-layout_compact'] ?? 'Compact')."\n";
echo "					</button>\n";
echo "					<button type='button' class='layout-toggle-btn".($selected_layout === 'LR' ? ' active' : '')."' data-layout='LR' onclick='set_layout(\"LR\");' title='".escape($text['label-layout_spread'] ?? $text['label-layout_left_right'] ?? 'Spread')."'>\n";
echo "						<i class='fas fa-grip-lines-vertical'></i> ".escape($text['label-layout_spread'] ?? 'Spread')."\n";
echo "					</button>\n";
echo "				</div>\n";
echo "				<input type='hidden' name='layout' id='sel-layout' value='".escape($selected_layout)."' />\n";
echo "			</div>\n";

echo "			<div>\n";
echo button::create(['type'=>'submit','label'=>$text['button-generate'],'icon'=>'project-diagram']);
echo "			</div>\n";
echo "		</div>\n";

echo "	</div>\n";
echo "</form>\n";

// Diagram area
echo "<div class='card'>\n";
echo "	<div style='padding: 10px 16px 6px;'>\n";
echo "		<div class='legend-grid'>\n";
$legend = [
	['type' => 'inbound',        'bg' => '#BBDEFB', 'border' => '#1565C0', 'label' => 'Inbound Route'],
	['type' => 'ivr',            'bg' => '#FFE0B2', 'border' => '#BF360C', 'label' => 'IVR Menu'],
	['type' => 'ring_group',     'bg' => '#C8E6C9', 'border' => '#1B5E20', 'label' => 'Ring Group'],
	['type' => 'extension',      'bg' => '#B2EBF2', 'border' => '#006064', 'label' => 'Extension'],
	['type' => 'call_flow',      'bg' => '#B3E5FC', 'border' => '#01579B', 'label' => 'Call Flow'],
	['type' => 'time_condition', 'bg' => '#FFF9C4', 'border' => '#F57F17', 'label' => 'Time Condition'],
	['type' => 'contact_center', 'bg' => '#DCEDC8', 'border' => '#33691E', 'label' => 'Contact Center'],
	['type' => 'voicemail',      'bg' => '#E1BEE7', 'border' => '#4A148C', 'label' => 'Voicemail'],
	['type' => 'hangup',         'bg' => '#FFCDD2', 'border' => '#B71C1C', 'label' => 'Hangup'],
	['type' => 'external',       'bg' => '#E0E0E0', 'border' => '#424242', 'label' => 'External'],
];
foreach ($legend as $leg) {
	echo "			<div class='legend-item'>\n";
	echo "				<div class='legend-dot' style='background:".escape($leg['bg'])."; border-color:".escape($leg['border']).";'></div>\n";
	echo "				<span>".escape($leg['label'])."</span>\n";
	echo "			</div>\n";
}
echo "		</div>\n";
echo "	</div>\n"; // padding div

echo "	<div style='padding: 0 16px 8px;'>\n";
echo "		<div id='diagram-container'>\n";
echo "			<div id='diagram-loading'><i class='fas fa-circle-notch fa-spin'></i>&nbsp; Building diagram…</div>\n";
echo "			<div id='diagram-placeholder'>".escape($text['message-select_destination'] ?? 'Select destination')."</div>\n";
echo "		</div>\n";
echo "	</div>\n";
echo "</div>\n";

echo "</form>\n";

?>

<script>
// Starting points data (for dynamic population of destination select)
var starting_points = <?php echo json_encode($starting_points); ?>;

// Node style map (body fill / border / titlebar / footer / option row)
var node_styles = {
	inbound:        { background: '#BBDEFB', border: '#1565C0', titlebar: '#90CAF9', footer: '#A8D0F0', row: '#90CAF9', font: '#0D47A1' },
	ivr:            { background: '#FFE0B2', border: '#BF360C', titlebar: '#FFB74D', footer: '#F5C896', row: '#FFB74D', font: '#BF360C' },
	ring_group:     { background: '#C8E6C9', border: '#1B5E20', titlebar: '#81C784', footer: '#A5D6A7', row: '#81C784', font: '#1B5E20' },
	extension:      { background: '#B2EBF2', border: '#006064', titlebar: '#4DD0E1', footer: '#80DEEA', row: '#4DD0E1', font: '#006064' },
	call_flow:      { background: '#B3E5FC', border: '#01579B', titlebar: '#4FC3F7', footer: '#81D4FA', row: '#4FC3F7', font: '#01579B' },
	time_condition: { background: '#FFF9C4', border: '#F57F17', titlebar: '#FFF176', footer: '#F0E68C', row: '#FFE082', font: '#E65100' },
	contact_center: { background: '#DCEDC8', border: '#33691E', titlebar: '#AED581', footer: '#C5E1A5', row: '#AED581', font: '#1B5E20' },
	voicemail:      { background: '#E1BEE7', border: '#6A1B9A', titlebar: '#CE93D8', footer: '#D1A7DB', row: '#CE93D8', font: '#4A148C' },
	hangup:         { background: '#FFCDD2', border: '#B71C1C', titlebar: '#EF9A9A', footer: '#F0B0B0', row: '#EF9A9A', font: '#B71C1C' },
	recording:      { background: '#F5F5F5', border: '#616161', titlebar: '#E0E0E0', footer: '#EEEEEE', row: '#E0E0E0', font: '#424242' },
	tone:           { background: '#F5F5F5', border: '#616161', titlebar: '#E0E0E0', footer: '#EEEEEE', row: '#E0E0E0', font: '#424242' },
	external:       { background: '#F5F5F5', border: '#616161', titlebar: '#E0E0E0', footer: '#EEEEEE', row: '#E0E0E0', font: '#424242' },
};

var CARD_LAYOUT = {
	width: 300,
	titleH: 32,
	nameH: 20,
	padX: 12,
	padTop: 6,
	padBottom: 6,
	rowH: 24,
	rowGap: 3,
	sectionLineH: 18,
	sectionPad: 4,
	footerH: 30,
	radius: 5,
	portR: 5,
	eyeSize: 12,
	editSize: 12,
	titleFont: 'bold 14px Arial',
	nameFont: '13px Arial',
	bodyFont: '13px Arial',
};

// Color legend (same items as the web UI strip above the diagram)
var legend_items = <?php echo json_encode($legend); ?>;

var network = null;
var diagram_resize_timer = null;
var port_parent = {}; // portId -> parent card id
var card_ports = {};  // cardId -> [port ids]

function card_style_for(type) {
	return node_styles[type] || node_styles['external'];
}

/** Banded list rows: IVR options, RG members, CC agents, etc. */
function is_banded_row(item) {
	return item && (item.type === 'row' || item.type === 'item');
}

function measure_card(card) {
	var L = CARD_LAYOUT;
	var w = L.width;
	var h = L.titleH + L.nameH + L.padTop;
	// x is half-width magnitude; glue_ports places ingress on left (-), egress on right (+)
	var ports = { in: { x: w / 2, y: 0 } };

	var cursor = L.titleH + L.nameH + L.padTop;
	var body = (card && card.body) ? card.body : [];
	body.forEach(function(item, idx) {
		if (item.type === 'section') {
			cursor += L.sectionPad;
			var lines = item.lines || [];
			var sectionTop = cursor;
			lines.forEach(function() { cursor += L.sectionLineH; });
			var sectionH = Math.max(L.sectionLineH, cursor - sectionTop);
			if (item.port) {
				ports[item.port] = { x: w / 2, yTop: sectionTop + sectionH / 2 };
			}
			cursor += L.sectionPad;
		} else {
			if (item.port) {
				ports[item.port] = { x: w / 2, yTop: cursor + L.rowH / 2 };
			}
			cursor += L.rowH;
			if (is_banded_row(item) && idx < body.length - 1 && is_banded_row(body[idx + 1])) {
				cursor += L.rowGap;
			}
		}
	});
	cursor += L.padBottom;
	if (card && card.timeout) {
		var tPort = card.timeout.port || 'timeout';
		ports[tPort] = { x: w / 2, yTop: cursor + L.footerH / 2 };
		cursor += L.footerH;
	}
	h = Math.max(cursor, L.titleH + L.nameH + 20);
	Object.keys(ports).forEach(function(pid) {
		if (ports[pid].yTop !== undefined) {
			ports[pid].y = ports[pid].yTop - h / 2;
			delete ports[pid].yTop;
		} else if (pid === 'in') {
			ports[pid].y = 0;
		}
	});
	return { width: w, height: h, ports: ports };
}

function draw_eye_icon(ctx, cx, cy, size, collapsed, color) {
	ctx.save();
	ctx.strokeStyle = color;
	ctx.fillStyle = color;
	ctx.lineWidth = 1.4;
	ctx.lineCap = 'round';
	// Almond outline
	ctx.beginPath();
	ctx.moveTo(cx - size * 0.5, cy);
	ctx.quadraticCurveTo(cx, cy - size * 0.42, cx + size * 0.5, cy);
	ctx.quadraticCurveTo(cx, cy + size * 0.42, cx - size * 0.5, cy);
	ctx.closePath();
	ctx.stroke();
	// Pupil
	ctx.beginPath();
	ctx.arc(cx, cy, size * 0.16, 0, Math.PI * 2);
	ctx.fill();
	if (collapsed) {
		ctx.beginPath();
		ctx.moveTo(cx - size * 0.52, cy + size * 0.42);
		ctx.lineTo(cx + size * 0.52, cy - size * 0.42);
		ctx.stroke();
	}
	ctx.restore();
}

function draw_edit_icon(ctx, cx, cy, size, color) {
	ctx.save();
	ctx.strokeStyle = color;
	ctx.fillStyle = color;
	ctx.lineWidth = 1.4;
	ctx.lineCap = 'round';
	ctx.lineJoin = 'round';
	var s = size * 0.42;
	// Pencil body (diagonal)
	ctx.beginPath();
	ctx.moveTo(cx - s, cy + s * 0.35);
	ctx.lineTo(cx + s * 0.25, cy - s * 0.9);
	ctx.lineTo(cx + s * 0.55, cy - s * 0.6);
	ctx.lineTo(cx - s * 0.7, cy + s * 0.65);
	ctx.closePath();
	ctx.stroke();
	// Tip
	ctx.beginPath();
	ctx.moveTo(cx - s, cy + s * 0.35);
	ctx.lineTo(cx - s * 0.7, cy + s * 0.65);
	ctx.lineTo(cx - s * 1.05, cy + s * 0.85);
	ctx.closePath();
	ctx.stroke();
	// Cap line
	ctx.beginPath();
	ctx.moveTo(cx + s * 0.05, cy - s * 0.7);
	ctx.lineTo(cx + s * 0.35, cy - s * 0.4);
	ctx.stroke();
	ctx.restore();
}

function node_canvas_pos(node) {
	var nx = node.x || 0;
	var ny = node.y || 0;
	if (network) {
		try {
			var pos = network.getPositions([node.id])[node.id];
			if (pos) { nx = pos.x; ny = pos.y; }
		} catch (err) { /* ignore */ }
	}
	return { x: nx, y: ny };
}

function hit_box_test(hit, node, canvasPt) {
	if (!hit) return false;
	var pos = node_canvas_pos(node);
	var lx = canvasPt.x - pos.x;
	var ly = canvasPt.y - pos.y;
	return lx >= hit.l && lx <= hit.r && ly >= hit.t && ly <= hit.b;
}

/** @returns {string|null} egress port name if the eye was hit */
function eye_hit_test(node, canvasPt) {
	if (!node || node._path_hidden || !node._eye_hits || !node._eye_hits.length) return null;
	var pos = node_canvas_pos(node);
	var lx = canvasPt.x - pos.x;
	var ly = canvasPt.y - pos.y;
	for (var i = 0; i < node._eye_hits.length; i++) {
		var hit = node._eye_hits[i];
		if (lx >= hit.l && lx <= hit.r && ly >= hit.t && ly <= hit.b) {
			return hit.port;
		}
	}
	return null;
}

function edit_hit_test(node, canvasPt) {
	if (!node || node._path_hidden || !node._edit_hit || !node._can_edit) return false;
	return hit_box_test(node._edit_hit, node, canvasPt);
}

function resolve_edit_url(node) {
	if (!node) return null;
	return node.edit_url || node_edit_url(node.id) || null;
}

function make_card_ctx_renderer(node) {
	var card = node.card || { title: node.type || 'App', icon: '', name: node.label || '', body: [] };
	var colors = card_style_for(node.type);
	var dims = measure_card(card);
	// stash for port positioning
	node._card_dims = dims;

	return function(args) {
		var ctx = args.ctx;
		var x = args.x;
		var y = args.y;
		var selected = args.state && args.state.selected;
		var L = CARD_LAYOUT;
		var w = dims.width;
		var h = dims.height;
		var left = x - w / 2;
		var top = y - h / 2;
		var muted = node._muted === true;
		var eyeReserve = L.eyeSize + 10;
		var editReserve = node._can_edit ? (L.editSize + 10) : 0;
		var collapsedPorts = node._collapsed_ports || {};
		var egressPorts = node._egress_ports || {};

		function round_rect(rx, ry, rw, rh, r) {
			ctx.beginPath();
			if (ctx.roundRect) {
				ctx.roundRect(rx, ry, rw, rh, r);
			} else {
				ctx.rect(rx, ry, rw, rh);
			}
		}

		function draw_row_eye(portName, eyeCy, color) {
			if (!egressPorts[portName] || node._path_hidden) return;
			var eyeCx = left + w - L.padX - L.eyeSize / 2;
			var half = L.eyeSize / 2 + 3;
			node._eye_hits.push({
				port: portName,
				l: (eyeCx - half) - x,
				r: (eyeCx + half) - x,
				t: (eyeCy - half) - y,
				b: (eyeCy + half) - y,
			});
			draw_eye_icon(ctx, eyeCx, eyeCy, L.eyeSize, !!collapsedPorts[portName], color);
		}

		return {
			drawNode: function() {
				ctx.save();
				if (muted) ctx.globalAlpha = 0.22;
				node._eye_hits = [];

				// Shadow
				ctx.shadowColor = 'rgba(0,0,0,0.15)';
				ctx.shadowBlur = 4;
				ctx.shadowOffsetX = 2;
				ctx.shadowOffsetY = 2;

				// Body fill
				round_rect(left, top, w, h, L.radius);
				ctx.fillStyle = muted ? '#E8E8E8' : colors.background;
				ctx.fill();
				ctx.shadowColor = 'transparent';

				// Titlebar
				ctx.save();
				round_rect(left, top, w, h, L.radius);
				ctx.clip();
				ctx.fillStyle = muted ? '#C0C0C0' : colors.titlebar;
				ctx.fillRect(left, top, w, L.titleH + L.nameH);
				ctx.restore();

				// Footer
				if (card.timeout) {
					ctx.save();
					round_rect(left, top, w, h, L.radius);
					ctx.clip();
					ctx.fillStyle = muted ? '#D0D0D0' : colors.footer;
					ctx.fillRect(left, top + h - L.footerH, w, L.footerH);
					ctx.restore();
				}

				var textColor = muted ? '#9E9E9E' : colors.font;
				var rowBg = muted ? '#D0D0D0' : (colors.row || colors.titlebar);
				var bodyItems = card.body || [];

				// Body (banded option/member rows with small gaps)
				var cursor = top + L.titleH + L.nameH + L.padTop;
				ctx.font = L.bodyFont;
				ctx.textBaseline = 'middle';
				bodyItems.forEach(function(item, idx) {
					if (item.type === 'section') {
						cursor += L.sectionPad;
						var lines = item.lines || [];
						var sectionTop = cursor;
						var sectionH = Math.max(L.sectionLineH, lines.length * L.sectionLineH);
						if (item.port) {
							ctx.fillStyle = rowBg;
							ctx.fillRect(left + 1, sectionTop, w - 2, sectionH);
						}
						ctx.fillStyle = textColor;
						var textMax = w - L.padX * 2 - (item.port && egressPorts[item.port] ? eyeReserve : 12);
						lines.forEach(function(line) {
							ctx.fillText(line, left + L.padX, cursor + L.sectionLineH / 2, textMax);
							cursor += L.sectionLineH;
						});
						if (item.port) {
							draw_row_eye(item.port, sectionTop + sectionH / 2, textColor);
						}
						cursor += L.sectionPad;
					} else {
						if (is_banded_row(item)) {
							ctx.fillStyle = rowBg;
							ctx.fillRect(left + 1, cursor, w - 2, L.rowH);
						}
						ctx.fillStyle = textColor;
						var rowMax = w - L.padX * 2 - (item.port && egressPorts[item.port] ? eyeReserve : 12);
						ctx.fillText(item.text || '', left + L.padX, cursor + L.rowH / 2, rowMax);
						if (item.port) {
							draw_row_eye(item.port, cursor + L.rowH / 2, textColor);
						}
						cursor += L.rowH;
						if (is_banded_row(item) && idx < bodyItems.length - 1 && is_banded_row(bodyItems[idx + 1])) {
							cursor += L.rowGap;
						}
					}
				});

				// Timeout footer label + eye
				if (card.timeout) {
					var tPort = card.timeout.port || 'timeout';
					ctx.font = L.bodyFont;
					ctx.fillStyle = textColor;
					ctx.fillText(
						card.timeout.label || '',
						left + L.padX,
						top + h - L.footerH / 2,
						w - L.padX * 2 - (egressPorts[tPort] ? eyeReserve : 12)
					);
					draw_row_eye(tPort, top + h - L.footerH / 2, textColor);
				}

				// Border
				round_rect(left, top, w, h, L.radius);
				ctx.strokeStyle = muted ? '#C0C0C0' : colors.border;
				ctx.lineWidth = selected ? 3 : 2;
				ctx.stroke();

				// Titlebar text (leave room for edit icon)
				ctx.fillStyle = textColor;
				ctx.textBaseline = 'middle';
				ctx.font = L.titleFont;
				var titleText = ((card.icon ? card.icon + ' ' : '') + (card.title || '')).trim();
				ctx.fillText(titleText, left + L.padX, top + L.titleH / 2, w - L.padX * 2 - editReserve);

				ctx.font = L.nameFont;
				ctx.fillText(card.name || '', left + L.padX, top + L.titleH + L.nameH / 2, w - L.padX * 2 - editReserve);

				// Edit affordance — top-right of titlebar (double-click card or click icon)
				if (node._can_edit && !node._path_hidden) {
					var editCx = left + w - L.padX - L.editSize / 2;
					var editCy = top + L.titleH / 2;
					var editHalf = L.editSize / 2 + 3;
					node._edit_hit = {
						l: (editCx - editHalf) - x,
						r: (editCx + editHalf) - x,
						t: (editCy - editHalf) - y,
						b: (editCy + editHalf) - y,
					};
					draw_edit_icon(ctx, editCx, editCy, L.editSize, textColor);
				} else {
					node._edit_hit = null;
				}

				// Synthetic / unlabeled egress ports (e.g. out_e0) — eye just inside right edge
				Object.keys(egressPorts).forEach(function(pname) {
					if (pname.indexOf('out_') !== 0) return;
					var base = dims.ports[pname];
					var eyeCy = base ? (y + (base.y || 0)) : y;
					draw_row_eye(pname, eyeCy, textColor);
				});

				ctx.restore();
			},
			nodeDimensions: { width: w, height: h },
		};
	};
}

function is_port_id(id) {
	return typeof id === 'string' && id.indexOf('::') !== -1;
}

function port_id(cardId, port) {
	return cardId + '::' + port;
}

function port_name_from_id(pid) {
	var idx = pid.indexOf('::');
	return idx === -1 ? '' : pid.slice(idx + 2);
}

function card_id_from_port(pid) {
	var idx = pid.indexOf('::');
	return idx === -1 ? pid : pid.slice(0, idx);
}

function is_ingress_port(pname) {
	return pname === 'in' || pname.indexOf('in_') === 0;
}

function is_egress_port(pname) {
	return !is_ingress_port(pname);
}

function find_card(card_nodes, cardId) {
	for (var ci = 0; ci < card_nodes.length; ci++) {
		if (card_nodes[ci].id === cardId) return card_nodes[ci];
	}
	return null;
}

function make_invisible_port(pid, parentId, pname, x, y) {
	return {
		id: pid,
		x: x,
		y: y,
		shape: 'dot',
		size: 1,
		color: { background: 'rgba(0,0,0,0)', border: 'rgba(0,0,0,0)' },
		borderWidth: 0,
		physics: false,
		fixed: { x: true, y: true },
		chosen: false,
		label: undefined,
		title: undefined,
		opacity: 0,
		_is_port: true,
		_parent: parentId,
		_port: pname,
	};
}

function build_port_nodes(card_nodes, edges) {
	var ports = [];
	port_parent = {};
	card_ports = {};

	card_nodes.forEach(function(n) {
		var dims = n._card_dims || measure_card(n.card || {});
		n._card_dims = dims;
		card_ports[n.id] = [];
		Object.keys(dims.ports).forEach(function(pname) {
			if (pname === 'in') return; // per-edge ingress created below
			var pid = port_id(n.id, pname);
			card_ports[n.id].push(pid);
			port_parent[pid] = n.id;
			ports.push(make_invisible_port(
				pid, n.id, pname,
				(n.x || 0) + dims.width / 2, // always right
				(n.y || 0) + (dims.ports[pname].y || 0)
			));
		});
	});

	// Per-edge ingress (left) and default egress (right) when no row port was set
	(edges || []).forEach(function(e, ei) {
		var eid = e.id || ('e' + ei);
		var fromCardId = e._from_card || e.from;
		var toCardId = e._to_card || e.to;

		// Ingress on target — always left-center
		var inName = 'in_' + eid;
		var inPid = port_id(toCardId, inName);
		if (!port_parent[inPid]) {
			var toCard = find_card(card_nodes, toCardId);
			if (toCard) {
				var toDims = toCard._card_dims || measure_card(toCard.card || {});
				card_ports[toCardId] = card_ports[toCardId] || [];
				card_ports[toCardId].push(inPid);
				port_parent[inPid] = toCardId;
				ports.push(make_invisible_port(
					inPid, toCardId, inName,
					(toCard.x || 0) - toDims.width / 2,
					(toCard.y || 0)
				));
			}
		}

		// Default egress on source when edge has no explicit from_port — always right-center
		var has_from_port = !!(e.from_port);
		if (!has_from_port) {
			var outName = 'out_' + eid;
			var outPid = port_id(fromCardId, outName);
			if (!port_parent[outPid]) {
				var fromCard = find_card(card_nodes, fromCardId);
				if (fromCard) {
					var fromDims = fromCard._card_dims || measure_card(fromCard.card || {});
					card_ports[fromCardId] = card_ports[fromCardId] || [];
					card_ports[fromCardId].push(outPid);
					port_parent[outPid] = fromCardId;
					ports.push(make_invisible_port(
						outPid, fromCardId, outName,
						(fromCard.x || 0) + fromDims.width / 2,
						(fromCard.y || 0)
					));
				}
			}
		}
	});

	return ports;
}

/**
 * Fixed sides: exits always on the right, ingress always on the left.
 * Fast path: moveNode only (no DataSet churn during drag).
 */
function glue_ports_to_cards(card_nodes) {
	if (!network || !card_nodes || !card_nodes.length) return;
	var positions = network.getPositions();

	card_nodes.forEach(function(n) {
		var pos = positions[n.id];
		if (!pos) return;
		var dims = n._card_dims || measure_card(n.card || {});
		var halfW = dims.width / 2;

		(card_ports[n.id] || []).forEach(function(pid) {
			var pname = port_name_from_id(pid);
			var yOff = 0;
			var side;

			if (is_ingress_port(pname)) {
				yOff = 0;
				side = -1; // left
			} else {
				var base = dims.ports[pname];
				yOff = base ? base.y : 0;
				side = 1; // right
			}

			try {
				network.moveNode(pid, pos.x + side * halfW, pos.y + yOff);
			} catch (err) { /* port may not exist yet */ }
		});
	});
}

/**
 * Waypoints stub out from egress and into ingress so arrows always point
 * rightward, and reverse/loop-back edges get a clean exit before turning.
 */
var WAYPOINT_GAP = 30;

function is_waypoint_id(id) {
	return typeof id === 'string' && id.indexOf('wp::') === 0;
}

function waypoint_ids(edgeId) {
	return {
		out: 'wp::out::' + edgeId,
		in:  'wp::in::'  + edgeId,
	};
}

function edge_segment_ids(edgeId) {
	return [edgeId + '::a', edgeId + '::b', edgeId + '::c'];
}

function make_waypoint_node(id, x, y) {
	return {
		id: id,
		x: x,
		y: y,
		shape: 'dot',
		size: 1,
		color: { background: 'rgba(0,0,0,0)', border: 'rgba(0,0,0,0)' },
		borderWidth: 0,
		physics: false,
		fixed: { x: true, y: true },
		chosen: false,
		opacity: 0,
		_is_port: true,
		_is_waypoint: true,
	};
}

function build_waypoint_nodes(free_nodes, logical_edges) {
	var card_map = {};
	free_nodes.forEach(function(n) { card_map[n.id] = n; });
	var nodes = [];

	logical_edges.forEach(function(e) {
		var fromCard = card_map[e._from_card];
		var toCard = card_map[e._to_card];
		if (!fromCard || !toCard) return;

		var fromHalf = (fromCard._card_dims && fromCard._card_dims.width)
			? fromCard._card_dims.width / 2
			: CARD_LAYOUT.width / 2;
		var toHalf = (toCard._card_dims && toCard._card_dims.width)
			? toCard._card_dims.width / 2
			: CARD_LAYOUT.width / 2;

		var outY = fromCard.y || 0;
		if (e.from_port && fromCard._card_dims && fromCard._card_dims.ports[e.from_port]) {
			outY += fromCard._card_dims.ports[e.from_port].y || 0;
		}
		var inY = toCard.y || 0;

		var ids = waypoint_ids(e.id);
		nodes.push(make_waypoint_node(
			ids.out,
			(fromCard.x || 0) + fromHalf + WAYPOINT_GAP,
			outY
		));
		nodes.push(make_waypoint_node(
			ids.in,
			(toCard.x || 0) - toHalf - WAYPOINT_GAP,
			inY
		));
	});
	return nodes;
}

function split_edges_via_waypoints(logical_edges) {
	var segs = [];
	logical_edges.forEach(function(e) {
		var ids = waypoint_ids(e.id);
		var style = {
			font: e.font,
			color: e.color,
			width: e.width,
			smooth: { type: 'cubicBezier', forceDirection: 'horizontal', roundness: 0.35 },
			_logical: e.id,
		};
		// Egress stub — leaves to the right of the source
		segs.push(Object.assign({}, style, {
			id: e.id + '::a',
			from: e.from,
			to: ids.out,
			label: e.label || '',
			arrows: { to: { enabled: false } },
		}));
		// Mid span between egress stub and ingress stub
		segs.push(Object.assign({}, style, {
			id: e.id + '::b',
			from: ids.out,
			to: ids.in,
			label: '',
			arrows: { to: { enabled: false } },
		}));
		// Ingress stub — always approaches from the left into the target
		segs.push(Object.assign({}, style, {
			id: e.id + '::c',
			from: ids.in,
			to: e.to,
			label: '',
			arrows: { to: { enabled: true, scaleFactor: 0.7, type: 'arrow' } },
		}));
	});
	return segs;
}

function move_waypoints(free_nodes, logical_edges) {
	if (!network) return;
	var positions = network.getPositions();

	logical_edges.forEach(function(e) {
		var ids = waypoint_ids(e.id);
		var egressPos = positions[e.from];
		var ingressPos = positions[e.to];
		try {
			if (egressPos) {
				network.moveNode(ids.out, egressPos.x + WAYPOINT_GAP, egressPos.y);
			}
			if (ingressPos) {
				network.moveNode(ids.in, ingressPos.x - WAYPOINT_GAP, ingressPos.y);
			}
		} catch (err) { /* ignore */ }
	});
}

function refresh_ports_and_routes(free_nodes, logical_edges, dragged_cards) {
	if (dragged_cards && dragged_cards.length) {
		glue_ports_to_cards(dragged_cards);
	} else {
		glue_ports_to_cards(free_nodes);
	}
	move_waypoints(free_nodes, logical_edges);
}

function rewire_edges_to_ports(edges, fallback_map) {
	return edges.map(function(e, i) {
		var eid = e.id || ('e' + i);
		var from_port = e.from_port || '';
		// Always leave via a right-side egress port
		var from = from_port
			? port_id(e.from, from_port)
			: port_id(e.from, 'out_' + eid);
		// Always enter via a left-side ingress port
		var to = port_id(e.to, 'in_' + eid);

		if (fallback_map && !fallback_map[from]) from = e.from;
		if (fallback_map && !fallback_map[to]) to = e.to;

		var label = e.label || '';
		if (from_port && (from_port.indexOf('opt_') === 0 || from_port === 'timeout' || from_port.indexOf('action_') === 0 || from_port.indexOf('ring_') === 0 || from_port === 'primary' || from_port === 'alternate')) {
			label = '';
		}

		return Object.assign({}, e, {
			id: eid,
			from: from,
			to: to,
			label: label,
			_from_card: e.from,
			_to_card: e.to,
		});
	});
}

function draw_port_connectors(ctx, free_nodes, edges) {
	if (!network) return;
	var used = {};
	(edges || []).forEach(function(e) {
		if (e._path_hidden) return;
		used[e.from] = true;
		used[e.to] = true;
	});
	var positions = network.getPositions();
	var L = CARD_LAYOUT;
	free_nodes.forEach(function(n) {
		if (n._muted || n._path_hidden) return;
		var colors = card_style_for(n.type);
		(card_ports[n.id] || []).forEach(function(pid) {
			if (!used[pid]) return;
			var pname = port_name_from_id(pid);
			var p = positions[pid];
			if (!p) return;
			ctx.beginPath();
			ctx.arc(p.x, p.y, L.portR, 0, Math.PI * 2);
			if (is_ingress_port(pname)) {
				ctx.fillStyle = '#ffffff';
				ctx.fill();
				ctx.lineWidth = 2;
				ctx.strokeStyle = colors.border;
				ctx.stroke();
			} else {
				ctx.fillStyle = colors.border;
				ctx.fill();
				ctx.lineWidth = 1.5;
				ctx.strokeStyle = '#ffffff';
				ctx.stroke();
			}
		});
	});
}

/**
 * Spread card positions into columns/rows so the graph fills ~fillRatio of the viewport.
 * Uses independent X/Y scales so both axes can reach the target coverage.
 */
function spread_nodes_to_viewport(nodes, container, fillRatio) {
	fillRatio = (fillRatio == null) ? 0.8 : fillRatio;
	if (!nodes || !nodes.length || !container) return;

	var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
	nodes.forEach(function(n) {
		var dims = n._card_dims || measure_card(n.card || {});
		var hw = dims.width / 2;
		var hh = dims.height / 2;
		minX = Math.min(minX, n.x - hw);
		maxX = Math.max(maxX, n.x + hw);
		minY = Math.min(minY, n.y - hh);
		maxY = Math.max(maxY, n.y + hh);
	});

	var bw = Math.max(maxX - minX, 1);
	var bh = Math.max(maxY - minY, 1);
	var vw = Math.max(container.clientWidth || 0, 400);
	var vh = Math.max(container.clientHeight || 0, 320);
	var targetW = vw * fillRatio;
	var targetH = vh * fillRatio;

	// Expand only (never compress a large graph); stretch axes independently
	var scaleX = Math.max(targetW / bw, 1);
	var scaleY = Math.max(targetH / bh, 1);

	var cx = (minX + maxX) / 2;
	var cy = (minY + maxY) / 2;
	nodes.forEach(function(n) {
		n.x = (n.x - cx) * scaleX;
		n.y = (n.y - cy) * scaleY;
	});
}

// Fill remaining viewport height while respecting FusionPBX chrome/padding.
function size_diagram_container() {
	var el = document.getElementById('diagram-container');
	if (!el) return;

	var top = el.getBoundingClientRect().top;
	var bottom_gap = 0;

	// Card wrapper padding below the diagram
	var wrapper = el.parentElement;
	if (wrapper) {
		var wrapper_style = window.getComputedStyle(wrapper);
		bottom_gap += parseFloat(wrapper_style.paddingBottom) || 0;
		bottom_gap += parseFloat(wrapper_style.marginBottom) || 0;

		var card = wrapper.parentElement;
		if (card) {
			var card_style = window.getComputedStyle(card);
			bottom_gap += parseFloat(card_style.paddingBottom) || 0;
			bottom_gap += parseFloat(card_style.marginBottom) || 0;
		}
	}

	// Theme #main_content bottom padding (keeps FusionPBX page margins)
	var main = document.getElementById('main_content');
	if (main) {
		bottom_gap += parseFloat(window.getComputedStyle(main).paddingBottom) || 0;
	}
	else {
		bottom_gap += 16;
	}

	var height = Math.max(320, Math.floor(window.innerHeight - top - bottom_gap));
	el.style.height = height + 'px';

	if (network) {
		network.setSize(el.clientWidth + 'px', height + 'px');
	}
}

function on_diagram_resize() {
	clearTimeout(diagram_resize_timer);
	diagram_resize_timer = setTimeout(size_diagram_container, 100);
}

window.addEventListener('resize', on_diagram_resize);
if (typeof $ !== 'undefined') {
	$(window).on('resizeEnd', size_diagram_container);
}
document.addEventListener('DOMContentLoaded', size_diagram_container);

// Update layout toggle selection (persisted via hidden form field)
function set_layout(layout) {
	layout = (layout === 'LR') ? 'LR' : 'UD';
	document.getElementById('sel-layout').value = layout;
	document.querySelectorAll('.layout-toggle-btn').forEach(function(btn) {
		btn.classList.toggle('active', btn.getAttribute('data-layout') === layout);
	});
}

// Populate destination dropdown when type changes
function populateDestinations(type) {
	var sel = document.getElementById('sel-uuid');
	sel.innerHTML = '<option value="">-- select destination --</option>';
	if (!type || !starting_points[type]) return;
	starting_points[type].forEach(function(item) {
		var opt = document.createElement('option');
		opt.value = item.uuid;
		opt.textContent = item.label;
		sel.appendChild(opt);
	});
}

// Build diagram from JSON data
function render_diagram(data) {
	var placeholder  = document.getElementById('diagram-placeholder');
	var loading_element    = document.getElementById('diagram-loading');
	var container    = document.getElementById('diagram-container');
	placeholder.style.display = 'none';
	document.getElementById('btn-fit').style.display = 'none';
	document.getElementById('btn-png').style.display = 'none';
	size_diagram_container();

	if (!data || !data.nodes || data.nodes.length === 0) {
		placeholder.textContent = <?php echo json_encode($text['message-no_data']); ?>;
		placeholder.style.display = 'flex';
		return;
	}

	// Always flow left → right; toggle only changes stacking density.
	// Separations must exceed card size or columns/rows overlap.
	var cardW = CARD_LAYOUT.width;
	var cardH = CARD_LAYOUT.titleH + CARD_LAYOUT.nameH + CARD_LAYOUT.footerH + 80;
	var stack_mode = (document.getElementById('sel-layout').value === 'LR') ? 'spread' : 'compact';
	var stack = (stack_mode === 'spread')
		? { levelSeparation: cardW + 160, nodeSpacing: cardH + 40, treeSpacing: cardH + 60, nodeDistance: cardH + 80 }
		: { levelSeparation: cardW + 100, nodeSpacing: cardH + 16, treeSpacing: cardH + 30, nodeDistance: cardH + 40 };

	var styled_nodes = data.nodes.map(function(n) {
		var colors = card_style_for(n.type);
		var dims = measure_card(n.card || {});
		var props = Object.assign({}, n, {
			shape: 'custom',
			ctxRenderer: make_card_ctx_renderer(Object.assign({}, n, { _card_dims: dims })),
			_card_dims: dims,
			size: Math.max(dims.width, dims.height) / 2,
			borderWidth: 0,
			color: { background: colors.background, border: colors.border },
			font: { color: colors.font, size: 13, face: 'Arial' },
			label: undefined,
			chosen: false,
			opacity: 1,
			_muted: false,
		});
		return props;
	});

	// Layout pass uses card-to-card edges (ignore ports) so hierarchy stays clean
	var layout_edges = data.edges.map(function(e, i) {
		return {
			id: e.id || ('e' + i),
			from: e.from,
			to: e.to,
			label: '',
			arrows: { to: { enabled: true, scaleFactor: 0.7, type: 'arrow' } },
			color: { color: '#555', highlight: '#555', opacity: 0.85 },
			width: 1.5,
			smooth: { type: 'cubicBezier', forceDirection: 'horizontal', roundness: 0.55 },
		};
	});

	loading_element.style.display = 'flex';
	if (network) { network.destroy(); network = null; }

	network = new vis.Network(container,
		{ nodes: new vis.DataSet(styled_nodes), edges: new vis.DataSet(layout_edges) },
		{
			layout: {
				hierarchical: {
					enabled:              true,
					direction:            'LR',
					sortMethod:           'directed',
					levelSeparation:      stack.levelSeparation,
					nodeSpacing:          stack.nodeSpacing,
					treeSpacing:          stack.treeSpacing,
					blockShifting:        true,
					edgeMinimization:     true,
					parentCentralization: true,
				}
			},
			physics: {
				enabled: true,
				solver: 'hierarchicalRepulsion',
				hierarchicalRepulsion: { nodeDistance: stack.nodeDistance, avoidOverlap: 1, damping: 0.12 },
				stabilization: { enabled: true, iterations: 300 },
			},
			interaction: { dragNodes: false, zoomView: false, dragView: false },
		}
	);

	network.once('stabilized', function() {
		var positions = network.getPositions();
		network.destroy();
		network = null;

		var free_nodes = styled_nodes.map(function(n) {
			var pos = positions[n.id] || { x: 0, y: 0 };
			var copy = Object.assign({}, n, {
				x: pos.x,
				y: pos.y,
				chosen: false,
				opacity: 1,
				_muted: false,
			});
			// Recreate ctxRenderer bound to this copy (mute flag lives on node)
			copy.ctxRenderer = make_card_ctx_renderer(copy);
			return copy;
		});

		// Stretch columns/rows to fill ~80% of the diagram viewport
		spread_nodes_to_viewport(free_nodes, container, 0.8);

		var edge_seed = data.edges.map(function(e, i) {
			return Object.assign({}, e, {
				id: e.id || ('e' + i),
				_from_card: e.from,
				_to_card: e.to,
			});
		});

		var port_nodes = build_port_nodes(free_nodes, edge_seed);
		var port_map = {};
		port_nodes.forEach(function(p) { port_map[p.id] = true; });
		free_nodes.forEach(function(n) { port_map[n.id] = true; });

		var wired_edges = rewire_edges_to_ports(edge_seed, port_map).map(function(e) {
			return Object.assign({}, e, {
				arrows: { to: { enabled: true, scaleFactor: 0.7, type: 'arrow' } },
				font:   { size: 12, align: 'middle', color: '#444', strokeWidth: 2, strokeColor: '#fff' },
				color:  { color: '#555', highlight: '#555', opacity: 0.85 },
				width:  1.5,
				smooth: { type: 'cubicBezier', forceDirection: 'horizontal', roundness: 0.55 },
			});
		});

		var waypoint_nodes = build_waypoint_nodes(free_nodes, wired_edges);
		var split_edges = split_edges_via_waypoints(wired_edges);

		var all_nodes = free_nodes.concat(port_nodes).concat(waypoint_nodes);
		var nodesDS = new vis.DataSet(all_nodes);
		var edgesDS = new vis.DataSet(split_edges);

		network = new vis.Network(container,
			{ nodes: nodesDS, edges: edgesDS },
			{
				layout:    { hierarchical: { enabled: false } },
				physics:   { enabled: false },
				interaction: {
					dragNodes: true,
					zoomView: true,
					dragView: true,
					tooltipDelay: 100,
					selectable: true,
					selectConnectedEdges: false,
				},
			}
		);

		// Fixed left-in / right-out; tiny waypoints keep final arrows pointing right
		refresh_ports_and_routes(free_nodes, wired_edges);

		var drag_raf = 0;
		network.on('dragging', function(params) {
			if (!params.nodes || !params.nodes.length) return;
			var dragged = [];
			params.nodes.forEach(function(id) {
				if (is_port_id(id) || is_waypoint_id(id)) return;
				var n = free_nodes.find(function(fn) { return fn.id === id; });
				if (n) dragged.push(n);
			});
			if (!dragged.length) return;
			if (drag_raf) return;
			drag_raf = requestAnimationFrame(function() {
				drag_raf = 0;
				refresh_ports_and_routes(free_nodes, wired_edges, dragged);
			});
		});
		network.on('dragEnd', function(params) {
			if (drag_raf) {
				cancelAnimationFrame(drag_raf);
				drag_raf = 0;
			}
			var positions = network.getPositions();
			free_nodes.forEach(function(n) {
				var pos = positions[n.id];
				if (pos) {
					n.x = pos.x;
					n.y = pos.y;
				}
			});
			refresh_ports_and_routes(free_nodes, wired_edges);
		});

		network.on('afterDrawing', function(ctx) {
			draw_port_connectors(ctx, free_nodes, wired_edges);
		});

		var node_map = {};
		free_nodes.forEach(function(n) { node_map[n.id] = n; });

		var outgoing = {};
		var incoming = {};
		wired_edges.forEach(function(e) {
			var fromCard = e._from_card || port_parent[e.from] || e.from;
			var toCard = e._to_card || port_parent[e.to] || e.to;
			var fromPort = e.from_port || ('out_' + e.id);
			if (!outgoing[fromCard]) outgoing[fromCard] = [];
			outgoing[fromCard].push({ id: e.id, from: fromCard, to: toCard, from_port: fromPort });
			if (!incoming[toCard]) incoming[toCard] = [];
			incoming[toCard].push({ id: e.id, from: fromCard, to: toCard, from_port: fromPort });
		});

		var path_hidden_edges = {};

		function exclusively_ingressed_by(childId, parentId) {
			var inns = incoming[childId] || [];
			if (!inns.length) return false;
			for (var i = 0; i < inns.length; i++) {
				if (inns[i].from !== parentId) return false;
			}
			return true;
		}

		function exclusively_ingressed_by_port(childId, parentId, portName) {
			var inns = incoming[childId] || [];
			if (!inns.length) return false;
			for (var i = 0; i < inns.length; i++) {
				if (inns[i].from !== parentId || inns[i].from_port !== portName) return false;
			}
			return true;
		}

		/** Hide all egress from a fully-hidden card and exclusive descendants. */
		function collect_all_egress_from(cardId, hiddenNodes, hiddenEdges) {
			(outgoing[cardId] || []).forEach(function(e) {
				hiddenEdges[e.id] = true;
				var child = e.to;
				if (exclusively_ingressed_by(child, cardId)) {
					if (!hiddenNodes[child]) {
						hiddenNodes[child] = true;
						collect_all_egress_from(child, hiddenNodes, hiddenEdges);
					}
				}
			});
		}

		/** Hide one egress port's line(s) and exclusive path beyond it. */
		function collect_from_port(cardId, portName, hiddenNodes, hiddenEdges) {
			(outgoing[cardId] || []).forEach(function(e) {
				if (e.from_port !== portName) return;
				hiddenEdges[e.id] = true;
				var child = e.to;
				if (exclusively_ingressed_by_port(child, cardId, portName)) {
					if (!hiddenNodes[child]) {
						hiddenNodes[child] = true;
						collect_all_egress_from(child, hiddenNodes, hiddenEdges);
					}
				}
			});
		}

		function apply_path_visibility() {
			var hiddenNodes = {};
			var hiddenEdges = {};

			free_nodes.forEach(function(n) {
				var egress = {};
				(outgoing[n.id] || []).forEach(function(e) {
					egress[e.from_port] = true;
				});
				n._egress_ports = egress;
				n._collapsed_ports = n._collapsed_ports || {};
				Object.keys(n._collapsed_ports).forEach(function(pname) {
					if (!egress[pname]) {
						delete n._collapsed_ports[pname];
						return;
					}
					if (n._collapsed_ports[pname]) {
						collect_from_port(n.id, pname, hiddenNodes, hiddenEdges);
					}
				});
			});
			path_hidden_edges = hiddenEdges;

			var node_updates = [];
			free_nodes.forEach(function(n) {
				n._path_hidden = !!hiddenNodes[n.id];
				n._can_edit = !!resolve_edit_url(n);
				n.ctxRenderer = make_card_ctx_renderer(n);
				node_updates.push({
					id: n.id,
					hidden: n._path_hidden,
					ctxRenderer: n.ctxRenderer,
					_path_hidden: n._path_hidden,
					_egress_ports: n._egress_ports,
					_collapsed_ports: n._collapsed_ports,
					_can_edit: n._can_edit,
				});
				(card_ports[n.id] || []).forEach(function(pid) {
					node_updates.push({ id: pid, hidden: n._path_hidden });
				});
			});

			var edge_updates = [];
			wired_edges.forEach(function(e) {
				var hide = !!hiddenEdges[e.id];
				e._path_hidden = hide;
				edge_segment_ids(e.id).forEach(function(sid) {
					if (!edgesDS.get(sid)) return;
					edge_updates.push({ id: sid, hidden: hide });
				});
				var wpIds = waypoint_ids(e.id);
				if (nodesDS.get(wpIds.out)) node_updates.push({ id: wpIds.out, hidden: hide });
				if (nodesDS.get(wpIds.in))  node_updates.push({ id: wpIds.in,  hidden: hide });
			});
			if (node_updates.length) nodesDS.update(node_updates);
			if (edge_updates.length) edgesDS.update(edge_updates);
			network.redraw();
		}

		// Initial per-egress eye affordances
		apply_path_visibility();

		function connected_subgraph(nodeId) {
			if (is_port_id(nodeId)) nodeId = port_parent[nodeId] || nodeId;
			var path_nodes = {};
			var path_edges = {};
			path_nodes[nodeId] = true;

			function walk(start, adj, next_key) {
				var queue = [start];
				var visited = {};
				visited[start] = true;
				while (queue.length) {
					var cur = queue.shift();
					(adj[cur] || []).forEach(function(e) {
						path_edges[e.id] = true;
						var next = e[next_key];
						path_nodes[next] = true;
						if (!visited[next]) {
							visited[next] = true;
							queue.push(next);
						}
					});
				}
			}

			walk(nodeId, outgoing, 'to');
			walk(nodeId, incoming, 'from');
			return { nodes: path_nodes, edges: path_edges };
		}

		function apply_selection_highlight(nodeId) {
			if (is_port_id(nodeId) || is_waypoint_id(nodeId)) nodeId = port_parent[nodeId] || nodeId;
			var subgraph = connected_subgraph(nodeId);

			var node_updates = free_nodes.map(function(n) {
				var active = !!subgraph.nodes[n.id];
				n._muted = !active;
				n.ctxRenderer = make_card_ctx_renderer(n);
				return {
					id: n.id,
					opacity: 1,
					hidden: !!n._path_hidden,
					ctxRenderer: n.ctxRenderer,
					_muted: n._muted,
				};
			});
			nodesDS.update(node_updates);
			network.redraw();

			var edge_updates = [];
			wired_edges.forEach(function(e) {
				var fromCard = e._from_card || port_parent[e.from] || e.from;
				var toCard = e._to_card || port_parent[e.to] || e.to;
				var active = subgraph.edges[e.id] || (subgraph.nodes[fromCard] && subgraph.nodes[toCard]);
				edge_segment_ids(e.id).forEach(function(sid) {
					if (!edgesDS.get(sid)) return;
					if (e._path_hidden || path_hidden_edges[e.id]) {
						edge_updates.push({ id: sid, hidden: true });
						return;
					}
					if (active) {
						edge_updates.push({
							id: sid,
							hidden: false,
							color: { color: '#1565C0', highlight: '#1565C0', opacity: 1 },
							width: 2.5,
							font: Object.assign({}, e.font, { color: '#1565C0' }),
						});
					} else {
						edge_updates.push({
							id: sid,
							hidden: false,
							color: { color: '#CFCFCF', highlight: '#CFCFCF', opacity: 0.25 },
							width: 1,
							font: Object.assign({}, e.font, { color: '#BDBDBD' }),
						});
					}
				});
			});
			if (edge_updates.length) edgesDS.update(edge_updates);
		}

		function clear_selection_highlight() {
			var node_updates = free_nodes.map(function(n) {
				n._muted = false;
				n.ctxRenderer = make_card_ctx_renderer(n);
				return {
					id: n.id,
					opacity: 1,
					hidden: !!n._path_hidden,
					ctxRenderer: n.ctxRenderer,
					_muted: false,
				};
			});
			nodesDS.update(node_updates);
			network.redraw();
			var edge_updates = [];
			wired_edges.forEach(function(e) {
				edge_segment_ids(e.id).forEach(function(sid) {
					if (!edgesDS.get(sid)) return;
					if (e._path_hidden || path_hidden_edges[e.id]) {
						edge_updates.push({ id: sid, hidden: true });
						return;
					}
					edge_updates.push({
						id: sid,
						hidden: false,
						color: e.color,
						width: e.width,
						font: e.font,
					});
				});
			});
			if (edge_updates.length) edgesDS.update(edge_updates);
		}

		var suppress_select = false;

		function open_node_edit(nodeId) {
			if (is_port_id(nodeId)) nodeId = port_parent[nodeId] || nodeId;
			var node = node_map[nodeId];
			var url = resolve_edit_url(node) || node_edit_url(nodeId);
			if (url) window.open(url, '_blank');
		}

		network.on('click', function(params) {
			var pt = params.pointer && params.pointer.canvas;
			if (!pt) return;
			for (var i = 0; i < free_nodes.length; i++) {
				var n = free_nodes[i];
				if (edit_hit_test(n, pt)) {
					suppress_select = true;
					open_node_edit(n.id);
					network.unselectAll();
					clear_selection_highlight();
					return;
				}
				var port = eye_hit_test(n, pt);
				if (port) {
					suppress_select = true;
					n._collapsed_ports = n._collapsed_ports || {};
					n._collapsed_ports[port] = !n._collapsed_ports[port];
					apply_path_visibility();
					network.unselectAll();
					clear_selection_highlight();
					return;
				}
			}
		});

		network.on('select', function(params) {
			if (suppress_select) {
				suppress_select = false;
				network.unselectAll();
				return;
			}
			var raw = (params.nodes || []).filter(function(id) {
				return !is_waypoint_id(id);
			});
			var selected = raw.filter(function(id) { return !is_port_id(id); });
			if (selected.length === 0 && raw.length && is_port_id(raw[0])) {
				var parent = port_parent[raw[0]];
				if (parent) {
					network.selectNodes([parent]);
					apply_selection_highlight(parent);
					return;
				}
			}
			if (selected.length === 0) {
				clear_selection_highlight();
			} else {
				apply_selection_highlight(selected[0]);
			}
		});

		network.on('doubleClick', function(params) {
			if (!params.nodes || params.nodes.length === 0) return;
			var nodeId = params.nodes[0];
			if (is_waypoint_id(nodeId)) return;
			if (is_port_id(nodeId)) nodeId = port_parent[nodeId] || nodeId;
			var pt = params.pointer && params.pointer.canvas;
			var node = node_map[nodeId];
			// Don't treat eye toggles as edit
			if (pt && node && eye_hit_test(node, pt)) return;
			open_node_edit(nodeId);
		});

		loading_element.style.display = 'none';
		size_diagram_container();
		network.fit({ animation: { duration: 500, easingFunction: 'easeInOutQuad' } });
		document.getElementById('btn-fit').style.display = '';
		document.getElementById('btn-png').style.display = '';
	});
}

// Resolve an edit URL from a node ID
function node_edit_url(nodeId) {
	var uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
	var m;
	if ((m = nodeId.match(new RegExp('^inbound_(' + uuid + ')$', 'i')))) return '/app/destinations/destination_edit.php?id=' + m[1];
	if ((m = nodeId.match(new RegExp('^ivr_(' + uuid + ')$', 'i'))))   return '/app/ivr_menus/ivr_menu_edit.php?id=' + m[1];
	if ((m = nodeId.match(new RegExp('^rg_(' + uuid + ')$', 'i'))))   return '/app/ring_groups/ring_group_edit.php?id=' + m[1];
	if ((m = nodeId.match(new RegExp('^cf_(' + uuid + ')$', 'i'))))   return '/app/call_flows/call_flow_edit.php?id=' + m[1];
	if ((m = nodeId.match(new RegExp('^tc_(' + uuid + ')$', 'i'))))   return '/app/time_conditions/time_condition_edit.php?id=' + m[1];
	if ((m = nodeId.match(new RegExp('^ext_(' + uuid + ')$', 'i'))))  return '/app/extensions/extension_edit.php?id=' + m[1];
	if ((m = nodeId.match(new RegExp('^cc_(' + uuid + ')$', 'i'))))   return '/app/call_centers/call_center_queue_edit.php?id=' + m[1];
	return null;
}

function fit_diagram() {
	if (network) network.fit({ animation: { duration: 400, easingFunction: 'easeInOutQuad' } });
}

function download_png() {
	if (!network) return;
	modal_open('modal-png-export', 'btn-png');
}

function dodownload_png(with_background) {
	var src = document.querySelector('#diagram-container canvas');
	if (!src) return;

	// Scale legend to match canvas resolution (retina / DPR)
	var scale = (src.clientWidth > 0) ? (src.width / src.clientWidth) : 1;
	var pad = Math.round(16 * scale);
	var box = Math.round(14 * scale);
	var gapX = Math.round(18 * scale);
	var gapY = Math.round(10 * scale);
	var fontSize = Math.round(12 * scale);
	var radius = Math.round(3 * scale);
	var labelGap = Math.round(6 * scale);

	var measure = document.createElement('canvas').getContext('2d');
	measure.font = fontSize + 'px sans-serif';

	var maxWidth = src.width - pad * 2;
	var rows = [];
	var row = [];
	var rowWidth = 0;
	legend_items.forEach(function(item) {
		var itemW = box + labelGap + measure.measureText(item.label).width;
		if (row.length && rowWidth + gapX + itemW > maxWidth) {
			rows.push(row);
			row = [];
			rowWidth = 0;
		}
		if (row.length) rowWidth += gapX;
		row.push({ item: item, width: itemW });
		rowWidth += itemW;
	});
	if (row.length) rows.push(row);

	var rowH = box + gapY;
	var legendH = pad + rows.length * rowH + pad;

	var canvas = document.createElement('canvas');
	canvas.width  = src.width;
	canvas.height = src.height + legendH;
	var ctx = canvas.getContext('2d');

	if (with_background) {
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, canvas.width, canvas.height);
	}
	ctx.drawImage(src, 0, 0);

	// Legend strip under the diagram
	ctx.font = fontSize + 'px sans-serif';
	ctx.textBaseline = 'middle';
	ctx.lineWidth = Math.max(1, Math.round(scale));

	var y = src.height + pad;
	rows.forEach(function(r) {
		var x = pad;
		r.forEach(function(entry) {
			var item = entry.item;
			ctx.beginPath();
			if (ctx.roundRect) {
				ctx.roundRect(x, y, box, box, radius);
			}
			else {
				ctx.rect(x, y, box, box);
			}
			ctx.fillStyle = item.bg;
			ctx.fill();
			ctx.strokeStyle = item.border;
			ctx.stroke();

			ctx.fillStyle = '#333333';
			ctx.fillText(item.label, x + box + labelGap, y + box / 2);
			x += entry.width + gapX;
		});
		y += rowH;
	});

	var link = document.createElement('a');
	link.download = 'call_flow_map.png';
	link.href = canvas.toDataURL('image/png');
	link.click();
}

<?php if (!empty($diagram_json) && $diagram_json !== 'null'): ?>
// Render pre-loaded diagram
document.addEventListener('DOMContentLoaded', function() {
	render_diagram(<?php echo $diagram_json; ?>);
});
<?php endif; ?>
</script>

<?php
require_once "resources/footer.php";

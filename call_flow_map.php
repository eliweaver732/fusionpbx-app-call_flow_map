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

//selected type/uuid from GET
	$selected_type = $_GET['type'] ?? '';
	$selected_uuid = $_GET['id'] ?? '';

//validate
	if (!empty($selected_type)) {
		$selected_type = preg_replace('/[^a-z_]/', '', $selected_type);
	}
	if (!empty($selected_uuid) && !is_uuid($selected_uuid)) {
		$selected_uuid = '';
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
		height: 600px;
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

// Node style map
var node_styles = {
	inbound:        { color: { background: '#BBDEFB', border: '#1565C0' }, font: { color: '#0D47A1' } },
	ivr:            { color: { background: '#FFE0B2', border: '#BF360C' }, font: { color: '#BF360C' } },
	ring_group:     { color: { background: '#C8E6C9', border: '#1B5E20' }, font: { color: '#1B5E20' } },
	extension:      { color: { background: '#B2EBF2', border: '#006064' }, font: { color: '#006064' } },
	call_flow:      { color: { background: '#B3E5FC', border: '#01579B' }, font: { color: '#01579B' } },
	time_condition: { color: { background: '#FFF9C4', border: '#F57F17' }, font: { color: '#E65100' } },
	contact_center: { color: { background: '#DCEDC8', border: '#33691E' }, font: { color: '#1B5E20' } },
	voicemail:      { color: { background: '#E1BEE7', border: '#6A1B9A' }, font: { color: '#4A148C' } },
	hangup:         { color: { background: '#FFCDD2', border: '#B71C1C' }, font: { color: '#B71C1C' } },
	external:       { color: { background: '#F5F5F5', border: '#616161' }, font: { color: '#424242' } },
};

var network = null;

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

	if (!data || !data.nodes || data.nodes.length === 0) {
		placeholder.textContent = <?php echo json_encode($text['message-no_data']); ?>;
		placeholder.style.display = 'flex';
		return;
	}

	var styled_nodes = data.nodes.map(function(n) {
		var style = node_styles[n.type] || node_styles['external'];
		var props = Object.assign({}, n, style, {
			shape: 'box',
			margin: { top: 8, bottom: 8, left: 10, right: 10 },
			widthConstraint: { maximum: 160 },
			font: Object.assign({ size: 13, face: 'Arial', multi: false }, style.font),
			borderWidth: 2,
			shadow: { enabled: true, size: 4, x: 2, y: 2, color: 'rgba(0,0,0,0.15)' },
		});
		return props;
	});

	var styled_edges = data.edges.map(function(e) {
		return Object.assign({}, e, {
			arrows: { to: { enabled: true, scaleFactor: 0.6, type: 'arrow' } },
			font:   { size: 11, align: 'middle', color: '#444', strokeWidth: 2, strokeColor: '#fff' },
			color:  { color: '#555', highlight: '#1565C0', opacity: 0.85 },
			width:  1.5,
			smooth: { type: 'cubicBezier', forceDirection: 'vertical', roundness: 0.6 },
		});
	});

	loading_element.style.display = 'flex';
	if (network) { network.destroy(); network = null; }

	network = new vis.Network(container,
		{ nodes: new vis.DataSet(styled_nodes), edges: new vis.DataSet(styled_edges) },
		{
			layout: {
				hierarchical: {
					enabled:              true,
					direction:            'UD',
					sortMethod:           'directed',
					levelSeparation:      140,
					nodeSpacing:          30,
					treeSpacing:          50,
					blockShifting:        true,
					edgeMinimization:     true,
					parentCentralization: true,
				}
			},
			physics: {
				enabled: true,
				solver: 'hierarchicalRepulsion',
				hierarchicalRepulsion: { nodeDistance: 80, avoidOverlap: 1, damping: 0.12 },
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
			return Object.assign({}, n, { x: pos.x, y: pos.y });
		});

		network = new vis.Network(container,
			{ nodes: new vis.DataSet(free_nodes), edges: new vis.DataSet(styled_edges) },
			{
				layout:    { hierarchical: { enabled: false } },
				physics:   { enabled: false },
				interaction: { dragNodes: true, zoomView: true, dragView: true, tooltipDelay: 100 },
			}
		);

		var node_map = {};
		free_nodes.forEach(function(n) { node_map[n.id] = n; });

		network.on('doubleClick', function(params) {
			if (params.nodes.length === 0) return;
			var nodeId = params.nodes[0];
			var node   = node_map[nodeId];
			var url    = (node && node.edit_url) || node_edit_url(nodeId);
			if (url) window.open(url, '_blank');
		});

		loading_element.style.display = 'none';
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

	var canvas = document.createElement('canvas');
	canvas.width  = src.width;
	canvas.height = src.height;
	var ctx = canvas.getContext('2d');

	if (with_background) {
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, canvas.width, canvas.height);
	}
	ctx.drawImage(src, 0, 0);

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

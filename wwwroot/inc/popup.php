<?php

# This file is a part of RackTables, a datacenter and server room management
# framework. See accompanying file "COPYING" for the full copyright and
# licensing information.

// Return a list of rack IDs, which are P or less positions
// far from the given rack in its row.
function getProximateRacks ($rack_id, $proximity = 0)
{
	$ret = array ($rack_id);
	if ($proximity > 0)
	{
		$rack = spotEntity ('rack', $rack_id);
		$rackList = listCells ('rack', $rack['row_id']);
		doubleLink ($rackList);
		$todo = $proximity;
		$cur_item = $rackList[$rack_id];
		while ($todo and array_key_exists ('prev_key', $cur_item))
		{
			$cur_item = $rackList[$cur_item['prev_key']];
			$ret[] = $cur_item['id'];
			$todo--;
		}
		$todo = $proximity;
		$cur_item = $rackList[$rack_id];
		while ($todo and array_key_exists ('next_key', $cur_item))
		{
			$cur_item = $rackList[$cur_item['next_key']];
			$ret[] = $cur_item['id'];
			$todo--;
		}
	}
	return $ret;
}

function findSparePorts ($port_info, $filter)
{
	$qparams = array ();
	$query = "
SELECT
	p.id,
	p.name,
	p.reservation_comment,
	p.iif_id,
	p.type as oif_id,
	pii.iif_name,
	d.dict_value as oif_name,
	p.object_id,
	o.name as object_name
FROM Port p
INNER JOIN Object o ON o.id = p.object_id
INNER JOIN PortInnerInterface pii ON p.iif_id = pii.id
INNER JOIN Dictionary d ON d.dict_key = p.type
";
	// porttype filter (non-strict match)
	$query .= "
INNER JOIN (
	SELECT Port.id FROM Port
	INNER JOIN
	(
		SELECT DISTINCT	pic2.iif_id
		FROM PortInterfaceCompat pic2
		INNER JOIN PortCompat pc ON pc.type2 = pic2.oif_id
";
		if ($port_info['iif_id'] != 1)
		{
			$query .= " INNER JOIN PortInterfaceCompat pic ON pic.oif_id = pc.type1 WHERE pic.iif_id = ? AND ";
			$qparams[] = $port_info['iif_id'];
		}
		else
		{
			$query .= " WHERE pc.type1 = ? AND ";
			$qparams[] = $port_info['oif_id'];
		}
		$query .= "
			pic2.iif_id <> 1
	) AS sub1 USING (iif_id)
	UNION
	SELECT Port.id
	FROM Port
	INNER JOIN PortCompat ON type1 = type
	WHERE
		iif_id = 1 and type2 = ?
) AS sub2 ON sub2.id = p.id
";
	$qparams[] = $port_info['oif_id'];

	// self and linked ports filter
	$query .= " WHERE p.id <> ? " .
		"AND p.id NOT IN (SELECT porta FROM Link) " .
		"AND p.id NOT IN (SELECT portb FROM Link) ";
	$qparams[] = $port_info['id'];
	// rack filter
	if (! empty ($filter['racks']))
	{
		$query .= 'AND p.object_id IN (SELECT DISTINCT object_id FROM RackSpace WHERE rack_id IN (' .
			questionMarks (count ($filter['racks'])) . ')) ';
		$qparams = array_merge ($qparams, $filter['racks']);
	}
	// objectname filter
	if (! empty ($filter['objects']))
	{
		$query .= 'AND o.name like ? ';
		$qparams[] = '%' . $filter['objects'] . '%';
	}
	// portname filter
	if (! empty ($filter['ports']))
	{
		$query .= 'AND p.name LIKE ? ';
		$qparams[] = '%' . $filter['ports'] . '%';
	}
	// ordering
	$query .= ' ORDER BY o.name';

	$ret = array();
	$result = usePreparedSelectBlade ($query, $qparams);
	
	$rows_by_pn = array();
	$prev_object_id = NULL;
	
	// fetch port rows from the DB
	while (TRUE)
	{
		$row = $result->fetch (PDO::FETCH_ASSOC);
		if (isset ($prev_object_id) and (! $row or $row['object_id'] != $prev_object_id))
		{
			// handle sorted object's portlist
			foreach (sortPortList ($rows_by_pn) as $ports_subarray)
				foreach ($ports_subarray as $port_row)
				{
					$port_description = $port_row['object_name'] . ' --  ' . $port_row['name'];
					if (count ($ports_subarray) > 1)
					{
						$if_type = $port_row['iif_id'] == 1 ? $port_row['oif_name'] : $port_row['iif_name'];
						$port_description .= " ($if_type)";
					}
					if (! empty ($port_row['reservation_comment']))
						$port_description .= '  --  ' . $port_row['reservation_comment'];
					$ret[$port_row['id']] = $port_description;
				}
			$rows_by_pn = array();
		}
		$prev_object_id = $row['object_id'];
		if ($row)
			$rows_by_pn[$row['name']][] = $row;
		else
			break;
	}

	return $ret;
}

// Return a list of all objects which are possible parents
//    Special case for VMs and VM Virtual Switches
//        - only select Servers with the Hypervisor attribute set to Yes
function findObjectParentCandidates ($object_id)
{
	$object = spotEntity ('object', $object_id);
	$args = array ($object['objtype_id'], $object_id, $object_id);

	$query  = "SELECT O.id, O.name FROM Object O ";
	$query .= "LEFT JOIN ObjectParentCompat OPC ON O.objtype_id = OPC.parent_objtype_id ";
	$query .= "WHERE OPC.child_objtype_id = ? ";
	$query .= "AND O.id != ? ";
	// exclude existing parents
	$query .= "AND O.id NOT IN (SELECT parent_entity_id FROM EntityLink WHERE parent_entity_type = 'object' AND child_entity_type = 'object' AND child_entity_id = ?) ";
	if ($object['objtype_id'] == 1504 || $object['objtype_id'] == 1507)
	{
		array_push($args, $object['objtype_id'], $object_id, $object_id);
		$query .= "AND OPC.parent_objtype_id != 4 ";
		$query .= "UNION ";
		$query .= "SELECT O.id, O.name FROM Object O  ";
		$query .= "LEFT JOIN ObjectParentCompat OPC ON O.objtype_id = OPC.parent_objtype_id ";
		$query .= "LEFT JOIN AttributeValue AV ON O.id = AV.object_id ";
		$query .= "WHERE OPC.child_objtype_id = ? ";
		$query .= "AND (O.objtype_id = 4 AND AV.attr_id = 26 AND AV.uint_value = 1501) ";
		$query .= "AND O.id != ? ";
		// exclude existing parents
		$query .= "AND O.id NOT IN (SELECT parent_entity_id FROM EntityLink WHERE parent_entity_type = 'object' AND child_entity_type = 'object' AND child_entity_id = ?) ";
	}
	$query .= "ORDER BY 2";

	$result = usePreparedSelectBlade ($query, $args);
	$ret = array();
	while ($row = $result->fetch (PDO::FETCH_ASSOC))
		$ret[$row['id']] = $row['name'];
	return $ret;
}

function sortObjectAddressesAndNames ($a, $b)
{
	$objname_cmp = sortTokenize($a['object_name'], $b['object_name']);
	if ($objname_cmp == 0)
	{
		$name_a = (isset ($a['port_name'])) ? $a['port_name'] : '';
		$name_b = (isset ($b['port_name'])) ? $b['port_name'] : '';
		$objname_cmp = sortTokenize($name_a, $name_b);
		if ($objname_cmp == 0)
			sortTokenize($a['ip'], $b['ip']);
		return $objname_cmp;
	}
	return $objname_cmp;
}

function renderPopupObjectSelector()
{
	$object_id = getBypassValue();
	echo '<div style="background-color: #f0f0f0; border: 1px solid #3c78b5; padding: 10px; height: 100%; text-align: center; margin: 5px;">';
	echo '<h2>Choose a container:</h2>';
	echo '<form action="javascript:;">';
	$parents = findObjectParentCandidates($object_id);
	printSelect ($parents, array ('name' => 'parents', 'size' => getConfigVar ('MAXSELSIZE')));
	echo '<br>';
	echo "<input type=submit value='Proceed' onclick='".
		"if (getElementById(\"parents\").value != \"\") {".
		"	opener.location=\"?module=redirect&page=object&tab=edit&op=linkEntities&object_id=${object_id}&child_entity_type=object&child_entity_id=${object_id}&parent_entity_type=object&parent_entity_id=\"+getElementById(\"parents\").value; ".
		"	window.close();}'>";
	echo '</form></div>';
}

function handlePopupPortLink()
{
	assertUIntArg ('port');
	assertUIntArg ('remote_port');
	assertStringArg ('cable', TRUE);
	$port_info = getPortInfo ($_REQUEST['port']);
	$remote_port_info = getPortInfo ($_REQUEST['remote_port']);
	$POIFC = getPortOIFCompat();
	if (isset ($_REQUEST['port_type']) and isset ($_REQUEST['remote_port_type']))
	{
		$type_local = $_REQUEST['port_type'];
		$type_remote = $_REQUEST['remote_port_type'];
	}
	else
	{
		$type_local = $port_info['oif_id'];
		$type_remote = $remote_port_info['oif_id'];
	}
	$matches = FALSE;
	$js_table = '';
	foreach ($POIFC as $pair)
		if ($pair['type1'] == $type_local && $pair['type2'] == $type_remote)
		{
			$matches = TRUE;
			break;
		}
		else
			$js_table .= "POIFC['${pair['type1']}-${pair['type2']}'] = 1;\n";

	if ($matches)
	{
		if ($port_info['oif_id'] != $type_local)
			commitUpdatePortOIF ($port_info['id'], $type_local);
		if ($remote_port_info['oif_id'] != $type_remote)
			commitUpdatePortOIF ($remote_port_info['id'], $type_remote);
		linkPorts ($port_info['id'], $remote_port_info['id'], $_REQUEST['cable']);
		showOneLiner 
		(
			8, 
			array
			(
				formatPortLink ($port_info['id'], $port_info['name'], NULL, NULL),
				formatPort ($remote_port_info),
			)
		);
		addJS (<<<END
window.opener.location.reload(true);
window.close();
END
		, TRUE);
	}
	else
	{
		// JS code to display port compatibility hint
		addJS (<<<END
POIFC = {};
$js_table
$(document).ready(function () {
	$('select.porttype').change(onPortTypeChange);	
	onPortTypeChange();
});
function onPortTypeChange() {
	var key = $('*[name=port_type]')[0].value + '-' + $('*[name=remote_port_type]')[0].value;
	if (POIFC[key] == 1)
	{
		$('#hint-not-compat').hide();
		$('#hint-compat').show();
	}
	else
	{
		$('#hint-compat').hide();
		$('#hint-not-compat').show();
	}
}
END
		, TRUE);
		addCSS (<<<END
.compat-hint {
	display: none;
	font-size: 125%;
}
.compat-hint#hint-compat {
	color: green;
}
.compat-hint#hint-not-compat {
	color: #804040;
}
END
		, TRUE);
		// render port type editor form
		echo '<form method=GET>';
		echo '<input type=hidden name="module" value="popup">';
		echo '<input type=hidden name="helper" value="portlist">';
		echo '<input type=hidden name="port" value="' . $port_info['id'] . '">';
		echo '<input type=hidden name="remote_port" value="' . $remote_port_info['id'] . '">';
		echo '<input type=hidden name="cable" value="' . htmlspecialchars ($_REQUEST['cable'], ENT_QUOTES) . '">';
		echo '<p>The ports you have selected are not compatible. Please select a compatible transceiver pair.';
		echo '<p>';
		echo formatPort ($port_info) . ' ';
		if ($port_info['iif_id'] == 1)
		{
			echo formatPortIIFOIF ($port_info);
			echo '<input type=hidden name="port_type" value="' . $port_info['oif_id'] . '">';
		}
		else
		{
			echo '<label>' . $port_info['iif_name'] . ' ';
			printSelect (getExistingPortTypeOptions ($port_info['id']), array ('class' => 'porttype', 'name' => 'port_type'), $type_local);
			echo '</label>';
		}
		echo ' &mdash; ';
		if ($remote_port_info['iif_id'] == 1)
		{
			echo formatPortIIFOIF ($remote_port_info);
			echo '<input type=hidden name="remote_port_type" value="' . $remote_port_info['oif_id'] . '">';
		}
		else
		{
			echo '<label>' . $remote_port_info['iif_name'] . ' ';
			printSelect (getExistingPortTypeOptions ($remote_port_info['id']), array ('class' => 'porttype', 'name' => 'remote_port_type'), $type_remote);
			echo '</label>';
		}
		echo ' ' . formatPort ($remote_port_info);
		echo '<p class="compat-hint" id="hint-not-compat">&#10005; Not compatible port types</p>';
		echo '<p class="compat-hint" id="hint-compat">&#10004; Compatible port types</p>';
		echo '<p><input type=submit name="do_link" value="Link">';
	}
}

function renderPopupPortSelector()
{
	assertUIntArg ('port');
	$port_id = $_REQUEST['port'];
	$port_info = getPortInfo ($port_id);
	$in_rack = $_REQUEST['in_rack'] != 'off';

	// fill port filter structure
	$filter = array
	(
		'racks' => array(),
		'objects' => '',
		'ports' => '',
	);
	if (isset ($_REQUEST['filter-obj']))
		$filter['objects'] = $_REQUEST['filter-obj'];
	if (isset ($_REQUEST['filter-port']))
		$filter['ports'] = $_REQUEST['filter-port'];
	if ($in_rack)
	{
		$object = spotEntity ('object', $port_info['object_id']);
		if ($object['rack_id'])
			$filter['racks'] = getProximateRacks ($object['rack_id'], getConfigVar ('PROXIMITY_RANGE'));
	}
	$spare_ports = array();
	if
	(
		$in_rack ||
		! empty ($filter['objects']) ||
		! empty ($filter['ports'])
	)
		$spare_ports = findSparePorts ($port_info, $filter);

	// display search form
	echo 'Link ' . formatPort ($port_info) . ' to...';
	echo '<form method=GET>';
	startPortlet ('Port list filter');
	echo '<input type=hidden name="module" value="popup">';
	echo '<input type=hidden name="helper" value="portlist">';
	echo '<input type=hidden name="port" value="' . $port_id . '">';
	echo '<table align="center" valign="bottom"><tr>';
	echo '<td class="tdleft"><label>Object name:<br><input type=text size=8 name="filter-obj" value="' . htmlspecialchars ($filter['objects'], ENT_QUOTES) . '"></label></td>';
	echo '<td class="tdleft"><label>Port name:<br><input type=text size=6 name="filter-port" value="' . htmlspecialchars ($filter['ports'], ENT_QUOTES) . '"></label></td>';
	echo '<td class="tdleft" valign="bottom"><input type="hidden" name="in_rack" value="off" /><label><input type=checkbox name="in_rack"' . ($in_rack ? ' checked' : '') . '>Nearest racks</label></td>';
	echo '<td valign="bottom"><input type=submit value="show ports"></td>';
	echo '</tr></table>';
	finishPortlet();

	// display results
	startPortlet ('Compatible spare ports');
	if (empty ($spare_ports))
		echo '(nothing found)';
	else
	{
		echo getSelect ($spare_ports, array ('name' => 'remote_port', 'size' => getConfigVar ('MAXSELSIZE')), NULL, FALSE);
		echo "<p>Cable ID: <input type=text id=cable name=cable>";
		echo "<p><input type='submit' value='Link' name='do_link'>";
	}
	finishPortlet();
	echo '</form>';
}

function renderPopupIPv4Selector()
{
	echo '<div style="background-color: #f0f0f0; border: 1px solid #3c78b5; padding: 10px; height: 100%; text-align: center; margin: 5px;">';
	echo '<h2>Choose a port:</h2><br><br>';
	echo '<form action="javascript:;">';
	echo '<input type=hidden id=ip>';
	echo '<select size=' . getConfigVar ('MAXSELSIZE') . ' id=addresses>';
	$addresses = getAllIPv4Allocations();
	usort ($addresses, 'sortObjectAddressesAndNames');
	foreach ($addresses as $address)
		echo "<option value='${address['ip']}' onclick='getElementById(\"ip\").value=\"${address['ip']}\";'>" .
		"${address['object_name']} ${address['name']} ${address['ip']}</option>\n";
	echo '</select><br><br>';
	echo "<input type=submit value='Proceed' onclick='".
		"if (getElementById(\"ip\")!=\"\") {".
		" opener.document.getElementById(\"remoteip\").value=getElementById(\"ip\").value;".
		" window.close();}'>";
	echo '</form></div>';
}

function renderPopupHTML()
{
	global $pageno, $tabno;
header ('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en" style="height: 100%;">
<?php
	assertStringArg ('helper');
	$text = '';
	switch ($_REQUEST['helper'])
	{
		case 'objlist':
			$pageno = 'object';
			$tabno = 'default';
			fixContext();
			assertPermission();
			$text .= getOutputOf ('renderPopupObjectSelector');
			break;
		case 'portlist':
			$pageno = 'depot';
			$tabno = 'default';
			fixContext();
			assertPermission();
			$text .= '<div style="background-color: #f0f0f0; border: 1px solid #3c78b5; padding: 10px; height: 100%; text-align: center; margin: 5px;">';
			if (isset ($_REQUEST['do_link']))
				$text .= getOutputOf ('callHook', 'handlePopupPortLink');
			else
				$text .= getOutputOf ('callHook' , 'renderPopupPortSelector');
			$text .= '</div>';
			break;
		case 'inet4list':
			$pageno = 'ipv4space';
			$tabno = 'default';
			fixContext();
			assertPermission();
			$text .= getOutputOf ('renderPopupIPv4Selector');
			break;
		default:
			throw new InvalidRequestArgException ('helper', $_REQUEST['helper']);
	}
	echo '<head><title>RackTables pop-up</title>';
	printPageHeaders();
	echo '</head>';
	echo '<body style="height: 100%;">' . $text . '</body>';
?>
</html>
<?php
}
?>

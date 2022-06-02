<?php

function plugin_rebooter_info ()
{
	return array
	(
		'name' => 'rebooter',
		'longname' => 'pdu-rebooter',
		'version' => '1.0',
		'home_url' => 'https://github.com/Vitovt92/racktables'
	);
}

// initialize plugin 
function plugin_rebooter_init ()
{

	global $interface_requires, $opspec_list, $page, $tab, $trigger;

	$tab['object']['rebooter'] = 'Reboot'; #custom rebooter page

    registerTabHandler ('object', 'rebooter', 'renderRebootServerForm'); #custom rebooter page

	registerHook ('renderObjectReboot_hook', 'plugin_rebooter_renderObjectReboot');
	
	registerOpHandler ('object', 'rebooter', 'reboot_on', 'rebooterSendON');
	registerOpHandler ('object', 'rebooter', 'reboot_off', 'rebooterSendOFF');

}

function plugin_rebooter_install ()
{
// 	return TRUE;
}

function plugin_rebooter_uninstall ()
{
	// return TRUE;
}

function renderRebootServerForm($object_id)
{

	global $nextorder, $virtual_obj_types;
	$info = spotEntity ('object', $object_id);
	amplifyCell ($info);
	// Main layout starts.
	echo "<table border=0 class=objectview cellspacing=0 cellpadding=0>";
	echo "<tr><td colspan=2 align=center><h1>${info['dname']}</h1></td></tr>\n";
	// A mandatory left column with varying number of portlets.
	echo "<tr><td class=pcleft>";

	// display summary portlet
	$summary = array();
	if ($info['name'] != '')
		$summary['Common name'] = $info['name'];
	elseif (considerConfiguredConstraint ($info, 'NAMEWARN_LISTSRC'))
		$summary[] = array ('<tr><td colspan=2 class=msg_error>Common name is missing.</td></tr>');
	$summary['Object type'] = '<a href="' . makeHref (array (
		'page' => 'depot',
		'tab' => 'default',
		'cfe' => '{$typeid_' . $info['objtype_id'] . '}'
	)) . '">' .  decodeObjectType ($info['objtype_id']) . '</a>';
	if ($info['label'] != '')
		$summary['Visible label'] = $info['label'];
	if ($info['asset_no'] != '')
		$summary['Asset tag'] = $info['asset_no'];
	elseif (considerConfiguredConstraint ($info, 'ASSETWARN_LISTSRC'))
		$summary[] = array ('<tr><td colspan=2 class=msg_error>Asset tag is missing.</td></tr>');
	$parents = getParents ($info, 'object');
	// lookup the human-readable object type, sort by it
	foreach ($parents as $parent_id => $parent)
		$parents[$parent_id]['object_type'] = decodeObjectType ($parent['objtype_id']);
	$grouped_parents = groupBy ($parents, 'object_type');
	ksort ($grouped_parents);
	foreach ($grouped_parents as $parents_group)
	{
		uasort ($parents_group, 'compare_name');
		$label = $parents_group[key ($parents_group)]['object_type'] . (count($parents_group) > 1 ? ' containers' : ' container');
		$fmt_parents = array();
		foreach ($parents_group as $parent)
			$fmt_parents[] = mkCellA ($parent);
		$summary[$label] = implode ('<br>', $fmt_parents);
	}
	$children = getChildren ($info, 'object');
	foreach (groupBy ($children, 'objtype_id') as $objtype_id => $children_group)
	{
		uasort ($children_group, 'compare_name');
		$fmt_children = array();
		foreach ($children_group as $child)
			$fmt_children[] = mkCellA ($child);
		$summary["Contains " . mb_strtolower(decodeObjectType ($objtype_id))] = implode ('<br>', $fmt_children);
	}
	if ($info['has_problems'] == 'yes')
		$summary[] = array ('<tr><td colspan=2 class=msg_error>Has problems</td></tr>');
	foreach (getAttrValuesSorted ($object_id) as $record)
		if
		(
			$record['value'] != '' &&
			permitted (NULL, NULL, NULL, array (array ('tag' => '$attr_' . $record['id'])))
		)
			$summary['{sticker}' . $record['name']] = formatAttributeValue ($record, $info['objtype_id']);
	$summary[] = array (getOutputOf ('printTagTRs',
		$info,
		makeHref
		(
			array
			(
				'page'=>'depot',
				'tab'=>'default',
				'andor' => 'and',
				'cfe' => '{$typeid_' . $info['objtype_id'] . '}',
			)
		)."&"
	));

	switchportInfoJS ($object_id); // load JS code to make portnames interactive

	// render Reboot buttons. 

	if (count ($info['ports']))
	{
		startPortlet ('Reboot');
		$hl_port_id = 0;
		if (isset ($_REQUEST['hl_port_id']))
		{
			genericAssertion ('hl_port_id', 'natural');
			$hl_port_id = $_REQUEST['hl_port_id'];
			addAutoScrollScript ("port-$hl_port_id");
		}

		echo "<table cellspacing=0 cellpadding='5' align='center' class='widetable'>";

		foreach ($info['ports'] as $port)
		{
			callHook ('renderObjectReboot_hook', $port, ($hl_port_id == $port['id']));
		}	
		echo "</table><br>";
		finishPortlet();
	}

}

//render rebooter forms
function plugin_rebooter_renderObjectReboot ($port, $is_highlighted)
{
	if ($port['remote_object_id'])
	{
		
		if ($port['oif_name'] == 'AC-in'){
			$alert_message_on='"Ви впевнені? Якщо ви підтвердите, то електричний порт '.$port['remote_name'].' на ребутері '.$port['remote_object_name'].' буде включено (ON)"';
			$alert_message_off='"Ви впевнені? Якщо ви підтвердите, то електричний порт '.$port['remote_name'].' на ребутері '.$port['remote_object_name'].' буде виключено (OFF)"';
			
			
			$port_power_arg = 'Power' . $port['remote_name'];    // For instance 'Power8', 
			$port_power_arg_upper = strtoupper($port_power_arg);    // in some cases need port power arg but in uppercase
			
			$get_data = callAPI('GET', 'http://'.$port['remote_object_asset_no'].'/cm', ['cmnd' => 'Power' . $port['remote_name']]);
			
			if(!isset($get_data['error']))
			{
				$response = json_decode($get_data, true);
				
				if (isset($response[$port_power_arg_upper]))
				{
					$port_status = $response[$port_power_arg_upper]; 
					
					if ($port_status === 'ON')  // Change color of port status depends on status 
					{
						$text_color = 'green';
					} else {
						$text_color = 'red';
					}
					echo '<tr>';
					echo '<td>';
					echo 'status: ';
					echo '</td>';
					echo "
					<td>
					<span title='Статус порта ребутера' style='font-weight:bold; font-size: 20px; color: {$text_color}'> {$port_status} </span>
					</td>
					";
					echo "</tr>";
				}
			} else {
				echo 'Error connect to rebooter IP: ' . $port['remote_object_name'] . ' - ' . $get_data['error'];
			};
			
			echo "<tr>";
			echo "<td>";
			
			echo "
				<form 
					method='post' 
					action='?module=redirect&page=object&tab=rebooter&op=reboot_on&object_id={$port['object_id']}' 
					onsubmit='return confirm(".$alert_message_on.");'
					>
					<input type='hidden' name='rebooter_ip' value='{$port['remote_object_asset_no']}' >
					<input type='hidden' name='rebooter_port' value='{$port['remote_name']}' >
					<input name='rebooter_type' value='{$port['remote_object_label']}' >
					<input title='Включити' style='cursor:pointer' type='submit' value='ON'>
				</form>";

			echo "</td>";
			echo "<td>";

			echo "
				<form 
					method='post' 
					action='?module=redirect&page=object&tab=rebooter&op=reboot_off&object_id={$port['object_id']}'
					onsubmit='return confirm(".$alert_message_off.");'
					>
					<input type='hidden' name='rebooter_ip' value='{$port['remote_object_asset_no']}' >
					<input type='hidden' name='rebooter_port' value='{$port['remote_name']}' >
					<input name='rebooter_type' value='{$port['remote_object_label']}' >
					<input title='Виключити' style='cursor:pointer' type='submit' value='OFF'>
				</form>";	
			echo "</td>";
			echo "</tr>";

	        }

	}
}

// when in rebooter tab click ON send http to rebooter API
function rebooterSendON ()
{
	if ($_POST['rebooter_ip'] && $_POST['rebooter_port'] && $_POST['rebooter_type'] )
	{
		$rebooter_ip = $_POST['rebooter_ip'];
		$rebooter_port = $_POST['rebooter_port'];
		$rebooter_arg = 'Power'.$rebooter_port;	

		if ($_POST['rebooter_type'] === "megatazik")
		{		
			$get_data = callAPI('GET', 'http://'.$rebooter_ip.'/cm', ['cmnd' => $rebooter_arg.' ON']);
		} elseif ($_POST['rebooter_type'] === "APC")
		{
			$output=null;
			$retval=null;
			exec("./../plugins/rebooter/APC/apc.py --host $rebooter_ip --on $rebooter_port", $output, $retval);
		}
	}
}


// when in rebooter tab click OFF send http to rebooter API
function rebooterSendOFF ()
{
	if ($_POST['rebooter_ip'] && $_POST['rebooter_port'] && $_POST['rebooter_type'] )
	{
		$rebooter_ip = $_POST['rebooter_ip'];
		$rebooter_port = $_POST['rebooter_port'];
		$rebooter_arg = 'Power'.$rebooter_port;	

		if ($_POST['rebooter_type'] === "megatazik")
		{
			$get_data = callAPI('GET', 'http://'.$rebooter_ip.'/cm', ['cmnd' => $rebooter_arg.' OFF']);
		}elseif ($_POST['rebooter_type'] === "APC")
		{
			$output=null;
			$retval=null;
			exec("./../plugins/rebooter/APC/apc.py --host $rebooter_ip --off $rebooter_port", $output, $retval);
		}
	}
}


// Function to make curl requests to API
function callAPI($method, $url, $data){
	$curl = curl_init();
	switch ($method){
	   case "POST":
		  curl_setopt($curl, CURLOPT_POST, 1);
		  if ($data)
			 curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		  break;
	   case "PUT":
		  curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
		  if ($data)
			 curl_setopt($curl, CURLOPT_POSTFIELDS, $data);			 					
		  break;
	   default:
		  if ($data)
			 $url = sprintf("%s?%s", $url, http_build_query($data));
	}
	// OPTIONS:
//	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 50); // add because of long API answer 
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_HTTPHEADER, array(
//		   'APIKEY: 111111111111111111111',
	   'Content-Type: application/json',
	));
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	// EXECUTE:
	$result = curl_exec($curl);
	if(!$result)
	{
		return ["error" => "Connection Failure"];
	}
	curl_close($curl);
	return $result;
 }





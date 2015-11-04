<?php

// Set up AWS client stuffs
// Initiate ec2client
use Aws\Ec2\Ec2Client;

// Load in the SDK
// This assumes you have the amazon_aws_detail plugin installed
if (file_exists( dirname(__FILE__).'/../amazon_aws_detail/aws-sdk/aws-autoloader.php')) {
  require dirname(__FILE__).'/../amazon_aws_detail/aws-sdk/aws-autoloader.php';

}

//////////////////////////////////////////////////////////////////////////////
// Function: rpt_run()
//
// Description:
//   Returns the output for this report.
//   It will first get the DATA for the report by executing whatever code gathers
//   data used by the report.  This is handled by the rpt_get_data() function.
//   It will then pass that data to the appropriate output generator.
//
//   A rpt_output_XYZ() function should be written for each type of output format
//   you want to support.  The data from rpt_get_data will be used by this function.
//
//   IN GENERAL, YOU SHOULD NOT NEED TO EDIT THIS FUNCTION
//
//////////////////////////////////////////////////////////////////////////////
function rpt_run($form, $output_format='html') {

    $status=0;

    // See if the output function they requested even exists
    $func_name = "rpt_output_{$output_format}";
    if (!function_exists($func_name)) {
        $rptoutput = "ERROR => This report does not support an '{$form['format']}' output format.";
        return(array(1,$rptoutput));
    }

    // if we are looking for the usage, skip gathering data.  Otherwise, gather report data.
    if (!$form['rpt_usage']) list($status, $rptdata) = rpt_get_data($form);

    if ($status) {
        $rptoutput = "NOTICE => There was a problem getting the data. <br> {$rptdata}";
    }
    // Pass the data to the output type
    else {
        // If the rpt_usage option was passed, add it to the gathered data
        if ($form['rpt_usage']) $rptdata['rpt_usage'] = $form['rpt_usage'];

        // Pass the data to the output generator
        list($status, $rptoutput) = $func_name($rptdata);
        if ($status)
            $rptoutput = "ERROR => There was a problem getting the output: {$rptoutput}";
    }

    return(array($status,$rptoutput));
}



//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////START EDITING BELOW////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////



//////////////////////////////////////////////////////////////////////////////
// Function: rpt_html_form()
//
// Description:
//   Returns the HTML form text for this report.
//   This is used by the display report code to present an html form to
//   the user.  This simply provides a gui to gather all the input variables.
//////////////////////////////////////////////////////////////////////////////
function rpt_html_form($report_name, $rptform='',$rptjs='') {
    global $images, $color, $style, $conf;
    $rpthtml = '';
    $rptjs = '';
//FIXME need to pass in the aws subnet-id and aws region

    if (stristr($rptform['subnet_type'],'AWS')) {
        // get the region info from the type
        // Assuming format of "AWS <region> LAN"
        list ($junk,$awsregion) = explode(' ',$rptform['subnet_type']);
    }

    // Create your input form below
    $rpthtml .= <<<EOL

        <form id="{$report_name}_report_form" onsubmit="el('rpt_submit_button').onclick(); return false;">
            <input type="hidden" value="{$report_name}" name="report"/>
            <input type="hidden" value="{$awsregion}" name="awsregion"/>
            Subnet: <input id="subnet" name="subnet" value="{$rptform['subnet']}" class="edit" type="text" size="15" />
            <input type="submit"
                   id="rpt_submit_button"
                   title="Search"
                   value="Run Report"
                   class="act"
                   onClick="el('report_content').innerHTML='<br><center><img src={$images}/loading.gif></center><br>';xajax_window_submit('display_report', xajax.getFormValues('{$report_name}_report_form'), 'run_report');"
            />
            <input class="act" type="button" name="reset" value="Clear" onClick="clearElements('{$report_name}_report_form');">
        </form>


EOL;

    // Return the html code for the form
    return(array(0,$rpthtml,$rptjs));
}














function rpt_get_data($form) {
    global $base,$onadb;

    // set the awsregion
    $awsregionlower=strtolower($form['awsregion']);

    // Get subnet info from ONA
    list($status, $rows, $onasubnet) = ona_find_subnet($form['subnet']);
    if ($rows) {
      $onasubnet_ip = ip_mangle($onasubnet['ip_addr'],'dotted');
      $onasubnet_cidr = ip_mangle($onasubnet['ip_mask'],'cidr');
    } else {
      echo "Unable to find subnet in ONA: {$form['subnet']}";
    }

    // Pull in config file data
    $awsconffile = (file_exists($onabase.'/etc/amazon_aws_detail.conf.php')) ? $onabase.'/etc/amazon_aws_detail.conf.php' : dirname(__FILE__).'/amazon_aws_detail.conf.php';
    if (file_exists($awsconffile)) {
        require_once($awsconffile);
        if (!isset($awsRegionKeys[$awsregionlower])) {
            $htmllines .= <<<EOL
                No Amazon Keys defined for region: $awsregionlower<br>
EOL;
        }

    // Connect with creds, should only be readonly
    $ec2client = new Ec2Client(array(
        'version'     => 'latest',
        'region'      => $awsregionlower,
        'credentials' => [
          'key'    => $awsRegionKeys[$awsregionlower]['key'],
          'secret' => $awsRegionKeys[$awsregionlower]['secret'],
        ],
    ));

    // Get content of a specific subnet, expect only one back
    $subnets = $ec2client->describeSubnets(array(
        'Filters' => array(
            array(
                'Name' => 'cidr',
                'Values' => array($onasubnet_ip.'/'.$onasubnet_cidr),
            ),
    )))->toArray();

    // Just get the first one
    $subnet = $subnets['Subnets'][0];


    // Get list of the running instances based on a filter
    // TODO do we want other instance states as well?
    $instances = $ec2client->describeInstances(array(
        'Filters' => array(
            array(
                'Name' => 'subnet-id',
                'Values' => array($subnet['SubnetId']), # running
            ),
        ),
    ))->toArray();


// Loop through the Reservations
// Reservations are created each time you spin up a specific set of instances
foreach ( $instances['Reservations'] as $res ) {
  // Loop through the instances in each reservation
  foreach ($res['Instances'] as $resinst ) {
//print_r($resinst);
    // Clear variables
    $nametag = '';
    $domaintag = '';
    // Gather tags
    foreach ($resinst['Tags'] as $tags ) {
      // Capture the 'name' tag for later.. not the best way to do things but its what is for now
      if ( $tags['Key'] == 'Name' ) { $nametag = $tags['Value']; }
      if ( $tags['Key'] == 'domain' ) { $domaintag = $tags['Value']; }
      // get a list of all the key/value pairs of tags
      $taglist="$taglist {$tags['Key']}:{$tags['Value']}";
    }

    $awsdata[$resinst['InstanceId']]['hostname'] = $nametag;
    $awsdata[$resinst['InstanceId']]['domainname'] = $domaintag;

    // gather interfaces
    // FIXME this ASSUMES device instance equates to the ethernet interface.. we'll see
    foreach ($resinst['NetworkInterfaces'] as $ints ) {
        //echo "      {$ints['NetworkInterfaceId']} eth{$ints['Attachment']['DeviceIndex']} {$ints['PrivateIpAddress']} {$ints['macAddress']} {$ints['PrivateDnsName']}\n";
        $awsdata[$resinst['InstanceId']]['deviceindex'] = $ints['Attachment']['DeviceIndex'];
        $awsdata[$resinst['InstanceId']]['ip'] = $ints['PrivateIpAddress'];
        $awsdata[$resinst['InstanceId']]['mac'] = $ints['macAddress'];

    }


  }
}

// loop through all the xml arrays that have been built.
foreach ($awsdata as $awsinstance) {

    // Clear vars each itteration of the loop
    $macaddr = '';
    $dnsdomain = '';
    // Gather some info from the nmap XML file
    $ipaddr = $awsinstance['ip'];
    $macaddr = mac_mangle($awsinstance['mac']);
    $devindex = $awsinstance['deviceindex'];
    $dnsname = $awsinstance['hostname'].'.'.$awsinstance['domainname'];
    $hostname = $awsinstance['hostname'];
    $domainname = $awsinstance['domainname'];
    $dnsrows=0;
    $dns = array();

    // Lookup the IP address in the database
    if ($ipaddr) {
        list($status, $introws, $interface) = ona_find_interface($ipaddr);
        if (!$introws) {
            $interface['ip_addr_text'] = 'NOT FOUND';
            list($status, $introws, $tmp) = ona_find_subnet($ipaddr);
            $interface['subnet_id'] = $tmp['id'];
        } else {
            // Lookup the DNS name in the database
            list($status, $dnsrows, $dnscount) = db_get_records($onadb, 'dns', "interface_id = ${interface['id']}", "", 0);
            list($status, $dnsptrrows, $dnsptr) = ona_get_dns_record(array('interface_id' => $interface['id'], 'type' => 'PTR'));
            list($status, $dnsprows, $dns) = ona_get_dns_record(array('id' => $dnsptr['dns_id']));
        }
    }
    // some base logic
    // if host is up in nmap but no db ip then put in $nodb
    // if host is up and is in db then put in $noissue
    // if host is down and not in db then skip
    // if host is down and in db then put in $nonet
    // if host is up an in db, does DNS match?
    //    in DNS but not DB
    //    in DB but not DNS
    //    DNS and DB dont match

    // Setup the base array element for the IP
    $rptdata['ip'][$ipaddr]=array();
    $rptdata['ip'][$ipaddr]['netip'] = $ipaddr;
    $rptdata['ip'][$ipaddr]['netdnsname'] = strtolower($dnsname);
    $rptdata['ip'][$ipaddr]['netmacaddr'] = $macaddr;
    // TODO: this assumes its always "eth<index>"
    $rptdata['ip'][$ipaddr]['netdevindex'] = 'eth'.$devindex;

    $rptdata['ip'][$ipaddr]['dbip'] = $interface['ip_addr_text'];
    $rptdata['ip'][$ipaddr]['dbsubnetid'] = $interface['subnet_id'];
    $rptdata['ip'][$ipaddr]['dbmacaddr'] = $interface['mac_addr'];
    $rptdata['ip'][$ipaddr]['dbdevindex'] = $interface['name'];

    $rptdata['ip'][$ipaddr]['dbdnsrows'] = $dnsrows;

    if (!$dns['fqdn']) {
        // lets see if its a PTR record
        if ($dnsptrrows) {
            // If we have a PTR for this interface, use it (never if built from ona?)
            $rptdata['ip'][$ipaddr]['dbdnsname'] = $dns['fqdn'];
            $rptdata['ip'][$ipaddr]['dbdnsptrname'] = $dnsp['fqdn'];
        } else {
            // find the hosts primary DNS record
            list($status, $hostrows, $host) = ona_get_host_record(array('id' => $interface['host_id']));
            if ($host['fqdn']) $host['fqdn'] = "(${host['fqdn']})";
            if ($dnsrows) {
                list($status, $dnstmprows, $dnstmp) = ona_get_dns_record(array('interface_id' => $interface['id']));
                $rptdata['ip'][$ipaddr]['dbdnsname'] = $dnstmp['fqdn'];
            } else {
                $rptdata['ip'][$ipaddr]['dbdnsname'] = 'NO PTR';
            }
            $rptdata['ip'][$ipaddr]['dbdnsptrname'] = $host['fqdn'];
        }
    } else {
        if ($dnsptrrows > 1) {
            $rptdata['ip'][$ipaddr]['dbdnsname'] = $dns['fqdn'];
            $rptdata['ip'][$ipaddr]['dbdnsptrname'] = $dnsp['fqdn'];
        } else {
            $rptdata['ip'][$ipaddr]['dbdnsname'] = $dns['fqdn'];
            $rptdata['ip'][$ipaddr]['dbdnsptrname'] = $dnsp['fqdn'];
        }
    }

  }
}


    return(array(0,$rptdata));
}






function rpt_output_html($form) {
    global $onadb, $style, $images;

    $text .= <<<EOL
    <table class="list-box" cellspacing="0" border="0" cellpadding="0" style="margin-bottom: 0;">
            <!-- Table Header -->
            <tr>
                <td class="list-header" align="center">Amazon AWS Instance Data</td>
                <td class="list-header" align="center">DATABASE</td>
                <td class="list-header" align="center">&nbsp;</td>
                <td class="list-header" align="center">Actions</td>
            </tr>
    </table>
        <div id="nmap_scan_results" style="overflow: auto; width: 100%; height: 89%;border-bottom: 1px solid;">
            <table class="list-box" cellspacing="0" border="0" cellpadding="0">
EOL;

    // netip    netname     netmac      dbip    dbname  dbmac

    foreach ((array)$form['ip'] as $record) {

        $act_status_fail = "<img src=\"{$images}/silk/stop.png\" border=\"0\">";
        $act_status_ok = "<img src=\"{$images}/silk/accept.png\" border=\"0\">";
        $act_status_partial = "<img src=\"{$images}/silk/error.png\" border=\"0\">";

        $action = '';
        $redcolor = '';

        // button info to view subnet
        $viewsubnet = <<<EOL
    <a onclick="xajax_window_submit('work_space', 'xajax_window_submit(\'display_subnet\', \'subnet_id=>{$record['dbsubnetid']}\', \'display\')');" title="Goto this subnet."><img src="{$images}/silk/application.png" border="0"></a>
EOL;



        // check mac addresses
        if ($record['netmacaddr'] != $record['dbmacaddr']) { $action = '&nbsp;'.$act_status_ok.' Update MAC'; }
        if ($record['netdevindex'] != $record['dbdevindex']) { $action = '&nbsp;'.$act_status_ok.' Update Interface Name'; }

        // Break out the host and domain parts of the name if we can
        if ($record['netdnsname']) {
            list($status, $rows, $domain) = ona_find_domain($record['netdnsname'],0);
            // FIXME.. if the domain does not exist we dont say anything about that here..
            // Now find what the host part of $search is
            $hostname = str_replace(".{$domain['fqdn']}", '', $record['netdnsname']);
        }

        // If we dont find it in the database
        if ($record['dbip'] == "NOT FOUND") {
            $action = <<<EOL
                    {$act_status_fail}
                    <a title="Add host."
                        class="act"
                        onClick="xajax_window_submit('edit_host', 'ip_addr=>{$record['netip']},hostname=>{$hostname},domain_id=>{$domain['id']},js=>null', 'editor');"
                    >Add as host</a>
EOL;
        }
        // If it is in the database and network
        if ($record['netip'] == $record['dbip']) {
            $action = '&nbsp;'.$act_status_ok.' OK';
            // But if the names are not the same then action is partial
            if ($record['netdnsname'] != $record['dbdnsname']) { $action = '&nbsp;'.$act_status_partial.' Update DNS'; }
            if (strstr($record['dbdnsname'], '(')) { $action = '&nbsp;'.$act_status_partial.' Update DNS PTR'; }
        }


        // if the database name is empty, then provide a generic "name"
        if (!$record['dbdnsname'] and ($record['dbip'] != 'NOT FOUND') and $record['netdnsname']) $record['dbdnsname'] = 'NONE SET';

        // if the names are different, offer an edit button for the DB
        if ($record['dbdnsrows']>0) {
          if (($record['netdnsname']) and strtolower($record['netdnsname']) != $record['dbdnsname']) {
            // not a lot of testing here to make sure it will find the right name.
            list($status, $rows, $rptdnsrecord) = ona_find_dns_record($record['dbdnsname']);
            $record['dbdnsname'] = <<<EOL
                    <a title="Edit DNS record"
                        class="act"
                        onClick="xajax_window_submit('edit_record', 'dns_record_id=>{$rptdnsrecord['id']},ip_addr=>{$record['dbip']},hostname=>{$hostname},domain_id=>{$domain['id']},js=>null', 'editor');"
                    >{$record['dbdnsname']}</a>
EOL;
          }
        }


        // If we have more than 2 dns records, display info about them
        if ($record['dbdnsrows'] > 2) {
                $dbdnsinfo = "<span style='font-weight: bold;'>{$record['dbdnsname']}&nbsp;{$record['dbdnsptrname']}</span>";
	} else {
                $dbdnsinfo = "{$record['dbdnsname']}&nbsp;{$record['dbdnsptrname']}";
        }


        $txt = <<<EOL
            <tr onMouseOver="this.className='row-highlight'" onMouseOut="this.className='row-normal'">
                <td class="list-row" align="left" style="{$style['borderR']};{$redcolor}">{$record['netstatus']}</td>
                <td class="list-row" align="left" style="{$redcolor}">{$record['netip']}</td>
                <td class="list-row" align="left">{$record['netdnsname']}&nbsp;</td>
                <td class="list-row" align="left">{$record['netdevindex']}&nbsp;</td>
                <td class="list-row" align="left" style="{$style['borderR']};">{$record['netmacaddr']}&nbsp;</td>
                <td class="list-row" align="left">{$record['dbip']}&nbsp;</td>
                <td class="list-row" align="left">{$dbdnsinfo}</td>
                <td class="list-row" align="left">{$record['dbdevindex']}&nbsp;</td>
                <td class="list-row" align="left" style="{$style['borderR']};">{$record['dbmacaddr']}&nbsp;</td>
                <td class="list-row" align="left">{$viewsubnet}{$action}&nbsp;</td>
            </tr>
EOL;


        // if we are in all mode, print only errors.. otherwise, print it all
        if ($form['all'] and strpos($action,'OK')) $txt = '';
        // add the new line to the html output variable
        $text .= $txt;
    }


    $text .=  "</table>{$hostpoolinfo}<center>END OF REPORT</center></div>";


    return(array(0,$text));
}





// csv wrapper function
function rpt_output_csv($form) {
    $form['csv_output'] = true;
    list($stat,$out) = rpt_output_text($form);
    return(array($stat,$out));
}





// output for text
function rpt_output_text($form) {
    global $onadb, $style, $images;

    // Provide a usage message here
    $usagemsg = <<<EOL
Report: nmap_scan
  Processes the XML output of an nmap scan and compares it to data in the database.

  Required:
    subnet=ID|IP|STRING   Subnet ID, IP, or name of existing subnet with a scan
      OR
    file=PATH             Local XML file will be sent to server for processing
      OR
    all                   Process ALL XML files on the server
      OR
    update_response       Update the last response field for all UP IPs to time in scan

  Output Formats:
    html
    text
    csv

NOTE: When running update_response, any entry that was updated will have a ~ indication
      at the beginning of the line.
      DNS names with a * preceeding them indicate there are more than one name available
      for this entry and it could have a more common name associated with it.

EOL;

    // Provide a usage message
    if ($form['rpt_usage']) {
        return(array(0,$usagemsg));
    }


    if (!$form['all']) { $text .=  "NMAP scan of {$form['totalhosts']} hosts done on {$form['runtime']}. {$form['scansource']}\n\n";
    } else {
        $text .= "Displaying records for ALL nmap scans in the system.  It also only shows issues, not entries that are OK.\n\n";
    }

    //$text .= sprintf("%-50s %-8s %-8s\n",'NMAP SCAN','DATABASE','Actions');
    if ($form['csv_output'])
        $text .= sprintf("%s,%s,%s,%s,%s,%s,%s,%s\n",'STAT','NET IP','NET NAME','NET MAC','DB IP','DB NAME','DB MAC','ACTION');
    else
        $text .= sprintf("%-6s %-15s %-25s %-12s %-15s %-25s %-12s %s\n",'STAT','NET IP','NET NAME','NET MAC','DB IP','DB NAME','DB MAC','ACTION');

    // netip    netname     netmac      dbip    dbname  dbmac

    $poolhostcount = 0;

    foreach ((array)$form['ip'] as $record) {

        // scans with only one row in them may show up wrong, skip them
        if (!$record['netstatus'] and !$record['netip']) continue;

        $action='';
        $upresp=' ';

        // Check devices that are down
        if ($record['netstatus'] == "down") {
            // Skip over hosts that are not in network or database
            if ($record['dbip'] == "NOT FOUND") continue;
            // If it is only in the database then they should validate the ip or remove from database
            if (($record['netip'] == $record['dbip']) or ($record['netdnsname'] != $record['dbdnsname'])) {
                $action = "Ping to verify then delete as desired";
            }
        }

        // check devices that are up
        if ($record['netstatus'] == "up") {

            // If this is the subnet address or broadcast then skip it.  Sometimes nmap shows them as up
            if ($record['netip'] == $form['netip']) continue;
            if ($record['netip'] == $broadcastip) continue;

            // update the database last response field.
            if ($form['update_response'] and $record['dbip'] != "NOT FOUND") {
                //if (isset($options['dcm_output'])) { $text .=  "dcm.pl -r interface_modify interface={$record['ip']} set_last_response='{$runtime}'\n"; }
                list($updatestatus, $output) = run_module('interface_modify', array('interface' => $record['dbip'], 'set_last_response' => $form['runtime']));
                if ($updatestatus) {
                    $self['error'] = "ERROR => Failed to update response time for '{$record['dbip']}': " . $output;
                    printmsg($self['error'], 1);
                }
                $upresp='~';
            }

            // Break out the host and domain parts of the name if we can
            if ($record['netdnsname']) {
                list($status, $rows, $domain) = ona_find_domain($record['netdnsname'],0);
                // Now find what the host part of $search is
                $hostname = str_replace(".{$domain['fqdn']}", '', $record['netdnsname']);
            }

            // If we dont find it in the database
            if ($record['dbip'] == "NOT FOUND") $action = "Add as host or Add as interface, check proper pool range";

            // If it is in the database and network
            if ($record['netip'] == $record['dbip']) {
                $action = 'OK';
                // But if the names are not the same then action is partial
                if ($record['netdnsname'] != $record['dbdnsname']) { $action = 'Update DNS'; }
                if (strstr($record['dbdnsname'], '(')) { $action = 'Update DNS PTR'; }
            }


            // if the database name is empty, then provide a generic "name"
            if (!$record['dbdnsname'] and ($record['dbip'] != 'NOT FOUND') and $record['netdnsname']) $record['dbdnsname'] = 'NONE SET';

            // if the names are different, offer an edit button for the DB
            if (($record['netdnsname']) and strtolower($record['netdnsname']) != $record['dbdnsname']) {
                // not a lot of testing here to make sure it will find the right name.
                list($status, $rows, $rptdnsrecord) = ona_find_dns_record($record['dbdnsname']);
            }

        }

        // If we have more than 2 dns records, display info about them
        if ($record['dbdnsrows'] > 2) {
            $record['dbdnsname'] = '*'.$record['dbdnsname'];
	}

/*
TODO:
* more testing of mac address stuff
* display info about last response time.. add option to update last response form file.. flag if db has newer times than the scan
*/
        if ($form['csv_output']) {
            $txt = sprintf("%s,%s,%s,%s,%s,%s,%s,\"%s\"\n", $upresp.$record['netstatus'],$record['netip'],$record['netdnsname'],$record['netmacaddr'],$record['dbip'],$record['dbdnsname'].' '.$record['dbdnsptrname'],$record['dbmacaddr'],$action);
        } else {
            $txt = sprintf("%-6s %-15s %-25s %-12s %-15s %-25s %-12s %s\n",$upresp.$record['netstatus'],$record['netip'],$record['netdnsname'],$record['netmacaddr'],$record['dbip'],$record['dbdnsname'].' '.$record['dbdnsptrname'],$record['dbmacaddr'],$action);
        }

        // if we are in all mode, print only errors.. otherwise, print it all
        if ($form['all'] and $action == 'OK') $txt = '';
        // add the new line to the html output variable
        $text .= $txt;
    }


    $text .=  "\n{$hostpoolinfo}END OF REPORT";


    return(array(0,$text));
}














?>

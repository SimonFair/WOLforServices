#!/usr/bin/php
<?php


$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/webGui/include/Custom.php";
require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt_helpers.php";
require_once "$docroot/plugins/dynamix.vm.manager/include/libvirt.php";
require_once "$docroot/plugins/dynamix.plugin.manager/include/PluginHelpers.php"; 
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";

function getContainerStats($container, $option) {
  exec("lxc-info " . $container, $content);
  foreach($content as $index => $string) {
    if (strpos($string, $option) !== FALSE)
      return trim(explode(':', $string)[1]);
  }
}

$mac = strtolower($argv[1]);

$libvirtd_running = is_file('/var/run/libvirt/libvirtd.pid') ;
$dockerd_running = is_file('/var/run/dockerd.pid');
$lxc_ls_exist = is_file('/usr/bin/lxc-ls');
#$RUNDOCKER = $RUNLXC = $RUNVM = true;
extract(parse_ini_file("/boot/config/wol.cfg")) ;
if (!isset($RUNLXC)) $RUNLXC = "y";
if (!isset($RUNVM)) $RUNVM = "y";
if (!isset($RUNDocker)) $RUNDocker = "y";
if (!isset($RUNSHUT)) $RUNSHUT = "n";
if (!isset($RUNSUSPMODE)) $RUNSUSPMODE = "disk";
if (!isset($RUNSUSPCMD)) $RUNSUSPCMD = "suspend";

$arrEntries = [] ;
if ($libvirtd_running && $RUNVM == "y") {
  $vms = $lv->get_domains();
  sort($vms,SORT_NATURAL);
  foreach($vms as $vm){
    $arrEntries['VM'][$vm]['interfaces'] = $lv->get_nic_info($vm);
    $arrEntries['VM'][$vm]['name'] = $vm;
  }
}
if ($dockerd_running && $RUNDOCKER == "y") {
  $DockerClient = new DockerClient();
  $containers      = $DockerClient->getDockerJSON("/containers/json?all=1");
  foreach($containers as $container)
    $arrEntries['Docker'][ substr($container["Names"][0],1) ] = [
        'interfaces' => ['0' => ['mac' => isset($container["NetworkSettings"]["Networks"]["bridge"]["MacAddress"]) ? $container["NetworkSettings"]["Networks"]["bridge"]["MacAddress"]:""]],
        'name' => substr($container["Names"][0],1),
        'state' => $container["State"],
    ];
}

if ($lxc_ls_exist && $RUNLXC == "y") {
  $lxc = explode("\n",shell_exec("lxc-ls -1")) ;
  $lxcpath = trim(shell_exec("lxc-config lxc.lxcpath"));
  foreach ($lxc as $lxcname) {
    if ($lxcname == "") continue;
    $values = explode("=",shell_exec("cat $lxcpath/$lxcname/config  | grep 'hwaddr'"));
    $arrEntries['LXC'][$lxcname]['interfaces'][0]['mac'] = trim($values[1]);
    $arrEntries['LXC'][$lxcname]['name'] = $lxcname;
  }
}

if (is_file("/boot/config/wol.json")) $user_mac = json_decode(file_get_contents("/boot/config/wol.json"),true); else $user_mac = [];

foreach($arrEntries as $typekey => $typedata)
{
  foreach($typedata as $typeEntry){
    $name=$typeEntry['name'];
    if (isset($user_mac[$typekey][$name])) {
      
      $name=$name;
      
      $arrEntries[$typekey][$name]['enable'] = $user_mac[$typekey][$name]['enable'];
      $arrEntries[$typekey][$name]['user_mac'] = strtolower($user_mac[$typekey][$name]['user_mac']);

    } else {
      $arrEntries[$typekey][$name]['enable'] = "enable";
      $arrEntries[$typekey][$name]['user_mac'] = 'None Defined';
    }
  }
}


$mac_list=[];
foreach($arrEntries as $type => $detail)
  {
    foreach($detail as $name => $entryDetail)
      {
        foreach($entryDetail['interfaces'] as $interfaces)
        {
            $interfaces['mac'] = strtolower($interfaces['mac']);
            if($interfaces['mac'] == "" && $entryDetail['user_mac'] == "None Defined") continue;
            if (isset($entryDetail['state'])) $state = $entryDetail['state']; else $state = "";
            if (isset($entryDetail['enable']) && !$entryDetail['enable'] ) $enable = false; else $enable = true;
            if ($entryDetail['user_mac'] != "None Defined") {
              $mac_list[$entryDetail['user_mac']] = [
                'type' => $type,
                'name' => $name,
                'state' => $state,             
                'enable' => $entryDetail['enable'],
              ];
            }
            if ($interfaces['mac'] != "") {
               $mac_list[$interfaces['mac']] = [
              'type' => $type,
              'name' => $name,
              'state' => $state,
              'enable' => $entryDetail['enable'],
            ];
          }
        }
      }
  }

 

  $found = array_key_exists($mac,$mac_list);


if ($found && $mac_list[$mac]['enable'] != "disable") {
        echo _("Found"). " " . $mac . " " .$mac_list[$mac]['type'] . " " . $mac_list[$mac]['name'];
        switch ($mac_list[$mac]['type']) {
        
          case "VM":
            if ($libvirtd_running && $RUNVM == "y") {
            $res = $lv->get_domain_by_name($mac_list[$mac]['name']);
            $dom = $lv->domain_get_info($res);
            $state = $lv->domain_state_translate($dom['state']);
            switch ($state) {
              case 'running':
                if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "shutdown") $lv->domain_shutdown("{$mac_list[$mac]['name']}");
                if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "suspend") { 
                  if ($RUNSUSPCMD == "dompmsuspend") echo " Suspend $RUNSUSPMODE ".shell_exec("virsh dompmsuspend \"{$mac_list[$mac]['name']}\" $RUNSUSPMODE ");
                  if ($RUNSUSPCMD == "suspend") $lv->domain_suspend("{$mac_list[$mac]['name']}");
                }
                if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "restart") $lv->domain_reboot("{$mac_list[$mac]['name']}");
                break;
              case 'paused':
                $lv->domain_resume("{$mac_list[$mac]['name']}");
                break;
              case 'pmsuspended':
                shell_exec("virsh dompmwakeup \"{$mac_list[$mac]['name']}\"");
                break;
              default:
                $lv->domain_start("{$mac_list[$mac]['name']}");
              }
            } 
            break;
          case "LXC":
            if ($lxc_ls_exist && $RUNLXC == "y") {
            $state = getContainerStats($mac_list[$mac]['name'], "State");
            switch ($state) {
              case 'RUNNING':
                if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "shutdown") shell_exec("lxc-stop {$mac_list[$mac]['name']}");
                if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "suspend") shell_exec("lxc-freeze {$mac_list[$mac]['name']}");
                if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "restart") {
                  shell_exec("lxc-stop {$mac_list[$mac]['name']}");
                  sleep(1);
                  shell_exec("lxc-start {$mac_list[$mac]['name']}");
                }
                break;
              case 'FROZEN':
                shell_exec("lxc-unfreeze {$mac_list[$mac]['name']}");  
                break;
              default:
                shell_exec("lxc-start {$mac_list[$mac]['name']}");  
              }
            }
            break;
          case "Docker":
            if ($dockerd_running && $RUNDOCKER == "y") {
              
              switch ($mac_list[$mac]['state']) {
                case "running":
                  if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "shutdown") shell_exec("docker stop {$mac_list[$mac]['name']}");
                  if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "suspend") shell_exec("docker pause {$mac_list[$mac]['name']}");
                  if ($RUNSHUT == "y" && $mac_list[$mac]['enable'] == "restart") shell_exec("docker restart {$mac_list[$mac]['name']}");
                  break;
                case "exited":
                  case "created":
                    shell_exec("docker start {$mac_list[$mac]['name']}");
                  break;
                  case "paused":
                    shell_exec("docker unpause {$mac_list[$mac]['name']}");
              }
            }
            break;
          }
        } else {
          if ($mac_list[$mac]['enable'] == "disable")  echo  $mac . " " . _(" has not been actioned as set to disabled");
          else echo _("Not Found"). " " . $mac . " " . _("ignoring or Maybe actions disabled for type(Docker/VM/LXC)");
        }

?>

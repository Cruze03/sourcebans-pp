<?php
/*************************************************************************
This file is part of SourceBans++

SourceBans++ (c) 2014-2023 by SourceBans++ Dev Team

The SourceBans++ Web panel is licensed under a
Creative Commons Attribution-NonCommercial-ShareAlike 3.0 Unported License.

You should have received a copy of the license along with this
work.  If not, see <http://creativecommons.org/licenses/by-nc-sa/3.0/>.

This program is based off work covered by the following copyright(s):
SourceBans 1.4.11
Copyright © 2007-2014 SourceBans Team - Part of GameConnect
Licensed under CC-BY-NC-SA 3.0
Page: <http://www.sourcebans.net/> - <http://www.gameconnect.net/>
*************************************************************************/

include_once '../init.php';

if (!$userbank->HasAccess(ADMIN_OWNER | ADMIN_ADD_BAN)) {
    echo "No Access";
    die();
}
require_once(INCLUDES_PATH . '/xajax.inc.php');
$xajax = new xajax();
//$xajax->debugOn();
$xajax->setRequestURI("./admin.kickit.php");
$xajax->registerFunction("KickPlayer");
$xajax->registerFunction("LoadServers");
$xajax->processRequests();
$username = $userbank->GetProperty("user");

function LoadServers($check, $type)
{
    $objResponse = new xajaxResponse();
    global $userbank, $username;
    if (!$userbank->HasAccess(ADMIN_OWNER | ADMIN_ADD_BAN)) {
        $objResponse->redirect("index.php?p=login&m=no_access", 0);
        Log::add("w", "Hacking Attempt", "$username tried to use the kick function, but doesnt have access.");
        return $objResponse;
    }
    $id      = 0;
    $servers = $GLOBALS['db']->Execute("SELECT sid, rcon FROM " . DB_PREFIX . "_servers WHERE enabled = 1 ORDER BY modid, sid;");
    while (!$servers->EOF) {
        //search for player
        if (!empty($servers->fields["rcon"])) {
            $text = '<font size="1">Searching...</font>';
            $objResponse->addScript("xajax_KickPlayer('" . $check . "', '" . $servers->fields["sid"] . "', '" . $id . "', '" . $type . "');");
        } else { //no rcon = servercount + 1 ;)
            $text = '<font size="1">No rcon password.</font>';
            $objResponse->addScript('set_counter(1);');
        }
        $objResponse->addAssign("srv_" . $id, "innerHTML", $text);
        $id++;
        $servers->MoveNext();
    }
    return $objResponse;
}

function KickPlayer($check, int $sid, $num, $type)
{
    require_once("../includes/system-functions.php");
    $objResponse = new xajaxResponse();
    global $userbank, $username;

    if (!$userbank->HasAccess(ADMIN_OWNER | ADMIN_ADD_BAN)) {
        $objResponse->redirect("index.php?p=login&m=no_access", 0);
        Log::add("w", "Hacking Attempt", "$username tried to process a kick, but doesnt have access.");
        return $objResponse;
    }

    $ret = rcon('status', $sid);

    if (!$ret) {
        $objResponse->addAssign("srv_$num", "innerHTML", "<font color='red' size='1'><i>Can't connect to server.</i></font>");
        $objResponse->addScript('set_counter(1);');
        return $objResponse;
    }

    // show hostname instead of the ip, but leave the ip in the title
    $hostsearch = preg_match_all('/hostname:[ ]*(.+)/', $ret, $hostname, PREG_PATTERN_ORDER);
    $hostname   = trunc(htmlspecialchars($hostname[1][0]), 25);
    if (!empty($hostname))
        $objResponse->addAssign("srvip_$num", "innerHTML", "<font size='1'><span title='" . $sdata['ip'] . ":" . $sdata['port'] . "'>" . $hostname . "</span></font>");

    foreach (parseRconStatus($ret) as $player) {
        if ($type == 0) {
            //SteamID search
            if (\SteamID\SteamID::compare($player['steamid'], $check)) {
                $GLOBALS['PDO']->query("UPDATE `:prefix_bans` SET sid = :sid WHERE authid = :authid AND RemovedBy IS NULL");
                $GLOBALS['PDO']->bind(':sid', $sid);
                $GLOBALS['PDO']->bind(':authid', $check);
                $GLOBALS['PDO']->execute();

                $domain = Host::complete();
                rcon("kickid $player[id] \"You have been banned by this server, check $domain for more info\"", $sid);

                $objResponse->addAssign("srv_$num", "innerHTML", "<font color='green' size='1'><b><u>Player Found & Kicked!</u></b></font>");
                $objResponse->addScript("set_counter('-1');");
                return $objResponse;
            }
        } elseif ($type == 1) {
            //IP search
            if ($player['ip'] === $check) {
                $GLOBALS['PDO']->query("UPDATE `:prefix_bans` SET sid = :sid WHERE ip = :ip AND RemovedBy IS NULL");
                $GLOBALS['PDO']->bind(':sid', $sid);
                $GLOBALS['PDO']->bind(':ip', $check);
                $GLOBALS['PDO']->execute();

                $domain = Host::complete();
                rcon("kickid $player[id] \"You have been banned by this server, check $domain for more info\"", $sid);

                $objResponse->addAssign("srv_$num", "innerHTML", "<font color='green' size='1'><b><u>Player Found & Kicked!</u></b></font>");
                $objResponse->addScript("set_counter('-1');");
                return $objResponse;
            }
        }
    }

    $objResponse->addAssign("srv_$num", "innerHTML", "<font size='1'>Player not found.</font>");
    $objResponse->addScript('set_counter(1);');
    return $objResponse;
}

$servers = $GLOBALS['db']->Execute("SELECT ip, port, rcon FROM " . DB_PREFIX . "_servers WHERE enabled = 1 ORDER BY modid, sid;");
$theme->assign('total', $servers->RecordCount());
$serverlinks = [];
$num         = 0;
while (!$servers->EOF) {
    $info         = [];
    $info['num']  = $num;
    $info['ip']   = $servers->fields["ip"];
    $info['port'] = $servers->fields["port"];
    array_push($serverlinks, $info);
    $num++;
    $servers->MoveNext();
}
$theme->assign('servers', $serverlinks);
$theme->assign('xajax_functions', $xajax->printJavascript("../scripts", "xajax.js"));
$theme->assign('check', $_GET["check"]); // steamid or ip address
$theme->assign('type', $_GET['type']);

$theme->left_delimiter  = "-{";
$theme->right_delimiter = "}-";
$theme->display('page_kickit.tpl');
$theme->left_delimiter  = "{";
$theme->right_delimiter = "}";

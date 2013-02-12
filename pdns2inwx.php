#!/usr/bin/env php
<?php
/*
 * migrate dns records from pdns mysql database to inwx nameserver via inwx API.
 * NS and SOA records are not migrated.
 * make sure to adjust config!
 *
 * by Andre Pascha <bender@duese.org>
 *
 */

error_reporting(E_ALL);
require "domrobot.class.php";


// hostmasters mail for SOA
$soaEmail = 'hostmaster@YOURDOMAIN.com';

// INWX API host
$apihost = "https://api.domrobot.com/xmlrpc/";
// INWX API username
$usr = "YOURUSERNAME";
// INWX API password
$pwd = "YOURPASSWORD";
// NS entries for WHOIS update
$nset = array (
    'ns.inwx.de',
    'ns2.inwx.de',
    'ns3.inwx.eu',
    'ns4.inwx.com',
    'ns5.inwx.net',
);

// PDNS mysql database settings
$dbuser = 'pdns';
$dbpass = 'YOURPASSWORD';
$dbname = 'pdns';
$dbhost = 'localhost';

function usage() {
    echo("usage: ".basename(__FILE__)." <domainname> [subdomainname]\n");
    echo("example: ".basename(__FILE__).": mydomain.org sub.mydomain.org\n");
}

function mlog($msg, $res) {
    echo($msg.": ".$res['msg']."\n");
    if ($res['code'] != 1000) {
        print_r($res);
        echo("\n");
        exit(1);
    }
}

if (!isset($argv[1])) {
    usage();
    exit(1);
}

$domain = $argv[1];
$issubdomain = false;
if (isset($argv[2])) {
    $issubdomain = true;
    $subdomain = $argv[2];
}
$olddomain = $issubdomain ? $subdomain : $domain;

$db = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
$res = $db->query("SELECT id FROM domains WHERE name='{$olddomain}'");
$rec = $res->fetch_assoc();
$dmid = $rec['id'];
$res = $db->query("SELECT name, type, content, ttl, prio FROM records WHERE domain_id=".$dmid." AND type!='SOA' AND type!='NS'");
while ($row = $res->fetch_assoc()) {
    $entries[] = $row;
}
echo("current domain entries\n");
foreach ($entries as $id => $ent) {
    echo(implode("\t", $ent)."\n");
}


$domrobot = new domrobot($apihost);
$domrobot->setDebug(false);
$domrobot->setLanguage('en');
$res = $domrobot->login($usr, $pwd);
mlog("login to inwx api", $res);

$res = $domrobot->call('domain','check',array('domain' => $domain));
mlog("check domain status", $res);

if (!$issubdomain) {
    $res = $domrobot->call('nameserver', 'delete', array('domain'=> $domain));
    echo("delete nameserver entries: ".$res['msg']."\n");

    $params = array();
    $params = array('domain' => $domain,
        'type'=>'MASTER', 
        'soaEmail'=> $soaEmail,
        'ns' => $nset);
    $res = $domrobot->call('nameserver', 'create', $params);
    mlog("create nameserver default entries", $res);
}

foreach ($entries as $e) {
    $domrobot->call('nameserver', 'createRecord', array('domain' => $domain,
        'type' => $e['type'],
        'content' => $e['content'],
        'name' => $e['name'],
        'prio' => $e['prio'],
        'ttl' => $e['ttl'],
    ));
    $r = implode(" ", $e);
    mlog("migrate record [".$r."]", $res);
}

if (!$issubdomain) {
    $res = $domrobot->call('domain', 'info', array('domain' => $domain));
    $n = $res['resData']['ns'];
    if (array_diff($n, $nset)) {
        $res = $domrobot->call('domain', 'update', array('domain'=>$domain, 'ns' => $nset));
        mlog("update nameserver set", $res);
    }
}


$res = $domrobot->logout();

?>

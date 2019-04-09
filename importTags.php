<?php
/*
 * Description:
 * Author: feipeixuan
 */
//include __DIR__ . "/../../common/common.inc.php";

function getUsers($file)
{
    $users = array();
    $resource = fopen($file, "r");
    while (!feof($resource)) {
        $line = fgets($resource);
        $userid = str_replace("\n","",$line);
        $users[]=$userid;
    }
    fclose($resource);
    return $users;
}

function importTags($users,$type){
    $typeArray=array("cron"=>"c","hot"=>"h","robot"=>"r","gift"=>"g");
    $key=$typeArray[$type];
    echo $key."\n";
//    $redis_super=useSuperNutRedis::getInstance();
//    foreach ($users as $userid){
//        $redis_super->init()->hset("risk$userid",$key,1);
//    }
}

importTags(null,"hot");
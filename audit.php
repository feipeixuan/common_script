<?php
/*
 * Description:
 * Author: feipeixuan
 */
ini_set('memory_limit', '-1');
function getUsers($file,$flag=false)
{
    $users = array();
    $resource = fopen($file, "r");
    while (!feof($resource)) {
        $line = fgets($resource);
        $line = str_replace("\n","",$line);
        if(!$flag){
            $users[]=$line;
        }else{
            $userid=explode(":",$line)[0];
            $num=explode(":",$line)[1];
            $users[$userid]=$num;
        }
    }
    fclose($resource);
    return $users;
}

function outputUsers($users,$filename){
    foreach($users as $userid){
        file_put_contents($filename,$userid."\n",FILE_APPEND);
    }
}

$array1=getUsers("input/hotUser.txt");
$array2=getUsers("input/invalidUser.txt");
$users=array();
foreach ($array1 as $userid=>$num){
    if(!in_array($userid,$array2)){
        $users[$userid]=$num;
        echo "111\n";
    }else{
        echo "222\n";
    }
}
//outputUsers($users,"output/new1_hotUser.txt");





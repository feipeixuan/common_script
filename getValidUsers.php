<?php
/*
 * Description:
 * Author: feipeixuan
 */
include __DIR__ . "/../../common/common.inc.php";

function getInValidUsers(){
    global $db_stats_write;
    $startId=1;
    $endId=42190116;
    $step=20000;
    while (true){
        $sql="select userid from user_valid where id between $startId and $startId+$step and release_time >='2025-01-01'";
        $rows=$db_stats_write->getAll($sql);
        foreach ($rows as $row){
            file_put_contents("invalidUser.txt",$row['userid']."\n",FILE_APPEND);
        }
        $startId+=$step;
        if($startId>=$endId){
            return;
        }
    }
}

function getRobotBlackUsers(){
    global $ktv_rec_read;
    $startId=1;
    $endId=271182392;
    $step=50000;
    while (true){
        $sql="select userid from suspicious_robot_login_user where userid between $startId and $startId+$step";
        $rows=$ktv_rec_read->getAll($sql);
        foreach ($rows as $row){
            file_put_contents("robotUser.txt",$row['userid']."\n",FILE_APPEND);
        }
        $startId+=$step;
        if($startId>=$endId){
            return;
        }
    }
}


function getEvilGiftBlackUsers(){
    global $ktv_rec_read;
    $startId=1;
    $endId=271182392;
    $step=100000;
    while (true){
        $sql="select userid from suspicious_evil_gift_user where userid between $startId and $startId+$step";
        $rows=$ktv_rec_read->getAll($sql);
        foreach ($rows as $row){
            file_put_contents("evilGiftUser.txt",$row['userid']."\n",FILE_APPEND);
        }
        $startId+=$step;
        if($startId>=$endId){
            return;
        }
    }
}

function getCronBlackUsers(){
    global $db_stats_read;
    $startId=1;
    $endId=43786247;
    $step=40000;
    while (true){
        $sql="select userid from cron_black_user where id between $startId and $startId+$step";
        $rows=$db_stats_read->getAll($sql);
        foreach ($rows as $row){
            file_put_contents("cronUser.txt",$row['userid']."\n",FILE_APPEND);
        }
        $startId+=$step;
        if($startId>=$endId){
            return;
        }
    }
}


function getHotBlackUsers(){
    global $db_stats_read;
    $startId=1;
    $endId=271182392;
    $step=40000;
    while (true){
        $sql="select userid from hot_black_user where userid between $startId and $startId+$step";
        $rows=$db_stats_read->getAll($sql);
        foreach ($rows as $row){
            file_put_contents("hotUser.txt",$row['userid']."\n",FILE_APPEND);
        }
        $startId+=$step;
        if($startId>=$endId){
            return;
        }
    }
}


getRobotBlackUsers();
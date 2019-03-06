<?php
/*
 * Description:
 * Author: feipeixuan
 */


$rootDir = "/home/log/audit/20190305";
function getPhotos($path)
{
    $users=array();
    $fileList = scandir($path);     //获取目录下所有文件
    foreach ($fileList as $file) {
        if ($file != '.' && $file != '..' && $file!="result.txt") {    //排除掉当./和../
            if (is_dir($path . '/' . $file)) {
                $tmpUsers=getPhotos($path . '/' . $file);
                $users=array_merge($tmpUsers,$users);
            } else {
                $photoInfo = explode(".", $file)[0];
                $userid = explode(":", $photoInfo)[0];
                $photoid = explode(":", $photoInfo)[1];
                $users[$userid]=$photoid;
            }
        }
    }
    return $users;
}
$users=array();
$cronUsers=array();
$fileList=scandir($rootDir);
foreach ($fileList as $file){
    if ($file != '.' && $file != '..' && $file!="result.txt") {
        $users=array_merge(getPhotos($rootDir."/$file/photos"),$users);
        $cronUsers=array_merge($rootDir."/$file/cronphotos",$cronUsers);
    }
}
print_r(array_diff($users,$cronUsers));

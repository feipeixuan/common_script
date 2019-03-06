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
                $users[]=$photoid;
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
        $cronUsers=array_merge(getPhotos($rootDir."/$file/cronphotos"),$cronUsers);
    }
}
$users=array_unique($users);
$cronUsers=array_unique($cronUsers);
$normalUsers=array_diff($users,$cronUsers);
foreach ($normalUsers as $normalUser){
    file_put_contents(__DIR__."/result1.txt",$normalUser."\n",FILE_APPEND);
}

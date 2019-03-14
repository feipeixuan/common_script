<?php
/*
 * Description:识别广告头像的脚本，每个小时跑一次
 * Author: feipeixuan
 */

include __DIR__ . "/../../../../common/common.inc.php";
require __DIR__ . "/../../../../tools/aliyun_photo_review/photo_audit_by_aliyun.class.php";

$zuitaoKtv = ZuitaoKTV::getInstance();

class AdFinder
{
    // 基础文件路径
    const BASE_DIR = "/home/log/audit/";

    // 最大单日调用量
    const MAX_NUM = 30000;

    // 年月日
    private $dateTime;

    // 小时
    private $computeHour;

    // 父目录
    private $parentDir;

    // 基于阿里的图片审核器
    private $photoAudit;

    private $redis_super;

    private $firstPaymentActivityService;

    function __construct()
    {
        $curTime = time();
        $this->dateTime = date('Ymd', $curTime - 3600);
        $this->computeHour = date('H', $curTime - 3600);
        $this->parentDir = self::BASE_DIR . $this->dateTime . "/" . $this->computeHour;
        $this->photoAudit = AliyunPhotoAudit::getInstance();
        $this->redis_super = useSuperNutRedis::getInstance();
        $this->firstPaymentActivityService = new FirstPaymentActivityService();
        // 创建相关的文件目录
        if (!is_dir(self::BASE_DIR . $this->dateTime)) {
            mkdir(self::BASE_DIR . $this->dateTime);
        }
        if (!is_dir($this->parentDir)) {
            mkdir($this->parentDir);
        }
        $dirArray = array("input", "photos", "output", "cronphotos", "badphotos","simphotos");
        foreach ($dirArray as $dirName) {
            if (!is_dir($this->parentDir . "/" . $dirName)) {
                mkdir($this->parentDir . "/" . $dirName);
            }
        }
        $originalFile = self::BASE_DIR . "input/" . "$this->dateTime$this->computeHour" . ".log";
        $destinationFile = self::BASE_DIR . $this->dateTime . "/" . $this->computeHour . "/input/" . $this->computeHour . ".log";
        copy($originalFile, $destinationFile); //拷贝到新目录
        unlink($originalFile); //删除旧目录下的文件
    }

    function __destruct()
    {
        $logFile = self::BASE_DIR . $this->dateTime . "/" . $this->computeHour . "/input/" . $this->computeHour . ".log";
        unlink($logFile);
        $this->cleanDir($this->parentDir . "/" . "photos");
        $this->cleanDir($this->parentDir . "/" . "cronphotos");
        $this->cleanDir($this->parentDir . "/" . "simphotos");
    }

    /**
     * 得到广告用户
     * 1.提取评论用户 2.基于属性进行裁剪 3.下载头像 4.本地分析头像 5.可疑头像交给阿里判断
     */
    public function execute()
    {
        $users = $this->extractCommentUsers();
        $users = $this->filterUsers($users);
        $this->downloadPhotos($users, $this->parentDir . "/" . "photos");
        $this->analyzePhotosBySim();
        $this->analyzePhotosByRule();
        $adUsers = $this->analyzePhotosByAli();
        $this->handleAdUser($adUsers);
    }

    /**
     * 删除文件夹
     */
    private function cleanDir($path = null)
    {
        if (is_dir($path)) {    //判断是否是目录
            $fileList = scandir($path);     //获取目录下所有文件
            foreach ($fileList as $fileName) {
                if ($fileName != '.' && $fileName != '..') {
                    if (is_dir($path . '/' . $fileName)) {
                        $this->cleanDir($path . '/' . $fileName);    //递归调用删除方法
                        rmdir($path . '/' . $fileName);    //删除当前文件夹
                    } else {
                        unlink($path . '/' . $fileName);
                    }
                }
            }
        }
    }

    /**
     * 提取参与评论的用户
     */
    public function extractCommentUsers()
    {
        $commentUsers = array();
        $followUsers = array();
        $cronUsers = array();
        $logFile = self::BASE_DIR . $this->dateTime . "/" . $this->computeHour . "/input/" . $this->computeHour . ".log";
        $resource = fopen($logFile, "r");
        while (!feof($resource)) {
            $line = fgets($resource);
            $params = explode("\t", $line);
            if (count($params) < 2) {
                break;
            }
            $ac = $params [1];
            $userid = $params [2];
            if ($ac != "giveworkcomment" && $ac!="followuser") {
                continue;
            }
            if($ac == "giveworkcomment")
            {
                $workid = $params [4];
                if (!key_exists($userid, $commentUsers)) {
                    $commentUsers[$userid] = array();
                }
                if (!in_array($workid, $commentUsers[$userid])) {
                    $commentUsers[$userid][] = $workid;
                }
            }
            if($ac == "followuser")
            {
                $followid = $params [4];
                if (!key_exists($userid, $followUsers)) {
                    $followUsers[$userid] = array();
                }
                if (!in_array($followid, $followUsers[$userid])) {
                    $followUsers[$userid][] = $followid;
                }
            }
        }
        //阈值暂时调整到3
        foreach ($commentUsers as $userid=>$works){
            if(count($works)>3){
                $cronUsers[]=$userid;
            }
        }
        //阈值暂时调整到3
        foreach ($followUsers as $userid=>$follows){
            if(count($follows)>=3){
                $cronUsers[]=$userid;
            }
        }
        $cronUsers=array_unique($cronUsers);
        fclose($resource);
        return $cronUsers;
    }

    /**
     * 过滤相关的用户
     */
    public function filterUsers($users)
    {
        $cronUsers = array();
        foreach ($users as $userid) {
            $userinfo = UserService::getInstance()->getUserInfoSnapshot($userid);
            $richLevel = $userinfo->userlevel->richLevel;
            $starLevel = $userinfo->userlevel->starLevel;
            if ($richLevel >= 1 || $starLevel >= 1) {
                continue;
            }
            if (!($photoid = $this->getPhotoId($userid))) {
                continue;
            }
            // 判断该头像是否已经check过
            $this->redis_super->init();
            $key = 'checkphotos:' .$photoid;
            if ($this->redis_super->exists($key)) {
                continue;
            }
            $cronUsers[$userid] = $photoid;
        }
        return $cronUsers;
    }

    /**
     * 基于规则本地分析图片
     */
    private function analyzePhotosByRule()
    {
        $inputDir = $this->parentDir . "/" . "photos";
        $outputDir = $this->parentDir . "/" . "cronphotos";
        exec("python2 adfinder.py $inputDir $outputDir");
    }

    /**
     * 基于阿里服务分析图片
     */
    private function analyzePhotosByAli()
    {
        $cronPhotos = array();
        $adUsers = array();
        $fileList = scandir($this->parentDir . "/" . "cronphotos");
        // 加载可疑图片
        foreach ($fileList as $file) {
            if ($file != ".." && $file != ".") {
                $photoInfo = explode(".", $file)[0];
                $userid = explode(":", $photoInfo)[0];
                $photoid = explode(":", $photoInfo)[1];
                $cronPhotos[$userid] = $photoid;
            }
        }

        // 审核图片
        foreach ($cronPhotos as $userid => $photoid) {
            $url = "http://aliimg.changba.com/cache/photo/" . $photoid . "_200_200.jpg";
            $scenes = array("ad");
            $ret = $this->photoAudit->AuditPhoto(array($userid => $url), $scenes);
            $detail = array_shift($ret);
            if ($detail["status"] == AliyunPhotoAudit::$block) {
                $adUsers[$userid] = $photoid;
            }
            // 记录该头像已经check过
            $this->redis_super->init();
            $key = 'checkphotos:' .$photoid;
            $this->redis_super->setex($key,86400*3,"1");
            // 计数器加1
            $this->redis_super->init();
            $key = 'daily:checkphotosnum' . date('Ymd');
            $num = intval($this->redis_super->get($key));
            if ($num >= self::MAX_NUM) {
                break;
            }
            $this->redis_super->incr($key, 1);
            $this->redis_super->expire($key, 86400);
        }
        // 下载图片
        $this->downloadPhotos($adUsers, $this->parentDir . "/" . "badphotos");
        return $adUsers;
    }

    /**
     * 基于相似度分析图片
     */
    public function analyzePhotosBySim(){
        $inputDir = $this->parentDir . "/" . "photos/";
        $outputDir = $this->parentDir . "/" . "simphotos/";
        exec("python2 simfinder.py $inputDir $outputDir sim");
        $fileList = scandir($this->parentDir . "/" . "simphotos");
        // 反馈可疑图片
        foreach ($fileList as $file) {
            if ($file != ".." && $file != ".") {
                $photoInfo = explode(".", $file)[0];
                $userid = explode(":", $photoInfo)[0];
                $photoid = explode(":", $photoInfo)[1];
                $this->addFeedback($userid);
                // 后面的图片就不走python脚本了
                unlink($inputDir."$file");
                $info=$this->formatString($userid,$photoid);
                file_put_contents($this->parentDir . "/result.txt", $info, FILE_APPEND);
                file_put_contents(self::BASE_DIR . $this->dateTime . "/result.txt", $info, FILE_APPEND);
                $key = 'daily:badphotos' . date('Ymd');
                $this->redis_super->init();
                $this->redis_super->hset($key,$userid,$photoid);
                $this->redis_super->expire($key, 86400);
            }
        }
    }

    /**
     * 获取用户的头像id
     */
    function getPhotoId($userid)
    {
        global $ktv_read;
        $sql = "select headphoto from user where userid=$userid";
        $photoid = $ktv_read->getOne($sql);
        if (empty($photoid) or $photoid == 4) {
            return false;
        }
        return $photoid;
    }

    /**
     * 下载文件
     */
    function downloadFile($file_url, $save_to)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_URL, $file_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        $file_content = curl_exec($ch);
        curl_close($ch);
        $downloaded_file = fopen($save_to, 'w');
        fwrite($downloaded_file, $file_content);
        fclose($downloaded_file);
    }

    /**
     * 下载头像
     */
    private function downloadPhotos($users, $photoDir)
    {
        foreach ($users as $userid => $photoid) {
            $size = 200;
            $url = 'http://aliimg.changba.com/cache/photo/' . $photoid . "_" . $size . "_" . $size . ".jpg";
            $this->downloadFile($url, $photoDir . "/$userid:$photoid" . ".jpg");
        }
    }

    /**
     * 处理广告小号
     */
    private function handleAdUser($users)
    {
        global $zuitaoKtv;
        foreach ($users as $userid => $photoid) {
            // 获取版本信息
            $info=$this->formatString($userid,$photoid);
            file_put_contents($this->parentDir . "/result.txt", $info, FILE_APPEND);
            file_put_contents(self::BASE_DIR . $this->dateTime . "/result.txt", $info, FILE_APPEND);
            // 进行封禁处理
            //$zuitaoKtv->UpdateUserValid($userid, 'all', 0, 0, "广告头像$photoid");
            //$zuitaoKtv->SetUserHeadPhoto($userid, 4);
            $this->addFeedback($userid);
            $key = 'daily:badphotos' . date('Ymd');
            $this->redis_super->init();
            $this->redis_super->hset($key,$userid,$photoid);
            $this->redis_super->expire($key, 86400);
        }
        $this->redis_super->init();
        $key = 'daily:badphotosnum' . date('Ymd');
        $this->redis_super->incr($key, count($users));
        $this->redis_super->expire($key, 86400);
    }

    public function addFeedback($userid){
        global $db_admin_internal;
        $sql = "insert into feedback (actionid,userid, type, subtype, answered) values($userid, 0,1,0,0)";
        $db_admin_internal->query ( $sql );
    }

    private function formatString($userid,$photoid){
        $redis_user_extra = new useUserExtraRedis ();
        $userextrainfo = $redis_user_extra->init()->hGetAll("uid:{$userid}");
        $changbaversion = isset ($userextrainfo ['versionnumber']) ? $userextrainfo ['versionnumber'] : 'unknown';
        $info=date('Ymd_H').":$userid:$photoid:$changbaversion\n";
        if(!$this->firstPaymentActivityService->isFirstPaymentUser($userid)) {
            $info=date('Ymd_H').":$userid:$photoid:$changbaversion:付费用户\n";
        }
        return $info;
    }

}

$adFinder = new AdFinder();
$adFinder->execute();



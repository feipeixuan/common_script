<?php
/*
 * Description:识别广告头像的脚本，每个小时跑一次
 * Author: feipeixuan
 */

include __DIR__ . "/../../../common/common.inc.php";
require __DIR__ . "/../../../tools/aliyun_photo_review/photo_audit_by_aliyun.class.php";

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

    function __construct()
    {
        $curTime = time();
        $this->dateTime = date('Ymd', $curTime - 3600);
        $this->computeHour = date('H', $curTime - 3600);
        $this->parentDir = self::BASE_DIR . $this->dateTime . "/" . $this->computeHour;
        $this->photoAudit = AliyunPhotoAudit::getInstance();
        $this->redis_super = useSuperNutRedis::getInstance();
    }

    private function init()
    {
        // 创建相关的文件目录
        if (!is_dir(self::BASE_DIR . $this->dateTime)) {
            mkdir(self::BASE_DIR . $this->dateTime);
        }
        if (!is_dir($this->parentDir)) {
            mkdir($this->parentDir);
        }
        $dirArray = array("input", "photos", "output", "cronphotos", "badphotos");
        foreach ($dirArray as $dirName) {
            if (!is_dir($this->parentDir . "/" . $dirName)) {
                mkdir($this->parentDir . "/" . $dirName);
            }
        }
        $originalFile=self::BASE_DIR."input/"."$this->dateTime$this->computeHour".".log";
        $destinationFile=self::BASE_DIR.$this->dateTime. "/" . $this->computeHour. "/input/" . $this->computeHour . ".log";
        echo $originalFile."\n";
        echo $destinationFile."\n";
        //copy($originalFile,$destinationFile); //拷贝到新目录
        //unlink($originalFile); //删除旧目录下的文件
    }

    /**
     * 得到广告用户
     * 1.提取评论用户 2.基于属性进行裁剪 3.下载头像 4.本地分析头像 5.可疑头像交给阿里判断
     */
    public function execute()
    {
        $this->init();
        $users = $this->extractCommentUsers();
        $users = $this->filterUsers($users);
        $this->downloadPhotos($users, $this->parentDir . "/" . "photos");
        $this->analyzePhotosByRule();
        $adUsers = $this->analyzePhotosByAli();
        $this->handleAdUser($adUsers);
    }

    /**
     * 提取参与评论的用户
     */
    public function extractCommentUsers()
    {
        $users = array();
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
            if ($ac != "giveworkcomment") {
                continue;
            }
            if (!in_array($userid, $users)) {
                $users[] = $userid;
            }
        }
        fclose($resource);
        return $users;
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
            if ($richLevel >= 2 || $starLevel >= 2) {
                continue;
            }
            if (!($photoid = $this->getPhotoId($userid))) {
                continue;
            }
            // 判断该头像是否已经check过
            $this->redis_super->init();
            $key = 'daily:checkphotos' . date('Ymd');
            if ($this->redis_super->sismember($key, $photoid)) {
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
            $key = 'daily:checkphotos' . date('Ymd');
            $this->redis_super->sadd($key, $photoid);
            $this->redis_super->expire($key, 86400);
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
        global $zuitaoktv;
        foreach ($users as $userid => $photoid) {
            file_put_contents($this->parentDir."/result.txt","$userid\n");
            $zuitaoktv->UpdateUserValid($userid, 'all', 0, 0, "广告头像");
            $zuitaoktv->SetUserHeadPhoto ( $userid, 4 );
        }
    }
}

$instance = new AdFinder();
//$instance->execute();



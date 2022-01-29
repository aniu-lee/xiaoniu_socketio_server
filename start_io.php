<?php
use Workerman\Worker;
use Workerman\Timer;
use PHPSocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;

include __DIR__ . '/vendor/autoload.php';

function debug_log($content) {
    $debugInfo = debug_backtrace();
    echo "【".$debugInfo[0]['line']."】==========BEG\n";
    if (gettype($content) != "array") {
        echo "========$content==========\n\n";
    }else{
        print_r($content);
    }
    echo "==========END\n\n";
}

/**
 * @param $data 数组数据
 * @param $api_key api_key
 * @return string
 */
function get_sign($data,$api_key) {
    ksort($data);
    $items = array();
    foreach ($data as $key=>$value) {
        if ($key != 'sign' && $value) $items[] = $key.'='.$value;
    }
    $items[] = 'api_key='.$api_key;
//    print_r($items);
    return md5(join('&&',$items));
}

/**
 * aes 加密
 * @param $key 32位
 * @param $str 加密的字符串
 * @return string
 */
function encrypt($key,$str) {
    $iv = substr($key, 0,16);
    $strEncode= base64_encode(openssl_encrypt($str, 'AES-256-CBC',$key, OPENSSL_RAW_DATA , $iv));
    return $strEncode;
}

/**
 * 解密字符串
 * @param $key
 * @param $strEncode
 * @return false|string
 */
function decrypt($key,$strEncode) {
    $iv = substr($key, 0,16);
    return openssl_decrypt(base64_decode($strEncode), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

function http_post($url, $param) {
    debug_log("开始请求");
    $oCurl = curl_init ();
    if (stripos ( $url, "https://" ) !== FALSE) {
        curl_setopt ( $oCurl, CURLOPT_SSL_VERIFYPEER, FALSE );
        curl_setopt ( $oCurl, CURLOPT_SSL_VERIFYHOST, FALSE );
    }
    if (is_string ( $param )) {
        $strPOST = $param;
    } else {
        $aPOST = array ();
        foreach ( $param as $key => $val ) {
            $aPOST [] = $key . "=" . urlencode ( $val );
        }
        $strPOST = join ( "&", $aPOST );
    }
    $headers = array(
        'user-agent: xiaoniu_socketio_server',
    );
    curl_setopt($oCurl, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt ( $oCurl, CURLOPT_URL, $url );
    curl_setopt ( $oCurl, CURLOPT_RETURNTRANSFER, 1 );
    curl_setopt ( $oCurl, CURLOPT_POST, true );
    curl_setopt ( $oCurl, CURLOPT_POSTFIELDS, $strPOST );
    curl_setopt($oCurl, CURLOPT_HTTPHEADER,$headers);
    $sContent = curl_exec ( $oCurl );
    curl_close ( $oCurl );
    return $sContent;
}

function http_get($url){
    //初始化
    $ch = curl_init();
    if (stripos ( $url, "https://" ) !== FALSE) {
        curl_setopt ( $ch, CURLOPT_SSL_VERIFYPEER, FALSE );
        curl_setopt ( $ch, CURLOPT_SSL_VERIFYHOST, FALSE );
    }
    curl_setopt($ch, CURLOPT_URL,$url);
    // 执行后不直接打印出来
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    //执行并获取HTML文档内容
    $output = curl_exec($ch);
    //释放curl句柄
    curl_close($ch);
    return $output;
}

function boots($msg) {
    $ret = http_get("http://api.qingyunke.com/api.php?key=free&appid=0&msg=$msg");
    $re = json_decode($ret,true);
    return $re;
}

function format_url($url,$p) {
    $a = explode("?",$url);
    if (count($a) == 1) {
        return $url.'?'.$p;
    }
    return $url.'&'.$p;
}

$json_config_path = dirname(__FILE__).DIRECTORY_SEPARATOR.'config.json';

if (!file_exists($json_config_path)){
    exit("配置文件config.json不存在，请配置");
}

// 读取配置
$json_string = file_get_contents($json_config_path);

// 用参数true把JSON字符串强制转成PHP数组
$config_datas = json_decode($json_string, true);

if (gettype($config_datas) != 'array') {
    exit("配置文件config.json格式有误,请检查");
}

$signConnectionMap = array();

/**
 * 全局数组保存room在线数据
 * [
 * client_id:[
 *      room:[
 *          11111
 *  ]
 * ]
 * ]
 */
$roomConnectionMap = array();
/**
 * 全局保存离线消息
 *users[{client_id名称}] = [
 * [{room名称}]=>[
 *      'event'=>'',
 *      'data'=>'',//数据
 *      'time'=>11111//推送的时间
 *      ]
 * ]
 *
 */
$offlineMapInfo = array();

// PHPSocketIO服务
$sender_io = new SocketIO($config_datas['sock_port']);

if (!empty($config_datas['origins'])){
    // 限制链接
    $sender_io->origins($config_datas['origins']);
}

// 客户端发起连接事件时，设置连接socket的各种事件回调
$sender_io->on('connection', function($socket){

//    echo '链接上了=>'.$socket->id;
    /**
     * 30秒内没授权成功，就把链接删除掉
     */
    $socket->time_id = Timer::add(30,function ()use($socket){
        if (!isset($socket->client_id)) {
            // 如果没授权过，就断开链接
            $socket->emit("server_return", array('type'=>'login','errcode'=>1,'errmsg'=>'没有进行授权，链接已关闭,请授权'));
            $socket->disconnect();
//            $socket->close();
        }
    },false);

    // 当客户端发来登录事件时触发 登录成功才可加入房间 接收通知
    $socket->on('login', function ($login_datas)use($socket){

        global $config_datas,$signConnectionMap;

        if ($socket->client_id) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'已登录过了'));
            return;
        }

        if (gettype($login_datas) == 'string') {
            $login_datas = json_decode($login_datas, true);
            if(!$login_datas) {
                $socket->emit("server_return", array('type'=>'login','errcode'=>1,'errmsg'=>'数据格式有误'));
                return;
            }
        }

        $login_datas_type = gettype($login_datas);

        if ($login_datas_type != 'array') {
            $socket->emit("server_return", array('type'=>'login','errcode'=>1,'errmsg'=>'数据格式有误'));
            return;
        }

        // 验证一下
        if (!isset($login_datas['client_id']) || empty($login_datas['client_id'])) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'client_id没传哦'));
            return;
        }

        if (!isset($login_datas['time_stamp']) || empty($login_datas['time_stamp'])) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'time_stamp没传哦'));
            return;
        }

        if (!isset($login_datas['nonce_str']) || empty($login_datas['nonce_str'])) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'nonce_str没传哦'));
            return;
        }

        if (!isset($login_datas['sign'])) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'sign没传哦'));
            return;
        }

        if (!isset($config_datas['clients'][$login_datas['client_id']])) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'client_id不存在哦'));
            return;
        }

        $time_stamp = $login_datas['time_stamp'];

        if (!is_int($time_stamp)) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'time_stamp不是整数'));
            return;
        }

        if (intval($time_stamp) > time() + 30*60 || intval($time_stamp) < time() - 30*60) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'time_stamp已过期'));
            return;
        }

        $client_id = $login_datas['client_id'];

        $clients = $config_datas['clients'][$login_datas['client_id']];

        $api_key = $clients['api_key'];

        $sign = $login_datas['sign'];

        if (array_key_exists($sign,$signConnectionMap)) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'该sign已被使用,请重新生成'));
            return;
        }

        $_sign = get_sign($login_datas,$api_key);

        if ($sign != $_sign) {
            $socket->emit("server_return",array('type'=>'login','errcode'=>1,'errmsg'=>'不能重复提交'));
            return;
        }

        // 签名加入 防止重放
        $signConnectionMap[$sign] = time();

        if (isset($socket->time_id)) {
            Timer::del($socket->time_id);
            unset($socket->time_id);
        }

        // 1.可用于失去链接判断 2.判断是否登录与否
        $socket->client_id = $client_id;

        // 加入房间 这个得做处理
        $socket->join($client_id); //为了群发

        $socket->emit("server_return",array('type'=>'login','errcode'=>0,'errmsg'=>'恭喜登录成功！','data'=>array('sid'=>$socket->id)));

        // 判断是否回调有回调调用一下
        $login_cb = $clients['login_cb'];
        if(!empty($login_cb)) {
            if (strlen($login_cb) > 4) {
                if (substr($login_cb,0,4) == 'http') {
                    // 请求一下
                    http_get(format_url($login_cb,"sid=".$socket->id));
                }
            }
        }
    });

    // 加入房间
    $socket->on('join', function ($login_datas)use($socket){

        global $offlineMapInfo,$sender_io,$config_datas,$roomConnectionMap,$signConnectionMap;

        if (gettype($login_datas) == 'string') {
            $login_datas = json_decode($login_datas, true);
            if(!$login_datas) {
                $socket->emit("server_return", array('type'=>'join','errcode'=>1,'errmsg'=>'数据格式有误'));
                return;
            }
        }

        $login_datas_type = gettype($login_datas);

        if ($login_datas_type != 'array') {
            $socket->emit("server_return", array('type'=>'join','errcode'=>1,'errmsg'=>'数据格式有误'));
            return;
        }

        if (!isset($login_datas['client_id']) || empty($login_datas['client_id'])) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'client_id没传哦'));
            return;
        }

        if (!isset($login_datas['room']) || empty($login_datas['room'])) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'room没传哦'));
            return;
        }

        if (!isset($login_datas['time_stamp']) || empty($login_datas['time_stamp'])) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'time_stamp没传哦'));
            return;
        }

        if (!isset($login_datas['nonce_str']) || empty($login_datas['nonce_str'])) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'nonce_str没传哦'));
            return;
        }

        if (!isset($login_datas['sign'])) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'sign没传哦'));
            return;
        }
        if (!isset($config_datas['clients'][$login_datas['client_id']])) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'client_id不存在哦'));
            return;
        }

        $time_stamp = $login_datas['time_stamp'];

        if (!is_int($time_stamp)) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'time_stamp不是整数'));
            return;
        }

        if (intval($time_stamp) > time() + 30*60 || intval($time_stamp) < time() - 30*60) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'time_stamp已过期'));
            return;
        }

        $client_id = $login_datas['client_id'];

        $clients = $config_datas['clients'][$login_datas['client_id']];

        $api_key = $clients['api_key'];

        $room = $login_datas['room'];

        $sign = $login_datas['sign'];

        if (array_key_exists($sign,$signConnectionMap)) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'该sign已被使用,请重新生成'));
            return;
        }

        $_sign = get_sign($login_datas,$api_key);

        if ($sign != $_sign) {
            $socket->emit("server_return",array('type'=>'join','errcode'=>1,'errmsg'=>'验证失败'));
            return;
        }

        $signConnectionMap[$sign] = time();
        if (isset($socket->room) && (!in_array($room,$socket->room))) {
            array_push($socket->room,$room);
        }else {
            $socket->room = array($room);
        }
//        debug_log($socket->room);
        // 加入房间 这个得做处理
        $socket->join($client_id.'-'.$room);// 为了区分哪个客户端，防止互相影响

        // 加入记录
        if(!array_key_exists($client_id,$roomConnectionMap))
        {
            $roomConnectionMap[$client_id][$room] = [$socket->id];
        }else{
            if (in_array($room,$roomConnectionMap[$client_id])) {
                array_push($roomConnectionMap[$client_id][$room],$socket->id);
            }else{
                $roomConnectionMap[$client_id][$room] = [$socket->id];
            }
        }
//        debug_log($roomConnectionMap);
        //检查是否有离线的数据，发送过去一下
        if (isset($offlineMapInfo[$client_id]) && isset($offlineMapInfo[$client_id][$room])){
            foreach ($offlineMapInfo[$client_id][$room] as $key => $value){
                $data = (string)$value['datas'];
                $event = $value['event'];
                $time = $value['time'];
                //时间未过期可以推送
                if (time() - $time < 3*24*60*60) {
                    $sender_io->to($client_id.'-'.$room)->emit($event, array('datas'=>$data,'is_online'=>false));
                }
                unset($offlineMapInfo[$client_id][$room][$key]);
            }
        }

        $socket->emit("server_return",array('type'=>'join','errcode'=>0,'errmsg'=>'恭喜加入房间成功！'));
    });

    // 当客户端发来 推送事件
    $socket->on('push', function ($login_datas)use($socket){

        global $sender_io,$config_datas,$signConnectionMap,$roomConnectionMap;

        if (gettype($login_datas) == 'string') {
            $login_datas = json_decode($login_datas, true);
            if(!$login_datas) {
                $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'数据格式有误！'));
                return;
            }
        }

        $login_datas_type = gettype($login_datas);

        if ($login_datas_type != 'array') {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'数据格式有误！'));
            return;
        }

        // 验证一下
        if (!isset($login_datas['client_id']) || empty($login_datas['client_id'])) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'client_id没传哦！'));
            return;
        }

        if (!isset($login_datas['datas']) || empty($login_datas['datas'])) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'datas没传哦！'));
            return;
        }

        if (!isset($login_datas['event']) || empty($login_datas['event'])) {
            $event = 'message';
        }else{
            $event = $login_datas['event'];
        }

        if (!isset($login_datas['room']) || empty($login_datas['room'])) {
            $room = '';
        }else {
            $room = $login_datas['room'];
        }

        if (!isset($login_datas['time_stamp']) || empty($login_datas['time_stamp'])) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'time_stamp没传哦'));
            return;
        }

        if (!isset($login_datas['nonce_str']) || empty($login_datas['nonce_str'])) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'nonce_str没传哦'));
            return;
        }

        if (!isset($login_datas['sign'])) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'sign没传哦'));
            return;
        }

        if (!isset($config_datas['clients'][$login_datas['client_id']])) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'client_id不存在哦'));
            return;
        }

        $time_stamp = $login_datas['time_stamp'];

        if (!is_int($time_stamp)) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'time_stamp不是整数'));
            return;
        }

        if (intval($time_stamp) > time() + 30*60 || intval($time_stamp) < time() - 30*60) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'time_stamp已过期'));
            return;
        }

        $client_id = $login_datas['client_id'];

        $clients = $config_datas['clients'][$login_datas['client_id']];

        $api_key = $clients['api_key'];

        $sign = $login_datas['sign'];

        if (array_key_exists($sign,$signConnectionMap)) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'该sign已被使用,请重新生成'));
            return;
        }

        $_sign = get_sign($login_datas,$api_key);

        if ($sign != $_sign) {
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'验证失败'));
            return;
        }

        $signConnectionMap[$sign] = time();
        $content = $login_datas['datas'];
        //内容要做处理 如果 aes_key 不为空 则需要加密
        if (!empty($config_datas['clients'][$client_id]['aes_key'])) {
            $content = encrypt($config_datas['clients'][$client_id]['aes_key'],$content);
        }

        if (array_key_exists($client_id,$roomConnectionMap) && array_key_exists($room,$roomConnectionMap[$client_id])) {
            if($room){
                $to = $client_id.'-'.$room;
            }else{
                $to =$client_id;
            }
            $sender_io->to($to)->emit($event, array('sid'=>$socket->id,'datas'=>$content,'room'=>$room));
            return;
        }else{
            $socket->emit("server_return",array('type'=>'push','errcode'=>1,'errmsg'=>'offline'));
            return;
        }
    });

    $socket->on('disconnect', function () use($socket) {
        // 未链接上 失去链接 删除 定时器
        if (isset($socket->time_id)) {
            Timer::del($socket->time_id);
            unset($socket->time_id);
        }

        // 未登录直接退出
        if (!isset($socket->client_id)) {
            return;
        }

        global $roomConnectionMap,$config_datas;

        $clients = $config_datas['clients'][$socket->client_id];

        // 判断是否回调有回调调用一下
        $disconnect_cb = $clients['disconnect_cb'];

        $sid = $socket->id;

        if(!empty($disconnect_cb)) {
            if (strlen($disconnect_cb) > 4) {
                if (substr($disconnect_cb,0,4) == 'http') {
                    // 请求一下
                    http_get(format_url($disconnect_cb,"sid=".$sid));
                }
            }
        }

        // 未进入房间
        if(!isset($socket->room)) {
            return;
        }

        foreach ($socket->room as $k=>$v) {

            if(array_key_exists($v,$roomConnectionMap[$socket->client_id]))
            {
                if (in_array($sid,$roomConnectionMap[$socket->client_id][$v])) {
                    unset($roomConnectionMap[$socket->client_id][$v][array_search($sid,$roomConnectionMap[$socket->client_id][$v])]);
                }
                if (empty($roomConnectionMap[$socket->client_id][$v])) unset($roomConnectionMap[$socket->client_id][$v]);
            }

        }
        // 判断是否为空 那就把它unset 减少内存开销
        if(empty($roomConnectionMap[$socket->client_id])) {
            unset($roomConnectionMap[$socket->client_id]);
        }
        // 减少内存开销
        unset($socket);
    });
});


$sender_io->on('workerStart', function(){
    // 监听一个http端口
    global $config_datas;

    $inner_http_worker = new Worker('http://0.0.0.0:'.$config_datas['http_port']);

    $inner_http_worker->count = 4;

    // 当http客户端发来数据时触发
    $inner_http_worker->onMessage = function(TcpConnection $http_connection, Request $request){
        // 推送数据的url格式 event=xxx&room=uid&content=xxxx&is_save_offline=1&client_id=
        global $roomConnectionMap,$sender_io,$offlineMapInfo,$config_datas;

        $post = $request->post();

        $_POST = $post ? $post : $request->get();

        $is_save_offline = @$_POST['is_save_offline'] ? @$_POST['is_save_offline'] : 0;

        if (!(intval($is_save_offline)==0 or intval($is_save_offline)==1)) {
            return $http_connection->send(json_encode(['errcode'=>1,'errmsg'=>'是否要存储离线消息有误，只能传1,0'],JSON_UNESCAPED_UNICODE));
        }

        $event = @$_POST['event'] ? @$_POST['event'] : 'message';

        $content = @$_POST['content'];//推送的内容不能为空

        $room = @$_POST['room']; //房间号 必填

        $client_id = @$_POST['client_id']; //client_id也不能为空，这个从配置里面获取

        if (empty($client_id)){
            return $http_connection->send(json_encode(['errcode'=>1,'errmsg'=>'client_id不能为空'],JSON_UNESCAPED_UNICODE));
        }

        if (empty($content)){
            return $http_connection->send(json_encode(['errcode'=>1,'errmsg'=>'内容不能为空'],JSON_UNESCAPED_UNICODE));
        }

        if (!isset($config_datas['clients'][$client_id])) {
            return $http_connection->send(json_encode(['errcode'=>1,'errmsg'=>'client_id不存在'],JSON_UNESCAPED_UNICODE));
        }

        //内容要做处理 如果 aes_key 不为空 则需要加密
        if (!empty($config_datas['clients'][$client_id]['aes_key'])) {
            $content = encrypt($config_datas['clients'][$client_id]['aes_key'],$content);
        }

        if($room){
            // to 可以 填 sid  $socket->id
            $sender_io->to($client_id.'-'.$room)->emit($event, array('datas'=>$content,'is_online'=>true));
        }else{
            $sender_io->to($client_id)->emit($event, array('datas'=>$content,'is_online'=>true));
        }

        // http接口返回，如果用户离线socket返回fail
        if ($room) {
            if (array_key_exists($client_id,$roomConnectionMap) && array_key_exists($room,$roomConnectionMap[$client_id])) {
                return $http_connection->send(json_encode(['errcode'=>0,'errmsg'=>'ok']));
            }
            // 存储 离线的消息
            if (intval($is_save_offline)==1) {
                //
                if (isset($offlineMapInfo[$client_id])) {
                    if (isset($offlineMapInfo[$client_id][$room])) {
                        array_push($offlineMapInfo[$client_id][$room],['room'=>$room,'event'=>$event,'datas'=>$content,'time'=>time()]);
                    }else {
                        $offlineMapInfo[$client_id][$room] = [
                            ['room'=>$room,'event'=>$event,'datas'=>$content,'time'=>time()]
                        ];
                    }
                }else {
                    $offlineMapInfo[$client_id][$room] = [
                        ['room'=>$room,'event'=>$event,'datas'=>$content,'time'=>time()]
                    ];
                }
            }
            return $http_connection->send(json_encode(['errcode'=>1,'errmsg'=>'offline']));
        } else {
            return $http_connection->send(json_encode(['errcode'=>0,'errmsg'=>'ok']));
        }
    };
    $inner_http_worker->onClose = function($connection)
    {
        // 删除定时器
//        Timer::del($connection->timer_id);
    };

    // 执行监听
    $inner_http_worker->listen();

    if ($inner_http_worker->id == 0) {
        $t = 24 * 60 * 60;
//        $t = 15;
        Timer::add($t,function (){

            global $offlineMapInfo,$signConnectionMap;

            $expire_time = 3*24*60*60;
//            $expire_time = 30;

            foreach ($signConnectionMap as $k => $v) {
                if (time() - $v > 1800) {
                    unset($signConnectionMap[$k]);
                }
            }

            foreach ($offlineMapInfo as $k => $v) {
                if (empty($v) || !isset($v)) {
                    // 键值对都没有就全部删掉
                    unset($offlineMapInfo[$k]);
                }else {
                    foreach ($v as $kk=>$vv){
                        if (empty($vv) || !isset($vv)) {
                            unset($offlineMapInfo[$k][$kk]);
                        }else {
                            foreach ($vv as $kkk => $vvv) {
                                $time = $vvv['time'];
                                if (time() - $time > $expire_time) {
                                    unset($offlineMapInfo[$k][$kk][$kkk]);
                                }
                            }
                        }
                    }
                }
            }
        });
    }
});

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}

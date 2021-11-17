<?php
use Workerman\Worker;
use Workerman\Timer;
use PHPSocketIO\SocketIO;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;


include __DIR__ . '/vendor/autoload.php';

// 读取配置
$json_string = file_get_contents(dirname(__FILE__).DIRECTORY_SEPARATOR.'config.json');

// 用参数true把JSON字符串强制转成PHP数组
$config_datas = json_decode($json_string, true);

function debug_log($content) {
//    echo "========$content==========\n\n";
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
        'user-agent: nn_push',
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

$signConnectionMap = array();

/**
 * 全局数组保存room在线数据
 * [
 * client_id:[
 *
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

    /**
     * 30秒内没授权成功，就把链接删除掉
     */
    $socket->time_id = Timer::add(30,function ()use($socket){
        if (!isset($socket->room)) {
            // 如果没授权过，就断开链接
            $socket->disconnect();
        }
    },false);

    // 当客户端发来登录事件时触发
    $socket->on('login', function ($login_datas)use($socket){

        global $offlineMapInfo,$sender_io,$config_datas,$roomConnectionMap,$signConnectionMap;

        $login_datas_type = gettype($login_datas);

        /**e
         * [client_id] => dfasdf
        [room] => aniulee
        [sign] => fasdfsdafsd
         * [time_stamp] => 1111111111
         * client_id=client_id&&room=safsdf&&api_key=aa
         * client_id=dfasdfasd&&room=sdfasdf&&time_stamp=11111&&api_key=aa
         */

        if ($login_datas_type == 'string') {
            $socket->emit("message", "数据格式有误！");
            return;
        }

        // 验证一下
        if (!isset($login_datas['client_id']) || empty($login_datas['client_id'])) {
            $socket->emit("message","client_id没传哦");
            return;
        }

        if (!isset($login_datas['room']) || empty($login_datas['room'])) {
            $socket->emit("message","room没传哦");
            return;
        }

        if (!isset($login_datas['time_stamp']) || empty($login_datas['time_stamp'])) {
            $socket->emit("message","time_stamp没传哦");
            return;
        }
        if (!isset($login_datas['sign'])) {
            $socket->emit("message","sign没传哦");
            return;
        }
        if (!isset($config_datas['clients'][$login_datas['client_id']])) {
            $socket->emit("message","client_id不存在哦");
            return;
        }

        $time_stamp = $login_datas['time_stamp'];

        if (!is_int($time_stamp)) {
            $socket->emit("message","time_stamp不是整数");
            return;
        }

        if (intval($time_stamp) > time() + 30*60 || intval($time_stamp) < time() - 30*60) {
            $socket->emit("message","time_stamp已过期");
            return;
        }

        $client_id = $login_datas['client_id'];

        $clients = $config_datas['clients'][$login_datas['client_id']];

        $api_key = $clients['api_key'];

        $room = $login_datas['room'];

        $sign = $login_datas['sign'];

        if (array_key_exists($sign,$signConnectionMap)) {
            $socket->emit("message","该sign已被使用,请重新生成");
            return;
        }

        $s = 'client_id='.$client_id.'&&room='.$room.'&&time_stamp='.$login_datas['time_stamp'].'&&api_key='.$api_key;

        $_sign = md5($s);

        if ($sign != $_sign) {
            $socket->emit("message","验证失败");
            return;
        }

        $signConnectionMap[$sign] = time();

        if (isset($socket->time_id)) {
            Timer::del($socket->time_id);
            unset($socket->time_id);
        }

        $socket->client_id = $client_id;
        $socket->room = $room;

        // 加入房间 这个得做处理
        $socket->join($client_id); //为了群发
        $socket->join($client_id.'-'.$room);// 为了区分哪个客户端，防止互相影响

        // 加入记录
        if(!array_key_exists($client_id,$roomConnectionMap))
        {
            $roomConnectionMap[$client_id] = [$room];
        }else{
            array_push($roomConnectionMap[$client_id],$room);
        }

        //检查是否有离线的数据，发送过去一下
        if (isset($offlineMapInfo[$client_id]) && isset($offlineMapInfo[$client_id][$room])){
            foreach ($offlineMapInfo[$client_id][$room] as $key => $value){
                $data = (string)$value['data'];
                $event = $value['event'];
                $time = $value['time'];
                //时间未过期可以推送
                if (time() - $time < 3*24*60*60) {
                    $sender_io->to($client_id.'-'.$room)->emit($event, array('data'=>$data,'is_online'=>false));
                }
                unset($offlineMapInfo[$client_id][$room][$key]);
            }
        }

        $socket->emit("message","授权成功");
    });
    
    // 当客户端断开连接是触发（一般是关闭网页或者跳转刷新导致）
    $socket->on('disconnect', function () use($socket) {
        if (isset($socket->time_id)) {
            Timer::del($socket->time_id);
            unset($socket->time_id);
        }
        if (!isset($socket->room)) {
            return;
        }
        global $roomConnectionMap;
        if(in_array($socket->room,$roomConnectionMap[$socket->client_id]))
        {
            unset($roomConnectionMap[$socket->client_id][array_search($socket->room,$roomConnectionMap[$socket->client_id])]);
        }
        unset($socket);
    });
});

// 当$sender_io启动后监听一个http端口，通过这个端口可以给任意uid或者所有uid推送数据
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
            $sender_io->to($client_id.'-'.$room)->emit($event, array('data'=>$content,'is_online'=>true));
        }else{
            $sender_io->to($client_id)->emit($event, array('data'=>$content,'is_online'=>true));
        }

        // http接口返回，如果用户离线socket返回fail
        if ($room) {
            if (array_key_exists($client_id,$roomConnectionMap) && in_array($room,$roomConnectionMap[$client_id])) {
                return $http_connection->send(json_encode(['errcode'=>0,'errmsg'=>'ok']));
            }
            // 存储 离线的消息
            if (intval($is_save_offline)==1) {
                //
                if (isset($offlineMapInfo[$client_id])) {
                    if (isset($offlineMapInfo[$client_id][$room])) {
                        array_push($offlineMapInfo[$client_id][$room],['room'=>$room,'event'=>$event,'data'=>$content,'time'=>time()]);
                    }else {
                        $offlineMapInfo[$client_id][$room] = [
                            ['room'=>$room,'event'=>$event,'data'=>$content,'time'=>time()]
                        ];
                    }
                }else {
                    $offlineMapInfo[$client_id][$room] = [
                        ['room'=>$room,'event'=>$event,'data'=>$content,'time'=>time()]
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

            //$expire_time = 30;

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

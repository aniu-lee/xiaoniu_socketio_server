# 小牛简易socketio推送服务（xiaoniu_socketio_server）


### 特性

* 基于workerman 实现

* 支持API动态推送

* 支持一对一，分组推送

* 支持离线推送

* 未授权，则无法接收推送信息，断掉链接

* 支持链接，失去链接事件的回调

* 数据安全，简单操作

* Docker 一键安装，方便使用

[体验地址](http://xiaoniu_socketio_client.demo.aniulee.com/ "体验地址")

### 更新记录


#### 2022-01-29

祝大家新年快乐！

* 增加socket push,join事件！
* 登录，加入房间，有所调整！
* 增加登录，失去链接回调
* 优化一些BUG,使系统更健壮！

#### 2021-11-17


* 项目第一版本


## 端口有两个（可自定义）
```$xslt
2120 socket 端口
2121 http推送的 端口
```
`防火墙记得放行`

## 配置
config.example.json 拷贝一份 `config.json` 进行修改

`配置参考`

```json
{
  "clients": {
    "demo":{
      "api_key": "demo",
      "aes_key": "698d51a19d8a121ce581499d7b701668",
      "login_cb": "http://127.0.0.1:6010/?a=1",
      "disconnect_cb": "http://127.0.0.1:6010/?a=2"
    }
  },
  "origins": "*:*",
  "sock_port": 2120,
  "http_port": 2121
}
```

## API推送

#### 请求url

`http://127.0.0.1:2121(端口可自定义)`

支持 GET,POST请求 推荐用POST

#### 参数

* client_id 客户端（设备）ID 跟配置一一对应  如果没配置请求是不通的，必填

* content 推送的内容，必填

* event 推送客户端的事件,不填默认 `message`

* room 对应的房间号 不填 默认全部

* is_save_offline 用户离线是否离线推送 1是 0否 默认 0

socket 推送示例

```json
{
  "datas": "推送的内容",
  "is_online": true // 是否在线数据 或者离线数据
}
```

返回示例：
```json
{
  "errcode": 0,
  "errmsg": "ok"
}
```
## 后台socket事件

#### 事件名：login 

>  用户登录,当socket链接成功后，30秒内如果没登录，就会被断掉链接。

##### 参数

* client_id 设备id

* time_stamp 当前时间戳（秒）

* nonce_str 随机字符串

* sign 签名 见下文

#### 事件名：join 

>  加入房间

##### 参数

* room 房间名称

* client_id 设备id

* time_stamp 当前时间戳（秒）

* nonce_str 随机字符串

* sign 签名 见下文

#### 事件名：push 

>  向房间推送信息

##### 参数

* room 房间名称 ，可为空。传空推送所有

* datas 数据 字符串 可传普通字符串，可传json字符串 

* event 对应接收事件 默认 `message`

* client_id 设备id

* time_stamp 当前时间戳（秒）

* nonce_str 随机字符串

* sign 签名 见下文

## 前端实现

```javascript
    // 连接服务端 端口 是 2120 （可自定义）
    var socket = io('http://'+document.domain+':2120');

    // 连接后登录
    socket.on('connect', function(){
    	socket.emit('login', {所需参数见上文});
    });
    
    // 后端推送来消息时 事件根据api自定义
    socket.on('message', function(msg){
         // {data: "1", is_online: true} data 就是http请求的content  is_online 是否是在线数据
         if(typeof msg == "object") {
             // 后台推送的数据
             // 如果 配置有填aes_key data会是个加密的字符串 需自行解密            
             if (msg['data']) {
                  msg = Decrypt(msg['data'])
             }
         }
    });

    // 返回内容 {type:'login',errcode:0,errmsg:'ok'}
    // type 返回的类型 login join push
    // errcode 0 代表成功 不等于代表有误 详情看errmsg
    // 登录成功会返回data {sid:'socket的sid'}
    socket.on('server_return',function (ret) {
        console.log(ret)
        var types = ret['type'];
        switch (types) {
            case 'login':
              if (ret['errcode'] == 0) {
                // 登录成功
              }else {
                  //登录失败 失败看 ret['errmsg']
              }
              break
            case 'join':
              if (ret['errcode'] == 0) {
                // 加入房间成功
              }else {
                // 加入房间失败
              }
          }
        })
```

### 鉴权须知（sign生成规则）

`以下参数，api_key值都是用来做demo测试用的！！`

`以下参数，api_key值都是用来做demo测试用的！！`

`以下参数，api_key值都是用来做demo测试用的！！`

**假如**你请求的参数有：

```
参数 username 值等于 taiyouqu
参数 password 值等于 123456
```

api_key 值等于 `aaabbb`

加密规则：

①、对参数按照key=value的格式，并按照参数名ASCII字典序排序如下：
```
password=123456&&username=taiyouqu
```
②、拼接api_key密钥：
以上结果:
```
password=123456&&username=taiyouqu&&api_key=aaabbb
```
③、 md5('第②步结果')
`sign = 0a243d3055e90663d4411ca49c6f3852`（**统一小写**）

## 常规部署

#### Linux系统


启动服务(debug)

`php start.php start`

正式环境启动服务

`php start.php start -d`

停止服务

`php start.php stop`


服务状态

`php start.php status`

#### windows系统

双击 start_for_win.bat

如果启动不成功请参考 [Workerman手册](http://doc.workerman.net/install/requirement.html) 配置环境

## docker一键部署

*. 修改项目绝对路径

[![](deploy/1.png "修改绝对路径")]()


* 安装docker

```
安装 docker 跟 docker-compose 自行安装
sudo docker-compose up --build -d
```

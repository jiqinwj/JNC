<?php

require_once "vendor/autoload.php";
/*
 *  各种协议要实现的方法
 *  tcp method: connect/receive/close
 *  udp method: packet / close
 *  stream/text
 *  http request
 *  websocket open/message/close
 *  mqtt connect/subscribe/unsubscribe/publish/close
 */
//http 协议启动demo
$server = new \JNC\Server("ws://0.0.0.0:4567");

$server->setting([
    'workerNum' => 1,//工作的进程数量
    'daemon' => false,//是否开启常驻进程
    'taskNum' => 1,//任务的进程数量
    //unix 套接字通信
    "task" => [
        "unix_socket_server_file" => "/Users/g01d-01-0349/code/JNC/sock/te_unix_socket_server.sock",
        "unix_socket_client_file" => "/Users/g01d-01-0349/code/JNC/sock/te_unix_socket_client.sock",
    ]
]);

//主进程启动
$server->on("masterStart",function (\JNC\Server $server){
    fprintf(STDOUT,"master server start\r\n");
});

//主进程关闭
$server->on("masterShutdown",function (\JNC\Server $server){
    fprintf(STDOUT,"master server shutdown\r\n");
});

//mqtt 的连接事件/ websocket事件
$server->on("open",function (\JNC\Server $server,\JNC\TcpConnection $connection){

    echo "握手成功\r\n";
    $connection->send("你好，世界".date("YmdHis"));

});

//$server->on("workerReload",function (\Te\Server $server){
//    fprintf(STDOUT,"worker <pid:%d> reload\r\n",posix_getpid());
//});
//$server->on("workerStop",function (\Te\Server $server){
//    fprintf(STDOUT,"worker <pid:%d> stop\r\n",posix_getpid());
//});


$server->on("workerStart", function (\JNC\Server $server) {
    //
    global $routes;
    global $dispatcher;
    $routes = require_once "app/routes/api.php";
    $dispatcher = new \App\Controllers\ControllerDispatcher();//路由控制器分发器

//    //构造http 服务
//    $httpServer = new \JNC\Server("http://0.0.0.0:4568");
//    $httpServer->on("request", function (\JNC\Server $server, \JNC\Request $request, \JNC\Response $response) {
//        global $routes;
//        global $dispatcher;
//        if (preg_match("/.html|.jpg|.png|.gif|.js|.css|.jpeg/", $request->_request['uri'])) {
//            $file = "app/resources/" . $request->_request['uri'];
//            $response->sendFile($file);
//            return true;
//        }
//
//        //echo "connectNum:".count(\Te\Server::$_connections);//拿到所有的连接  它是静态成员，跟对象没有关系
//        //它是能拿到http连接客户端以及websocket客户端
////        foreach (\Te\Server::$_connections as $ix=>$connection){
////
////            if ($connection->_link_type==\Te\TcpConnection::WS_LINK_TYPE){
////
////                $connection->send(json_encode($request->_get));
////            }
////        }
//        $dispatcher->callAction($routes, $request, $response);
//    });
//    //监听+接收客户端的连接
//    $httpServer->Listen();
//    $httpServer->acceptClient();

});

$server->on("connect",function (\JNC\Server $server,\JNC\TcpConnection $connection){
    //fprintf(STDOUT,"<pid:%d>有客户端连接了\r\n",posix_getpid());
    $server->echoLog("有客户端连接了");
});


$server->on("request",function (\JNC\Server $server,\JNC\Request $request,\JNC\Response $response){

    global $routes;
    global $dispatcher;
    if (preg_match("/.html|.jpg|.png|.gif|.js|.css|.jpeg/",$request->_request['uri'])){
        $file = "app/resources/".$request->_request['uri'];
        $response->sendFile($file);
        return true;
    }
    //$response->header("Content-Type","application/json");
    //$data = array_merge($_GET,$_POST);
    //$response->write(json_encode($data));

    $dispatcher->callAction($routes,$request,$response);

    //print_r($_GET);
    print_r($request->_get);

    //print_r($_POST);

    //print_r($_FILES);
    //print_r($request->_post);
    //print_r($request->_request);

    //$response->header("Content-Type","application/json");
    // $response->write(json_encode(['a'=>'b','c'=>123]));
    //print_r($request->_request['uri']);
    //response->sendFile("www/".$request->_request['uri']);
    //$response->chunked("hello,world");
    //sleep(1);
    //$response->chunked("hello,china");
    //$response->chunked("hello,china");
    //$response->chunked("hello,china");
    //$response->chunked("hello,china");
    //$response->end();

});


//fread
$server->on("receive",function (\JNC\Server $server,$msg,\JNC\TcpConnection $connection){


    fprintf(STDOUT,"<pid:%d>recv from client<%d>:%s\r\n",posix_getpid(),(int)$connection->socketfd(),$msg);
    $server->echoLog("recv from client<%d>:%s\r\n",(int)$connection->socketfd(),$msg);

    //echo time()."\r\n";

//    if (DIRECTORY_SEPARATOR=="/"){
//        $server->task(function ($result)use($server){
//
//            sleep(10);
//
//            $server->echoLog("异步任务我执行完，时间到了\r\n");
//            //echo time()."\r\n";
//
//        });//耗时任务可以投递到任务进程来做
//    }


    //$data = file_get_contents("test.txt");
    $connection->send("phpser");
});
//$server->on("receiveBufferFull",function (\Te\Server $server,\Te\TcpConnection $connection){
//
//    $server->echoLog("接收缓冲区已经满了");
//    //$connection->send("i am server");
//});
$server->on("close",function (\JNC\Server $server,\JNC\TcpConnection $connection){
    //fprintf(STDOUT,"<pid:%d>客户端断开连接了\r\n",posix_getpid());
    $server->echoLog("客户端断开连接了");
});

$server->on("workerReload",function (\JNC\Server $server){
    fprintf(STDOUT,"worker <pid:%d> reload\r\n",posix_getpid());
});

$server->on("workerStop",function (\JNC\Server $server){
    fprintf(STDOUT,"worker <pid:%d> stop\r\n",posix_getpid());
});

$server->on("message",function (\JNC\Server $server,$frame,\JNC\TcpConnection $connection){


    fprintf(STDOUT,"pid=%d 收到websocket客户端的数据了：%s\r\n",1,$frame);

    //$data = file_get_contents("tex.log");

//    $connection->send("hello,world".date("YmdHis"));

});

$server->Start();



<?php


require_once "vendor/autoload.php";
$client =  new \JNC\Client("ws://127.0.0.1:4567");


$client->on("connect",function (\JNC\Client $client){

    $client->send("hellox");
    fprintf(STDOUT,"socket<%d> connect success!\r\n",(int)$client->socketfd());

});
//这里服务器返回数据的时候，我才继续往服务器发送数据
$client->on("receive",function (\JNC\Client $client,$msg){

    fprintf(STDOUT,"recv from server:%s\n",$msg);
//    $client->send("i am client 客户端");
});


$client->on("open",function (\JNC\Client $client){

    //mqtt
    fprintf(STDOUT,"成功连接websocket服务\r\n");

});


$client->on("message",function (\JNC\Client $client,$msg){

    fprintf(STDOUT,"websocket client recv from server:%s\r\n",$msg);

    //心跳启动的时候我不发送了 因为默认会有定时器发送 ping

//    $client->send("i am client 客户端");
});

$client->on("error",function (\JNC\Client $client,$errno,$errstr){

    fprintf(STDOUT,"连接错误：errno:%d,errstr:%s\n",$errno,$errstr);
});


$client->on("close",function (\JNC\Client $client){

    fprintf(STDOUT,"服务器断开我的连接了\n");
});

$client->Start();
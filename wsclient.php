<?php


require_once "vendor/autoload.php";
$client =  new \JNC\Client("ws://127.0.0.1:4567");


$client->on("connect",function (\Te\Client $client){

    $client->send("hellox");
    fprintf(STDOUT,"socket<%d> connect success!\r\n",(int)$client->socketfd());

});
//这里服务器返回数据的时候，我才继续往服务器发送数据
$client->on("receive",function (\Te\Client $client,$msg){

    fprintf(STDOUT,"recv from server:%s\n",$msg);
    //$client->send("i am client 客户端");
});


$client->on("open",function (\Te\Client $client){

    //mqtt
    fprintf(STDOUT,"成功连接websocket服务\r\n");

});


$client->on("message",function (\Te\Client $client,$msg){

    fprintf(STDOUT,"websocket client recv from server:%s\r\n",$msg);

    //$client->send("i am client 客户端");
});

$client->on("error",function (\Te\Client $client,$errno,$errstr){

    fprintf(STDOUT,"连接错误：errno:%d,errstr:%s\n",$errno,$errstr);
});


$client->on("close",function (\Te\Client $client){

    fprintf(STDOUT,"服务器断开我的连接了\n");
});

$client->Start();
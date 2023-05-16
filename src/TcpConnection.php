<?php

namespace JNC;

use JNC\Protocols\Websocket;

class TcpConnection
{
    const HEART_TIME = 10;//心跳事件
    public $_sockfd;//当前连接的fd socket
    public $_clientIp;//ip:port 当前连接的ip port
    public $_server;//当前服务器
    public $_heartTime = 0;//心跳
    const STATUS_CLOSED = 10;//当前状态已经关闭
    const STATUS_CONNECTED = 11;//当前状态 已经连接
    public $_protocol;//当前的协议
    public $_link_type = 0;//当前连接的类型是什么
    const HTTP_LINK_TYPE = 12;
    const WS_LINK_TYPE = 13;
    const TCP_LINK_TYPE = 14;
    const MQTT_LINK_TYPE = 15;

    public $_recvBufferSize = 1024 * 1000 * 100;//100kb  表示当前的连接接收缓冲区的大小
    public $_recvLen = 0;              //表示当前连接目前接收到的字节数大小
    public $_recvBufferFull = 0;       //表示当前连接接收的字节数是否超出缓冲区
    public $_recvBuffer = '';//真正接收的数据

    public $_sendLen = 0; //表示当前连接目前发送的字节数大小
    public $_sendBuffer = '';//真正发送的数据
    public $_sendBufferSize = 1024 * 1000 * 100; //表示当前的连接发送缓冲区的大小
    public $_sendBufferFull = 0; //表示当前连接发送的字节数是否超出缓冲区
    public $_readBufferSize = 8092;//读取缓存的大小


    //粘包问题处理
    public function __construct($sockfd, $clientIp, $server, $protocol)
    {
        $this->_sockfd = $sockfd;
        stream_set_blocking($this->_sockfd, 0);
        stream_set_write_buffer($this->_sockfd, 0);
        stream_set_read_buffer($this->_sockfd, 0);
        $this->_clientIp = $clientIp;
        $this->_server = $server;
        $this->_status = self::STATUS_CONNECTED;
        $this->_protocol = $protocol;
        //这里面为什么要存入server 里面有循环监听事件，这里面存入当前连接可读事件
        Server::$_eventLoop->add($this->_sockfd, Event\Event::EV_READ, [$this, "recv4socket"]);
    }

    public function resetHeartTime()
    {
        $this->_heartTime = time();
    }

    public function recv4socket()
    {
        if ($this->_recvLen<$this->_recvBufferSize){
            $data = fread($this->_sockfd,$this->_readBufferSize);
            if ($data===''||$data===false){
                if (feof($this->_sockfd)||!is_resource($this->_sockfd)){
                    $this->Close();
                }
            }else{
                //把接收到的数据放在接收缓冲区里
                $this->_recvBuffer.=$data;
                $this->_recvLen+=strlen($data);
                $this->_server->onRecv();
            }
        }else{
            $this->_recvBufferFull++;
            $this->_server->runEventCallBack("receiveBufferFull",[$this]);
        }
        if ($this->_recvLen>0){
            $this->handleMessage();
        }
    }

    public function handleMessage()
    {
        if (is_object($this->_protocol)&&$this->_protocol!=null){
            while ($this->_protocol->Len($this->_recvBuffer)){
                $msgLen = $this->_protocol->msgLen($this->_recvBuffer);
                //截取一条消息
                $oneMsg = substr($this->_recvBuffer,0,$msgLen);
                $this->_recvBuffer = substr($this->_recvBuffer,$msgLen);
                $this->_recvLen-=$msgLen;
                $this->_recvBufferFull--;
                $this->_server->onMsg();
                $this->resetHeartTime();
                $message = $this->_protocol->decode($oneMsg);
                //$server->runEventCallBack("receive",[$message,$this]);

                $this->runEventCallBack($message);
            }
        }else{
            //$server->runEventCallBack("receive",[$this->_recvBuffer,$this]);
            $this->runEventCallBack($this->_recvBuffer);
            $this->_recvBuffer = '';
            $this->_recvLen=0;
            $this->_recvBufferFull=0;
            $this->_server->onMsg();
            $this->resetHeartTime();
        }

    }

    //判断是否关闭或者是否是个资源
    public function isConnected()
    {
        return $this->_status == self::STATUS_CONNECTED && is_resource($this->_sockfd);
    }

    //发送数据
    public function send($data = '')
    {
        if (!$this->isConnected()) {
            $this->Close();
            return false;
        }
        $len = strlen($data);

        //如果当前发送的数据的大小没有超过发送的限制缓冲区大小 就继续发送
        if ($this->_sendLen + $len < $this->_sendBufferSize) {

            //判断这个连接是不是传统的协议 那么就需要编码
            if (is_object($this->_protocol) && $this->_protocol != null) {
                $bin = $this->_protocol->encode($data);
                $this->_sendBuffer .= $bin[1];
                $this->_sendLen += $bin[0];
            } else {
                $this->_sendBuffer .= $data;
                $this->_sendLen += $len;
            }
            //如果发送的大小大于等于 发送缓冲区的大小
            if ($this->_sendLen >= $this->_sendBufferSize) {
                $this->_sendBufferFull++;
            }
        }

        //fwrite 在发送数据的时候由于网络原因 [会存在以下几种情况，1只发送一半，2 能完整的发送 3 对端突然关了]
        $writeLen = fwrite($this->_sockfd, $this->_sendBuffer, $this->_sendLen);
        if ($writeLen == $this->_sendLen) {//这代表发送完了，需要清理数据
            $this->_sendBuffer = '';
            $this->_sendLen = 0;
            $this->_sendBufferFull = 0;
            return true;
        } else if ($writeLen > 0) {//1只发送一半
            $this->_sendBuffer = substr($this->_sendBuffer, $writeLen);//截取要发送的数据
            $this->_sendLen -= $writeLen;//发送的长度---
            $this->_sendBufferFull--;//发送的缓冲区---
            Server::$_eventLoop->add($this->_sockfd, Event\Event::EV_WRITE, [$this, "write2socket"]);
            return true;
        } else {
            //代表对端可能关闭了
            if (feof($this->_sockfd) || !is_resource($this->_sockfd)) {
                $this->Close();
            }
        }
        return false;
    }

    public function needWrite()
    {//fork 如果是子进程下 是会不一样的
        return $this->_sendLen > 0;
    }

    //真正的发送数据
    public function write2socket()
    {
        if ($this->needWrite()) {
            set_error_handler(function () {
            });
            $writeLen = fwrite($this->_sockfd, $this->_sendBuffer, $this->_sendLen);
            restore_error_handler();
            if ($writeLen == $this->_sendLen) {
                $this->_sendBuffer = '';
                $this->_sendLen = 0;
                //发送完了删除当前连接写事件
                Server::$_eventLoop->del($this->_sockfd, Event\Event::EV_WRITE);
                //websocket
//                if ($this->_protocol instanceof Websocket) {
//                    if ($this->_protocol->_websocket_handshake_status == Websocket::WEBSOCKET_RUNNING_STATUS)
//                        $this->_server->runEventCallBack("open", [$this]);
//                } else {
//                    $this->Close();
//                }
            } else if ($writeLen > 0) {
                $this->_sendBuffer = substr($this->_sendBuffer, $writeLen);
                $this->_sendLen -= $writeLen;
            } else {
                if (feof($this->_sockfd) || !is_resource($this->_sockfd)) {
                    $this->Close();
                }
            }
        }


    }


    //关闭请求
    public function Close()
    {
        $this->_server->echoLog("移除<socket:%d>连接\r\n", (int)$this->_sockfd);
        //print_r(debug_backtrace());该函数显示由 debug_backtrace() 函数代码生成的数据。
        //移除这个连接的读写事件
        Server::$_eventLoop->del($this->_sockfd, Event\Event::EV_WRITE);
        Server::$_eventLoop->del($this->_sockfd, Event\Event::EV_READ);
        //判断是否是个资源 如果是就关闭这个fd 文件句柄
        if (is_resource($this->_sockfd)) {
            fclose($this->_sockfd);
        }

        /** @var Server $server */
        $server = $this->_server;
        $server->runEventCallBack("close", [$this]);
        $server->removeClient($this->_sockfd);


        //状态变为已关闭
        $this->_status = self::STATUS_CLOSED;
        $this->_sockfd = null;

        //发送缓冲区都至于0
        $this->_sendLen = 0;
        $this->_sendBuffer = '';
        $this->_sendBufferFull = 0;
        $this->_sendBufferSize = 0;

        //接收缓冲区都变为0
        $this->_recvLen = 0;
        $this->_recvBuffer = '';
        $this->_recvBufferFull = 0;
        $this->_recvBufferSize = 0;
    }


    public function runEventCallBack($msg)
    {
        /** @var Server $server */
        $server = $this->_server;
        switch ($this->_server->_usingProtocol) {

            case "tcp":
            case "text":
            case "stream":
                $server->runEventCallBack("receive", [$msg, $this]);
                break;
            case 'http':
                $request = $this->createRequest();
                $response = new Response($this);

                if ($request->_request['method'] == "OPTIONS") {

                    $response->sendMethods();

                } else {
                    $server->runEventCallBack("request", [$request, $response]);

                }

                break;
            case 'ws':
                if ($this->_protocol->_websocket_handshake_status==Websocket::WEBSOCKET_START_STATUS){
                    if($this->send()){
                        //握手成功
                        if ($this->_protocol->_websocket_handshake_status==Websocket::WEBSOCKET_RUNNING_STATUS){

                            $server->runEventCallBack("open",[$this]);
                        }else{
                            $this->Close();
                        }
                    }
                }
                else if ($this->_protocol->_websocket_handshake_status==Websocket::WEBSOCKET_RUNNING_STATUS){
                    if ($this->_protocol->_opcode == Websocket::OPCODE_PING){
                        echo "收到ping帧\r\n";
                        $this->send();
                    }else{
                        $server->runEventCallBack("message",[$msg,$this]);
                    }
                }else{
                    $this->Close();
                }
                break;
            case 'redis':
                //我们会根据情况给大家写一个redis客户端
                break;
        }
    }

    //创建http 上下文
    public function createRequest()
    {
        $request = new Request();
        $request->_get = $_GET;
        $request->_post = $_POST;
        $request->_request = $_REQUEST;
        $request->_files = $_FILES;
        return $request;
    }

    public function checkHeartTime()
    {
        $now = time();
        if ($now-$this->_heartTime>=self::HEART_TIME){
            $this->_server->echoLog("心跳时间已经超出:%d\n",$now-$this->_heartTime);
            return true;
        }
        return false;
    }


}
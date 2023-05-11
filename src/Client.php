<?php

namespace JNC;

use JNC\Event\Epoll;
use JNC\Event\Select;
use JNC\Protocols\Ws;

class Client
{
    public $_mainSocket;
    public $_events = [];
    public $_readBufferSize = 102400;
    public $_recvBufferSize = 1024 * 100;//100kb  表示当前的连接接收缓冲区的大小
    public $_recvLen = 0;              //表示当前连接目前接收到的字节数大小

    public $_sendLen = 0;
    public $_sendBuffer = '';
    public $_sendBufferSize = 1024 * 100;
    public $_sendBufferFull = 0;

    //它是一个接收缓冲区，可以接收多条消息【数据包】，数据像水一样粘在一起
    public $_recvBuffer = '';

    public $_protocol;
    public $_local_socket;

    public $_sendNum = 0;
    public $_sendMsgNum = 0;
    const STATUS_CLOSED = 10;
    const STATUS_CONNECTED = 11;

    public $_status;//状态信息
    static public $_eventLoop;

    public $_protocols = [
        "stream" => "Te\Protocols\Stream",
        "text" => "Te\Protocols\Text",
        "ws" => "Te\Protocols\Ws",
        "mqtt" => "Te\Protocols\MqttClient",
    ];
    public $_mqttSetting = [];
    public $_usingProtocol;

    public function onSendWrite()
    {
        ++$this->_sendNum;
    }
    public function onSendMsg()
    {
        ++$this->_sendMsgNum;
    }
    public function on($eventName,$eventCall){
        $this->_events[$eventName] = $eventCall;
    }

    public function socketfd()
    {
        return $this->_mainSocket;

    }
    public function runEventCallBack($eventName,$args=[])
    {
        if (isset($this->_events[$eventName])&&is_callable($this->_events[$eventName])){
            return $this->_events[$eventName]($this,...$args);//
        }else{
            fprintf(STDOUT,"not found %s event call\n",$eventName);
        }
    }


    public function __construct($local_socket)
    {
        //$this->_local_socket = $local_socket;
        //connect
        //$this->_protocol = new Stream();

        list($protocol,$ip,$port) = explode(":",$local_socket);

        if (isset($this->_protocols[$protocol])){
            $this->_usingProtocol = $protocol;
            $this->_protocol = new $this->_protocols[$protocol]();
        }else{
            $this->_usingProtocol = "tcp";
        }

        $this->_local_socket = "tcp:".$ip.":".$port;

        if (DIRECTORY_SEPARATOR=="/"){
            static::$_eventLoop = new Epoll();
        }
        else{
            static::$_eventLoop = new Select();
        }
    }

    public function Close()
    {
        fclose($this->_mainSocket);
        $this->runEventCallBack("close",[$this]);
        $this->_status = self::STATUS_CLOSED;
        $this->_mainSocket = null;
    }

    public function isConnected()
    {
        return $this->_status == self::STATUS_CONNECTED&&is_resource($this->_mainSocket);
    }

    public function write2socket()
    {
        //fprintf(STDOUT,"接收进程的sendLen:%d,sendBufferLen:%d\r\n",$this->_sendLen,strlen($this->_sendBuffer));

        if ($this->needWrite()&&$this->isConnected()){
            //fprintf(STDOUT,"write2socket\r\n");

            $writeLen = fwrite($this->_mainSocket,$this->_sendBuffer,$this->_sendLen);
            //print_r($writeLen);
            $this->onSendWrite();
            if ($writeLen==$this->_sendLen){
                $this->_sendBuffer = '';
                $this->_sendLen = 0;
                static::$_eventLoop->del($this->_mainSocket,Event\Event::EV_WRITE);
                return true;
            }
            else if ($writeLen>0){

                $this->_sendBuffer = substr($this->_sendBuffer,$writeLen);
                $this->_sendLen-=$writeLen;
            }else{
                if (!is_resource($this->_mainSocket)||feof($this->_mainSocket)){
                    $this->Close();
                }

            }
        }

        //fprintf(STDOUT,"我写了:%d字节\n",$writeLen);

    }


    public function send($data='')
    {
        $len = strlen($data);

        if ($this->_sendLen+$len<$this->_sendBufferSize){

            $bin = $this->_protocol->encode($data);
            $this->_sendBuffer.=$bin[1];
            $this->_sendLen+=$bin[0];
            if ($this->_sendLen>=$this->_sendBufferSize){

                $this->_sendBufferFull++;
            }
            $this->onSendMsg();
        }else{
            $this->runEventCallBack("receiveBufferFull",[$this]);
        }

        //fwrite 在发送数据的时候【会存在以下几种情况，1只发送一半,2 能完整的发送  3对端关了】
        $writeLen = fwrite($this->_mainSocket,$this->_sendBuffer,$this->_sendLen);
        if ($writeLen==$this->_sendLen){
            $this->_sendBuffer = '';
            $this->_sendLen=0;
            $this->_sendBufferFull=0;
            $this->onSendWrite();
            return true;
        }
        else if ($writeLen>0){

            $this->_sendBuffer = substr($this->_sendBuffer,$writeLen);
            $this->_sendLen-=$writeLen;
            $this->_sendBufferFull--;

            static::$_eventLoop->add($this->_mainSocket,Event\Event::EV_WRITE,[$this,"write2socket"]);
            return true;
        }else{
            if (!is_resource($this->_mainSocket)||feof($this->_mainSocket)){
                $this->Close();
            }
        }
        return false;
    }

    /*
     * 直接发送 不用对数据进行编码
     */
    public function send2fd($data='')
    {
        $len = strlen($data);
        if ($this->_sendLen+$len<$this->_sendBufferSize){

            $this->_sendBuffer.=$data;
            $this->_sendLen+=strlen($data);
            if ($this->_sendLen>=$this->_sendBufferSize){

                $this->_sendBufferFull++;
            }
            $this->onSendMsg();
        }else{
            $this->runEventCallBack("receiveBufferFull",[$this]);
        }

        //fwrite 在发送数据的时候【会存在以下几种情况，1只发送一半,2 能完整的发送  3对端关了】
        $writeLen = fwrite($this->_mainSocket,$this->_sendBuffer,$this->_sendLen);
        if ($writeLen==$this->_sendLen){
            $this->_sendBuffer = '';
            $this->_sendLen=0;
            $this->_sendBufferFull=0;
            $this->onSendWrite();
            return true;
        }
        else if ($writeLen>0){

            $this->_sendBuffer = substr($this->_sendBuffer,$writeLen);
            $this->_sendLen-=$writeLen;
            $this->_sendBufferFull--;

            static::$_eventLoop->add($this->_mainSocket,Event\Event::EV_WRITE,[$this,"write2socket"]);
            return true;
        }else{
            if (!is_resource($this->_mainSocket)||feof($this->_mainSocket)){
                $this->Close();
            }
        }
        return false;
    }

    public function needWrite()
    {//fork
        return $this->_sendLen>0;
    }
    public function recv4socket()
    {
        if ($this->isConnected()){
            $data = fread($this->_mainSocket,$this->_readBufferSize);
            if ($data===''||$data===false){
                if (feof($this->_mainSocket)||!is_resource($this->_mainSocket)){
                    $this->Close();
                }
            }else{
                $this->_recvBuffer.=$data;
                $this->_recvLen+=strlen($data);
            }
            if ($this->_recvLen>0){
                $this->handleMessage();
            }
        }

    }


    public function handleMessage()
    {
        while ($this->_protocol->Len($this->_recvBuffer)){

            $msgLen = $this->_protocol->msgLen($this->_recvBuffer);
            //截取一条消息
            $oneMsg = substr($this->_recvBuffer,0,$msgLen);
            $this->_recvBuffer = substr($this->_recvBuffer,$msgLen);
            $this->_recvLen-=$msgLen;

            $message = $this->_protocol->decode($oneMsg);
            //$this->runEventCallBack("receive",[$message]);
            $this->callEventCallBack($message);
        }
    }

    public function callEventCallBack($msg="")
    {

        switch ($this->_usingProtocol){

            case "tcp":
            case "text":
            case "stream":
                $this->runEventCallBack("receive",[$msg]);
                break;
            case 'http':
                //http 客户端实现意义也不大，没必要
                //file_get_content(uri)
                //curl
                //...
                break;
            case 'ws':

                if ($this->_protocol->_websocket_handshake_status==Ws::WEBSOCKET_START_STATUS){
                    if(!$this->send()){
                        $this->Close();
                    }

                }
                else if ($this->_protocol->_websocket_handshake_status==Ws::WEBSOCKET_PREPARE_STATUS){

                    //这一步是握手验证,主要是验证服务器处理的key是否正确
                    if($this->_protocol->verifyWsHandShake()){

                        $this->runEventCallBack("open",[]);
                    }else{
                        $this->Close();
                    }
                }
                else if ($this->_protocol->_websocket_handshake_status==Ws::WEBSOCKET_RUNNING_STATUS){

                    $this->runEventCallBack("message",[$msg]);
                }else{
                    $this->Close();
                }

                break;
            case 'mqtt':
                break;
            case 'redis':
                //我们会根据情况给大家写一个redis客户端
                break;
        }
    }


    public function Start()
    {
        $this->_mainSocket = stream_socket_client($this->_local_socket,$errno,$errstr);


        if (is_resource($this->_mainSocket)){
            stream_set_blocking($this->_mainSocket,0);
            stream_set_write_buffer($this->_mainSocket,0);
            stream_set_read_buffer($this->_mainSocket,0);

            //走websocket 客户端就要主动的发起握手连接
            if ($this->_protocol instanceof Ws){
                $this->callEventCallBack("");
            }
//            else if ($this->_protocol instanceof MqttClient){
//                $this->callEventCallBack("");
//            }
            else{
                //tcp stream text
                $this->runEventCallBack("connect",[$this]);
            }
            $this->_status = self::STATUS_CONNECTED;
            static::$_eventLoop->add($this->_mainSocket,Event\Event::EV_READ,[$this,"recv4socket"]);


            //发送心跳ping
            static::$_eventLoop->add(5,Event\Event::EV_TIMER,[$this,"sendPing"]);

//            static::$_eventLoop->add($this->_mqttSetting['keepAlive'],Event\Event::EV_TIMER,[$this,"sendMqttPing"]);

            $this->loop();
        }else{

            $this->runEventCallBack("error",[$this,$errno,$errstr]);
            exit(0);
        }

    }
    public function sendPing($timerId,$arg)
    {
        if ($this->_protocol->_websocket_handshake_status==Ws::WEBSOCKET_RUNNING_STATUS)
        {

            $ping = $this->_protocol->ping();
            $this->send2fd($ping);
//            fprintf(STDOUT,"heart ping:%d\r\n",$len);
        }

    }
    public function sendMqttPing($timerId,$arg)
    {
        $ping = $this->_protocol->responsePingReqPacket();
        $this->send2fd($ping);
        //fprintf(STDOUT,"heart ping:%d\r\n",$len);

    }

    public function loop()
    {
        return static::$_eventLoop->loop();
    }







}
<?php

namespace JNC\Protocols;

/*
 * 客户端的Websocket协议
 */

/**
 * +-+-+-+-+-------+-+-------------+-------------------------------+
 * |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
 * |I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
 * |N|V|V|V|       |S|             |   (if payload len==126/127)   |
 * | |1|2|3|       |K|             |                               |
 * +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
 * |     Extended payload length continued, if payload len == 127  |
 * + - - - - - - - - - - - - - - - +-------------------------------+
 * |                               |Masking-key, if MASK set to 1  |
 * +-------------------------------+-------------------------------+
 * | Masking-key (continued)       |          Payload Data         |
 * +-------------------------------- - - - - - - - - - - - - - - - +
 * :                     Payload Data continued ...                :
 * + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
 * |                     Payload Data continued ...                |
 * +---------------------------------------------------------------+
 **/
class Ws
{
    public $_http;
    public $_websocket_handshake_status;
    const  WEBSOCKET_START_STATUS = 10;
    const  WEBSOCKET_PREPARE_STATUS = 11;//因为客户端发送连接 要处理中间的握手协议校验 所以需要一个中间状态。
    const  WEBSOCKET_RUNNING_STATUS = 13;
    const  WEBSOCKET_CLOSED_STATUS = 12;

    public $_fin;
    public $_opcode;
    public $_mask;//mask 标志位
    public $_payload_len;
    public $_masKey = [];

    const OPCODE_TEXT = 0X01;
    const OPCODE_BINARY = 0X02;
    const OPCODE_CLOSED = 0X08;
    const OPCODE_PING = 0X09;
    const OPCODE_PONG = 0X0A;

    public $_headerLen;
    public $_dataLen=0;

    /*
     * 客户端握手协议：
     *  Host: server.example.com
        Upgrade: websocket
        Connection: Upgrade
        Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==
        Origin: http://example.com
        Sec-WebSocket-Protocol: chat, superchat
        Sec-WebSocket-Version: 13
    Sec-WebSocket-Key：与后面服务端响应首部的 Sec-WebSocket-Accept 是配套的，提供基本的防护，比如恶意的连接，或者无意的连接。
     */
    public $_key;

    /*
     *  WebSocket 为了保持客户端、服务端的实时双向通信，需要确保客户端、服务端之间的 TCP 通道保持连接没有断开。
     *  然而，对于长时间没有数据往来的连接，如果依旧长时间保持着，可能会浪费包括的连接资源
     *  发送方 ->接收方：ping
        接收方 ->发送方：pong
        ping、pong 的操作，对应的是 WebSocket 的两个控制帧，opcode分别是 0x9、0xA。
     */
    public function ping()
    {
        return chr(0x80|self::OPCODE_PING).chr(0x00);
    }

    public function pong()
    {
        return chr(0x80|self::OPCODE_PONG).chr(0x00);
    }

    public function __construct()
    {
        $this->_http = new Http();
        $this->_websocket_handshake_status = self::WEBSOCKET_START_STATUS;
    }

    public function encode($data = '')
    {
        if ($this->_websocket_handshake_status==self::WEBSOCKET_START_STATUS){
            $handshakeData = $this->handshake();//一开始先进行握手协议
            if ($handshakeData){
                $this->_websocket_handshake_status = self::WEBSOCKET_PREPARE_STATUS;
                return $this->_http->encode($handshakeData);

            }
        }else{
            //理解清楚变量在内存的数据布局
            $maskKey = chr(0x00).chr(0x00).chr(0x00).chr(0x00);
            //对数据进行掩码操作  异或处理
            $dataLen = strlen($data);
            for ($i=0;$i<$dataLen;$i++){
                $data[$i] = $data[$i]^$maskKey[$i&0x03];
            }
            $headerLen = 2;
            if ($dataLen<=125){
                //0b1000 0001
                //FIN=1
                //0001
                //MASK=1
                $frame = pack("CCN",0b10000001,(0x80|$dataLen),$maskKey).$data;
            }
            else if ($dataLen<65536){
                $headerLen+=2;
                $frame = pack("CCnN",0b10000001,(0x80|126),$dataLen,$maskKey).$data;
            }else{
                $headerLen+=8;
                $frame = pack("CCJN",0b10000001,(0x80|127),$dataLen,$maskKey).$data;
            }
            $headerLen+=4;

            return [$headerLen+$dataLen,$frame];
        }
    }

    public function decode($data = '')
    {
        if ($this->_websocket_handshake_status==self::WEBSOCKET_PREPARE_STATUS){
            return $this->_http->decode($data);
        }else{
            return  substr($data,$this->_headerLen);
        }
    }
    public function test()
    {

        $text = sprintf("fin:%d,opcode:%d,mask:%d,dataLen:%d\r\n",$this->_fin,$this->_opcode,$this->_mask,$this->_dataLen);

        fwrite(STDOUT,$text);
    }

    public function Len($data)
    {
        if ($this->_websocket_handshake_status==self::WEBSOCKET_PREPARE_STATUS){

            return $this->_http->Len($data);
        }else{

            //这是服务器返回的websocket数据帧  ，是没有这个掩码
            if (strlen($data)<2){
                return false;
            }

            $bin = unpack("CfirstByte/CsecondByte",$data);

            $firstByte = $bin['firstByte'];
            $secondByte = $bin['secondByte'];

            $this->_headerLen=2;
            $this->_dataLen = 0;//因为是常驻进程  数据是驻留在内存中 这个数据长度要一起重置下 不然会出问题
            //0b 开头是二进制 0x 开头是十六进制
            $this->_fin = $firstByte&0b10000000;
            $this->_opcode = $firstByte&0b00001111;//数据桢
            if ($this->_opcode==self::OPCODE_CLOSED){
                $this->_websocket_handshake_status = self::WEBSOCKET_CLOSED_STATUS;
                return true;
            }

            if ($this->_opcode==self::OPCODE_PONG){
                return true;
            }

            $this->_payload_len = $secondByte&0b01111111;

            if ($this->_payload_len==0b01111110){

                $this->_headerLen+=2;
            }
            else if ($this->_payload_len==0b01111111){
                $this->_headerLen+=8;

            }
            if (strlen($data)<$this->_headerLen){
                return false;
            }

            //2 bytes
            if ($this->_payload_len==0b01111110){

                $bin = unpack("Cf/Cs/ndataLen",$data);
                $this->_dataLen = $bin['dataLen'];
            }
            else if ($this->_payload_len==0b01111111){

                //8bytes
                $bin = unpack("Cf/Cs/JdataLen",$data);
                $this->_dataLen = $bin['dataLen'];
            }else{
                $this->_dataLen = $this->_payload_len;
            }
            if (strlen($data)<$this->_headerLen+$this->_dataLen){
                return false;
            }
            return true;
        }
    }

    public function msgLen($data = '')
    {
        if ($this->_websocket_handshake_status==self::WEBSOCKET_PREPARE_STATUS){
            return $this->_http->msgLen($data);
        }else{
            return $this->_headerLen+$this->_dataLen;
        }
    }

    //握手验证
    public function verifyWsHandShake()
    {

        if (isset($_REQUEST['Connection'])&&$_REQUEST['Connection']=="Upgrade"
            &&isset($_REQUEST['Upgrade'])&&$_REQUEST['Upgrade']=="websocket"
        ){

            $Acceptkey = $_REQUEST['Sec_WebSocket_Accept'];
            if ($Acceptkey){

                $key = base64_encode(sha1($this->_key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
                if ($key!=$Acceptkey){
                    return false;
                }
                $this->_websocket_handshake_status=self::WEBSOCKET_RUNNING_STATUS;
                return true;
            }
        }
    }

    public function handshake()
    {
        /***
         * GET /chat HTTP/1.1
        Host: server.example.com
        Upgrade: websocket
        Connection: Upgrade
        Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==
        Origin: http://example.com
        Sec-WebSocket-Protocol: chat, superchat
        Sec-WebSocket-Version: 13
         */
        //对应的加密算法 公式：通过 SHA1 计算出摘要，并转成 base64 字符串。
        $key = base64_encode(md5(mt_rand(),true));
        $this->_key = $key;
        $text = sprintf("GET /chat HTTP/1.1\r\n");
        $text.= sprintf("Upgrade: websocket\r\n");
        $text.= sprintf("Host: 127.0.0.1:4567\r\n");
        $text.= sprintf("Connection: Upgrade\r\n");
        $text.= sprintf("Sec-WebSocket-Key: %s\r\n",$key);
        $text.= sprintf("Sec-WebSocket-Version: %s\r\n\r\n","13");

        return $text;

    }
}
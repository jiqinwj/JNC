<?php

namespace JNC\Protocols;


/*
 * 1 握手 http
 * 2 数据帧 websocket 内存结构怎么样
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
/*
 * wiki https://segmentfault.com/a/1190000012948613
 */
class Websocket implements Protocol
{
    public $_http;//为了启动转发http服务
    public $_websocket_handshake_status;//服务
    const  WEBSOCKET_START_STATUS = 10;// 刚开始启动服务
    const  WEBSOCKET_RUNNING_STATUS = 11;// 运行中
    const  WEBSOCKET_CLOSED_STATUS = 12; // 关闭状态

    /*
     *  %x0：表示一个延续帧。当 Opcode 为 0 时，表示本次数据传输采用了数据分片，当前收到的数据帧为其中一个数据分片。
        %x1：表示这是一个文本帧（frame）
        %x2：表示这是一个二进制帧（frame）
        %x3-7：保留的操作代码，用于后续定义的非控制帧。
        %x8：表示连接断开。
        %x8：表示这是一个 ping 操作。
        %xA：表示这是一个 pong 操作。
        %xB-F：保留的操作代码，用于后续定义的控制帧。
     */
    public $_opcode;
    const OPCODE_TEXT=0X01;
    const OPCODE_BINARY=0X02;
    const OPCODE_CLOSED=0X08;
    const OPCODE_PING=0X09;
    const OPCODE_PONG=0X0A;

    /*
     * FIN：1 个比特。
       如果是 1，表示这是 消息（message）的最后一个分片（fragment），如果是 0，表示不是是 消息（message）的最后一个 分片（fragment）。
     */
    public $_fin;//fin 制位

    /*
     * Mask: 1 个比特。
       表示是否要对数据载荷进行掩码操作。从客户端向服务端发送数据时，需要对数据进行掩码操作；从服务端向客户端发送数据时，不需要对数据进行掩码操作。
     */
    public $_mask;
    /*
     * 数据载荷的长度
     */
    public $_payload_len;
    /*
     * Masking-key：0 或 4 字节（32 位）
       所有从客户端传送到服务端的数据帧，数据载荷都进行了掩码操作，Mask 为 1，且携带了 4 字节的 Masking-key。如果 Mask 为 0，则没有 Masking-key。
     */

    /*
     * 初始化对应的服务：http是用来握手的
     */
    public function __construct()
    {
        $this->_http = new Http();
        $this->_websocket_handshake_status = self::WEBSOCKET_START_STATUS;
    }

    public $_masKey = [];
    public function Len($data)
    {
        // TODO: Implement Len() method.
        if ($this->_websocket_handshake_status==self::WEBSOCKET_START_STATUS){

            return $this->_http->Len($data);
        }else{
            //1bytes|1bytes|2bytes/8bytes|4bytes|nbytes
            //FIN|RSV|OPCODE|MASK|
            if (strlen($data)<2){
                return false;
            }
            $this->_headerLen=2;
            $this->_dataLen = 0;
            //echo $data;//它的内存是连续的，是多个字节的
            $firstByte = ord($data[0]);//取一个字节  但是它是ascii码
            //1000 0000 = 0x80
            $this->_fin = ($firstByte&0x80)==0x80?1:0;
            //0000 1111
            $this->_opcode = ($firstByte&0x0F);

            if ($this->_opcode==self::OPCODE_CLOSED){
                $this->_websocket_handshake_status = self::WEBSOCKET_CLOSED_STATUS;
                return true;
            }

            if ($this->_opcode==self::OPCODE_PING){

                return true;
            }

            $secondByte = ord($data[1]);

            $this->_mask = ($secondByte&0x80)==0x80?1:0;
            if ($this->_mask==0){
                $this->_websocket_handshake_status = self::WEBSOCKET_CLOSED_STATUS;
                return false;
            }
            $this->_headerLen+=4;

            //0111 1111
            //它发送的数据长度小于或等于125的话长度就是这么多
            //如果超过125就用2个字节来表示数据长度
            //如果超过了2个字节的长度，就用8个字节来存储数据长度

            $this->_payload_len = $secondByte&0x7F;
            if ($this->_payload_len==126){

                $this->_headerLen+=2;
            }
            else if ($this->_payload_len==127){
                $this->_headerLen+=8;

            }

            if (strlen($data)<$this->_headerLen){
                return false;
            }

            if ($this->_payload_len==126){

                $len = 0;
                $len|=ord($data[2])<<8;
                $len|=ord($data[3])<<0;
                $this->_dataLen = $len;
            }
            else if ($this->_payload_len==127){

                $len = 0;
                $len|=ord($data[2])<<56;
                $len|=ord($data[3])<<48;
                $len|=ord($data[4])<<40;
                $len|=ord($data[5])<<32;
                $len|=ord($data[6])<<24;
                $len|=ord($data[7])<<16;
                $len|=ord($data[8])<<8;
                $len|=ord($data[9])<<0;

                $this->_dataLen = $len;

            }else{
                $this->_dataLen = $this->_payload_len;
            }
            print_r($this->_dataLen);

            $this->_masKey[0] = $data[$this->_headerLen-4];
            $this->_masKey[1] = $data[$this->_headerLen-3];
            $this->_masKey[2] = $data[$this->_headerLen-2];
            $this->_masKey[3] = $data[$this->_headerLen-1];


            //小于的话，表示后面的数据载荷还没有接收完
            if (strlen($data)<$this->_headerLen+$this->_dataLen){
                return false;
            }
            return true;
        }
    }

    public function encode($data = '')
    {
        if ($this->_websocket_handshake_status==self::WEBSOCKET_START_STATUS){

            $handshakeData = $this->handshake();

            if ($handshakeData){
                $this->_websocket_handshake_status = self::WEBSOCKET_RUNNING_STATUS;
                return $this->_http->encode($handshakeData);

            }else{
                $this->_websocket_handshake_status = self::WEBSOCKET_CLOSED_STATUS;
                return $this->_http->encode($this->response400(""));
            }

        }else{

            if ($this->_opcode == self::OPCODE_PING){
                $this->_opcode = "";
//                $pong = $this->pong();
//                return [2,$pong];


            }
            //1000 0000
            //0000 0001
            //1000 0001
            $dataLen = strlen($data);//500
            $firstByte = 0x80|self::OPCODE_TEXT;
            $secondByte = 0x00|$dataLen;
            $headerLen = 2;
            if ($dataLen<=125){
                $frame = chr($firstByte).chr($secondByte).$data;
            }
            else if ($dataLen<65536){

                $len1 = $dataLen>>8&0xFF;//右边移动8位  高位就会移动到左边来
                $len2 = $dataLen>>0&0xFF;
                $frame = chr($firstByte).chr(126).chr($len1).chr($len2).$data;
                $headerLen+=2;
            }else{
                $frame = chr($firstByte).chr(127)
                    .chr($dataLen>>56&0XFF)
                    .chr($dataLen>>48&0XFF)
                    .chr($dataLen>>40&0XFF)
                    .chr($dataLen>>32&0XFF)
                    .chr($dataLen>>24&0XFF)
                    .chr($dataLen>>16&0XFF)
                    .chr($dataLen>>8&0XFF)
                    .chr($dataLen>>0&0XFF)
                    .$data;
                $headerLen+=8;
            }
            return [$headerLen+$dataLen,$frame];
        }
    }

    public function decode($data = '')
    {
        if ($this->_websocket_handshake_status==self::WEBSOCKET_START_STATUS){
            return $this->_http->decode($data);
        }else{
            //掩码运算
            // wiki https://baijiahao.baidu.com/s?id=1713307876537918339&wfr=spider&for=pc
            $data = substr($data,$this->_headerLen);
            if (strlen($data)<$this->_dataLen){
                return "";
            }
            for ($i=0;$i<$this->_dataLen;$i++){
                $data[$i] =$data[$i]^$this->_masKey[$i&0b00000011];
            }
            return $data;
        }
    }

    public function msgLen($data = '')
    {
        if ($this->_websocket_handshake_status==self::WEBSOCKET_START_STATUS){
            return $this->_http->msgLen($data);
        }else{
            return $this->_headerLen+$this->_dataLen;
        }
    }

    //这个是失败的http报文
    //websocket客户端会判断
    //成功的时候必须是101
    public function response400($data='')
    {
        $len = strlen($data);
        $text =sprintf("HTTP/1.1 %d %s\r\n",200,'OK');
        $text.=sprintf("Date: %s\r\n",date("Y-m-d H:i:s"));
        $text.=sprintf("OS: %s\r\n",PHP_OS);
        $text.=sprintf("Server: %s\r\n","Te/1.0");
        $text.=sprintf("Content-Language: %s\r\n","zh-CN,zh;q=0.9");
        $text.=sprintf("Connection: %s\r\n","Close");//keep-alive close
        $text.=sprintf("Access-Control-Allow-Origin: *\r\n");
        $text.=sprintf("Content-Type: %s\r\n","text/html;charset=utf-8");
        $text.=sprintf("Content-Length: %d\r\n",$len);
        $text.="\r\n";
        $text.=$data;
        return $text;
    }

    //握手的报文
    public function handshake()
    {
        if (isset($_REQUEST['Connection'])&&$_REQUEST['Connection']=="Upgrade"
            &&isset($_REQUEST['Upgrade'])&&$_REQUEST['Upgrade']=="websocket"
        ){
            $key = $_REQUEST['Sec_WebSocket_Key'];
            if ($key){
                $acceptKey = base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
                $text = sprintf("HTTP/1.1 101 Switching Protocols\r\n");
                $text.= sprintf("Upgrade: websocket\r\n");
                $text.= sprintf("Connection: Upgrade\r\n");
                $text.= sprintf("Sec-WebSocket-Accept: %s\r\n\r\n",$acceptKey);
                return $text;
            }
        }
        return false;
    }

    public function ping()
    {
        return chr(0x01|self::OPCODE_PING).chr(0x00);
    }

    public function pong()
    {
        return chr(0x01|self::OPCODE_PONG).chr(0x00);
    }
}
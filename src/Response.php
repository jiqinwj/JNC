<?php

namespace JNC;

class Response
{
    private $_statusReason = [
        '200' => 'OK',
        '400' => 'Bad Request',
        '401' => 'Unauthorized',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '405' => 'Method Not Allowed',
        '406' => 'Not Acceptable',
        '500' => 'Internal Server Error',
        '501' => 'Not Implemented',
        '502' => 'Bad Gateway',
    ];

    public $_connection;
    public $_headers = [];
    public $_status_code = 200;
    public $_status_info = "OK";

    public function __construct(TcpConnection $connection)
    {
        $this->_connection = $connection;
    }

    public function header($key, $value)
    {

        $this->_headers[$key] = $value;
    }

    public function status($code)
    {

        $this->_status_code = $code;
        if (isset($this->_statusReason[$code])) {

            $this->_status_info = $this->_statusReason[$code];
        }
    }

    public function write($data = "")
    {
        $len = strlen($data);
        $text = sprintf("HTTP/1.1 %d %s\r\n", $this->_status_code, $this->_status_info);
        //$text.=sprintf("Date: %s\r\n",date("Y-m-d H:i:s"));
        //$text.=sprintf("OS: %s\r\n",PHP_OS);
        $text .= sprintf("Server: %s\r\n", "JNC/1.0");
        $text .= sprintf("Content-Language: %s\r\n", "zh-CN,zh;q=0.9");

        //$text.=sprintf("Connection: %s\r\n",$_REQUEST['Connection']);//keep-alive close
        $text .= sprintf("Connection: %s\r\n", isset($_REQUEST['Connection']) ? $_REQUEST['Connection'] : 'close');//keep-alive close
        //$text.=sprintf("Access-Control-Allow-Origin: *\r\n");


        foreach ($this->_headers as $k => $v) {

            $text .= sprintf("%s: %s\r\n", $k, $v);
        }


        if (!isset($this->_headers['Content-Type'])) {
            $text .= sprintf("Content-Type: %s\r\n", "text/html;charset=utf-8");
        }
//        if (isset($_REQUEST['Accept_Encoding'])){
//
//            $encoding = $_REQUEST['Accept_Encoding'];
//            if (preg_match("/gzip/",$encoding)){
//
//                //启用内容压缩
//                $data = gzencode($data);
//                $len = strlen($data);
//                $text.=sprintf("Content-Encoding: %s\r\n","gzip");
//            }
//        }
        $text .= sprintf("Content-Length: %d\r\n", $len);

        $text .= "\r\n";
        $text .= $data;

        print_r("返回数据");

        $this->_connection->send($text);

        //http 1.1 1.0 0.9 GET Connection: keep-alive
        if (!isset($_REQUEST['Connection']) || strtolower($_REQUEST['Connection']) == "close") {
            $this->_connection->Close();
        }
    }

    //压缩协议
    public function chunked($data = "")
    {
        //$len = strlen($data);
        $text = "";
        if ($this->_start_chunked == 1) {
            $this->_start_chunked = 0;
            $text = sprintf("HTTP/1.1 %d %s\r\n", $this->_status_code, $this->_status_info);
            $text .= sprintf("Date: %s\r\n", date("Y-m-d H:i:s"));
            $text .= sprintf("OS: %s\r\n", PHP_OS);
            $text .= sprintf("Server: %s\r\n", "Jnc/1.0");
            $text .= sprintf("Content-Language: %s\r\n", "zh-CN,zh;q=0.9");

            $text .= sprintf("Connection: %s\r\n", $_REQUEST['Connection']);//keep-alive close
            $text .= sprintf("Access-Control-Allow-Origin: *\r\n");


            foreach ($this->_headers as $k => $v) {

                $text .= sprintf("%s: %s\r\n", $k, $v);
            }

            //$text.=sprintf("Content-Length: %d\r\n",$len);
            if (!isset($this->_headers['Content-Type'])) {
                $text .= sprintf("Content-Type: %s\r\n", "text/html;charset=utf-8");
            }
            $text .= sprintf("Transfer-Encoding: chunked\r\n");
            $text .= "\r\n";
        }

        $dataLen = dechex(strlen($data));
        $text .= $dataLen . "\r\n";
        $text .= $data . "\r\n";

        //每个Http中的body中分为 length 和 chunked data 。length 就是传输的数据长度，chunked data就是实际的传输数据。二者通过换行符分隔。
        //$text.="0\r\n";//用它来结束，表示响应实体结束了
        //$text.="\r\n";


        //https://blog.csdn.net/qq_40276626/article/details/120381161 wiki
        $this->_connection->send($text);

        //http 1.1 1.0 0.9 GET Connection: keep-alive

    }

    public function sendFile($file)
    {

        if (!file_exists($file)) {
            $this->status(404);
            $this->write("Not found file");
            return false;
        }
        $data = file_get_contents($file);
        if (!class_exists("finfo", false)) {
            return false;
        }
        $fi = new \finfo(FILEINFO_MIME_TYPE);
        $len = strlen($data);
        $text = sprintf("HTTP/1.1 %d %s\r\n", $this->_status_code, $this->_status_info);
        $text .= sprintf("Date: %s\r\n", date("Y-m-d H:i:s"));
        $text .= sprintf("OS: %s\r\n", PHP_OS);
        $text .= sprintf("Server: %s\r\n", "jnc/1.0");
        $text .= sprintf("Content-Language: %s\r\n", "zh-CN,zh;q=0.9");

        $text .= sprintf("Connection: %s\r\n", $_REQUEST['Connection']);//keep-alive close
        $text .= sprintf("Access-Control-Allow-Origin: *\r\n");

        foreach ($this->_headers as $k => $v) {
            $text .= sprintf("%s: %s\r\n", $k, $v);
        }
        $text .= sprintf("Content-Type: %s\r\n", $fi->file($file));
        if (isset($_REQUEST['Accept_Encoding'])) {

            $encoding = $_REQUEST['Accept_Encoding'];
            if (preg_match("/gzip/", $encoding)) {
                //启用内容压缩
                $data = gzencode($data);
                $len = strlen($data);
                $text .= sprintf("Content-Encoding: %s\r\n", "gzip");
            }
        }
        $text .= sprintf("Content-Length: %d\r\n", $len);
        $text .= "\r\n";
        $text .= $data;

        $this->_connection->send($text);

        if (strtolower($_REQUEST['Connection']) == "close") {
            $this->_connection->Close();
        }
    }

    //跨域处理
    public function sendMethods()
    {
        $text = "HTTP/1.1 200 OK\r\n";
        $text .= sprintf("Server: %s\r\n", "jnc");
        $text .= sprintf("Date: %s\r\n", date("Y-m-d H:i:s"));
        $text .= sprintf("Content-Length: 0\r\n");
        $text .= sprintf("Connection: keep-alive\r\n");
        $text .= sprintf("Access-Control-Allow-Origin: *\r\n");
        $text .= sprintf("Access-Control-Allow-Method:POST,GET\r\n");
        $text .= sprintf("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept\r\n\r\n");

        $this->_connection->send($text);
    }

}
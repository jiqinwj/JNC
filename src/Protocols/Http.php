<?php

namespace JNC\Protocols;
class Http implements Protocol
{
    public $_headerLen = 0;
    public $_bodyLen = 0;


    public $_post;
    public $_get;

    public function Len($data)
    {
        //前面肯定有这些
        if (strpos($data, "\r\n\r\n")) {
            //先➕上之前空白和换行4个字节
            $this->_headerLen = strpos($data, "\r\n\r\n");
            $this->_headerLen += 4;
            $bodyLen = 0;
            //有时候经常忘记正则表达式
            if (preg_match("/\r\nContent-Length: ?(\d+)/i", $data, $matches)) {
                $bodyLen = $matches[1];
            }
            $this->_bodyLen = $bodyLen;

            $totalLen = $this->_headerLen + $this->_bodyLen;

            if (strlen($data) >= $totalLen) {

                return true;
            }
            return false;
        }
        return false;
    }

    public function encode($data = '')
    {
        //编码 前面代表字节的长度，后面代码真正的数据
        return [strlen($data), $data];
    }

    public function decode($data = '')
    {
        $header = substr($data, 0, $this->_headerLen - 4);
        $body = substr($data, $this->_headerLen);
        $this->parseHeader($header);
        if ($body) {
            $this->parseBody($body);
        }
        return $body;
    }

    public function msgLen($data = '')
    {
        return $this->_bodyLen + $this->_headerLen;
    }

    //解析头部
    public function parseHeader($data)
    {
        //解析请求的url 路径 方法
        $_REQUEST = $_GET = [];
        $temp = explode("\r\n", $data);
        $startLine = $temp[0];
        list($method, $uri, $schema) = explode(" ", $startLine);
        $_REQUEST['uri'] = parse_url($uri)['path'];
        $query = parse_url($uri, PHP_URL_QUERY);
        parse_str($query, $_GET);
        $_REQUEST['method'] = $method;
        $_REQUEST['schema'] = $schema;

        unset($temp[0]);

        //解析那些key value
        foreach ($temp as $item) {
            $kv = explode(": ", $item, 2);
            $key = str_replace("-", "_", $kv[0]);
            $_REQUEST[$key] = rtrim($kv[1]);
        }

        //请求的服务器的端口和地址
        if (isset($_REQUEST["Host"])) {
            $ipAddr = explode(":", $_REQUEST["Host"], 2);
            $_REQUEST['ip'] = $ipAddr[0];
            $_REQUEST['port'] = $ipAddr[1]??80;
        }
    }


    //解析body
    public function parseBody($data)
    {

        $_POST = [];
        $content_type = $_REQUEST['Content_Type'];

        $boundary = "";//边界 \S 匹配非空白字符
        if (preg_match("/boundary=(\S+)/i", $content_type, $matches)) {
            $boundary = "--" . $matches[1];
            $content_type = "multipart/form-data";
        }

        //支持多种post 提交格式
        switch ($content_type) {
            case 'multipart/form-data':
                $this->parseFormData($boundary, $data);
                break;
            case 'application/x-www-form-urlencoded':
                parse_str($data, $_POST);
                break;
            case 'application/json':
                $_POST = json_decode($data, true);
                break;
        }
    }

    public function parseFormData($boundary, $data)
    {
        $data = substr($data, 0, -4);
        $formData = explode($boundary, $data);

        $_FILES = [];
        $key = 0;

        //解析form 表单数据
        foreach ($formData as $field) {

            if ($field) {
                $kv = explode("\r\n\r\n", $field, 2);//解析key 的值
                $value = rtrim($kv[1], "\r\n");//去除结尾的空白+换行
                //如果是文件的话以下处理
                if (preg_match('/name="(.*)"; filename="(.*)"/', $kv[0], $matches)) {
                    $_FILES[$key]['name'] = $matches[1];
                    $_FILES[$key]['file_name'] = $matches[2];
                    //$_FILES[$key]['file_value'] = $value;
                    file_put_contents("www/" . $matches[2], $value);//如果是文件就是二进制文件
                    $_FILES[$key]['file_size'] = strlen($value);
                    $fileType = explode("\r\n", $kv[0], 2);
                    $fileType = explode(": ", $fileType[1]);
                    $_FILES[$key]['file_type'] = $fileType[2];
                    ++$key;
                } else if (preg_match('/name="(.*)"/', $kv[0], $matches)) {
                    $_POST[$matches[1]] = $value;
                }
            }
        }
    }
}


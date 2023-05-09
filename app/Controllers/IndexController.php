<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2020/12/7 0007
 * Time: 下午 4:50
 */

namespace App\Controllers;


class IndexController extends BaseController
{

    function index()
    {
        print_r("index");
        $this->_response->write(json_encode(["code"=>200,"info"=>"数据请求成功"]));

    }
}
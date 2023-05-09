<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2020/12/7 0007
 * Time: 下午 5:25
 */
namespace App\Controllers;

use JNC\Request;
use JNC\Response;


class BaseController
{
    public $_response;
    public $_request;

    public function __construct(Request $request,Response $response)
    {
        $this->_request = $request;
        $this->_response = $response;

    }
}
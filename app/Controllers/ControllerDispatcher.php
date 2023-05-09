<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2020/12/7 0007
 * Time: 下午 4:52
 */

namespace App\Controllers;


use JNC\Request;
use JNC\Response;
use JNC\TcpConnection;

class ControllerDispatcher
{


    public function callAction($routes, Request $request, Response $response)
    {
        $uri = $request->_request['uri'];
        if (isset($routes[$uri])) {

            $route = explode("@", $routes[$uri]);
            $controller = new $route[0]($request, $response);
            $action = $route[1];
            try {
                if (method_exists($controller, $action)) {
                    $result = $controller->{$action}();
                }
            } catch (\Exception $e) {

                $result = $e->getMessage();
            }
        } else {

            $result = "Route not found";
        }
        //$response->header("Content-Type","application/json");
        $response->write($result);
        return true;
    }

    public function callWsAction($routes, TcpConnection $connection, $frame)
    {
        $frame = json_decode($frame, true);
        $cmd = $frame['cmd'];

        if (isset($routes['ws'][$cmd])) {

            $route = explode("@", $routes['ws'][$cmd]);
            $controller = new $route[0]($connection);
            $action = $route[1];
            try {
                if (method_exists($controller, $action)) {
                    $result = $controller->{$action}($frame['postData']);
                }
            } catch (\Exception $e) {

                $result = $e->getMessage();
            }
        } else {

            $result = "Route not found";
        }
        $connection->send($result);
        return true;
    }
}
<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2020/12/7 0007
 * Time: 下午 4:50
 */

return [
    "/api/index"=>"App\Controllers\IndexController@index",
    'ws'=>[
        "gpio/led"=>"App\Controllers\WsController@gpio",
        "oled/info"=>"App\Controllers\WsController@oled",
        "adc/info"=>"App\Controllers\WsController@adc",
        "mqtt/connect"=>"App\Controllers\WsController@mqtt",
    ]
];
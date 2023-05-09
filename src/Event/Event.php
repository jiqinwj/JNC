<?php

namespace JNC\Event;

//抽象接口事件 针对各种协议支持的epoll select 事件
interface Event
{
    const EV_READ = 10;//读事件
    const EV_WRITE = 11;//写事件


    const EV_SIGNAL = 12;//信号事件

    const EV_TIMER = 13;//定时器事件
    const EV_TIMER_ONCE = 14;//定时器事件 只处理一次

    //监听socket 连接socket
    public function add($fd,$flag,$func,$arg);//添加事件
    public function del($fd,$flag);//删除事件

    public function loop();//循环是否有可读可写的fd socket句柄
    public function exitLoop();//关闭循环监听
    public function clearTimer();//清理定时器
    public function clearSignalEvents();//清理信号事件
}
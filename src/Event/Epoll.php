<?php

namespace JNC\Event;

class Epoll implements Event
{


    public $_eventBase;//事件基类
    public $_allEvents = [];//所有的事件
    public $_signalEvents = [];//所有的信号事件
    public $_timers = [];//定时器

    static public $_timerId = 1;

    public function __construct()
    {
        $this->_eventBase = new \EventBase();
    }


    public function add($fd, $flag, $func, $arg=[])
    {
        switch ($flag) {
            case self::EV_READ:
                //fd 必须设置为非阻塞方式，因为epoll内部是使用非阻塞的文件描述符把它添加内核事件表
                //如果在事件上设置了Event::PERSIST标志，则该事件是持久的
                $event = new \Event($this->_eventBase, $fd, \Event::READ | \Event::PERSIST, $func, $arg);
                //$eventBase->io->entries[$fd] = new \Event($this->_eventBase,$fd,\Event::WRITE|\Event::PERSIST,$func,$arg);
                if (!$event || !$event->add()) {
                    //echo "read 事件添加失败\r\n";
                    print_r(error_get_last());//函数获取最后发生的错误
                    return false;
                }
                $this->_allEvents[(int)$fd][self::EV_READ] = $event;
                //echo "read 事件添加成功\r\n";
                return true;

                break;
            case self::EV_WRITE:

                //event = event_new(b->base, fd, what, event_cb, (void *)e);
                $event = new \Event($this->_eventBase, $fd, \Event::WRITE | \Event::PERSIST, $func, $arg);

                if (!$event || !$event->add()) {
                    //echo "write 事件添加失败\r\n";
                    return false;
                }
                //echo "write 事件添加成功\r\n";
                $this->_allEvents[(int)$fd][self::EV_WRITE] = $event;
                return true;

                break;
            case self::EV_SIGNAL:
                $event = new \Event($this->_eventBase, $fd, \Event::SIGNAL, $func, $arg);
                if (!$event || !$event->add()) {
                    return false;
                }
                $this->_signalEvents[(int)$fd] = $event;
                return true;
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                //$fd=1
                $timerId = static::$_timerId;
                $param = [$func, $flag, $timerId, $arg];
                $event = new \Event($this->_eventBase, -1, \Event::TIMEOUT | \Event::PERSIST, [$this, "timerCallBack"], $param);
                if (!$event || !$event->add($fd)) {
                    //echo "定时事件添加失败\r\n";
                    return false;
                }
                //echo "定时事件添加成功\r\n";
                $this->_timers[$timerId][$flag] = $event;
                ++static::$_timerId;
                return $timerId;
                break;
        }
    }

    public function del($fd, $flag)
    {
        //[1][read] = event
        //[1][write] = event
        //
        switch ($flag) {

            case self::EV_READ:
                if (isset($this->_allEvents[(int)$fd][self::EV_READ])) {
                    $event = $this->_allEvents[(int)$fd][self::EV_READ];
                    if ($event->del()) {
                        //echo "读事件移除成功\r\n";
                    }

                    unset($this->_allEvents[(int)$fd][self::EV_READ]);
                }
                if (empty($this->_allEvents[(int)$fd])) {

                    unset($this->_allEvents[(int)$fd]);
                }
                return true;
                break;
            case self::EV_WRITE:
                if (isset($this->_allEvents[(int)$fd][self::EV_WRITE])) {
                    $event = $this->_allEvents[(int)$fd][self::EV_WRITE];
                    if ($event->del()) {
                        //echo "写事件移除成功\r\n";
                    }

                    unset($this->_allEvents[(int)$fd][self::EV_WRITE]);
                }
                if (empty($this->_allEvents[(int)$fd])) {

                    unset($this->_allEvents[(int)$fd]);
                }
                return true;
                break;
            case self::EV_SIGNAL:
                if (isset($this->_signalEvents[$fd])) {
                    if ($this->_signalEvents[$fd]->del()) {
                        unset($this->_signalEvents[$fd]);
                        //echo "信号事件移除成功\r\n";
                    }

                }
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:

                if (isset($this->_timers[$fd][$flag])) {
                    if ($this->_timers[$fd][$flag]->del()) {
                        //echo "定时事件移除成功\r\n";
                        unset($this->_timers[$fd][$flag]);
                    }

                }
                break;
        }
    }

    public function loop()
    {
        //echo "执行事件循环了\r\n";
        $this->_eventBase->loop();//while epoll_wait
    }

    public function exitLoop()
    {
        return $this->_eventBase->stop();
    }

    public function clearSignalEvents()
    {
        foreach ($this->_signalEvents as $fd => $event) {
            if ($event->del()) {
                //echo "移除信号事件成功\r\n";
            }
        }
        $this->_signalEvents = [];
    }

    public function clearTimer()
    {
        foreach ($this->_timers as $timerId => $event) {
            if (current($event)->del()) {
                //echo "移除定时事件成功\r\n";
            }
        }
        $this->_timers = [];
    }

    public function timerCallBack($fd,$what,$arg)
    {
        // $param = [$func,$flag,$timerId,$arg];
        $func = $arg[0];
        $flag = $arg[1];
        $timerId = $arg[2];
        $userArg = $arg[3];

        if ($flag==Event::EV_TIMER_ONCE){
            $event = $this->_timers[$timerId][$flag];
            $event->del();
            unset($this->_timers[$timerId][$flag]);
        }
        call_user_func_array($func,[$timerId,$userArg]);
    }
}
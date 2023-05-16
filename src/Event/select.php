<?php

namespace JNC\Event;


class Select implements Event
{

    public $_eventBase;
    public $_allEvents = [];

    public $_readFds = [];//可读的fd 文件句柄
    public $_writeFds = [];//可写的fd 文件句柄
    public $_exptFds = [];
    public $_timeout = 100000000;//1秒=1000毫秒 1毫秒=1000微妙 100秒 微妙级别的定时

    public $_signalEvents = [];//信号事件
    public $_timers = [];//定时器
    static public $_timerId = 1;
    public $_run = true;//是否运行

    public function add($fd, $flag, $func, $arg = [])
    {
        switch ($flag) {
            case self::EV_READ:
                $fdKey = (int)$fd;
                $this->_readFds[$fdKey] = $fd;
                $this->_allEvents[$fdKey][self::EV_READ] = [$func, [$fd, $flag, $arg]];
                return true;
                break;
            case self::EV_WRITE:
                $fdKey = (int)$fd;
                $this->_writeFds[$fdKey] = $fd;
                $this->_allEvents[$fdKey][self::EV_WRITE] = [$func, [$fd, $flag, $arg]];
                return true;
                break;
            case self::EV_SIGNAL:
                $param = [$func, $arg];
                $this->_signalEvents[$fd] = $param;
                //第三个参数为什么是false,它默认是true【这部分的知识在讲中断信号的讲过】
                if (pcntl_signal($fd, [$this, "signalHandler"], false)) {
                    //echo posix_getpid()." pid 中断信号事件添加成功 $fd \r\n";
                }
                return true;
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                //fd 现在是当成这个微妙
                $timerId = static::$_timerId;
                $runTime = microtime(true) + $fd;
                $param = [$func, $runTime, $flag, $timerId, $fd, $arg];

                $this->_timers[$timerId] = $param;

                $selectTime = $fd * 1000000;//这里是转换为秒 百万级微妙
                if ($this->_timeout >= $selectTime) {
                    $this->_timeout = $selectTime;
                }
                ++static::$_timerId;
                return $timerId;
                break;
        }
    }

    public function del($fd, $flag)
    {
        //[1][read] = event
        //[1][write] = event
        //_allEvents[1][read] = func
        //_allEvents[1][write] = func
        switch ($flag){

            case self::EV_READ:
                $fdKey = (int)$fd;
                unset($this->_allEvents[$fdKey][self::EV_READ]);
                unset($this->_readFds[$fdKey]);

                if (empty($this->_allEvents[$fdKey])){
                    unset($this->_allEvents[$fdKey]);
                }
                return true;
                break;
            case self::EV_WRITE:
                $fdKey = (int)$fd;
                unset($this->_allEvents[$fdKey][self::EV_WRITE]);
                unset($this->_writeFds[$fdKey]);

                if (empty($this->_allEvents[$fdKey])){
                    unset($this->_allEvents[$fdKey]);
                }
                return true;
                break;
            case self::EV_SIGNAL:

                if (isset($this->_signalEvents[$fd])){
                    unset($this->_signalEvents[$fd]);
                    pcntl_signal($fd,SIG_IGN);
                }
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:

                if (isset($this->_timers[$fd])){
                    unset($this->_timers[$fd]);
                }
                break;
        }
    }

    public function loop()
    {
        while ($this->_run){

            if (DIRECTORY_SEPARATOR=="/"){
                pcntl_signal_dispatch();
            }

            $reads = $this->_readFds;
            $writes = $this->_writeFds;
            $expts = $this->_exptFds;// 发送紧急数据有点用，URG tcp头部结构的标志 FIN|RST|ACK|PSH|URG
            set_error_handler(function (){});
            $ret = stream_select($reads,$writes,$expts,0,$this->_timeout);
            restore_error_handler();

            if (!empty($this->_timers)){
                $this->timerCallBack();
            }
            //select 是可重入函数【中断系统】可中断系统调用
            if (!$ret){
                continue;
            }
            if ($reads){
                foreach ($reads as $fd) {
                    $fdKey = (int)$fd;
                    if (isset($this->_allEvents[$fdKey][self::EV_READ])){
                        $callback = $this->_allEvents[$fdKey][self::EV_READ];
                        call_user_func_array($callback[0],$callback[1]);
                    }
                }
            }
            if ($writes){
                foreach ($writes as $fd) {
                    $fdKey = (int)$fd;
                    if (isset($this->_allEvents[$fdKey][self::EV_WRITE])){
                        $callback = $this->_allEvents[$fdKey][self::EV_WRITE];
                        call_user_func_array($callback[0],$callback[1]);
                    }
                }
            }
        }
    }

    public function exitLoop()
    {
        // TODO: Implement exitLoop() method.
        $this->_run=false;
        $this->_readFds=[];
        $this->_writeFds=[];
        $this->_exptFds=[];
        $this->_allEvents=[];
        return true;
    }

    public function clearTimer()
    {
        // TODO: Implement clearTimer() method.
        $this->_timers = [];
    }

    public function clearSignalEvents()
    {
        // TODO: Implement clearSignalEvents() method.
        foreach ($this->_signalEvents as $fd=>$arg){
            pcntl_signal($fd,SIG_IGN,false);//pcntl_signal(SIGPIPE, SIG_IGN, false)：忽略内核发来的SIGPIPE信号,当连接已closed,进程继续发数据到无效socke
        }
        $this->_signalEvents = [];
    }

    public function timerCallBack()
    {

        //print_r($this->_timers);
        //$param = [$func,$runTime,$flag,$timerId,$arg];
        foreach ($this->_timers as $k=>$timer){

            $func = $timer[0];
            $runTime = $timer[1];//未来执行的时间点
            $flag = $timer[2];
            $timerId = $timer[3];
            $fd = $timer[4];
            $arg = $timer[5];

            if ($runTime-microtime(true)<=0){

                if ($flag==Event::EV_TIMER_ONCE){
                    unset($this->_timers[$timerId]);
                }else{
                    $runTime = microtime(true)+$fd;//取得下一个时间点
                    $this->_timers[$k][1] = $runTime;
                }
                call_user_func_array($func,[$timerId,$arg]);
            }

        }
    }
}
<?php

namespace JNC;

use JNC\Event\Epoll;
use JNC\Event\Event;
use JNC\Event\Select;
use JNC\Protocols\Http;
use JNC\Protocols\Websocket;
use mysql_xdevapi\Exception;

class Server
{

    public $_mainSocket; //创建主socket stream_socket_server
    public $_local_socket; //域名+端口 本地域名
    public $_usingProtocol; //正在使用的协议
    public $_protocol; //当前使用的协议对象
    public $_startTime = 0;//当前运行的开始时间
    public $_setting = [];//一些参数设置
    public $_events = [];//一些事件触发方法//比方start.
    public static $_os;//判断是什么操作系统
    static public $_eventLoop;//时间循环监听列表
    static public $_clientNum = 0;//统计客户端连接数量
    static public $_recvNum = 0;//执行recv/fread调用次数
    static public $_msgNum = 0;//接收了多少条消息
    static public $_connections = [];//存入客户端的连接
    public static $_startFile;//启动文件
    public static $_pidFile;//pid文件
    public static $_logFile;//log文件

    static public $_status;
    public $_pidMap = [];//pid map 映射

    const STATUS_STARTING = 1;
    const STATUS_RUNNING = 2;
    const STATUS_SHUTDOWN = 3;

    /*
     * 支持多种协议
     */
    public $_protocols = [
        "stream" => "JNC\Protocols\Stream",
        "text" => "JNC\Protocols\Text",//自定义协议
        "ws" => "JNC\Protocols\Websocket",
        "http" => "JNC\Protocols\Http",
        "mqtt" => "JNC\Protocols\Mqtt",
    ];


    //构造方法
    public function __construct($local_socket)
    {
        list($protocol, $ip, $port) = explode(":", $local_socket);
        //检查协议里面有没有支持的协议。有的话 就实例化此协议
        if (isset($this->_protocols[$protocol])) {
            $this->_usingProtocol = $protocol;
            $this->_protocol = new $this->_protocols[$protocol];
        } else {
            $this->_usingProtocol = "tcp";//默认走tcp协议
        }
        $this->_startTime = time();
        $this->_local_socket = "tcp:" . $ip . ":" . $port;
    }

    //监听方法
    public function Listen()
    {
        $flag = STREAM_SERVER_LISTEN | STREAM_SERVER_BIND;
        /* wiki http://www.360doc.com/content/20/0204/01/99071_889552632.shtml
         * 三次握手协议
         * 客户端发送SYN报文,客户端TCP状态SYN-SENT状态,如果服务端没有返回确认，执行重试,重试次数为net
         * 服务端收到SYN报文，状态转换为SYN-RECV状态，并将连接句柄【也就是创建的socket描述符】放到半连接队列中，同时返回SYN+ACK，半连接队列大小伟net.ipv4.tcp_max_syn_backlog
         * 如果半连接已经满了，服务端直接丢弃SYN,客户端自动重试，
         * 客户端收到服务端SYN+ACK后，会返回ACK报文，客户端状态修改为ESTABLISHED状态
         * 如果全连接队列已满，当net.ipv4.tcp_abort_on_overflow为0，则丢弃ACK，并重发SYN+ACK，重试次数为net.ipv4.tcp_synack_retries。
           当net.ipv4.tcp_abort_on_overflow为1，则收到ACK后，直接发RST段关闭连接，客户端一般报connection reset by peer的错误。
         * 服务端收到ACK报文后,检查全连接队列是否已满，如果队列未满，则将这个连接句柄放入全连接队列，服务端状态修改为ESTABLISHED状态，至此TCP三次握手结束
         * note:
         * 如果backlog值太小，在高并发的情况下，客户端的ack会被丢弃，并触发服务端重新发送SYN+ACK。客户端出现大量连接失败的情况。
           如果backlog值太大，会产生大量的socket连接堆积。连接超时后，会导致write Broken pipe错误。也就是写失败。
           所以该参数要根据业务场景，进行配置调优。
         */
        $option['socket']['backlog'] = 102400; //全连接队列
        /*
         * 一个客户端连接---结果2个连接epoll_wait 都会返回1 虽然epoll的都是独立的，但是每个进程的监听socket都是一样的
            解决方案：
             1 每个进程都要拥有独立的监听socket
             2 加上reuseport 复用端口
             3 当服务器收到socket的时候 其实是linux内核会分发到相应的进程中
         */
        $option['socket']['so_reuseport'] = 1;

        $context = stream_context_create($option);
        $this->_mainSocket = stream_socket_server($this->_local_socket, $errno, $errstr, $flag, $context);
        /*
         * socket_import_stream函数可以将使用stream_socket_server创建stream socket句柄转换为标准的socket句柄，
         * 因为标准socket支持更多的配置选项。在workerman中有如下代码：
         */
        $socket = socket_import_stream($this->_mainSocket);
        //https://zhuanlan.zhihu.com/p/80104656
        // Nagle算法的作用是减少小包的数量
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
        //设置为非阻塞
        stream_set_blocking($this->_mainSocket, 0);

        //不是一个可靠的资源 直接退出
        if (!is_resource($this->_mainSocket)) {
            $this->echoLog("server create fail:%s\n", $errstr);
            exit(0);
        }
    }

    //处理回调方法
    public function runEventCallBack($eventName, $args = [])
    {
        if (isset($this->_events[$eventName]) && is_callable($this->_events[$eventName])) {
            return $this->_events[$eventName]($this, ...$args);
        }
    }

    //接受客户端的请求
    public function Accept()
    {
        //屏蔽socket 错误
        set_error_handler(function () {
        });
        //stream_socket_accept peername 存入当前的客户端IP和PORT
        $confd = stream_socket_accept($this->_mainSocket, -1, $peername);
        restore_error_handler();
        if (is_resource($confd)) {
            $protocol = null;
            if (is_object($this->_protocol)) {
                $protocol = clone $this->_protocol;
            }

            $connection = new TcpConnection($confd, $peername, $this, $protocol);
            $this->onClientJoin();//连接上了客户端数量++
            if ($protocol instanceof Http) {
                $connection->_link_type = TcpConnection::HTTP_LINK_TYPE;
            } else if ($protocol instanceof Websocket) {
                $connection->_link_type = TcpConnection::WS_LINK_TYPE;
            }


            static::$_connections[(int)$confd] = $connection;

            $this->runEventCallBack("connect", [$connection]);
        }
    }

    //监听到连接后，握手接收客户端的数据
    public function acceptClient()
    {
        static::$_eventLoop->add($this->_mainSocket, Event::EV_READ, [$this, "Accept"]);
    }

    //检查配置是否存在
    public function checkSetting($item)
    {
        if (isset($this->_setting[$item]) && $this->_setting[$item] == true) {
            return true;
        }
        return false;
    }

    //打印日志
    public function echoLog($format, ...$data)
    {
        if ($this->checkSetting("daemon") && static::$_os != "WIN") {
            $info = sprintf($format, ...$data);
            $msg = "[pid:" . posix_getpid() . "]-[" . date("Y-m-d H:i:s") . "]-[info:" . $info . "]\r\n";
            file_put_contents(static::$_logFile, $msg, FILE_APPEND);
        } else {
            fprintf(STDOUT, $format, ...$data);
        }
    }

    //设置一些参数
    public function setting($setting)
    {
        $this->_setting = $setting;
    }

    //回调事件方法
    public function on($eventName, $eventCall)
    {
        $this->_events[$eventName] = $eventCall;
    }

    public function onRecv()
    {
        ++static::$_recvNum;
    }

    public function onMsg()
    {
        ++static::$_msgNum;
    }

    public function onClientJoin()
    {
        ++static::$_clientNum;
    }

    //移除连接
    public function removeClient($sockfd)
    {
        if (isset(static::$_connections[(int)$sockfd])) {
            unset(static::$_connections[(int)$sockfd]);
            --static::$_clientNum;
        }
    }

    //设置为守护进程运行
    public function daemon()
    {
        //会话的首进程ID会作为整个会话的ID
        //wiki https://blog.csdn.net/csdn_leidada/article/details/125356851
        /*
         * 1. 设置文件创建屏蔽字 umask(0)
           文件创建屏蔽字是指屏蔽掉文件创建时的对应位（umask() 控制系统文件和目录默认权限）。由于使用fork系统调用新建的子进程继承了父进程的文件创建掩码，
        这就给该子进程使用文件带来了诸多的不便。因此，把文件创建掩码设置为0，可以大大增强该守护进程的灵活性。
         */
        umask(000);
        /*
         * 调用fork，父进程退出（exit）；
          如果该守护进程是作为一条简单的shell命令启动的，那么父进程终止使得shell认为该命令已经执行完毕；
        保证子进程不是一个进程组的组长进程，为什么要保证不是进程组组长呢？ 因为进程组组长调用setsid创建会话会报错；
         */
        $pid = pcntl_fork();
        if ($pid > 0) {
            exit(0);
        }

        /*
         *  子进程调用setsid 函数来创建会话
            先介绍一下Linux中的进程与控制终端，登录会话和进程组之间的关系：进程属于一个进程组，
            进程组号（GID）就是进程组长的进程号（PID）。登录会话可以包含多个进程组。这些进程组共享一个控制终端。这个控制终端通常是创建进程的登录终端。
            控制终端，登录会话和进程组通常是从父进程继承下来的。我们的目的就是要摆脱它们，使之不受它们的影响。方法是在第2点的基础上，调用setsid()使进程成为会话组长：
            setsid()调用成功后，进程成为新的会话组长和新的进程组长，并与原来的登录会话和进程组脱离。由于会话过程对控制终端的独占性，进程同时与控制终端脱离。
            调用setsid有3个作用：
            让进程摆脱原会话的控制；
            让进程摆脱原进程组的控制；
            让进程摆脱原控制终端的控制
         */
        if (-1 == posix_setsid()) {
            throw new Exception("setsid failure");
            exit(0);
        }
        /*
         * 当调用setsid函数后，一般会在创建一个子进程，让会话首进程退出，确保该进程不会再获得控制终端
            （1）调用一次fork的作用：
            第一次fork的作用是让shell认为这条命令已经终止，不用挂在终端输入上，
            还有就是为了后面的setsid服务，因为调用setsid函数的进程不能是进程组组长，如果不fork出子进程，则此时的父进程是进程组组长，
            就无法调用setsid。当子进程调用完setsid函数之后，子进程是会话组长也是进程组组长，并且脱离了控制终端，此时，不管控制终端如何操作
            ，新的进程都不会收到一些信号使得进程退出。
            （2）第二次fork的作用：
            虽然当前关闭了和终端的联系，但是后期可能会误操作打开了终端。
            只有会话首进程能打开终端设备， 也就是再fork一次，再把父进程退出，再次fork的子进程作为守护进程继续运行，保证了该守护进程不再是会话的首进程。
            第二次不是必须的，是可选的。

         */
        $pid = pcntl_fork();
        if ($pid > 0) {
            exit(0);
        }
    }

    public function resetFd()
    {

//        fclose(STDIN);
//        fclose(STDOUT);
//        fclose(STDERR);
//
//        fopen("/dev/null","a");
//        fopen("/dev/null","a");
//        fopen("/dev/null","a");


    }

    public function Start()
    {
        static::$_status = self::STATUS_STARTING;
        $this->init();//初始化一些数据
        global $argv;
        $command = $argv[1];

        switch ($command) {

            case "start":
                cli_set_process_title("JNC/master");//设置进程的名字
                if (is_file(static::$_pidFile)) {
                    $masterPid = file_get_contents(static::$_pidFile);
                } else {
                    $masterPid = 0;
                }

                //当前进程启动后，会从pidFile取出服务器的进程号，如果进程号存活并且当前进程不是已经启动的服务器进程
                /* posix_kill($master_pid,0)用来判断进程是否存在，posix_kill本意是向该进程发送信号。
                 * posix_getpid(): int  Return the process identifier of the current process. 返回当前进程的 id
                 */
                $masterPidisAlive = $masterPid && posix_kill($masterPid, 0) && $masterPid != posix_getpid();

                //防止重复启动
                if ($masterPidisAlive) {
                    exit("server already running...");
                }

                $this->runEventCallBack("masterStart", [$this]);


                if ("LINUX" == static::$_os) {

                    if ($this->checkSetting("daemon")) {

                        $this->daemon();
                        $this->resetFd();
                    }
                    $this->saveMasterPid();
                    $this->installSignalHandler();
                    $this->forkWorker();
                    $this->forkTasker();

                    static::$_status = self::STATUS_RUNNING;

                    //不要再使用echo,print var_dump
                    //fpm 框架[laravel,tp,yii,ci..]
                    $this->displayStartInfo();
                    $this->masterWorker();
                } else {
                    //c /c ++ win api   msdn
                    $this->displayStartInfo();
                    $this->worker();

                }


                break;
            case "stop":

                $masterPid = file_get_contents(static::$_pidFile);
                if ($masterPid && posix_kill($masterPid, 0)) {

                    posix_kill($masterPid, SIGINT);
                    echo "发送了SIGTERM信号了\r\n";
                    echo $masterPid . "\r\n";
                    $timeout = 5;
                    $stopTime = time();
                    while (1) {

                        $masterPidisAlive = $masterPid && posix_kill($masterPid, 0) && $masterPid != posix_getpid();
                        if ($masterPidisAlive) {

                            if (time() - $stopTime >= $timeout) {

                                $this->echoLog("server stop failure\r\n");
                                break;
                            }
                            sleep(1);
                            continue;
                        }
                        $this->echoLog("server stop success\r\n");
                        break;
                    }

                } else {
                    exit("server not exist...");
                }
                break;
            default:
                //php te.php start|stop
                $usage = "php " . pathinfo(static::$_startFile)['filename'] . ".php [start|stop]\r\n";
                exit($usage);
        }
    }

    public function tasker($i)
    {
        srand();
        mt_rand();
        cli_set_process_title("Te/tasker");
        $unix_socket_file = $this->_setting['task']['unix_socket_server_file'] . $i;
        if (file_exists($unix_socket_file)) {
            unlink($unix_socket_file);
        }
        //创建好的socket文件绑定一个文件
        $this->_unix_socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        socket_bind($this->_unix_socket, $unix_socket_file);//绑定一个地址

        $stream = socket_export_stream($this->_unix_socket);
        socket_set_blocking($stream, 0);

        //socket_import_stream($this->_unix_socket);

        if (DIRECTORY_SEPARATOR == "/") {
            static::$_eventLoop = new Epoll();
        } else {
            static::$_eventLoop = new Select();
        }
        pcntl_signal(SIGINT, SIG_IGN, false);
        pcntl_signal(SIGTERM, SIG_IGN, false);
        pcntl_signal(SIGQUIT, SIG_IGN, false);

        static::$_eventLoop->add(SIGINT, Event::EV_SIGNAL, [$this, "tasksigHandler"]);
        static::$_eventLoop->add(SIGTERM, Event::EV_SIGNAL, [$this, "tasksigHandler"]);
        static::$_eventLoop->add(SIGQUIT, Event::EV_SIGNAL, [$this, "tasksigHandler"]);

        static::$_eventLoop->add($stream, Event::EV_READ, [$this, "acceptUdpClient"]);

        //$this->runEventCallBack("workerStart",[$this]);

        $this->eventLoop();//while

        //fprintf(STDOUT,"<workerPid:%d>exit success\r\n",posix_getpid());
        //子进程退出之前做一些工作
        //$this->runEventCallBack("workerStop",[$this]);
        exit(0);


//        while (1){
//
//            $len = socket_recvfrom($this->_unix_socket,$buf,1024,0,$unixClientFile);
//            if ($len){
//
//                fprintf(STDOUT,"recv data:%s,file=%s\n",$buf,$unixClientFile);
//
//                //socket_sendto($sockfd,$buf,strlen($buf),0,$unixClientFile);
//            }
////            if(strncasecmp(trim($buf),"quit",4)==0){
////                break;
////            }
//        }
    }


    public function forkTasker()
    {
        $workerNum = 1;
        if (isset($this->_setting['taskNum'])) {
            $workerNum = $this->_setting['taskNum'];
        }
        for ($i = 0; $i < $workerNum; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->tasker($i + 1);
            } else {
                $this->_pidMap[$pid] = $pid;
            }
        }
    }

    //保存主进程id
    public function saveMasterPid()
    {
        $masterPid = posix_getpid();
        file_put_contents(static::$_pidFile, $masterPid);
    }

    public function installSignalHandler()
    {
        //安装信号处理器
        /*
         * SIGINT：程序终止(interrupt)信号，通常由ctrl+c触发；
         * SIGTERM：信号触发命令：kill pid、kill -15 pid 、kill -SIGTERM等；
         * SIGQUIT    建立CORE文件终止进程，并且生成core文件
         * SIGPIPE    终止进程向一个没有读进程的管道写数据
         */
        pcntl_signal(SIGINT, [$this, "sigHandler"], false);
        pcntl_signal(SIGTERM, [$this, "sigHandler"], false);
        pcntl_signal(SIGQUIT, [$this, "sigHandler"], false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);//主要是读写socket文件时产生该信号时忽略
    }

    public function init()
    {
        date_default_timezone_set("Asia/Shanghai");
        //pidFile logFile startFile
        $trace = debug_backtrace();
        $startFile = array_pop($trace)['file'];
        static::$_startFile = $startFile;
        static::$_pidFile = pathinfo($startFile)['filename'] . ".pid";
        static::$_logFile = pathinfo($startFile)['filename'] . ".log";
        if (!file_exists(static::$_logFile)) {
            touch(static::$_logFile);
        }
        // /home/soft/php/bin
        //
        if (DIRECTORY_SEPARATOR == "/") {
            static::$_os = "LINUX";
            chown(static::$_logFile, posix_getuid());//函数改变指定文件的所有者。
        } else {
            static::$_os = "WIN";
        }
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            $this->echoLog("<file:%s>---<line:%s>---<info:%s>\r\n", $errfile, $errline, $errstr);
        });
    }


    //创建子worker 干活
    public function forkWorker()
    {
        $workerNum = 1;
        if (isset($this->_setting['workerNum'])) {
            $workerNum = $this->_setting['workerNum'];
        }
        for ($i = 0; $i < $workerNum; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->worker();
            } else {
                $this->_pidMap[$pid] = $pid;
            }
        }
    }

    public function worker()
    {
        //cow 进程在复制的时候会上父进程已经存在的数据【正文段+数据（bss .data 常量数据段，静态全局数据段）段】
        /*
         * 这里说子进程拥有父进程数据空间以及堆、栈的副本，实际上，在大多数的实现中也并不是真正的完全副本。
         * 更多是采用了COW（Copy On Write）即写时复制的技术来节约存储空间。简单来说，如果父进程和子进程都不修改这些 数据、堆、栈 的话，
         * 那么父进程和子进程则是暂时共享同一份 数据、堆、栈。只有当父进程或者子进程试图对 数据、堆、栈 进行修改的时候，才会产生复制操作，这就叫做写时复制。
         */
        srand();
        mt_rand();

        //正常启动时复制的数据这个状态是STARTING ,如果是子进程异常【接收到中断等退出】
        if (self::STATUS_RUNNING == static::$_status) {
            //echo "我是异常终止之后启动新进程替换的\r\n";
            $this->runEventCallBack("workerReload", [$this]);
        } else {
            //echo "我是正常启动的\r\n";
            static::$_status = self::STATUS_RUNNING;
        }
        cli_set_process_title("Te/worker");
        $this->Listen();//这个为了防止惊群效应

        if (DIRECTORY_SEPARATOR == "/") {
            static::$_eventLoop = new Epoll();

            pcntl_signal(SIGINT, SIG_IGN, false);
            pcntl_signal(SIGTERM, SIG_IGN, false);
            pcntl_signal(SIGQUIT, SIG_IGN, false);

            static::$_eventLoop->add(SIGINT, Event::EV_SIGNAL, [$this, "sigHandler"]);
            static::$_eventLoop->add(SIGTERM, Event::EV_SIGNAL, [$this, "sigHandler"]);
            static::$_eventLoop->add(SIGQUIT, Event::EV_SIGNAL, [$this, "sigHandler"]);

        } else {
            static::$_eventLoop = new Select();
        }

        //static::$_eventLoop->add($this->_mainSocket,Event::EV_READ,[$this,"Accept"]);
        $this->acceptClient();

        //static::$_eventLoop->add(1,Event::EV_TIMER,[$this,"checkHeartTime"]);

        //每个进程统计自己的连接数+fread接收次数+消息数
        //static::$_eventLoop->add(1,Event::EV_TIMER,[$this,"statistics"]);
        //static::$_eventLoop->add(2,Event::EV_TIMER,function ($timerId,$arg){
//
        //     echo posix_getpid()."do 定时\r\n";
        // });

        $this->runEventCallBack("workerStart", [$this]);

        $this->eventLoop();//while

        //fprintf(STDOUT,"<workerPid:%d>exit success\r\n",posix_getpid());
        //子进程退出之前做一些工作
        $this->runEventCallBack("workerStop", [$this]);
        exit(0);
    }

    //事件循环
    public function eventLoop()
    {
        static::$_eventLoop->loop();
    }

    //显示终端信息
    public function displayStartInfo()
    {
        $info = "\r\n\e[31;40m" . file_get_contents("logo.txt") . " \e[0m \r\n";
        $info .= "\e[33;40m Te workerNum:" . $this->_setting['workerNum'] . " \e[0m \r\n";
        $info .= "\e[33;40m Te taskNum:" . $this->_setting['taskNum'] . " \e[0m \r\n";

        $info .= "\e[33;40m Te run mode:" . ($this->checkSetting("daemon") ? "deamon" : "debug") . " \e[0m \r\n";
        $info .= "\e[33;40m Te working with :" . $this->_usingProtocol . " protocol \e[0m \r\n";
        $info .= "\e[33;40m Te server listen on :" . $this->_local_socket . " \e[0m \r\n";
        $info .= "\e[33;40m Te run on :" . static::$_os . " platform \e[0m \r\n";
        fwrite(STDOUT, $info);
    }

    public function masterWorker()
    {
        //主进程回收退出的子进程
        while (1) {
            /*
              * wiki https://blog.csdn.net/qq_35845964/article/details/84188299
              * 执行此代码会在终端输出你想要的结果，其实官方的pcntl_signal性能极差，主要是PHP的函数无法直接注册到操作系统信号设置中，所以pcntl信号需要依赖tick机制来完成。
              pcntl_signal的实现原理是，触发信号后先将信号加入一个队列中。然后在PHP的ticks回调函数中不断检查是否有信号，如果有信号就执行PHP中指定的回调函数，如果没有则跳出函数。
              ticks=1表示每执行1行PHP代码就回调此函数。实际上大部分时间都没有信号产生，但ticks的函数一直会执行。
              比较好的做法是去掉ticks，转而使用pcntl_signal_dispatch，在代码循环中自行处理信号。
            */
            pcntl_signal_dispatch();
            $pid = pcntl_wait($status);
            pcntl_signal_dispatch();
            if ($pid > 0) {
                unset($this->_pidMap[$pid]);
                if (self::STATUS_SHUTDOWN != static::$_status) {
                    $this->reloadWorker();
                }
            }
            if (empty($this->_pidMap)) {
                break;
            }
        }
        $this->runEventCallBack("masterShutdown", [$this]);
        exit(0);
    }

    public function reloadWorker()
    {
        $pid = pcntl_fork();//cow 它会复制数据
        if ($pid === 0) {
            $this->worker();
        } else {
            $this->_pidMap[$pid] = $pid;
        }
    }

    //主进程和子进程收到中断信号会执行该函数
    public function sigHandler($sigNum)
    {
        $masterPid = file_get_contents(static::$_pidFile);
        switch ($sigNum) {
            case SIGINT:
            case SIGTERM:
            case SIGQUIT:
                //主进程
                if ($masterPid == posix_getpid()) {
                    foreach ($this->_pidMap as $pid => $pid) {
                        posix_kill($pid, $sigNum);//SIGKILL 它是粗暴的关掉，不过子进程在干什么 SIGTERM,SIGQUIT
                    }
                    static::$_status = self::STATUS_SHUTDOWN;
                } else {
                    //子进程的 就要停掉现在的任务了
                    static::$_eventLoop->del($this->_mainSocket, Event::EV_READ);
                    set_error_handler(function () {
                    });
                    fclose($this->_mainSocket);
                    restore_error_handler();
                    $this->_mainSocket = null;
                    foreach (static::$_connections as $fd => $connection) {
                        $connection->Close();
                    }
                    static::$_connections = [];
                    static::$_eventLoop->clearSignalEvents();
                    static::$_eventLoop->clearTimer();
                    if (static::$_eventLoop->exitLoop()) {
                        $this->echoLog("<pid:%d> worker exit event loop success\r\n", posix_getpid());
                    }
                }
                break;
        }
    }


    //task 监听信号
    public function tasksigHandler($sigNum)
    {
        static::$_eventLoop->del($this->_unix_socket, Event::EV_READ);
        set_error_handler(function () {
        });
        fclose($this->_unix_socket);
        restore_error_handler();
        $this->_unix_socket = null;

        static::$_eventLoop->clearSignalEvents();
        static::$_eventLoop->clearTimer();

        if (static::$_eventLoop->exitLoop()) {
            $this->echoLog("<pid:%d> task exit event loop success\r\n", posix_getpid());
        }
    }

    //unix socket tcp,udp
    public function acceptUdpClient()
    {

        //unix 通信 udp
        set_error_handler(function () {
        });
        $len = socket_recvfrom($this->_unix_socket, $buf, 65535, 0, $unixClientFile);
        restore_error_handler();
        if ($buf && $unixClientFile) {
            $udpConnection = new UdpConnection($this->_unix_socket, $len, $buf, $unixClientFile);
            //$this->runEventCallBack("task",[$udpConnection,$buf]);
            //$wrapper = unserialize($buf);
            //$closure = $wrapper->getClosure();
            //$closure($this);

        }
        return false;
    }


}
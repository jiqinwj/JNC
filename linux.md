### linux 常用命令
netstat -anp | grep 8080

-- 查看端口是否被占用 

ps aux | grep 9503 | xargs kill -9

-- ps 命令是最常用的监控进程的命令，通过此命令可以查看系统中所有运行进程的详细信息。

### wiki https://blog.csdn.net/weixin_37780776/article/details/118947353
xargs （英文全拼： eXtended ARGuments）是给命令传递参数的一个过滤器，也是组合多个命令的一个工具。之所以能用到这个命令，主要是由于很多命令不支持管道符号 | 来传递参数，而日常工作中经常有这个必要
<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2020/11/20 0020
 * Time: 下午 8:09
 */

$a = 9;
//phpstorm 我用的是盗版 【我没有钱，我穷没有钱买正版】
$bin = pack("x");//它在内存中数据是0000 0000 虽然是空值，但是占用一个字节
echo strlen($bin);
echo $bin;

echo ord($bin);

//print_r(unpack("x",$bin));

echo "************************\r\n";
//0000 0001 0000 0000 0000 0011
$b = 65539;//占用2个字节的存储空间
//0000 0001 0010 1100
//$bin = pack("C",$b);//高位丢掉
//print_r(unpack("C",$bin));
$bin = pack("NXX",$b);//类似截取，把数值后面的2个字节干掉
echo strlen($bin);
//echo $bin;
print_r(unpack("n",$bin));
//$x = (unpack("Clen1/Clen2/Clen3",$bin));
//$ret = 0;
//echo "\r\n";
//$ret|=$x['len1']<<16;
//$ret|=$x['len2']<<8;
//$ret|=$x['len3']<<0;
//echo $ret;

//在使用的时候一定要注意：1字节序 2要知道要用多少字节来存储【打包】数据
//取出来的时候就很灵活,unpack,ord
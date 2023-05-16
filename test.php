<?php


//echo 0x80|126;
const OPCODE_PING=0X09;
const OPCODE_PONG=0X0A;


//137(十进制) = 10001001

//$data = chr(0x80|0X09).chr(0x00);
$data =  chr(0x80|0X09).chr(0x00);

$bin = unpack("CfirstByte/CsecondByte",$data);
$firstByte = $bin['firstByte'];
$secondByte = $bin['secondByte'];

echo $firstByte;

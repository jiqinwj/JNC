<?php

namespace JNC\Protocols;

interface Protocol
{
    public function Len($data);

    public function encode($data = '');

    public function decode($data = '');

    public function msgLen($data = '');
}
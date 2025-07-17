<?php

namespace nova\plugin\redis;

use nova\framework\core\ConfigObject;

class RedisConfig extends ConfigObject
{
    public string $host = "";
    public int $port = 6379;
    public string $password = "";
    public int $timeout = 0;
}
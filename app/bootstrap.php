<?php

$projectRoot = dirname(__DIR__);
require $projectRoot . '/config.php';

$localConfig = $projectRoot . '/config.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}


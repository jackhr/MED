<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Auth.php';

Auth::startSession();
Auth::requireAuthForPage('login.php');

phpinfo();

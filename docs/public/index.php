<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

(new Dscs\Docs\App(dirname(__DIR__)))->handle($_SERVER['REQUEST_URI'] ?? '/');

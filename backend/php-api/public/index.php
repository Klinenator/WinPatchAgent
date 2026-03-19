<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use PatchAgent\Api\App;

$app = new App();
$app->run();

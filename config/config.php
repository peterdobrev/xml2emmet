<?php
declare(strict_types=1);
$env = [];
foreach ($_ENV as $k => $v) $env[$k] = (string)$v;
foreach (['XML2EMMET_DB_HOST','XML2EMMET_DB_PORT','XML2EMMET_DB_NAME','XML2EMMET_DB_USER','XML2EMMET_DB_PASS','XML2EMMET_SESSION_NAME','XML2EMMET_SECURE_COOKIE','XML2EMMET_DEBUG'] as $k) {
    $g = getenv($k);
    if ($g !== false && !isset($env[$k])) $env[$k] = $g;
}
return \App\Config::fromEnv($env);

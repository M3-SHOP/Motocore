<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/funcoes.php';
require_once __DIR__ . '/includes/google_config.php';

if (autenticado()) {
    redirecionar('index.php');
}

$client = clienteGoogle();

$estado = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $estado;

$client->setState($estado);

header('Location: ' . $client->createAuthUrl());
exit;
<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Escapa texto antes de o apresentar em HTML.
 */
function e(?string $valor): string
{
    return htmlspecialchars(
        $valor ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Verifica se existe um utilizador autenticado.
 */
function autenticado(): bool
{
    return isset($_SESSION['utilizador_id']);
}

/**
 * Protege formulários contra pedidos CSRF.
 */
function tokenCsrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validarCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !is_string($_SESSION['csrf_token'] ?? null) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Pedido inválido. Atualiza a página e tenta novamente.');
    }
}

/**
 * Redireciona para uma página do site.
 */
function redirecionar(string $pagina): never
{
    header('Location: ' . $pagina);
    exit;
}
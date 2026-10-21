<?php
require_once __DIR__ . '/funcoes.php';

$tituloPagina = $tituloPagina ?? 'MotoMarket';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
          content="Compra e vende motas em Portugal no MotoMarket.">
    <title><?= e($tituloPagina) ?> | MotoMarket</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="topo">
    <div class="container barra">
        <a class="logo" href="index.php">Moto<span>Market</span></a>

        <form class="pesquisa-topo" action="index.php" method="get">
            <input
                type="search"
                name="q"
                placeholder="O que procuras?"
                aria-label="Pesquisar anúncios"
            >
            <button type="submit">Pesquisar</button>
        </form>

        <nav class="navegacao">
            <?php if (autenticado()): ?>
                <a href="publicar.php" class="botao-publicar">
                    + Publicar anúncio
                </a>
                <a href="minha_conta.php">A minha conta</a>
                <form action="logout.php" method="post" class="logout-form">
    <input type="hidden" name="csrf_token"
           value="<?= e(tokenCsrf()) ?>">
    <button type="submit" class="link-botao">Sair</button>
</form>
            <?php else: ?>
                <a href="login.php">Entrar</a>
                <a href="registo.php" class="botao-publicar">
                    Criar conta
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="container conteudo">
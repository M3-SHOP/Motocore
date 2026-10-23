<?php
require_once __DIR__ . '/funcoes.php';

$tituloPagina = $tituloPagina ?? 'MotoCore';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description"
          content="Compra e vende motas em Portugal no MotoCore.">

    <title><?= e($tituloPagina) ?> | MotoCore</title>

    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="topo">
    <div class="container barra">

        <!-- ESQUERDA: MENU -->
        <button
            type="button"
            class="menu-toggle"
            id="menuToggle"
            aria-label="Abrir menu"
            aria-expanded="false"
            aria-controls="menuDropdown"
        >
            <span></span>
            <span></span>
            <span></span>
        </button>

        <!-- CENTRO: LOGÓTIPO -->
        <a class="logo logo-centro" href="index.php">
            Moto<span>Core</span>
        </a>

        <!-- DIREITA: PESQUISA SEM BOTÃO -->
        <form class="pesquisa-topo" action="index.php" method="get">
            <input
                type="search"
                name="q"
                placeholder="Pesquisar motas..."
                aria-label="Pesquisar anúncios"
                value="<?= isset($_GET['q']) && is_string($_GET['q'])
                    ? e($_GET['q'])
                    : '' ?>"
            >
        </form>

        <!-- MENU DE NAVEGAÇÃO -->
        <nav class="menu-dropdown" id="menuDropdown" hidden>

            <a href="index.php">Início</a>
            <a href="comparar.php">Comparar motas</a>

            <?php if (autenticado()): ?>

                <a href="publicar.php">+ Publicar anúncio</a>
                <a href="minha_conta.php">Os meus anúncios</a>
                <a href="conversas.php" class="link-mensagens">
                    Mensagens
                    <span id="contador-mensagens" hidden>0</span>
                </a>
                <a href="minha_conta.php">A minha conta</a>

                <div class="menu-separador"></div>

                <form action="logout.php" method="post" class="menu-logout">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e(tokenCsrf()) ?>"
                    >

                    <button type="submit" class="link-botao">
                        Terminar sessão
                    </button>
                </form>

            <?php else: ?>

                <a href="login.php">Entrar</a>
                <a href="registo.php">Criar conta</a>

            <?php endif; ?>

        </nav>
    </div>
</header>

<style>
/* CABEÇALHO MOTocore */

.topo {
    background: var(--branco);
    border-bottom: 1px solid var(--borda);
    position: sticky;
    top: 0;
    z-index: 100;
}

.barra {
    position: relative;
    min-height: 78px;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 20px;
}

/* MENU À ESQUERDA */

.menu-toggle {
    justify-self: start;
    width: 44px;
    height: 44px;
    padding: 10px;

    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 6px;

    background: var(--branco);
    border: 1px solid var(--borda);
    border-radius: 8px;
    cursor: pointer;
}

.menu-toggle:hover,
.menu-toggle:focus-visible {
    border-color: var(--cor-principal);
    outline: none;
}

.menu-toggle span {
    display: block;
    width: 100%;
    height: 3px;
    border-radius: 3px;
    background: var(--texto);
    transition: transform .2s, opacity .2s;
}

.menu-toggle.ativo span:first-child {
    transform: translateY(9px) rotate(45deg);
}

.menu-toggle.ativo span:nth-child(2) {
    opacity: 0;
}

.menu-toggle.ativo span:last-child {
    transform: translateY(-9px) rotate(-45deg);
}

/* LOGÓTIPO AO CENTRO */

.logo-centro {
    grid-column: 2;
    justify-self: center;
    font-size: 25px;
    font-weight: 800;
    white-space: nowrap;
}

.logo-centro span {
    color: var(--cor-principal);
}

/* PESQUISA À DIREITA */

.pesquisa-topo {
    grid-column: 3;
    justify-self: end;
    width: 100%;
    max-width: 300px;
    margin: 0;
}

.pesquisa-topo input {
    width: 100%;
    min-width: 0;
    padding: 12px 14px;

    color: var(--texto);
    background: var(--fundo);
    border: 1px solid var(--borda);
    border-radius: 8px;

    outline: none;
}

.pesquisa-topo input::placeholder {
    color: var(--texto-secundario);
}

.pesquisa-topo input:focus {
    border-color: var(--cor-principal);
}

/* MENU ABERTO */

.menu-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;

    z-index: 101;
    width: 270px;
    max-width: calc(100vw - 24px);
    padding: 8px;

    background: var(--branco);
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    box-shadow: 0 12px 30px #00000040;
}

.menu-dropdown[hidden] {
    display: none;
}

.menu-dropdown a,
.menu-dropdown .link-botao {
    display: block;
    width: 100%;
    padding: 12px 14px;

    color: var(--texto);
    background: transparent;
    border: 0;
    border-radius: 8px;

    text-align: left;
    text-decoration: none;
    font: inherit;
    font-size: 14px;
    cursor: pointer;
}

.menu-dropdown a:hover,
.menu-dropdown .link-botao:hover {
    color: var(--cor-principal);
    background: var(--fundo);
}

.menu-dropdown .menu-logout {
    margin: 0;
}

.menu-separador {
    height: 1px;
    margin: 6px 8px;
    background: var(--borda);
}

/* TABLETS E TELEMÓVEIS */

@media (max-width: 600px) {
    .barra {
        min-height: 68px;
        grid-template-columns: 44px 1fr 44px;
        gap: 10px;
        padding-top: 10px;
        padding-bottom: 10px;
    }

    .logo-centro {
        font-size: 21px;
    }

    .pesquisa-topo {
        grid-column: 1 / -1;
        grid-row: 2;
        max-width: none;
    }

    .menu-dropdown {
        top: 58px;
    }
}
</style>

<script>
(() => {
    const toggle = document.getElementById('menuToggle');
    const menu = document.getElementById('menuDropdown');

    if (!toggle || !menu) return;

    function fecharMenu() {
        menu.hidden = true;
        toggle.classList.remove('ativo');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Abrir menu');
    }

    toggle.addEventListener('click', () => {
        const abrir = menu.hidden;

        menu.hidden = !abrir;
        toggle.classList.toggle('ativo', abrir);
        toggle.setAttribute('aria-expanded', String(abrir));
        toggle.setAttribute(
            'aria-label',
            abrir ? 'Fechar menu' : 'Abrir menu'
        );
    });

    document.addEventListener('click', event => {
        if (
            !toggle.contains(event.target) &&
            !menu.contains(event.target)
        ) {
            fecharMenu();
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            fecharMenu();
            toggle.focus();
        }
    });
})();
</script>

<main class="container conteudo">
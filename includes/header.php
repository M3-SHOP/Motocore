
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

        <!-- MENU HAMBÚRGUER -->
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

        <!-- LOGÓTIPO -->
        <a class="logo logo-centro" href="index.php">
            Moto<span>Core</span>
        </a>

        <!-- ATALHOS NA BARRA -->
        <div class="atalhos-topo">
            <?php if (autenticado()): ?>

                <a
                    href="conversas.php"
                    class="atalho-mensagens"
                    aria-label="Mensagens"
                    title="Mensagens"
                >
                    <svg viewBox="0 0 24 24"
                         width="23" height="23"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="1.8"
                         stroke-linecap="round"
                         stroke-linejoin="round"
                         aria-hidden="true">
                        <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8
                                 8.5 8.5 0 0 1-7.6 4.7
                                 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7
                                 a8.4 8.4 0 0 1-.9-3.8
                                 8.5 8.5 0 0 1 4.7-7.6
                                 8.4 8.4 0 0 1 3.8-.9h.5
                                 a8.5 8.5 0 0 1 8 8v.5Z"/>
                    </svg>

                    <span id="contador-mensagens"
                          class="contador-mensagens"
                          hidden>0</span>
                </a>

                <a href="publicar.php" class="atalho-publicar">
                    <span class="icone-mais" aria-hidden="true">+</span>
                    <span class="texto-publicar">Publicar anúncio</span>
                </a>

            <?php else: ?>

                <a href="login.php" class="atalho-entrar">
                    Entrar
                </a>

                <a href="registo.php" class="atalho-publicar">
                    <span class="icone-mais" aria-hidden="true">+</span>
                    <span class="texto-publicar">Criar conta</span>
                </a>

            <?php endif; ?>
        </div>

        <!-- PESQUISA -->
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

        <!-- MENU HAMBÚRGUER -->
        <nav class="menu-dropdown" id="menuDropdown" hidden>
            <a href="index.php">Início</a>
            <a href="comparar.php">Comparar motas</a>

            <?php if (autenticado()): ?>

                <a href="minha_conta.php">Os meus anúncios</a>
                <a href="minha_conta.php">A minha conta</a>

                <div class="menu-separador"></div>

                <form action="logout.php" method="post"
                      class="menu-logout">
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
/* CABEÇALHO */
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
    grid-template-columns: 44px 1fr auto;
    align-items: center;
    gap: 18px;
}

/* MENU HAMBÚRGUER */
.menu-toggle {
    grid-column: 1;
    grid-row: 1;
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

/* LOGÓTIPO */
.logo-centro {
    grid-column: 2;
    grid-row: 1;
    justify-self: center;
    font-size: 25px;
    font-weight: 800;
    white-space: nowrap;
}

.logo-centro span {
    color: var(--cor-principal);
}

/* ATALHOS */
.atalhos-topo {
    grid-column: 3;
    grid-row: 1;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
}

.atalho-mensagens {
    position: relative;
    width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--texto);
    background: var(--fundo);
    border: 1px solid var(--borda);
    border-radius: 10px;
    text-decoration: none;
    transition: .2s;
}

.atalho-mensagens:hover {
    color: var(--cor-principal);
    border-color: var(--cor-principal);
}

.contador-mensagens:not([hidden]) {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 19px;
    height: 19px;
    padding: 0 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    background: #e53935;
    border: 2px solid var(--branco);
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.atalho-publicar {
    min-height: 44px;
    padding: 0 15px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    color: #fff;
    background: var(--cor-principal);
    border: 1px solid var(--cor-principal);
    border-radius: 10px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 700;
    white-space: nowrap;
    transition: opacity .2s, transform .2s;
}

.atalho-publicar:hover {
    opacity: .9;
    transform: translateY(-1px);
}

.icone-mais {
    font-size: 23px;
    line-height: 1;
}

.atalho-entrar {
    color: var(--texto);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}

/* PESQUISA */
.pesquisa-topo {
    grid-column: 1 / -1;
    grid-row: 2;
    width: 100%;
    max-width: 420px;
    justify-self: center;
    margin: 0 0 12px;
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

.pesquisa-topo input:focus {
    border-color: var(--cor-principal);
}

/* MENU ABERTO */
.menu-dropdown {
    position: absolute;
    top: 70px;
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

/* COMPUTADOR */
@media (min-width: 601px) {
    .barra {
        grid-template-columns: 44px auto minmax(180px, 1fr) auto;
        gap: 18px;
    }

    .menu-toggle {
        grid-column: 1;
    }

    .logo-centro {
        grid-column: 2;
        justify-self: start;
    }

    .atalhos-topo {
        grid-column: 4;
    }

    .pesquisa-topo {
        grid-column: 3;
        grid-row: 1;
        max-width: 320px;
        margin: 0;
    }
}

/* TELEMÓVEIS */
@media (max-width: 600px) {
    .barra {
        min-height: 68px;
        grid-template-columns: 38px minmax(0, 1fr) auto;
        gap: 8px;
        padding-top: 10px;
        padding-bottom: 10px;
    }

    .menu-toggle {
        width: 38px;
        height: 40px;
        padding: 8px;
    }

    .logo-centro {
        font-size: 20px;
        justify-self: start;
    }

    .atalhos-topo {
        gap: 6px;
    }

    .atalho-mensagens {
        width: 38px;
        height: 40px;
    }

    .atalho-publicar {
        width: 40px;
        min-height: 40px;
        padding: 0;
        gap: 0;
    }

    .icone-mais {
        font-size: 26px;
    }

    .texto-publicar {
        display: none;
    }

    .pesquisa-topo {
        grid-column: 1 / -1;
        grid-row: 2;
        max-width: none;
        margin: 0;
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

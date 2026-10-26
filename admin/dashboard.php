<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/funcoes.php';

// Verificar se existe sessão iniciada
if (!autenticado()) {
    redirecionar('../login.php');
}

// Confirmar permissões na base de dados
$stmt = $pdo->prepare(
    'SELECT nome, papel, estado
     FROM utilizadores
     WHERE id = ?
     LIMIT 1'
);

$stmt->execute([(int) $_SESSION['utilizador_id']]);
$admin = $stmt->fetch();

if (
    !$admin ||
    $admin['papel'] !== 'admin' ||
    $admin['estado'] !== 'ativo'
) {
    http_response_code(403);
    exit('Acesso reservado a administradores.');
}

// Estatísticas
$anuncios = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM anuncios
     WHERE moderacao = 'publicado'
       AND estado_anuncio = 'ativo'"
)->fetchColumn();

$denuncias = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM denuncias
     WHERE estado IN ('pendente', 'em_analise')"
)->fetchColumn();

$empresas = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM utilizadores
     WHERE tipo_conta = 'stand'
       AND stand_aprovado = 0
       AND estado = 'pendente'"
)->fetchColumn();

$utilizadores = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM utilizadores
     WHERE estado = 'ativo'"
)->fetchColumn();

function eAdmin(string $valor): string
{
    return htmlspecialchars(
        $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Dashboard | Motocore</title>

<style>
:root {
    --cor-principal: #2589ff;
    --cor-principal-hover: #0870e8;

    --texto: #f1f5f9;
    --texto-secundario: #9ca3af;

    --fundo: #0b0f14;
    --branco: #151b23;
    --borda: #283241;

    --raio: 12px;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: var(--fundo);
    color: var(--texto);
    line-height: 1.5;
}

a {
    color: inherit;
    text-decoration: none;
}

.layout {
    min-height: 100vh;
}

/* BARRA LATERAL */

.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 20;
    display: flex;
    flex-direction: column;
    width: 255px;
    padding: 26px 18px;
    background: #10151c;
    border-right: 1px solid var(--borda);
}

.brand {
    display: flex;
    align-items: center;
    gap: 2px;
    margin: 0 0 36px 8px;
    font-size: 25px;
    font-weight: 800;
    letter-spacing: -.8px;
}

.brand span {
    color: var(--cor-principal);
}

.admin-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
    margin: -22px 0 28px 8px;
    padding: 5px 9px;
    border: 1px solid #2589ff35;
    border-radius: 6px;
    background: #2589ff12;
    color: #60aaff;
    font-size: 11px;
    font-weight: 700;
}

.label {
    margin: 0 10px 12px;
    color: #7c8797;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.3px;
    text-transform: uppercase;
}

.nav {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 46px;
    margin: 4px 0;
    padding: 12px;
    border: 1px solid transparent;
    border-radius: 8px;
    color: #aeb8c6;
    font-size: 14px;
    transition: background .2s, color .2s, border-color .2s;
}

.nav-icon {
    display: grid;
    place-items: center;
    width: 23px;
    flex-shrink: 0;
    color: #91a0b2;
    font-size: 17px;
}

.nav:hover {
    background: #151e29;
    color: white;
}

.nav:hover .nav-icon {
    color: #60aaff;
}

.nav.active {
    background: #172d48;
    border-color: #2589ff35;
    color: #83bdff;
}

.nav.active .nav-icon {
    color: #60aaff;
}

.sidebar-bottom {
    margin-top: auto;
    padding: 20px 8px 0;
    border-top: 1px solid var(--borda);
}

.admin-perfil {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.admin-avatar {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    border: 1px solid #2589ff40;
    border-radius: 50%;
    background: #172d48;
    color: #83bdff;
    font-size: 17px;
    font-weight: 800;
}

.admin-info {
    min-width: 0;
}

.admin-info strong {
    display: block;
    overflow-wrap: anywhere;
    font-size: 13px;
}

.admin-info small {
    display: block;
    margin-top: 3px;
    color: var(--texto-secundario);
    font-size: 12px;
}

.voltar-site {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-top: 22px;
    padding: 11px;
    border-radius: 8px;
    color: #9ca3af;
    font-size: 13px;
    transition: background .2s, color .2s;
}

.voltar-site:hover {
    background: #1b2635;
    color: #60aaff;
}

/* CONTEÚDO PRINCIPAL */

.main {
    min-width: 0;
    min-height: 100vh;
    margin-left: 255px;
}

.topbar {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-height: 70px;
    padding: 16px 34px;
    background: #10151ceF;
    border-bottom: 1px solid var(--borda);
    backdrop-filter: blur(12px);
}

.caminho {
    color: #7c8797;
    font-size: 13px;
}

.caminho span {
    margin: 0 7px;
    color: #465365;
}

.caminho strong {
    color: #e2e8f0;
    font-weight: 600;
}

.estado-admin {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #9ca3af;
    font-size: 12px;
    white-space: nowrap;
}

.estado-admin::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #4ade80;
    box-shadow: 0 0 10px #4ade8040;
}

.content {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    padding: 34px;
}

/* TÍTULO */

.cabecalho-dashboard {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
}

.eyebrow {
    color: #60aaff;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.7px;
    text-transform: uppercase;
}

h1 {
    margin: 10px 0;
    font-size: clamp(27px, 3vw, 36px);
    line-height: 1.2;
    letter-spacing: -1px;
}

.subtitle {
    max-width: 650px;
    margin: 0;
    color: var(--texto-secundario);
    font-size: 14px;
    line-height: 1.8;
}

.admin-tag {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 12px;
    border: 1px solid #283241;
    border-radius: 8px;
    background: #151b23;
    color: #cbd5e1;
    font-size: 12px;
    white-space: nowrap;
}

/* ESTATÍSTICAS */

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 17px;
    margin: 32px 0 40px;
}

.card {
    position: relative;
    min-width: 0;
    padding: 22px;
    overflow: hidden;
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    background: var(--branco);
    transition: transform .2s, border-color .2s, box-shadow .2s;
}

.card:hover {
    transform: translateY(-3px);
    border-color: #2589ff70;
    box-shadow: 0 10px 28px #00000025;
}

.card-topo {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.card-label {
    color: var(--texto-secundario);
    font-size: 13px;
    line-height: 1.5;
}

.card-icon {
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    border: 1px solid #2589ff30;
    border-radius: 10px;
    background: #2589ff14;
    color: #60aaff;
    font-size: 20px;
}

.card-icon.alerta-icon {
    border-color: #f59e0b30;
    background: #f59e0b12;
    color: #fbbf24;
}

.card-icon.empresa-icon {
    border-color: #a78bfa30;
    background: #a78bfa12;
    color: #c4b5fd;
}

.card-icon.utilizador-icon {
    border-color: #34d39930;
    background: #34d39912;
    color: #6ee7b7;
}

.number {
    margin: 18px 0 5px;
    color: #f8fafc;
    font-size: clamp(29px, 3vw, 36px);
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -.8px;
    overflow-wrap: anywhere;
}

.card small {
    color: #7c8797;
    font-size: 12px;
}

/* SECÇÕES */

.section-title {
    margin: 0 0 6px;
    font-size: 21px;
    letter-spacing: -.4px;
}

.section-subtitle {
    margin: 0;
    color: var(--texto-secundario);
    font-size: 13px;
    line-height: 1.7;
}

.section-header {
    margin-bottom: 20px;
}

/* ATALHOS DE GESTÃO */

.shortcuts {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.shortcut {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    min-width: 0;
    padding: 22px;
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    background: var(--branco);
    transition: transform .2s, border-color .2s, background .2s;
}

.shortcut:hover {
    transform: translateY(-2px);
    border-color: #2589ff80;
    background: #18212d;
}

.shortcut-icon {
    display: grid;
    place-items: center;
    width: 45px;
    height: 45px;
    flex-shrink: 0;
    border: 1px solid #2589ff30;
    border-radius: 10px;
    background: #2589ff14;
    color: #60aaff;
    font-size: 21px;
}

.shortcut-corpo {
    flex: 1;
    min-width: 0;
}

.shortcut strong {
    display: block;
    margin: 1px 0 8px;
    font-size: 15px;
    line-height: 1.5;
}

.shortcut p {
    margin: 0;
    color: var(--texto-secundario);
    font-size: 13px;
    line-height: 1.8;
}

.shortcut-seta {
    flex-shrink: 0;
    margin-top: 3px;
    color: #64748b;
    font-size: 18px;
    transition: transform .2s, color .2s;
}

.shortcut:hover .shortcut-seta {
    transform: translateX(3px);
    color: #60aaff;
}

/* RODAPÉ */

footer {
    margin-top: 42px;
    padding-top: 20px;
    border-top: 1px solid var(--borda);
    color: #687587;
    font-size: 12px;
    text-align: center;
}

/* TABLETS */

@media (max-width: 1150px) {
    .sidebar {
        width: 225px;
    }

    .main {
        margin-left: 225px;
    }

    .content {
        padding: 28px 22px;
    }

    .topbar {
        padding-inline: 22px;
    }

    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .card {
        padding: 19px;
    }
}

/* TELEMÓVEIS */

@media (max-width: 700px) {
    .sidebar {
        position: relative;
        inset: auto;
        width: 100%;
        padding: 18px 14px;
        border-right: 0;
        border-bottom: 1px solid var(--borda);
    }

    .brand {
        margin: 0 0 12px 4px;
        font-size: 23px;
    }

    .admin-badge {
        margin: 0 0 17px 4px;
    }

    .label {
        margin: 0 4px 7px;
    }

    .sidebar nav {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 5px;
    }

    .nav {
        min-height: 42px;
        gap: 8px;
        margin: 0;
        padding: 10px;
        font-size: 12px;
    }

    .nav-icon {
        width: 20px;
        font-size: 15px;
    }

    .sidebar-bottom {
        margin-top: 15px;
        padding: 14px 4px 0;
    }

    .admin-avatar {
        width: 36px;
        height: 36px;
    }

    .voltar-site {
        margin-top: 12px;
        padding: 9px;
    }

    .main {
        margin-left: 0;
    }

    .topbar {
        position: relative;
        min-height: 54px;
        padding: 13px 16px;
    }

    .caminho {
        font-size: 11px;
    }

    .caminho span {
        margin: 0 4px;
    }

    .estado-admin {
        font-size: 0;
    }

    .estado-admin::before {
        width: 8px;
        height: 8px;
    }

    .content {
        padding: 24px 14px;
    }

    .cabecalho-dashboard {
        flex-direction: column;
        gap: 14px;
    }

    h1 {
        font-size: 28px;
    }

    .subtitle {
        font-size: 13px;
    }

    .admin-tag {
        padding: 7px 10px;
    }

    .stats {
        gap: 10px;
        margin: 24px 0 32px;
    }

    .card {
        padding: 14px;
    }

    .card-topo {
        align-items: flex-start;
        flex-direction: column-reverse;
        gap: 12px;
    }

    .card-icon {
        width: 35px;
        height: 35px;
        border-radius: 9px;
        font-size: 17px;
    }

    .card-label {
        font-size: 12px;
    }

    .number {
        margin: 14px 0 5px;
        font-size: 28px;
    }

    .card small {
        font-size: 11px;
        line-height: 1.6;
    }

    .section-title {
        font-size: 19px;
    }

    .shortcuts {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .shortcut {
        gap: 12px;
        padding: 17px 14px;
    }

    .shortcut-icon {
        width: 39px;
        height: 39px;
        font-size: 18px;
    }

    .shortcut strong {
        font-size: 14px;
    }

    .shortcut p {
        font-size: 12px;
    }

    footer {
        margin-top: 30px;
    }
}

@media (max-width: 360px) {
    .sidebar nav {
        grid-template-columns: 1fr;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .card-topo {
        align-items: center;
        flex-direction: row;
    }

    .number {
        font-size: 31px;
    }
}
</style>
</head>

<body>

<div class="layout">

    <aside class="sidebar">

        <a class="brand" href="dashboard.php">
            MOTO<span>CORE</span>
        </a>

        <div class="admin-badge">
            <span>●</span> PAINEL ADMINISTRATIVO
        </div>

        <div class="label">Principal</div>

        <nav aria-label="Navegação de administração">

            <a class="nav active" href="dashboard.php" aria-current="page">
                <span class="nav-icon">▦</span>
                <span>Dashboard</span>
            </a>

            <a class="nav" href="anuncios.php">
                <span class="nav-icon">🏍</span>
                <span>Anúncios</span>
            </a>

            <a class="nav" href="empresas.php">
                <span class="nav-icon">▤</span>
                <span>Empresas</span>
            </a>

            <a class="nav" href="denuncias.php">
                <span class="nav-icon">⚑</span>
                <span>Denúncias</span>
            </a>

        </nav>

        <div class="sidebar-bottom">

            <div class="admin-perfil">

                <div class="admin-avatar">
                    <?= eAdmin(
                        strtoupper(
                            function_exists('mb_substr')
                                ? mb_substr((string) $admin['nome'], 0, 1, 'UTF-8')
                                : substr((string) $admin['nome'], 0, 1)
                        )
                    ) ?>
                </div>

                <div class="admin-info">
                    <strong><?= eAdmin((string) $admin['nome']) ?></strong>
                    <small>Administrador</small>
                </div>

            </div>

            <a class="voltar-site" href="../index.php">
                <span>←</span>
                <span>Voltar ao site</span>
            </a>

        </div>

    </aside>

    <main class="main">

        <header class="topbar">

            <div class="caminho">
                Motocore
                <span>/</span>
                Administração
                <span>/</span>
                <strong>Dashboard</strong>
            </div>

            <div class="estado-admin">
                Sessão de administrador ativa
            </div>

        </header>

        <div class="content">

            <section class="cabecalho-dashboard">

                <div>
                    <div class="eyebrow">Centro de controlo</div>

                    <h1>
                        Olá, <?= eAdmin((string) $admin['nome']) ?>!
                    </h1>

                    <p class="subtitle">
                        Gere os anúncios, as empresas e a segurança
                        da Motocore a partir de um único lugar.
                    </p>
                </div>

                <div class="admin-tag">
                    <span>✓</span>
                    Acesso administrativo
                </div>

            </section>

            <section class="stats" aria-label="Estatísticas da plataforma">

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">Anúncios ativos</div>
                        <div class="card-icon">🏍</div>
                    </div>

                    <div class="number">
                        <?= number_format($anuncios, 0, ',', '.') ?>
                    </div>

                    <small>Anúncios publicados na plataforma</small>

                </article>

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">Denúncias pendentes</div>
                        <div class="card-icon alerta-icon">⚑</div>
                    </div>

                    <div class="number">
                        <?= number_format($denuncias, 0, ',', '.') ?>
                    </div>

                    <small>Por analisar ou resolver</small>

                </article>

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">Stands pendentes</div>
                        <div class="card-icon empresa-icon">▤</div>
                    </div>

                    <div class="number">
                        <?= number_format($empresas, 0, ',', '.') ?>
                    </div>

                    <small>A aguardar aprovação</small>

                </article>

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">Utilizadores ativos</div>
                        <div class="card-icon utilizador-icon">♙</div>
                    </div>

                    <div class="number">
                        <?= number_format($utilizadores, 0, ',', '.') ?>
                    </div>

                    <small>Contas ativas na plataforma</small>

                </article>

            </section>

            <section>

                <div class="section-header">
                    <h2 class="section-title">Gestão da plataforma</h2>
                    <p class="section-subtitle">
                        Acede rapidamente às principais ferramentas de administração.
                    </p>
                </div>

                <div class="shortcuts">

                    <a class="shortcut" href="anuncios.php">

                        <div class="shortcut-icon">🏍</div>

                        <div class="shortcut-corpo">
                            <strong>Moderação de anúncios</strong>
                            <p>
                                Consulta os anúncios, acompanha a moderação
                                e gere os conteúdos publicados.
                            </p>
                        </div>

                        <span class="shortcut-seta">→</span>

                    </a>

                    <a class="shortcut" href="empresas.php">

                        <div class="shortcut-icon">▤</div>

                        <div class="shortcut-corpo">
                            <strong>Aprovação de empresas</strong>
                            <p>
                                Analisa os pedidos de registo dos stands
                                e gere as contas profissionais.
                            </p>
                        </div>

                        <span class="shortcut-seta">→</span>

                    </a>

                    <a class="shortcut" href="denuncias.php">

                        <div class="shortcut-icon">⚑</div>

                        <div class="shortcut-corpo">
                            <strong>Gestão de denúncias</strong>
                            <p>
                                Analisa as denúncias e acompanha
                                os casos sinalizados pelos utilizadores.
                            </p>
                        </div>

                        <span class="shortcut-seta">→</span>

                    </a>

                </div>

            </section>

            <footer>
                Motocore · Painel de administração
            </footer>

        </div>

    </main>

</div>

</body>
</html>

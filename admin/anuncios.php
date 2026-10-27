<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/funcoes.php';

// Verificar autenticação.
if (!autenticado()) {
    redirecionar('../login.php');
}

// Confirmar permissões de administrador.
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

$adminId = (int) $_SESSION['utilizador_id'];

// Processar ações de moderação.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $anuncioId = filter_input(
        INPUT_POST,
        'anuncio_id',
        FILTER_VALIDATE_INT
    );

    $acao = $_POST['acao'] ?? '';

    if (!$anuncioId || $anuncioId < 1) {
        redirecionar('anuncios.php?erro=anuncio');
    }

    $acoesPermitidas = [
        'publicar' => 'publicado',
        'remover' => 'removido',
    ];

    if (!is_string($acao) || !isset($acoesPermitidas[$acao])) {
        redirecionar('anuncios.php?erro=acao');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT id, moderacao
             FROM anuncios
             WHERE id = ?
             FOR UPDATE'
        );

        $stmt->execute([$anuncioId]);
        $anuncio = $stmt->fetch();

        if (!$anuncio) {
            $pdo->rollBack();
            redirecionar('anuncios.php?erro=anuncio');
        }

        $estadoAnterior = (string) $anuncio['moderacao'];
        $estadoNovo = $acoesPermitidas[$acao];

        if ($estadoAnterior !== $estadoNovo) {
            $stmt = $pdo->prepare(
                'UPDATE anuncios
                 SET moderacao = ?
                 WHERE id = ?'
            );

            $stmt->execute([$estadoNovo, $anuncioId]);

            $stmt = $pdo->prepare(
                'INSERT INTO historico_anuncios
                 (anuncio_id, utilizador_id, estado_anterior, estado_novo)
                 VALUES (?, ?, ?, ?)'
            );

            $stmt->execute([
                $anuncioId,
                $adminId,
                $estadoAnterior,
                $estadoNovo,
            ]);
        }

        $pdo->commit();

        redirecionar(
            'anuncios.php?sucesso=' .
            ($acao === 'publicar' ? 'publicado' : 'removido')
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Erro na moderação de anúncios: ' . $e->getMessage()
        );

        http_response_code(500);
        exit('Não foi possível atualizar o anúncio.');
    }
}

// Pesquisa e filtros.
$pesquisa = trim((string) ($_GET['q'] ?? ''));
$filtroModeracao = (string) ($_GET['moderacao'] ?? '');
$filtroEstado = (string) ($_GET['estado'] ?? '');

if (!in_array(
    $filtroModeracao,
    ['', 'publicado', 'removido'],
    true
)) {
    $filtroModeracao = '';
}

if (!in_array(
    $filtroEstado,
    ['', 'ativo', 'vendido', 'reservado', 'inativo'],
    true
)) {
    $filtroEstado = '';
}

$where = [];
$parametros = [];

if ($pesquisa !== '') {
    $where[] = '(
        a.titulo LIKE ?
        OR a.marca LIKE ?
        OR a.modelo LIKE ?
        OR u.nome LIKE ?
    )';

    $termo = '%' . $pesquisa . '%';

    array_push(
        $parametros,
        $termo,
        $termo,
        $termo,
        $termo
    );
}

if ($filtroModeracao !== '') {
    $where[] = 'a.moderacao = ?';
    $parametros[] = $filtroModeracao;
}

if ($filtroEstado !== '') {
    $where[] = 'a.estado_anuncio = ?';
    $parametros[] = $filtroEstado;
}

$sqlWhere = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

// Obter anúncios.
$stmt = $pdo->prepare(
    "SELECT
        a.id,
        a.titulo,
        a.marca,
        a.modelo,
        a.ano,
        a.preco,
        a.estado_anuncio,
        a.moderacao,
        a.localidade,
        a.distrito,
        a.criado_em,
        u.nome AS vendedor,
        u.tipo_conta,
        i.caminho AS imagem
     FROM anuncios a
     INNER JOIN utilizadores u
        ON u.id = a.utilizador_id
     LEFT JOIN imagens_anuncios i
        ON i.anuncio_id = a.id
        AND i.principal = 1
     $sqlWhere
     ORDER BY a.criado_em DESC, a.id DESC
     LIMIT 200"
);

$stmt->execute($parametros);
$anuncios = $stmt->fetchAll();

// Estatísticas.
$totalAnuncios = (int) $pdo->query(
    'SELECT COUNT(*) FROM anuncios'
)->fetchColumn();

$totalPublicados = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM anuncios
     WHERE moderacao = 'publicado'
       AND estado_anuncio = 'ativo'"
)->fetchColumn();

$totalRemovidos = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM anuncios
     WHERE moderacao = 'removido'"
)->fetchColumn();

// Escapar valores HTML.
function eAnuncio(string $valor): string
{
    return htmlspecialchars(
        $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$mensagens = [
    'publicado' => 'Anúncio publicado com sucesso.',
    'removido' => 'Anúncio removido da publicação.',
];

$sucesso = $_GET['sucesso'] ?? '';
$erro = $_GET['erro'] ?? '';

?>
HTML
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Anúncios | Motocore</title>

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

button,
input,
select {
    font: inherit;
}

button,
select {
    cursor: pointer;
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
    background: #10151cef;
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
    max-width: 750px;
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
    border: 1px solid var(--borda);
    border-radius: 8px;
    background: var(--branco);
    color: #cbd5e1;
    font-size: 12px;
    white-space: nowrap;
}

/* ESTATÍSTICAS */

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
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

.card-icon.removido-icon {
    border-color: #f8717130;
    background: #f8717112;
    color: #fca5a5;
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

css
/* PAINÉIS */

.panel {
    min-width: 0;
    margin-bottom: 22px;
    padding: 22px;
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    background: var(--branco);
}

.panel-title {
    margin: 0 0 6px;
    font-size: 19px;
    letter-spacing: -.3px;
}

.panel-description {
    margin: 0 0 20px;
    color: var(--texto-secundario);
    font-size: 13px;
    line-height: 1.7;
}

/* FILTROS */

.filters {
    display: grid;
    grid-template-columns:
        minmax(180px, 2fr)
        repeat(2, minmax(130px, 1fr))
        auto;
    gap: 12px;
}

.filters input,
.filters select {
    width: 100%;
    min-width: 0;
    min-height: 44px;
    padding: 11px 13px;
    outline: none;
    border: 1px solid #354154;
    border-radius: 8px;
    background: #0f141b;
    color: var(--texto);
    font-size: 13px;
    transition: border-color .2s, box-shadow .2s;
}

.filters input::placeholder {
    color: #7c8797;
}

.filters input:focus,
.filters select:focus {
    border-color: var(--cor-principal);
    box-shadow: 0 0 0 3px #2589ff20;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 42px;
    padding: 10px 15px;
    border: 1px solid transparent;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    text-align: center;
    transition: background .2s, border-color .2s, color .2s;
}

.btn-primary {
    background: var(--cor-principal);
    border-color: var(--cor-principal);
    color: #fff;
}

.btn-primary:hover {
    background: var(--cor-principal-hover);
    border-color: var(--cor-principal-hover);
}

.btn-secondary {
    background: #1b2635;
    border-color: #354154;
    color: #cbd5e1;
}

.btn-secondary:hover {
    background: #243449;
}

.btn-remove {
    background: #7f1d1d20;
    border-color: #7f1d1d;
    color: #fca5a5;
}

.btn-remove:hover {
    background: #7f1d1d50;
}

.filters .btn {
    min-height: 44px;
}

/* MENSAGENS */

.alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 22px 0;
    padding: 14px 16px;
    border: 1px solid #166534;
    border-radius: 9px;
    background: #14532d33;
    color: #86efac;
    font-size: 13px;
    line-height: 1.7;
}

.alert.error {
    border-color: #991b1b;
    background: #7f1d1d33;
    color: #fca5a5;
}

/* TABELA */

.table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--borda);
    border-radius: 9px;
    scrollbar-color: #354154 #10151c;
}

table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
    background: #121821;
}

thead {
    background: #10151c;
}

th {
    padding: 15px 13px;
    border-bottom: 1px solid var(--borda);
    color: #8794a5;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1px;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}

td {
    padding: 15px 13px;
    border-bottom: 1px solid #283241a8;
    color: #dbe3ed;
    font-size: 12px;
    vertical-align: middle;
}

tbody tr {
    transition: background .2s;
}

tbody tr:hover {
    background: #192331;
}

tbody tr:last-child td {
    border-bottom: 0;
}

.product {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 220px;
}

.product img,
.product-placeholder {
    display: grid;
    place-items: center;
    width: 68px;
    height: 52px;
    flex-shrink: 0;
    overflow: hidden;
    border: 1px solid var(--borda);
    border-radius: 8px;
    background: #0b0f14;
    object-fit: cover;
}

.product-placeholder {
    color: #60aaff;
    font-size: 23px;
}

.product-info {
    min-width: 0;
}

.product-info strong {
    display: block;
    max-width: 260px;
    margin-bottom: 5px;
    color: #f1f5f9;
    font-size: 12px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.muted {
    display: block;
    margin-top: 3px;
    color: #8794a5;
    font-size: 11px;
    line-height: 1.6;
}

.price {
    color: #f8fafc;
    font-weight: 800;
    white-space: nowrap;
}

/* ESTADOS */

.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px 9px;
    border: 1px solid transparent;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 700;
    white-space: nowrap;
}

.badge.publicado,
.badge.ativo {
    border-color: #34d39930;
    background: #34d39912;
    color: #6ee7b7;
}

.badge.removido,
.badge.inativo {
    border-color: #f8717130;
    background: #f8717112;
    color: #fca5a5;
}

.badge.vendido {
    border-color: #60a5fa30;
    background: #60a5fa12;
    color: #93c5fd;
}

.badge.reservado {
    border-color: #fbbf2430;
    background: #fbbf2412;
    color: #fcd34d;
}

/* AÇÕES */

.actions {
    display: flex;
    align-items: center;
    gap: 7px;
}

.actions form {
    margin: 0;
}

.actions .btn {
    min-height: 34px;
    padding: 7px 10px;
    font-size: 11px;
    white-space: nowrap;
}

/* ESTADO VAZIO */

.empty {
    padding: 42px 20px;
    color: var(--texto-secundario);
    text-align: center;
}

.empty-icon {
    display: block;
    margin-bottom: 10px;
    color: #60aaff;
    font-size: 28px;
}

.empty strong {
    display: block;
    margin-bottom: 5px;
    color: #e2e8f0;
    font-size: 14px;
}

.empty p {
    margin: 0;
    font-size: 12px;
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
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .card {
        padding: 19px;
    }

    .filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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
        grid-template-columns: 1fr;
        gap: 10px;
        margin: 24px 0 32px;
    }

    .card {
        padding: 16px;
    }

    .card-topo {
        align-items: center;
    }

    .card-icon {
        width: 37px;
        height: 37px;
        font-size: 17px;
    }

    .number {
        margin: 14px 0 5px;
        font-size: 29px;
    }

    .section-title {
        font-size: 19px;
    }

    .panel {
        padding: 15px;
    }

    .filters {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .filters input,
    .filters select,
    .filters .btn {
        min-height: 44px;
    }

    .table-wrap {
        margin-top: 16px;
    }

    footer {
        margin-top: 30px;
    }
}

@media (max-width: 360px) {
    .sidebar nav {
        grid-template-columns: 1fr;
    }

    .card-topo {
        gap: 8px;
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

            <a class="nav" href="dashboard.php">
                <span class="nav-icon">▦</span>
                <span>Dashboard</span>
            </a>

            <a
                class="nav active"
                href="anuncios.php"
                aria-current="page"
            >
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
                    <?= eAnuncio(
                        strtoupper(
                            function_exists('mb_substr')
                                ? mb_substr(
                                    (string) $admin['nome'],
                                    0,
                                    1,
                                    'UTF-8'
                                )
                                : substr(
                                    (string) $admin['nome'],
                                    0,
                                    1
                                )
                        )
                    ) ?>
                </div>

                <div class="admin-info">
                    <strong>
                        <?= eAnuncio((string) $admin['nome']) ?>
                    </strong>
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
                <strong>Anúncios</strong>
            </div>

            <div class="estado-admin">
                Sessão de administrador ativa
            </div>

        </header>

        <div class="content">

            <section class="cabecalho-dashboard">

                <div>
                    <div class="eyebrow">
                        Moderação da plataforma
                    </div>

                    <h1>Gestão de anúncios</h1>

                    <p class="subtitle">
                        Pesquisa, consulta e modera os anúncios da
                        Motocore. Gere os conteúdos publicados e
                        acompanha o estado de cada anúncio.
                    </p>
                </div>

                <div class="admin-tag">
                    <span>✓</span>
                    Acesso administrativo
                </div>

            </section>

            <?php if (isset($mensagens[$sucesso])): ?>

                <div class="alert" role="status">
                    <span>✓</span>
                    <span>
                        <?= eAnuncio($mensagens[$sucesso]) ?>
                    </span>
                </div>

            <?php elseif ($erro !== ''): ?>

                <div class="alert error" role="alert">
                    <span>⚠</span>
                    <span>
                        Não foi possível executar a ação.
                        Confirma os dados e tenta novamente.
                    </span>
                </div>

            <?php endif; ?>

            <section
                class="stats"
                aria-label="Estatísticas de anúncios"
            >

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">
                            Total de anúncios
                        </div>

                        <div class="card-icon">
                            🏍
                        </div>
                    </div>

                    <div class="number">
                        <?= number_format(
                            $totalAnuncios,
                            0,
                            ',',
                            '.'
                        ) ?>
                    </div>

                    <small>
                        Todos os anúncios registados
                    </small>

                </article>

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">
                            Anúncios publicados e ativos
                        </div>

                        <div class="card-icon">
                            ✓
                        </div>
                    </div>

                    <div class="number">
                        <?= number_format(
                            $totalPublicados,
                            0,
                            ',',
                            '.'
                        ) ?>
                    </div>

                    <small>
                        Publicados e disponíveis
                    </small>

                </article>

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">
                            Anúncios removidos
                        </div>

                        <div class="card-icon removido-icon">
                            ⚑
                        </div>
                    </div>

                    <div class="number">
                        <?= number_format(
                            $totalRemovidos,
                            0,
                            ',',
                            '.'
                        ) ?>
                    </div>

                    <small>
                        Removidos da publicação
                    </small>

                </article>

            </section>

            <section class="panel">

                <div class="section-header">
                    <h2 class="section-title">
                        Pesquisar e filtrar
                    </h2>

                    <p class="section-subtitle">
                        Encontra anúncios por título, marca,
                        modelo ou vendedor.
                    </p>
                </div>

                <form
                    class="filters"
                    method="get"
                    action="anuncios.php"
                >

                    <input
                        type="search"
                        name="q"
                        placeholder="Título, marca, modelo ou vendedor"
                        aria-label="Pesquisar anúncios"
                        value="<?= eAnuncio($pesquisa) ?>"
                    >

                    <select
                        name="moderacao"
                        aria-label="Filtrar por moderação"
                    >
                        <option value="">
                            Toda a moderação
                        </option>

                        <option
                            value="publicado"
                            <?= $filtroModeracao === 'publicado'
                                ? 'selected'
                                : '' ?>
                        >
                            Publicado
                        </option>

                        <option
                            value="removido"
                            <?= $filtroModeracao === 'removido'
                                ? 'selected'
                                : '' ?>
                        >
                            Removido
                        </option>
                    </select>

                    <select
                        name="estado"
                        aria-label="Filtrar por estado"
                    >
                        <option value="">
                            Todos os estados
                        </option>

                        <?php foreach (
                            ['ativo', 'vendido', 'reservado', 'inativo']
                            as $estado
                        ): ?>

                            <option
                                value="<?= eAnuncio($estado) ?>"
                                <?= $filtroEstado === $estado
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= eAnuncio(ucfirst($estado)) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <button class="btn btn-primary" type="submit">
                        <span>⌕</span>
                        Pesquisar
                    </button>

                </form>

                <?php if (
                    $pesquisa !== '' ||
                    $filtroModeracao !== '' ||
                    $filtroEstado !== ''
                ): ?>

                    <div style="margin-top: 15px;">
                        <a
                            class="btn btn-secondary"
                            href="anuncios.php"
                        >
                            Limpar filtros
                        </a>
                    </div>

                <?php endif; ?>

            </section>

            <section class="panel">

                <div class="section-header">
                    <h2 class="section-title">
                        Lista de anúncios
                    </h2>

                    <p class="section-subtitle">
                        Consulta os detalhes e gere a publicação
                        dos anúncios.
                    </p>
                </div>

                <p class="muted" style="margin-bottom: 18px;">
                    São apresentados até 200 resultados,
                    do mais recente para o mais antigo.
                </p>

                <div class="table-wrap">

                    <table>

                        <thead>
                            <tr>
                                <th>Anúncio</th>
                                <th>Vendedor</th>
                                <th>Preço</th>
                                <th>Estado</th>
                                <th>Moderação</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if (!$anuncios): ?>

                            <tr>
                                <td colspan="7">
                                    <div class="empty">
                                        <span class="empty-icon">
                                            🏍
                                        </span>

                                        <strong>
                                            Não foram encontrados anúncios
                                        </strong>

                                        <p>
                                            Experimenta alterar os filtros
                                            ou os termos de pesquisa.
                                        </p>
                                    </div>
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($anuncios as $anuncio): ?>

                                <tr>

                                    <td>
                                        <div class="product">

                                            <?php if (
                                                !empty($anuncio['imagem'])
                                            ): ?>

                                                <img
                                                    src="../<?= eAnuncio(
                                                        (string) $anuncio['imagem']
                                                    ) ?>"
                                                    alt=""
                                                    loading="lazy"
                                                >

                                            <?php else: ?>

                                                <div
                                                    class="product-placeholder"
                                                    aria-hidden="true"
                                                >
                                                    🏍
                                                </div>

                                            <?php endif; ?>

                                            <div class="product-info">

                                                <strong>
                                                    <?= eAnuncio(
                                                        (string) $anuncio['titulo']
                                                    ) ?>
                                                </strong>

                                                <span class="muted">
                                                    <?= eAnuncio(
                                                        (string) $anuncio['marca']
                                                    ) ?>
                                                    <?= eAnuncio(
                                                        (string) $anuncio['modelo']
                                                    ) ?>
                                                    ·
                                                    <?= (int) $anuncio['ano'] ?>
                                                </span>

                                                <span class="muted">
                                                    <?= eAnuncio(
                                                        (string) $anuncio['localidade']
                                                    ) ?>,
                                                    <?= eAnuncio(
                                                        (string) $anuncio['distrito']
                                                    ) ?>
                                                </span>

                                            </div>

                                        </div>
                                    </td>

                                    <td>
                                        <?= eAnuncio(
                                            (string) $anuncio['vendedor']
                                        ) ?>

                                        <span class="muted">
                                            <?= eAnuncio(
                                                (string) $anuncio['tipo_conta']
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="price">
                                            <?= number_format(
                                                (float) $anuncio['preco'],
                                                2,
                                                ',',
                                                '.'
                                            ) ?> €
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge <?= eAnuncio(
                                            (string) $anuncio['estado_anuncio']
                                        ) ?>">
                                            <?= eAnuncio(
                                                ucfirst(
                                                    (string) $anuncio['estado_anuncio']
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge <?= eAnuncio(
                                            (string) $anuncio['moderacao']
                                        ) ?>">
                                            <?= eAnuncio(
                                                ucfirst(
                                                    (string) $anuncio['moderacao']
                                                )
                                            ) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?= eAnuncio(
                                            date(
                                                'd/m/Y',
                                                strtotime(
                                                    (string) $anuncio['criado_em']
                                                )
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <div class="actions">

                                            <?php if (
                                                $anuncio['moderacao'] === 'publicado'
                                            ): ?>

                                                <form
                                                    method="post"
                                                    action="anuncios.php"
                                                    onsubmit="return confirm('Tens a certeza de que queres remover este anúncio da publicação?');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= eAnuncio(
                                                            tokenCsrf()
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="anuncio_id"
                                                        value="<?= (int) $anuncio['id'] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="acao"
                                                        value="remover"
                                                    >

                                                    <button
                                                        class="btn btn-remove"
                                                        type="submit"
                                                    >
                                                        Remover
                                                    </button>

                                                </form>

                                            <?php else: ?>

                                                <form
                                                    method="post"
                                                    action="anuncios.php"
                                                    onsubmit="return confirm('Queres voltar a publicar este anúncio?');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= eAnuncio(
                                                            tokenCsrf()
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="anuncio_id"
                                                        value="<?= (int) $anuncio['id'] ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="acao"
                                                        value="publicar"
                                                    >

                                                    <button
                                                        class="btn btn-primary"
                                                        type="submit"
                                                    >
                                                        Publicar
                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                        </div>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

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
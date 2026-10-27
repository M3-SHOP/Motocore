<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/funcoes.php';

if (!autenticado()) {
    redirecionar('../login.php');
}

$stmt = $pdo->prepare(
    "SELECT nome, papel, estado
     FROM utilizadores
     WHERE id = ?
     LIMIT 1"
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
     INNER JOIN utilizadores u ON u.id = a.utilizador_id
     LEFT JOIN imagens_anuncios i
        ON i.anuncio_id = a.id AND i.principal = 1
     $sqlWhere
     ORDER BY a.criado_em DESC, a.id DESC
     LIMIT 200"
);

$stmt->execute($parametros);
$anuncios = $stmt->fetchAll();

$totalAnuncios = (int) $pdo->query(
    'SELECT COUNT(*) FROM anuncios'
)->fetchColumn();

$totalPublicados = (int) $pdo->query(
    "SELECT COUNT(*) FROM anuncios
     WHERE moderacao = 'publicado'
       AND estado_anuncio = 'ativo'"
)->fetchColumn();

$totalRemovidos = (int) $pdo->query(
    "SELECT COUNT(*) FROM anuncios
     WHERE moderacao = 'removido'"
)->fetchColumn();

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

<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Anúncios | Motocore Admin</title>

<style>
:root {
    color-scheme: dark;
    --fundo: #0b0f14;
    --painel: #151b23;
    --borda: #283241;
    --texto: #f1f5f9;
    --muted: #9ca3af;
    --azul: #2589ff;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--fundo);
    color: var(--texto);
    font: 14px/1.6 Arial, sans-serif;
}

a {
    color: inherit;
    text-decoration: none;
}

.layout {
    min-height: 100vh;
}

.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: 235px;
    padding: 25px 17px;
    background: #10151c;
    border-right: 1px solid var(--borda);
}

.brand {
    display: block;
    margin: 0 0 30px 7px;
    font-size: 24px;
    font-weight: 800;
}

.brand span {
    color: var(--azul);
}

.label {
    margin: 0 10px 10px;
    color: #7c8797;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1px;
    text-transform: uppercase;
}

.nav {
    display: block;
    margin: 5px 0;
    padding: 11px 12px;
    border: 1px solid transparent;
    border-radius: 8px;
    color: #aeb8c6;
}

.nav:hover,
.nav.active {
    background: #172d48;
    border-color: #2589ff35;
    color: #83bdff;
}

.sidebar-bottom {
    position: absolute;
    right: 17px;
    bottom: 22px;
    left: 17px;
    padding-top: 16px;
    border-top: 1px solid var(--borda);
    color: var(--muted);
    overflow-wrap: anywhere;
}

.sidebar-bottom strong {
    color: var(--texto);
}

.main {
    margin-left: 235px;
    min-width: 0;
}

.topbar {
    padding: 20px 30px;
    border-bottom: 1px solid var(--borda);
    color: var(--muted);
}

.content {
    max-width: 1500px;
    margin: auto;
    padding: 30px;
}

.eyebrow {
    color: #60aaff;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}

h1 {
    margin: 7px 0;
    font-size: 32px;
    letter-spacing: -1px;
}

.subtitle {
    margin: 0;
    color: var(--muted);
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
    margin: 28px 0;
}

.stat,
.panel {
    min-width: 0;
    padding: 20px;
    background: var(--painel);
    border: 1px solid var(--borda);
    border-radius: 12px;
}

.stat span {
    color: var(--muted);
}

.stat strong {
    display: block;
    margin-top: 8px;
    font-size: 29px;
}

.panel {
    margin-bottom: 22px;
}

h2 {
    margin: 0 0 16px;
    font-size: 19px;
}

.filters {
    display: grid;
    grid-template-columns:
        minmax(180px, 2fr)
        repeat(2, minmax(130px, 1fr))
        auto;
    gap: 10px;
}

input,
select,
button {
    min-height: 42px;
    padding: 10px 12px;
    border: 1px solid #354154;
    border-radius: 7px;
    background: #0f141b;
    color: var(--texto);
    font: inherit;
}

button {
    cursor: pointer;
}

.primary {
    border-color: var(--azul);
    background: var(--azul);
    color: white;
    font-weight: bold;
}

button:hover {
    filter: brightness(1.12);
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th,
td {
    padding: 13px 11px;
    border-bottom: 1px solid var(--borda);
    text-align: left;
    vertical-align: middle;
}

th {
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
}

td {
    font-size: 13px;
}

.product {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 200px;
}

.product img {
    width: 65px;
    height: 48px;
    border-radius: 6px;
    object-fit: cover;
    background: #0b0f14;
}

.product strong {
    display: block;
}

.muted {
    color: var(--muted);
    font-size: 12px;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 5px;
    background: #263244;
    font-size: 11px;
    white-space: nowrap;
}

.badge.publicado,
.badge.ativo {
    color: #6ee7b7;
    background: #064e3b55;
}

.badge.removido,
.badge.inativo {
    color: #fca5a5;
    background: #7f1d1d55;
}

.actions {
    display: flex;
    gap: 7px;
    align-items: center;
}

.actions form {
    margin: 0;
}

.actions button {
    min-height: 34px;
    padding: 6px 9px;
    font-size: 12px;
}

.remove {
    border-color: #7f1d1d;
    color: #fca5a5;
}

.alert {
    margin: 18px 0;
    padding: 12px 15px;
    border: 1px solid #166534;
    border-radius: 8px;
    background: #14532d33;
    color: #86efac;
}

.alert.error {
    border-color: #991b1b;
    background: #7f1d1d33;
    color: #fca5a5;
}

.empty {
    padding: 35px 10px;
    color: var(--muted);
    text-align: center;
}

@media (max-width: 1000px) {
    .sidebar {
        position: static;
        width: 100%;
        padding: 16px;
    }

    .brand {
        margin-bottom: 14px;
    }

    .sidebar nav {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .nav {
        margin: 0;
    }

    .sidebar-bottom {
        position: static;
        margin-top: 12px;
        padding-top: 12px;
    }

    .main {
        margin-left: 0;
    }

    .content {
        padding: 22px 16px;
    }

    .topbar {
        padding: 14px 16px;
    }
}

@media (max-width: 650px) {
    h1 {
        font-size: 27px;
    }

    .stats {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .stat {
        padding: 15px;
    }

    .stat strong {
        font-size: 25px;
    }

    .filters {
        grid-template-columns: 1fr;
    }

    .panel {
        padding: 14px;
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

    <div class="label">Administração</div>

    <nav aria-label="Menu administrativo">
        <a class="nav" href="dashboard.php">▦ &nbsp; Dashboard</a>
        <a class="nav active" href="anuncios.php" aria-current="page">
            🏍 &nbsp; Anúncios
        </a>
        <a class="nav" href="empresas.php">▤ &nbsp; Empresas</a>
        <a class="nav" href="denuncias.php">⚑ &nbsp; Denúncias</a>
    </nav>

    <div class="sidebar-bottom">
        <strong><?= eAnuncio((string) $admin['nome']) ?></strong><br>
        <span>Administrador</span><br><br>
        <a href="../index.php">← Voltar ao site</a>
    </div>
</aside>

<main class="main">
    <header class="topbar">
        Motocore / Administração / <strong>Anúncios</strong>
    </header>

    <div class="content">
        <div class="eyebrow">Moderação da plataforma</div>
        <h1>Gestão de anúncios</h1>
        <p class="subtitle">
            Pesquisa, consulta e modera os anúncios da Motocore.
        </p>

        <?php if (isset($mensagens[$sucesso])): ?>
            <div class="alert" role="status">
                <?= eAnuncio($mensagens[$sucesso]) ?>
            </div>
        <?php elseif ($erro !== ''): ?>
            <div class="alert error" role="alert">
                Não foi possível executar a ação.
                Confirma os dados e tenta novamente.
            </div>
        <?php endif; ?>

        <section class="stats" aria-label="Estatísticas de anúncios">
            <article class="stat">
                <span>Total de anúncios</span>
                <strong><?= number_format($totalAnuncios, 0, ',', '.') ?></strong>
            </article>

            <article class="stat">
                <span>Anúncios publicados e ativos</span>
                <strong><?= number_format($totalPublicados, 0, ',', '.') ?></strong>
            </article>

            <article class="stat">
                <span>Anúncios removidos</span>
                <strong><?= number_format($totalRemovidos, 0, ',', '.') ?></strong>
            </article>
        </section>

        <section class="panel">
            <h2>Pesquisar e filtrar</h2>

            <form class="filters" method="get" action="anuncios.php">
                <input
                    type="search"
                    name="q"
                    placeholder="Título, marca, modelo ou vendedor"
                    value="<?= eAnuncio($pesquisa) ?>"
                >

                <select name="moderacao" aria-label="Filtrar por moderação">
                    <option value="">Toda a moderação</option>
                    <option value="publicado" <?= $filtroModeracao === 'publicado' ? 'selected' : '' ?>>
                        Publicado
                    </option>
                    <option value="removido" <?= $filtroModeracao === 'removido' ? 'selected' : '' ?>>
                        Removido
                    </option>
                </select>

                <select name="estado" aria-label="Filtrar por estado">
                    <option value="">Todos os estados</option>

                    <?php foreach (['ativo', 'vendido', 'reservado', 'inativo'] as $estado): ?>
                        <option
                            value="<?= eAnuncio($estado) ?>"
                            <?= $filtroEstado === $estado ? 'selected' : '' ?>
                        >
                            <?= eAnuncio(ucfirst($estado)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="primary" type="submit">Pesquisar</button>
            </form>
        </section>

        <section class="panel">
            <h2>Lista de anúncios</h2>

            <p class="muted">
                São apresentados até 200 resultados, do mais recente para o mais antigo.
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
                            <td colspan="7" class="empty">
                                Não foram encontrados anúncios.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($anuncios as $anuncio): ?>
                            <tr>
                                <td>
                                    <div class="product">
                                        <?php if (!empty($anuncio['imagem'])): ?>
                                            <img
                                                src="../<?= eAnuncio((string) $anuncio['imagem']) ?>"
                                                alt=""
                                                loading="lazy"
                                            >
                                        <?php else: ?>
                                            <div aria-hidden="true">🏍️</div>
                                        <?php endif; ?>

                                        <div>
                                            <strong>
                                                <?= eAnuncio((string) $anuncio['titulo']) ?>
                                            </strong>

                                            <span class="muted">
                                                <?= eAnuncio((string) $anuncio['marca']) ?>
                                                <?= eAnuncio((string) $anuncio['modelo']) ?>
                                                · <?= (int) $anuncio['ano'] ?>
                                            </span>

                                            <div class="muted">
                                                <?= eAnuncio((string) $anuncio['localidade']) ?>,
                                                <?= eAnuncio((string) $anuncio['distrito']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <?= eAnuncio((string) $anuncio['vendedor']) ?>
                                    <div class="muted">
                                        <?= eAnuncio((string) $anuncio['tipo_conta']) ?>
                                    </div>
                                </td>

                                <td>
                                    <?= number_format((float) $anuncio['preco'], 2, ',', '.') ?> €
                                </td>

                                <td>
                                    <span class="badge <?= eAnuncio((string) $anuncio['estado_anuncio']) ?>">
                                        <?= eAnuncio(ucfirst((string) $anuncio['estado_anuncio'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge <?= eAnuncio((string) $anuncio['moderacao']) ?>">
                                        <?= eAnuncio(ucfirst((string) $anuncio['moderacao'])) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= eAnuncio(date(
                                        'd/m/Y',
                                        strtotime((string) $anuncio['criado_em'])
                                    )) ?>
                                </td>

                                <td>
                                    <div class="actions">
                                        <?php if ($anuncio['moderacao'] === 'publicado'): ?>
                                            <form
                                                method="post"
                                                action="anuncios.php"
                                                onsubmit="return confirm('Tens a certeza de que queres remover este anúncio da publicação?');"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= eAnuncio(tokenCsrf()) ?>"
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

                                                <button class="remove" type="submit">
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
                                                    value="<?= eAnuncio(tokenCsrf()) ?>"
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

                                                <button class="primary" type="submit">
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
    </div>
</main>
</div>
</body>
</html>

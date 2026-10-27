<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/funcoes.php';

if (!autenticado()) {
    redirecionar('../login.php');
}

$stmt = $pdo->prepare(
    'SELECT nome, papel, estado
     FROM utilizadores WHERE id = ? LIMIT 1'
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

function eEmpresa(?string $valor): string
{
    return htmlspecialchars(
        $valor ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $acao = $_POST['acao'] ?? '';
    $id = filter_var(
        $_POST['utilizador_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$id || $id < 1) {
        $erro = 'Empresa inválida.';
    } elseif (!in_array(
        $acao,
        ['aprovar', 'rejeitar', 'suspender', 'reativar'],
        true
    )) {
        $erro = 'Ação inválida.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT id, tipo_conta, papel, estado, stand_aprovado
                 FROM utilizadores WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$id]);
            $empresa = $stmt->fetch();

            if (
                !$empresa ||
                $empresa['tipo_conta'] !== 'stand' ||
                $empresa['papel'] === 'admin'
            ) {
                throw new RuntimeException('Empresa inválida.');
            }

            switch ($acao) {
                case 'aprovar':
                    if (
                        $empresa['estado'] !== 'pendente' ||
                        (int) $empresa['stand_aprovado'] !== 0
                    ) {
                        throw new RuntimeException(
                            'O pedido já não está pendente.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE utilizadores
                         SET estado = 'ativo', stand_aprovado = 1
                         WHERE id = ?"
                    );
                    $stmt->execute([$id]);
                    $mensagem = 'Stand aprovado com sucesso.';
                    break;

                case 'rejeitar':
                    if (
                        $empresa['estado'] !== 'pendente' ||
                        (int) $empresa['stand_aprovado'] !== 0
                    ) {
                        throw new RuntimeException(
                            'Só podes rejeitar pedidos pendentes.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE utilizadores
                         SET estado = 'suspenso'
                         WHERE id = ?"
                    );
                    $stmt->execute([$id]);
                    $mensagem = 'Pedido rejeitado.';
                    break;

                case 'suspender':
                    if (
                        $empresa['estado'] !== 'ativo' ||
                        (int) $empresa['stand_aprovado'] !== 1
                    ) {
                        throw new RuntimeException(
                            'Este stand não pode ser suspenso.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE utilizadores SET estado = 'suspenso'
                         WHERE id = ?"
                    );
                    $stmt->execute([$id]);
                    $mensagem = 'Stand suspenso.';
                    break;

                case 'reativar':
                    if (
                        $empresa['estado'] !== 'suspenso' ||
                        (int) $empresa['stand_aprovado'] !== 1
                    ) {
                        throw new RuntimeException(
                            'Este stand não pode ser reativado.'
                        );
                    }

                    $stmt = $pdo->prepare(
                        "UPDATE utilizadores SET estado = 'ativo'
                         WHERE id = ?"
                    );
                    $stmt->execute([$id]);
                    $mensagem = 'Stand reativado.';
                    break;
            }

            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($ex->getMessage());
            $erro = $ex instanceof RuntimeException
                ? $ex->getMessage()
                : 'Não foi possível concluir a operação.';
        }
    }
}

/*
 * Pesquisa e filtros.
 */
$pesquisa = trim((string) ($_GET['q'] ?? ''));
$filtro = (string) ($_GET['estado'] ?? '');

if (!in_array($filtro, ['pendente', 'ativo', 'suspenso'], true)) {
    $filtro = '';
}

$where = [
    "u.tipo_conta = 'stand'",
    "u.papel <> 'admin'"
];

$parametros = [];

if ($pesquisa !== '') {
    $where[] = '(
        u.nome LIKE ?
        OR u.nome_stand LIKE ?
        OR u.email LIKE ?
        OR u.username LIKE ?
    )';

    $termo = '%' . $pesquisa . '%';

    $parametros = [
        $termo,
        $termo,
        $termo,
        $termo
    ];
}

if ($filtro === 'pendente') {
    $where[] = "u.estado = 'pendente' AND u.stand_aprovado = 0";
} elseif ($filtro === 'ativo') {
    $where[] = "u.estado = 'ativo' AND u.stand_aprovado = 1";
} elseif ($filtro === 'suspenso') {
    $where[] = "u.estado = 'suspenso'";
}

$sqlWhere = implode(' AND ', $where);

/*
 * Estatísticas.
 */
$totalPendentes = (int) $pdo->query(
    "SELECT COUNT(*) FROM utilizadores
     WHERE tipo_conta = 'stand'
       AND papel <> 'admin'
       AND estado = 'pendente'
       AND stand_aprovado = 0"
)->fetchColumn();

$totalAprovados = (int) $pdo->query(
    "SELECT COUNT(*) FROM utilizadores
     WHERE tipo_conta = 'stand'
       AND papel <> 'admin'
       AND estado = 'ativo'
       AND stand_aprovado = 1"
)->fetchColumn();

$totalSuspensos = (int) $pdo->query(
    "SELECT COUNT(*) FROM utilizadores
     WHERE tipo_conta = 'stand'
       AND papel <> 'admin'
       AND estado = 'suspenso'"
)->fetchColumn();

/*
 * Consultar empresas.
 */
$stmt = $pdo->prepare(
    "SELECT
        u.id,
        u.nome,
        u.username,
        u.email,
        u.telefone,
        u.nome_stand,
        u.localidade,
        u.distrito,
        u.estado,
        u.stand_aprovado,
        u.criado_em
     FROM utilizadores u
     WHERE $sqlWhere
     ORDER BY
        CASE
            WHEN u.estado = 'pendente'
             AND u.stand_aprovado = 0 THEN 0
            ELSE 1
        END,
        u.criado_em DESC,
        u.id DESC
     LIMIT 200"
);

$stmt->execute($parametros);
$empresas = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Empresas | Motocore Admin</title>


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

button, input, select, textarea {
    font: inherit;
}

button, .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 15px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--cor-principal);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background .2s, transform .2s;
}

button:hover, .btn:hover {
    background: var(--cor-principal-hover);
}

.btn-secundario {
    background: #1b2635;
    border-color: var(--borda);
    color: #dbe5f0;
}

.btn-secundario:hover {
    background: #253347;
}

.btn-perigo {
    background: #7f1d1d;
    border-color: #991b1b;
}

.btn-perigo:hover {
    background: #991b1b;
}

.btn-sucesso {
    background: #065f46;
    border-color: #047857;
}

.btn-sucesso:hover {
    background: #047857;
}

/* CONTEÚDO */

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
    color: var(--texto-secundario);
    font-size: 12px;
    white-space: nowrap;
}

.estado-admin::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #4ade80;
}

.content {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    padding: 34px;
}

/* TÍTULOS */

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

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin: 30px 0 20px;
}

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

/* ESTATÍSTICAS */

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 17px;
    margin: 30px 0;
}

.card {
    min-width: 0;
    padding: 22px;
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    background: var(--branco);
    transition: border-color .2s, transform .2s;
}

.card:hover {
    transform: translateY(-2px);
    border-color: #2589ff70;
}

.card-label {
    color: var(--texto-secundario);
    font-size: 13px;
}

.number {
    margin: 15px 0 5px;
    font-size: clamp(28px, 3vw, 36px);
    font-weight: 800;
    line-height: 1.2;
}

.card small {
    color: #7c8797;
    font-size: 12px;
}

/* PAINÉIS E FORMULÁRIOS */

.panel {
    min-width: 0;
    margin-bottom: 24px;
    padding: 24px;
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    background: var(--branco);
}

.filtros, .form-row {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 22px;
}

.campo {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 7px;
    min-width: 180px;
}

.campo label {
    color: #cbd5e1;
    font-size: 12px;
    font-weight: 700;
}

input, select, textarea {
    width: 100%;
    min-width: 0;
    padding: 11px 12px;
    border: 1px solid var(--borda);
    border-radius: 8px;
    outline: none;
    background: #0f141b;
    color: var(--texto);
    font-size: 13px;
}

input::placeholder, textarea::placeholder {
    color: #687587;
}

input:focus, select:focus, textarea:focus {
    border-color: var(--cor-principal);
    box-shadow: 0 0 0 3px #2589ff18;
}

select option {
    background: #151b23;
    color: var(--texto);
}

textarea {
    min-height: 100px;
    resize: vertical;
}

/* TABELAS */

.table-wrap {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--borda);
    border-radius: 10px;
}

table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
    font-size: 13px;
}

thead {
    background: #10151c;
}

th {
    padding: 15px 16px;
    color: #9ca3af;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .7px;
    text-transform: uppercase;
    white-space: nowrap;
}

td {
    padding: 15px 16px;
    border-top: 1px solid var(--borda);
    color: #dbe3ed;
    vertical-align: middle;
}

tbody tr {
    transition: background .2s;
}

tbody tr:hover {
    background: #1b2635;
}

td small {
    display: block;
    margin-top: 4px;
    color: #7c8797;
}

.acoes {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    min-width: 150px;
}

/* ESTADOS */

.badge {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border: 1px solid var(--borda);
    border-radius: 6px;
    background: #1b2635;
    color: #cbd5e1;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.badge-ativo, .badge-aprovado, .badge-resolvida {
    border-color: #34d39935;
    background: #34d39912;
    color: #6ee7b7;
}

.badge-pendente, .badge-analise {
    border-color: #f59e0b35;
    background: #f59e0b12;
    color: #fbbf24;
}

.badge-inativo, .badge-rejeitado, .badge-removido {
    border-color: #f8717135;
    background: #f8717112;
    color: #fca5a5;
}

/* MENSAGENS */

.alert {
    margin: 20px 0;
    padding: 14px 16px;
    border: 1px solid var(--borda);
    border-radius: 9px;
    background: var(--branco);
    font-size: 13px;
}

.alert-sucesso {
    border-color: #34d39950;
    background: #34d39910;
    color: #6ee7b7;
}

.alert-erro {
    border-color: #f8717150;
    background: #f8717110;
    color: #fca5a5;
}

.alert-aviso {
    border-color: #f59e0b50;
    background: #f59e0b10;
    color: #fbbf24;
}

.vazio {
    padding: 45px 20px;
    color: var(--texto-secundario);
    text-align: center;
}

.vazio strong {
    display: block;
    margin-bottom: 8px;
    color: var(--texto);
    font-size: 16px;
}

/* PAGINAÇÃO */

.paginacao {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 22px;
}

.paginacao a, .paginacao span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    padding: 9px 12px;
    border: 1px solid var(--borda);
    border-radius: 7px;
    background: var(--branco);
    color: #cbd5e1;
    font-size: 12px;
}

.paginacao .active {
    border-color: var(--cor-principal);
    background: #172d48;
    color: #83bdff;
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
}

/* TELEMÓVEIS */

@media (max-width: 700px) {
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

    .content {
        padding: 24px 14px;
    }

    h1 {
        font-size: 28px;
    }

    .subtitle {
        font-size: 13px;
    }

    .stats {
        gap: 10px;
    }

    .card {
        padding: 15px;
    }

    .panel {
        padding: 16px;
    }

    .section-title {
        font-size: 19px;
    }

    .filtros, .form-row {
        align-items: stretch;
        flex-direction: column;
    }

    .campo {
        min-width: 0;
    }

    .acoes {
        min-width: 130px;
    }

    th, td {
        padding: 12px;
    }

    footer {
        margin-top: 30px;
    }
}

@media (max-width: 360px) {
    .stats {
        grid-template-columns: 1fr;
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

        <div class="label">Principal</div>

        <nav aria-label="Navegação administrativa">
            <a class="nav" href="dashboard.php">▦ Dashboard</a>
            <a class="nav" href="anuncios.php">🏍 Anúncios</a>
            <a class="nav active" href="empresas.php" aria-current="page">▤ Empresas</a>
            <a class="nav" href="denuncias.php">⚑ Denúncias</a>
        </nav>

        <div class="sidebar-bottom">
            <strong><?= eEmpresa((string) $admin['nome']) ?></strong>
            <div>Administrador</div>
            <a class="nav" href="../index.php">← Voltar ao site</a>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            Motocore / Administração / <strong>Empresas</strong>
        </header>

        <div class="content">
            <div class="eyebrow">Centro de controlo</div>
            <h1>Gestão de empresas</h1>
            <p class="subtitle">
                Analisa pedidos de registo, aprova stands e gere as contas profissionais.
            </p>

            <?php if ($mensagem !== ''): ?>
                <div class="alert" role="status">
                    <?= eEmpresa($mensagem) ?>
                </div>
            <?php endif; ?>

            <?php if ($erro !== ''): ?>
                <div class="alert error" role="alert">
                    <?= eEmpresa($erro) ?>
                </div>
            <?php endif; ?>

            <section class="stats" aria-label="Estatísticas das empresas">
                <article class="stat">
                    <span>Pedidos pendentes</span>
                    <strong><?= number_format($totalPendentes, 0, ',', '.') ?></strong>
                </article>

                <article class="stat">
                    <span>Stands aprovados</span>
                    <strong><?= number_format($totalAprovados, 0, ',', '.') ?></strong>
                </article>

                <article class="stat">
                    <span>Stands suspensos</span>
                    <strong><?= number_format($totalSuspensos, 0, ',', '.') ?></strong>
                </article>
            </section>

            <section class="panel">
                <h2>Pesquisar empresas</h2>

                <form class="filters" method="get" action="empresas.php">
                    <input
                        type="search"
                        name="q"
                        value="<?= eEmpresa($pesquisa) ?>"
                        placeholder="Nome, stand, email ou username"
                        aria-label="Pesquisar empresas"
                    >

                    <select name="estado" aria-label="Filtrar por estado">
                        <option value="">Todos os estados</option>
                        <option value="pendente" <?= $filtro === 'pendente' ? 'selected' : '' ?>>
                            Pendentes
                        </option>
                        <option value="ativo" <?= $filtro === 'ativo' ? 'selected' : '' ?>>
                            Aprovados
                        </option>
                        <option value="suspenso" <?= $filtro === 'suspenso' ? 'selected' : '' ?>>
                            Suspensos
                        </option>
                    </select>

                    <button class="primary" type="submit">Pesquisar</button>
                </form>
            </section>

            <section class="panel">
                <h2>Empresas registadas</h2>
                <p class="subtitle" style="margin-bottom:18px">
                    São apresentadas até 200 empresas por pesquisa.
                </p>

                <?php if (!$empresas): ?>
                    <div class="empty">
                        Não foram encontradas empresas com estes critérios.
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Empresa</th>
                                    <th>Contacto</th>
                                    <th>Localização</th>
                                    <th>Estado</th>
                                    <th>Registo</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($empresas as $empresa): ?>
                                    <?php
                                    $estado = (string) $empresa['estado'];

                                    $pendente =
                                        $estado === 'pendente'
                                        && (int) $empresa['stand_aprovado'] === 0;

                                    $aprovado =
                                        $estado === 'ativo'
                                        && (int) $empresa['stand_aprovado'] === 1;

                                    $nomeEmpresa = trim(
                                        (string) ($empresa['nome_stand'] ?? '')
                                    );

                                    if ($nomeEmpresa === '') {
                                        $nomeEmpresa = (string) $empresa['nome'];
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= eEmpresa($nomeEmpresa) ?></strong>
                                            <div class="muted">
                                                <?= eEmpresa((string) ($empresa['username'] ?? 'Sem username')) ?>
                                            </div>
                                            <div class="muted">
                                                ID #<?= (int) $empresa['id'] ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?= eEmpresa((string) ($empresa['email'] ?? 'Sem email')) ?>
                                            <div class="muted">
                                                <?= eEmpresa((string) ($empresa['telefone'] ?? 'Sem telefone')) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?= eEmpresa((string) ($empresa['localidade'] ?? '—')) ?>
                                            <div class="muted">
                                                <?= eEmpresa((string) ($empresa['distrito'] ?? '')) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <?php if ($pendente): ?>
                                                <span class="badge pendente">Pendente</span>
                                            <?php elseif ($aprovado): ?>
                                                <span class="badge ativo">Aprovado</span>
                                            <?php else: ?>
                                                <span class="badge suspenso">
                                                    <?= $estado === 'suspenso' ? 'Suspenso' : eEmpresa($estado) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?= eEmpresa(
                                                date(
                                                    'd/m/Y',
                                                    strtotime((string) $empresa['criado_em'])
                                                )
                                            ) ?>
                                        </td>

                                        <td>
                                            <div class="actions">
                                                <?php if ($pendente): ?>
                                                    <form method="post" action="empresas.php">
                                                        <input type="hidden" name="csrf_token" value="<?= eEmpresa(tokenCsrf()) ?>">
                                                        <input type="hidden" name="utilizador_id" value="<?= (int) $empresa['id'] ?>">
                                                        <input type="hidden" name="acao" value="aprovar">
                                                        <button class="success" type="submit">
                                                            Aprovar
                                                        </button>
                                                    </form>

                                                    <form method="post" action="empresas.php" onsubmit="return confirm('Tens a certeza de que queres rejeitar este pedido?');">
                                                        <input type="hidden" name="csrf_token" value="<?= eEmpresa(tokenCsrf()) ?>">
                                                        <input type="hidden" name="utilizador_id" value="<?= (int) $empresa['id'] ?>">
                                                        <input type="hidden" name="acao" value="rejeitar">
                                                        <button class="danger" type="submit">
                                                            Rejeitar
                                                        </button>
                                                    </form>

                                                <?php elseif ($aprovado): ?>
                                                    <form method="post" action="empresas.php" onsubmit="return confirm('Queres suspender este stand?');">
                                                        <input type="hidden" name="csrf_token" value="<?= eEmpresa(tokenCsrf()) ?>">
                                                        <input type="hidden" name="utilizador_id" value="<?= (int) $empresa['id'] ?>">
                                                        <input type="hidden" name="acao" value="suspender">
                                                        <button class="danger" type="submit">
                                                            Suspender
                                                        </button>
                                                    </form>

                                                <?php elseif (
                                                    $estado === 'suspenso'
                                                    && (int) $empresa['stand_aprovado'] === 1
                                                ): ?>
                                                    <form method="post" action="empresas.php" onsubmit="return confirm('Queres reativar este stand?');">
                                                        <input type="hidden" name="csrf_token" value="<?= eEmpresa(tokenCsrf()) ?>">
                                                        <input type="hidden" name="utilizador_id" value="<?= (int) $empresa['id'] ?>">
                                                        <input type="hidden" name="acao" value="reativar">
                                                        <button class="success" type="submit">
                                                            Reativar
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="muted">Sem ações disponíveis</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <footer class="muted" style="text-align:center;padding:20px">
                Motocore · Painel de administração
            </footer>
        </div>
    </main>
</div>
</body>
</html>

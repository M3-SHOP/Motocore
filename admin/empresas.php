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

// Processar ações administrativas
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
                 FROM utilizadores
                 WHERE id = ?
                 FOR UPDATE'
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
                        "UPDATE utilizadores
                         SET estado = 'suspenso'
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
                        "UPDATE utilizadores
                         SET estado = 'ativo'
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

// Pesquisa e filtros
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

// Estatísticas
$totalPendentes = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM utilizadores
     WHERE tipo_conta = 'stand'
       AND papel <> 'admin'
       AND estado = 'pendente'
       AND stand_aprovado = 0"
)->fetchColumn();

$totalAprovados = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM utilizadores
     WHERE tipo_conta = 'stand'
       AND papel <> 'admin'
       AND estado = 'ativo'
       AND stand_aprovado = 1"
)->fetchColumn();

$totalSuspensos = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM utilizadores
     WHERE tipo_conta = 'stand'
       AND papel <> 'admin'
       AND estado = 'suspenso'"
)->fetchColumn();

// Consultar empresas
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

<title>Empresas | Motocore</title>

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

/* BOTÕES */

button,
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 38px;
    padding: 9px 13px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--cor-principal);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition:
        background .2s,
        border-color .2s,
        transform .2s;
}

button:hover,
.btn:hover {
    background: var(--cor-principal-hover);
}

button:focus-visible,
a:focus-visible,
input:focus-visible,
select:focus-visible {
    outline: 2px solid #60aaff;
    outline-offset: 3px;
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

/* ESTRUTURA */

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
    overflow-y: auto;
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
    color: #fff;
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
    box-shadow: 0 0 10px #4ade8040;
}

.content {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
    padding: 34px;
}

/* CABEÇALHO */

.cabecalho-pagina {
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
    min-width: 0;
    padding: 22px;
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

.card-icon.pendente-icon {
    border-color: #f59e0b30;
    background: #f59e0b12;
    color: #fbbf24;
}

.card-icon.suspenso-icon {
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

.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin: 0 0 20px;
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

/* PAINÉIS */

.panel {
    min-width: 0;
    margin-bottom: 24px;
    padding: 24px;
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    background: var(--branco);
}

/* PESQUISA E FILTROS */

.filtros {
    display: flex;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 14px;
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

input,
select {
    width: 100%;
    min-width: 0;
    min-height: 42px;
    padding: 11px 12px;
    border: 1px solid var(--borda);
    border-radius: 8px;
    outline: none;
    background: #0f141b;
    color: var(--texto);
    font-size: 13px;
}

input::placeholder {
    color: #687587;
}

input:focus,
select:focus {
    border-color: var(--cor-principal);
    box-shadow: 0 0 0 3px #2589ff18;
}

select option {
    background: #151b23;
    color: var(--texto);
}

.filtros-botoes {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

/* TABELA */

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

td strong {
    font-size: 13px;
    overflow-wrap: anywhere;
}

.muted {
    display: block;
    margin-top: 4px;
    color: #7c8797;
    font-size: 12px;
    overflow-wrap: anywhere;
}

.acoes {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    min-width: 150px;
}

.acoes form {
    margin: 0;
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

.badge-ativo {
    border-color: #34d39935;
    background: #34d39912;
    color: #6ee7b7;
}

.badge-pendente {
    border-color: #f59e0b35;
    background: #f59e0b12;
    color: #fbbf24;
}

.badge-suspenso {
    border-color: #f8717135;
    background: #f8717112;
    color: #fca5a5;
}

/* ALERTAS */

.alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 22px 0;
    padding: 14px 16px;
    border: 1px solid var(--borda);
    border-radius: 9px;
    background: var(--branco);
    font-size: 13px;
    line-height: 1.7;
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

.alert-icon {
    flex-shrink: 0;
    font-weight: 800;
}

/* ESTADO VAZIO */

.vazio {
    padding: 45px 20px;
    color: var(--texto-secundario);
    text-align: center;
}

.vazio-icon {
    display: grid;
    place-items: center;
    width: 52px;
    height: 52px;
    margin: 0 auto 15px;
    border: 1px solid var(--borda);
    border-radius: 14px;
    background: #10151c;
    color: #60aaff;
    font-size: 23px;
}

.vazio strong {
    display: block;
    margin-bottom: 8px;
    color: var(--texto);
    font-size: 16px;
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
        overflow: visible;
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

    .content {
        padding: 24px 14px;
    }

    .cabecalho-pagina {
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

    .panel {
        padding: 16px;
    }

    .filtros {
        align-items: stretch;
        flex-direction: column;
    }

    .campo {
        min-width: 0;
    }

    .filtros-botoes {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .filtros-botoes button,
    .filtros-botoes a {
        width: 100%;
    }

    .acoes {
        min-width: 135px;
    }

    .acoes form {
        flex: 1 1 auto;
    }

    .acoes button {
        width: 100%;
    }

    th,
    td {
        padding: 12px;
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

    .filtros-botoes {
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

        <div class="admin-badge">
            <span>●</span>
            PAINEL ADMINISTRATIVO
        </div>

        <div class="label">Principal</div>

        <nav aria-label="Navegação administrativa">

            <a class="nav" href="dashboard.php">
                <span class="nav-icon">▦</span>
                <span>Dashboard</span>
            </a>

            <a class="nav" href="anuncios.php">
                <span class="nav-icon">🏍</span>
                <span>Anúncios</span>
            </a>

            <a class="nav active" href="empresas.php" aria-current="page">
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
                    <?= eEmpresa(
                        strtoupper(
                            function_exists('mb_substr')
                                ? mb_substr((string) $admin['nome'], 0, 1, 'UTF-8')
                                : substr((string) $admin['nome'], 0, 1)
                        )
                    ) ?>
                </div>

                <div class="admin-info">
                    <strong>
                        <?= eEmpresa((string) $admin['nome']) ?>
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
                <strong>Empresas</strong>
            </div>

            <div class="estado-admin">
                Sessão de administrador ativa
            </div>

        </header>

        <div class="content">

            <section class="cabecalho-pagina">

                <div>
                    <div class="eyebrow">Centro de controlo</div>

                    <h1>Gestão de empresas</h1>

                    <p class="subtitle">
                        Analisa pedidos de registo, aprova stands e gere
                        as contas profissionais da Motocore.
                    </p>
                </div>

                <div class="admin-tag">
                    <span>✓</span>
                    Acesso administrativo
                </div>

            </section>

            <?php if ($mensagem !== ''): ?>
                <div class="alert alert-sucesso" role="status">
                    <span class="alert-icon">✓</span>
                    <span><?= eEmpresa($mensagem) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($erro !== ''): ?>
                <div class="alert alert-erro" role="alert">
                    <span class="alert-icon">!</span>
                    <span><?= eEmpresa($erro) ?></span>
                </div>
            <?php endif; ?>

            <section class="stats" aria-label="Estatísticas das empresas">

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">Pedidos pendentes</div>
                        <div class="card-icon pendente-icon">◷</div>
                    </div>

                    <div class="number">
                        <?= number_format($totalPendentes, 0, ',', '.') ?>
                    </div>

                    <small>A aguardar aprovação administrativa</small>

                </article>

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">Stands aprovados</div>
                        <div class="card-icon">▤</div>
                    </div>

                    <div class="number">
                        <?= number_format($totalAprovados, 0, ',', '.') ?>
                    </div>

                    <small>Contas profissionais ativas</small>

                </article>

                <article class="card">

                    <div class="card-topo">
                        <div class="card-label">Stands suspensos</div>
                        <div class="card-icon suspenso-icon">⚑</div>
                    </div>

                    <div class="number">
                        <?= number_format($totalSuspensos, 0, ',', '.') ?>
                    </div>

                    <small>Contas com acesso suspenso</small>

                </article>

            </section>

            <section class="panel">

                <div class="section-header">
                    <div>
                        <h2 class="section-title">Pesquisar empresas</h2>
                        <p class="section-subtitle">
                            Pesquisa por nome, stand, email ou username
                            e filtra os resultados por estado.
                        </p>
                    </div>
                </div>

                <form class="filtros" method="get" action="empresas.php">

                    <div class="campo">
                        <label for="q">Pesquisa</label>

                        <input
                            type="search"
                            id="q"
                            name="q"
                            value="<?= eEmpresa($pesquisa) ?>"
                            placeholder="Nome, stand, email ou username"
                        >
                    </div>

                    <div class="campo">
                        <label for="estado">Estado da empresa</label>

                        <select id="estado" name="estado">
                            <option value="">Todos os estados</option>

                            <option
                                value="pendente"
                                <?= $filtro === 'pendente' ? 'selected' : '' ?>
                            >
                                Pendentes
                            </option>

                            <option
                                value="ativo"
                                <?= $filtro === 'ativo' ? 'selected' : '' ?>
                            >
                                Aprovados
                            </option>

                            <option
                                value="suspenso"
                                <?= $filtro === 'suspenso' ? 'selected' : '' ?>
                            >
                                Suspensos
                            </option>
                        </select>
                    </div>

                    <div class="filtros-botoes">

                        <button type="submit">
                            <span>⌕</span>
                            Pesquisar
                        </button>

                        <?php if ($pesquisa !== '' || $filtro !== ''): ?>
                            <a class="btn btn-secundario" href="empresas.php">
                                Limpar filtros
                            </a>
                        <?php endif; ?>

                    </div>

                </form>

            </section>

            <section class="panel">

                <div class="section-header">

                    <div>
                        <h2 class="section-title">Empresas registadas</h2>

                        <p class="section-subtitle">
                            Consulta os dados e gere os pedidos de registo
                            e as contas profissionais.
                        </p>
                    </div>

                    <span class="badge">
                        <?= number_format(count($empresas), 0, ',', '.') ?>
                        resultados
                    </span>

                </div>

                <p class="section-subtitle" style="margin-bottom: 18px">
                    São apresentadas até 200 empresas por pesquisa.
                </p>

                <?php if (!$empresas): ?>

                    <div class="vazio">

                        <div class="vazio-icon">⌕</div>

                        <strong>Não foram encontradas empresas</strong>

                        <p>
                            Não existem resultados para os critérios selecionados.
                            Experimenta alterar a pesquisa ou os filtros.
                        </p>

                        <?php if ($pesquisa !== '' || $filtro !== ''): ?>
                            <a class="btn btn-secundario" href="empresas.php">
                                Limpar filtros
                            </a>
                        <?php endif; ?>

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

                                    $dataRegisto = strtotime(
                                        (string) $empresa['criado_em']
                                    );
                                    ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?= eEmpresa($nomeEmpresa) ?>
                                            </strong>

                                            <span class="muted">
                                                @<?= eEmpresa(
                                                    (string) (
                                                        $empresa['username']
                                                        ?? 'Sem username'
                                                    )
                                                ) ?>
                                            </span>

                                            <span class="muted">
                                                ID #<?= (int) $empresa['id'] ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= eEmpresa(
                                                (string) (
                                                    $empresa['email']
                                                    ?? 'Sem email'
                                                )
                                            ) ?>

                                            <span class="muted">
                                                <?= eEmpresa(
                                                    (string) (
                                                        $empresa['telefone']
                                                        ?? 'Sem telefone'
                                                    )
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= eEmpresa(
                                                (string) (
                                                    $empresa['localidade']
                                                    ?? '—'
                                                )
                                            ) ?>

                                            <span class="muted">
                                                <?= eEmpresa(
                                                    (string) (
                                                        $empresa['distrito']
                                                        ?? ''
                                                    )
                                                ) ?>
                                            </span>
                                        </td>

                                        <td>

                                            <?php if ($pendente): ?>

                                                <span class="badge badge-pendente">
                                                    Pendente
                                                </span>

                                            <?php elseif ($aprovado): ?>

                                                <span class="badge badge-ativo">
                                                    Aprovado
                                                </span>

                                            <?php elseif ($estado === 'suspenso'): ?>

                                                <span class="badge badge-suspenso">
                                                    Suspenso
                                                </span>

                                            <?php else: ?>

                                                <span class="badge">
                                                    <?= eEmpresa($estado) ?>
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <?= $dataRegisto !== false
                                                ? date('d/m/Y', $dataRegisto)
                                                : '—'
                                            ?>
                                        </td>

                                        <td>

                                            <div class="acoes">

                                                <?php if ($pendente): ?>

                                                    <form
                                                        method="post"
                                                        action="empresas.php"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= eEmpresa(tokenCsrf()) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="utilizador_id"
                                                            value="<?= (int) $empresa['id'] ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="acao"
                                                            value="aprovar"
                                                        >

                                                        <button
                                                            class="btn-sucesso"
                                                            type="submit"
                                                        >
                                                            ✓ Aprovar
                                                        </button>
                                                    </form>

                                                    <form
                                                        method="post"
                                                        action="empresas.php"
                                                        onsubmit="return confirm('Tens a certeza de que queres rejeitar este pedido?');"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= eEmpresa(tokenCsrf()) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="utilizador_id"
                                                            value="<?= (int) $empresa['id'] ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="acao"
                                                            value="rejeitar"
                                                        >

                                                        <button
                                                            class="btn-perigo"
                                                            type="submit"
                                                        >
                                                            ✕ Rejeitar
                                                        </button>
                                                    </form>

                                                <?php elseif ($aprovado): ?>

                                                    <form
                                                        method="post"
                                                        action="empresas.php"
                                                        onsubmit="return confirm('Queres suspender este stand?');"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= eEmpresa(tokenCsrf()) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="utilizador_id"
                                                            value="<?= (int) $empresa['id'] ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="acao"
                                                            value="suspender"
                                                        >

                                                        <button
                                                            class="btn-perigo"
                                                            type="submit"
                                                        >
                                                            Suspender
                                                        </button>
                                                    </form>

                                                <?php elseif (
                                                    $estado === 'suspenso'
                                                    && (int) $empresa['stand_aprovado'] === 1
                                                ): ?>

                                                    <form
                                                        method="post"
                                                        action="empresas.php"
                                                        onsubmit="return confirm('Queres reativar este stand?');"
                                                    >
                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= eEmpresa(tokenCsrf()) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="utilizador_id"
                                                            value="<?= (int) $empresa['id'] ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="acao"
                                                            value="reativar"
                                                        >

                                                        <button
                                                            class="btn-sucesso"
                                                            type="submit"
                                                        >
                                                            ↻ Reativar
                                                        </button>
                                                    </form>

                                                <?php else: ?>

                                                    <span class="muted">
                                                        Sem ações disponíveis
                                                    </span>

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

            <footer>
                Motocore · Painel de administração
            </footer>

        </div>

    </main>

</div>

</body>
</html>
<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/funcoes.php';
require_once __DIR__ . '/includes/google_config.php';

function erroGoogle(string $mensagem): never
{
    http_response_code(400);

    $tituloPagina = 'Autenticação Google';
    require __DIR__ . '/includes/header.php';

    echo '<section class="formulario-container">';
    echo '<h1>Não foi possível entrar</h1>';
    echo '<div class="alerta erro" role="alert">';
    echo e($mensagem);
    echo '</div>';
    echo '<p><a href="login.php">Voltar ao login</a></p>';
    echo '</section>';

    require __DIR__ . '/includes/footer.php';
    exit;
}

if (isset($_GET['error'])) {
    unset($_SESSION['google_oauth_state']);
    erroGoogle('O acesso com Google foi cancelado ou recusado.');
}

$estadoRecebido = $_GET['state'] ?? '';
$estadoGuardado = $_SESSION['google_oauth_state'] ?? '';

unset($_SESSION['google_oauth_state']);

if (
    !is_string($estadoRecebido) ||
    !is_string($estadoGuardado) ||
    $estadoGuardado === '' ||
    !hash_equals($estadoGuardado, $estadoRecebido)
) {
    erroGoogle('Pedido inválido. Tenta novamente.');
}

$codigo = $_GET['code'] ?? '';

if (!is_string($codigo) || $codigo === '') {
    erroGoogle('Não foi recebido um código válido do Google.');
}

try {
    $client = clienteGoogle();
    $token = $client->fetchAccessTokenWithAuthCode($codigo);

    if (!is_array($token) || isset($token['error'])) {
        erroGoogle('Não foi possível validar o acesso Google.');
    }

    $idToken = $token['id_token'] ?? null;

    if (!is_string($idToken) || $idToken === '') {
        erroGoogle('O Google não devolveu um token de identidade válido.');
    }

    $dadosGoogle = $client->verifyIdToken($idToken);

    if (!is_array($dadosGoogle)) {
        erroGoogle('Não foi possível confirmar a tua identidade Google.');
    }

    $googleId = $dadosGoogle['sub'] ?? '';
    $email = strtolower(trim($dadosGoogle['email'] ?? ''));
    $emailVerificado = $dadosGoogle['email_verified'] ?? false;
    $nome = trim($dadosGoogle['name'] ?? '');

    if (
        !is_string($googleId) ||
        $googleId === '' ||
        !is_string($email) ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        $emailVerificado !== true
    ) {
        erroGoogle('O Google não confirmou um email válido.');
    }

    if ($nome === '') {
        $nome = strstr($email, '@', true) ?: 'Utilizador Motocore';
    }

    $nome = mb_substr($nome, 0, 100);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT id, nome, username, estado
         FROM utilizadores
         WHERE google_id = ?
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([$googleId]);
    $utilizador = $stmt->fetch();

    if (!$utilizador) {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM utilizadores
             WHERE email = ?
             LIMIT 1'
        );
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $pdo->rollBack();

            erroGoogle(
                'Já existe uma conta com este email. Entra com o método '
                . 'habitual. A associação de contas Google será tratada '
                . 'separadamente nas definições.'
            );
        }

        $stmt = $pdo->prepare(
            'INSERT INTO utilizadores
                (nome, username, email, password_hash, google_id,
                 tipo_conta, stand_aprovado, email_verificado_em)
             VALUES (?, NULL, ?, NULL, ?, ?, ?, NOW())'
        );

        $stmt->execute([
            $nome,
            $email,
            $googleId,
            'particular',
            0
        ]);

        $id = (int) $pdo->lastInsertId();

        $utilizador = [
            'id' => $id,
            'nome' => $nome,
            'username' => null,
            'estado' => 'ativo'
        ];
    }

    if ($utilizador['estado'] !== 'ativo') {
        $pdo->rollBack();
        erroGoogle('Esta conta está indisponível.');
    }

    $stmt = $pdo->prepare(
        'UPDATE utilizadores
         SET ultimo_login = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$utilizador['id']]);

    $pdo->commit();

    session_regenerate_id(true);

    $_SESSION['utilizador_id'] = (int) $utilizador['id'];
    $_SESSION['utilizador_nome'] = $utilizador['nome'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    if (
        !is_string($utilizador['username'] ?? null) ||
        trim($utilizador['username']) === ''
    ) {
        redirecionar('escolher_username.php');
    }

    $_SESSION['utilizador_username'] = $utilizador['username'];

    redirecionar('index.php');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erro de autenticação Google: ' . $e->getMessage());

    erroGoogle(
        'Ocorreu um erro ao iniciar sessão. Tenta novamente.'
    );
}
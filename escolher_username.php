<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/funcoes.php';

if (empty($_SESSION['utilizador_id'])) {
    redirecionar('login.php');
}

$idUtilizador = (int) $_SESSION['utilizador_id'];
$erro = '';
$username = '';

$stmt = $pdo->prepare(
    'SELECT id, nome, username, estado
     FROM utilizadores
     WHERE id = ?
     LIMIT 1'
);
$stmt->execute([$idUtilizador]);
$utilizador = $stmt->fetch();

if (!$utilizador || $utilizador['estado'] !== 'ativo') {
    $_SESSION = [];
    session_destroy();
    redirecionar('login.php');
}

if (
    is_string($utilizador['username']) &&
    $utilizador['username'] !== ''
) {
    $_SESSION['utilizador_username'] = $utilizador['username'];
    redirecionar('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenRecebido = $_POST['csrf_token'] ?? '';
    $tokenGuardado = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($tokenRecebido) ||
        !is_string($tokenGuardado) ||
        $tokenGuardado === '' ||
        !hash_equals($tokenGuardado, $tokenRecebido)
    ) {
        $erro = 'A sessão expirou. Atualiza a página e tenta novamente.';
    } else {
        $username = strtolower(trim($_POST['username'] ?? ''));

        if (!preg_match('/\A[a-z0-9._]{3,30}\z/', $username)) {
            $erro = 'Usa 3 a 30 caracteres: letras, números, pontos ou underscores.';
        } elseif (
            str_starts_with($username, '.') ||
            str_ends_with($username, '.') ||
            str_contains($username, '..')
        ) {
            $erro = 'O nome não pode começar ou terminar com ponto, nem conter pontos consecutivos.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE utilizadores
                     SET username = ?
                     WHERE id = ?
                       AND username IS NULL'
                );
                $stmt->execute([$username, $idUtilizador]);

                if ($stmt->rowCount() !== 1) {
                    $stmt = $pdo->prepare(
                        'SELECT username
                         FROM utilizadores
                         WHERE id = ?
                         LIMIT 1'
                    );
                    $stmt->execute([$idUtilizador]);
                    $atual = $stmt->fetch();

                    if (
                        !$atual ||
                        $atual['username'] !== $username
                    ) {
                        $erro = 'Não foi possível guardar o nome. Atualiza a página e tenta novamente.';
                    }
                }

                if ($erro === '') {
                    $_SESSION['utilizador_username'] = $username;
                    redirecionar('index.php');
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $erro = 'Este nome de utilizador já está ocupado. Escolhe outro.';
                } else {
                    error_log('Erro ao guardar username: ' . $e->getMessage());
                    $erro = 'Ocorreu um erro ao guardar. Tenta novamente.';
                }
            }
        }
    }
}


$tituloPagina = 'Escolher nome de utilizador';
require __DIR__ . '/includes/header.php';

$nomeApresentacao = (string) $utilizador['nome'];
$inicial = mb_strtoupper(mb_substr($nomeApresentacao, 0, 1));
?>

<section class="username-container">

    <div class="username-marca" aria-hidden="true">M</div>

    <h1>Bem-vindo ao Motocore</h1>

    <p class="username-descricao">
        A tua conta está quase pronta.
        Escolhe como queres aparecer na comunidade.
    </p>

    <div class="username-boas-vindas">
        <span>Nome da tua conta</span>
        <strong><?= e($nomeApresentacao) ?></strong>
    </div>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro" role="alert">
            <?= e($erro) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="escolher_username.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <div class="username-campo">
            <label for="username">Nome de utilizador</label>

            <div class="username-input-wrapper">
                <span class="username-prefixo">@</span>

                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= e($username) ?>"
                    minlength="3"
                    maxlength="30"
                    pattern="[A-Za-z0-9._]{3,30}"
                    autocomplete="username"
                    placeholder="o.teu.nome"
                    aria-describedby="username-ajuda"
                    required
                    autofocus
                >
            </div>

            <p class="username-ajuda" id="username-ajuda">
                Entre 3 e 30 caracteres. Podes utilizar letras,
                números, pontos e underscores. O nome tem de estar disponível.
            </p>
        </div>

        <div class="username-preview">
            <div class="username-preview-titulo">
                Pré-visualização do perfil
            </div>

            <div class="username-preview-perfil">
                <div class="username-avatar" aria-hidden="true">
                    <?= e($inicial) ?>
                </div>

                <div class="username-preview-nome">
                    <strong><?= e($nomeApresentacao) ?></strong>
                    <span id="username-preview-texto">
                        @<?= e($username !== '' ? $username : 'o.teu.nome') ?>
                    </span>
                </div>
            </div>
        </div>

        <button type="submit" class="username-botao">
            Criar o meu perfil
        </button>

        <p class="username-seguranca">
            O teu nome de utilizador poderá ser alterado mais tarde
            nas definições da conta.
        </p>

    </form>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('username');
    const preview = document.getElementById('username-preview-texto');

    if (!input || !preview) {
        return;
    }

    input.addEventListener('input', function () {
        const valor = input.value.trim().toLowerCase();
        preview.textContent = '@' + (valor || 'o.teu.nome');
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

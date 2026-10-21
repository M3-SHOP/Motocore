
<?php
require_once __DIR__ . '/includes/funcoes.php';

if (autenticado()) {
    redirecionar('index.php');
}

$erro = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        'SELECT id, nome, password_hash, estado
         FROM utilizadores
         WHERE email = ?
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $utilizador = $stmt->fetch();

    if (
        $utilizador &&
        password_verify($password, $utilizador['password_hash']) &&
        $utilizador['estado'] === 'ativo'
    ) {
        session_regenerate_id(true);

        $_SESSION['utilizador_id'] = (int) $utilizador['id'];
        $_SESSION['utilizador_nome'] = $utilizador['nome'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare(
            'UPDATE utilizadores SET ultimo_login = NOW() WHERE id = ?'
        );
        $stmt->execute([$utilizador['id']]);

        redirecionar('index.php');
    }

    $erro = 'Email ou palavra-passe incorretos, ou conta indisponível.';
}

$tituloPagina = 'Entrar';
require __DIR__ . '/includes/header.php';
?>

<section class="formulario-container">
    <h1>Entrar na tua conta</h1>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post" class="formulario">
        <input type="hidden" name="csrf_token"
               value="<?= e(tokenCsrf()) ?>">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" required
               autocomplete="email" value="<?= e($email) ?>">

        <label for="password">Palavra-passe</label>
        <input id="password" type="password" name="password"
               required autocomplete="current-password">

        <button class="botao principal largura-total" type="submit">
            Entrar
        </button>
    </form>

    <p>Ainda não tens conta? <a href="registo.php">Regista-te</a>.</p>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>


<?php
require_once __DIR__ . '/includes/funcoes.php';

if (autenticado()) {
    redirecionar('index.php');
}

$erro = '';
$nome = '';
$email = '';
$tipoConta = 'particular';
$nomeStand = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $nome = trim($_POST['nome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $tipoConta = $_POST['tipo_conta'] ?? 'particular';
    $nomeStand = trim($_POST['nome_stand'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmacao = $_POST['confirmar_password'] ?? '';

    if (
        $nome === '' ||
        mb_strlen($nome) > 100 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 190
    ) {
        $erro = 'Preenche corretamente o nome e o email.';
    } elseif (!in_array($tipoConta, ['particular', 'stand'], true)) {
        $erro = 'Seleciona um tipo de conta válido.';
    } elseif (
        $tipoConta === 'stand' &&
        ($nomeStand === '' || mb_strlen($nomeStand) > 150)
    ) {
        $erro = 'Indica o nome do stand.';
    } elseif (strlen($password) < 10) {
        $erro = 'A palavra-passe deve ter pelo menos 10 caracteres.';
    } elseif ($password !== $confirmacao) {
        $erro = 'As palavras-passe não coincidem.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id FROM utilizadores WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $erro = 'Já existe uma conta com esse email.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO utilizadores
                    (nome, email, password_hash, tipo_conta, nome_stand)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $nome,
                $email,
                $hash,
                $tipoConta,
                $tipoConta === 'stand' ? $nomeStand : null
            ]);

            session_regenerate_id(true);
            $_SESSION['utilizador_id'] = (int) $pdo->lastInsertId();
            $_SESSION['utilizador_nome'] = $nome;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            redirecionar('index.php');
        }
    }
}

$tituloPagina = 'Criar conta';
require __DIR__ . '/includes/header.php';
?>

<section class="formulario-container">
    <h1>Criar conta</h1>
    <p>Junta-te à comunidade MotoCore.</p>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post" class="formulario">
        <input type="hidden" name="csrf_token"
               value="<?= e(tokenCsrf()) ?>">

        <label for="nome">Nome</label>
        <input id="nome" name="nome" required maxlength="100"
               autocomplete="name" value="<?= e($nome) ?>">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" required
               maxlength="190" autocomplete="email"
               value="<?= e($email) ?>">

        <label for="tipo_conta">Tipo de conta</label>
        <select id="tipo_conta" name="tipo_conta">
            <option value="particular"
                <?= $tipoConta === 'particular' ? 'selected' : '' ?>>
                Particular
            </option>
            <option value="stand"
                <?= $tipoConta === 'stand' ? 'selected' : '' ?>>
                Stand profissional
            </option>
        </select>

        <label for="nome_stand">Nome do stand (se aplicável)</label>
        <input id="nome_stand" name="nome_stand" maxlength="150"
               value="<?= e($nomeStand) ?>">

        <label for="password">Palavra-passe</label>
        <input id="password" type="password" name="password"
               minlength="10" required autocomplete="new-password">

        <label for="confirmar_password">Confirmar palavra-passe</label>
        <input id="confirmar_password" type="password"
               name="confirmar_password" minlength="10"
               required autocomplete="new-password">

        <button class="botao principal largura-total" type="submit">
            Criar conta
        </button>
    </form>

    <p>Já tens conta? <a href="login.php">Inicia sessão</a>.</p>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

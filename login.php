<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/funcoes.php';

if (autenticado()) {
    redirecionar('index.php');
}

$erro = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $emailRecebido = $_POST['email'] ?? '';
    $passwordRecebida = $_POST['password'] ?? '';

    $email = is_string($emailRecebido)
        ? strtolower(trim($emailRecebido))
        : '';

    $password = is_string($passwordRecebida)
        ? $passwordRecebida
        : '';

    if (
        $email === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $erro = 'Introduz um email válido.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, nome, password_hash, estado
                 FROM utilizadores
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->execute([$email]);
            $utilizador = $stmt->fetch();

            $hash = $utilizador['password_hash'] ?? null;

            if (
                $utilizador &&
                is_string($hash) &&
                $hash !== '' &&
                password_verify($password, $hash) &&
                $utilizador['estado'] === 'ativo'
            ) {
                session_regenerate_id(true);

                $_SESSION['utilizador_id'] =
                    (int) $utilizador['id'];

                $_SESSION['utilizador_nome'] =
                    $utilizador['nome'];

                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                $stmt = $pdo->prepare(
                    'UPDATE utilizadores
                     SET ultimo_login = NOW()
                     WHERE id = ?'
                );

                $stmt->execute([$utilizador['id']]);

                redirecionar('index.php');
            }

            $erro = 'Email ou palavra-passe incorretos, ou conta indisponível.';

        } catch (PDOException $e) {
            error_log($e->getMessage());

            $erro = 'Não foi possível iniciar sessão. Tenta novamente.';
        }
    }
}

$tituloPagina = 'Entrar';

require __DIR__ . '/includes/header.php';
?>

<style>
.login-google {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    min-height: 48px;
    padding: 12px 16px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    background: #fff;
    color: #222;
    font: inherit;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    transition: background .2s, border-color .2s;
}

.login-google:hover {
    background: #f5f5f5;
    border-color: #9ca3af;
}

.login-separador {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 22px 0;
    color: #777;
    font-size: .9rem;
}

.login-separador::before,
.login-separador::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #ddd;
}

.login-nota {
    margin-top: 12px;
    color: #777;
    font-size: .85rem;
    text-align: center;
}
</style>

<section class="formulario-container">

    <h1>Entrar na tua conta</h1>

    <p>Bem-vindo de volta à Motocore.</p>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro" role="alert">
            <?= e($erro) ?>
        </div>
    <?php endif; ?>

    <form method="post" class="formulario">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(tokenCsrf()) ?>"
        >

        <label for="email">Email</label>

        <input
            id="email"
            type="email"
            name="email"
            required
            maxlength="190"
            autocomplete="email"
            value="<?= e($email) ?>"
        >

        <label for="password">Palavra-passe</label>

        <input
            id="password"
            type="password"
            name="password"
            required
            autocomplete="current-password"
        >

        <button
            class="botao principal largura-total"
            type="submit"
        >
            Entrar
        </button>

    </form>

    <div class="login-separador">ou</div>

    <a class="login-google" href="google_login.php">
        <svg
            width="20"
            height="20"
            viewBox="0 0 48 48"
            aria-hidden="true"
        >
            <path fill="#EA4335"
                d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
                transform="translate(0 4)"/>
            <path fill="#4285F4"
                d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.76 7.18l7.73 6C44.42 37.99 46.98 31.8 46.98 24.55z"/>
            <path fill="#FBBC05"
                d="M10.53 28.59A14.4 14.4 0 0 1 9.75 24c0-1.59.27-3.13.76-4.59l-7.98-6.19A23.9 23.9 0 0 0 0 24c0 3.87.93 7.52 2.56 10.78l7.97-6.19z"
                transform="translate(0 0)"/>
            <path fill="#34A853"
                d="M24 48c6.48 0 11.93-2.13 15.91-5.8l-7.73-6c-2.14 1.45-4.89 2.3-8.18 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
        </svg>

        Continuar com Google
    </a>

    <p class="login-nota">
        Entra de forma segura com a tua conta Google.
    </p>

    <p>
        Ainda não tens conta?
        <a href="registo.php">Regista-te</a>.
    </p>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
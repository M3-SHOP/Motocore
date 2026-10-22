<?php

declare(strict_types=1);

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

    $nomeRecebido = $_POST['nome'] ?? '';
    $emailRecebido = $_POST['email'] ?? '';
    $tipoRecebido = $_POST['tipo_conta'] ?? 'particular';
    $standRecebido = $_POST['nome_stand'] ?? '';
    $passwordRecebida = $_POST['password'] ?? '';
    $confirmacaoRecebida = $_POST['confirmar_password'] ?? '';

    $nome = is_string($nomeRecebido)
        ? trim($nomeRecebido)
        : '';

    $email = is_string($emailRecebido)
        ? strtolower(trim($emailRecebido))
        : '';

    $tipoConta = is_string($tipoRecebido)
        ? $tipoRecebido
        : '';

    $nomeStand = is_string($standRecebido)
        ? trim($standRecebido)
        : '';

    $password = is_string($passwordRecebida)
        ? $passwordRecebida
        : '';

    $confirmacao = is_string($confirmacaoRecebida)
        ? $confirmacaoRecebida
        : '';

    if (
        $nome === '' ||
        mb_strlen($nome) > 100 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 190
    ) {
        $erro = 'Preenche corretamente o nome e o email.';

    } elseif (
        !in_array($tipoConta, ['particular', 'stand'], true)
    ) {
        $erro = 'Seleciona um tipo de conta válido.';

    } elseif (
        $tipoConta === 'stand' &&
        ($nomeStand === '' || mb_strlen($nomeStand) > 150)
    ) {
        $erro = 'Indica um nome de stand válido.';

    } elseif (strlen($password) < 10) {
        $erro = 'A palavra-passe deve ter pelo menos 10 caracteres.';

    } elseif ($password !== $confirmacao) {
        $erro = 'As palavras-passe não coincidem.';

    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id
                 FROM utilizadores
                 WHERE email = ?
                 LIMIT 1'
            );

            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                $erro = 'Já existe uma conta com esse email.';

            } else {
                $hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $aprovado = $tipoConta === 'particular' ? 1 : 0;

                $stmt = $pdo->prepare(
                    'INSERT INTO utilizadores
                        (nome, email, password_hash, tipo_conta,
                         nome_stand, stand_aprovado)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $nome,
                    $email,
                    $hash,
                    $tipoConta,
                    $tipoConta === 'stand' ? $nomeStand : null,
                    $aprovado
                ]);

                $id = (int) $pdo->lastInsertId();

                session_regenerate_id(true);

                $_SESSION['utilizador_id'] = $id;
                $_SESSION['utilizador_nome'] = $nome;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                if ($tipoConta === 'stand') {
                    redirecionar(
                        'minha_conta.php?stand=pendente'
                    );
                }

                redirecionar('index.php');
            }

        } catch (PDOException $e) {
            error_log($e->getMessage());

            $erro = 'Não foi possível criar a conta. Tenta novamente.';
        }
    }
}

$tituloPagina = 'Criar conta';

require __DIR__ . '/includes/header.php';
?>

<style>
.registo-opcoes {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin: 24px 0;
}

.registo-opcao {
    padding: 20px;
    border: 1px solid #d8d8d8;
    border-radius: 12px;
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
    transition: border-color .2s, background .2s;
}

.registo-opcao[aria-pressed="true"] {
    border: 2px solid #f97316;
    background: rgba(249, 115, 22, .08);
}

.registo-opcao strong,
.registo-opcao span {
    display: block;
}

.registo-opcao strong {
    margin-bottom: 8px;
    font-size: 1.05rem;
}

.registo-opcao span {
    font-size: .9rem;
    line-height: 1.5;
}

.registo-separador {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 22px 0;
    color: #777;
    font-size: .9rem;
}

.registo-separador::before,
.registo-separador::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #ddd;
}

.registo-google {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    min-height: 48px;
    padding: 12px 16px;
    border: 1px solid #ccc;
    border-radius: 8px;
    background: #fff;
    color: #222;
    font: inherit;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    transition: background .2s;
}

.registo-google:hover {
    background: #f5f5f5;
}

.registo-nota {
    margin-top: 12px;
    font-size: .85rem;
    color: #777;
}

@media (max-width: 520px) {
    .registo-opcoes {
        grid-template-columns: 1fr;
    }
}
</style>

<section class="formulario-container">

    <h1>Criar conta Motocore</h1>

    <p>Escolhe o tipo de conta para começares.</p>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro" role="alert">
            <?= e($erro) ?>
        </div>
    <?php endif; ?>

    <div class="registo-opcoes">

        <button
            type="button"
            class="registo-opcao"
            id="opcao-particular"
            aria-pressed="<?= $tipoConta === 'particular' ? 'true' : 'false' ?>"
        >
            <strong>👤 Conta Individual</strong>
            <span>
                Para comprar motas, guardar favoritos e contactar vendedores.
            </span>
        </button>

        <button
            type="button"
            class="registo-opcao"
            id="opcao-stand"
            aria-pressed="<?= $tipoConta === 'stand' ? 'true' : 'false' ?>"
        >
            <strong>🏢 Conta Empresarial</strong>
            <span>
                Para stands profissionais que pretendem anunciar motas.
            </span>
        </button>

    </div>

    <form method="post" class="formulario" id="form-registo">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(tokenCsrf()) ?>"
        >

        <input
            type="hidden"
            name="tipo_conta"
            id="tipo_conta"
            value="<?= e($tipoConta) ?>"
        >

        <label for="nome">Nome do responsável / nome completo</label>

        <input
            id="nome"
            name="nome"
            required
            maxlength="100"
            autocomplete="name"
            value="<?= e($nome) ?>"
        >

        <div
            id="campos-stand"
            <?= $tipoConta !== 'stand' ? 'hidden' : '' ?>
        >

            <label for="nome_stand">Nome do stand</label>

            <input
                id="nome_stand"
                name="nome_stand"
                maxlength="150"
                autocomplete="organization"
                value="<?= e($nomeStand) ?>"
                <?= $tipoConta === 'stand' ? 'required' : '' ?>
                <?= $tipoConta !== 'stand' ? 'disabled' : '' ?>
            >

            <p class="registo-nota">
                A conta empresarial ficará sujeita a validação
                antes de poder publicar anúncios.
            </p>

        </div>

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
            minlength="10"
            required
            autocomplete="new-password"
        >

        <label for="confirmar_password">Confirmar palavra-passe</label>

        <input
            id="confirmar_password"
            type="password"
            name="confirmar_password"
            minlength="10"
            required
            autocomplete="new-password"
        >

        <button
            class="botao principal largura-total"
            type="submit"
        >
            Criar conta
        </button>

    </form>

    <div id="google-area"
         <?= $tipoConta !== 'particular' ? 'hidden' : '' ?>>

        <div class="registo-separador">ou</div>

        <a class="registo-google" href="google_login.php">

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
                    d="M10.53 28.59A14.4 14.4 0 0 1 9.75 24c0-1.59.27-3.13.76-4.59l-7.98-6.19A23.9 23.9 0 0 0 0 24c0 3.87.93 7.52 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853"
                    d="M24 48c6.48 0 11.93-2.13 15.91-5.8l-7.73-6c-2.14 1.45-4.89 2.3-8.18 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>

            Registar com Google

        </a>

        <p class="registo-nota">
            Cria uma conta individual com a tua conta Google.
        </p>

    </div>

    <p>
        Já tens conta?
        <a href="login.php">Inicia sessão</a>.
    </p>

</section>

<script>
(() => {
    const tipo = document.getElementById('tipo_conta');
    const camposStand = document.getElementById('campos-stand');
    const nomeStand = document.getElementById('nome_stand');
    const googleArea = document.getElementById('google-area');

    const opcaoParticular =
        document.getElementById('opcao-particular');

    const opcaoStand =
        document.getElementById('opcao-stand');

    function selecionarConta(valor) {
        const eStand = valor === 'stand';

        tipo.value = valor;

        camposStand.hidden = !eStand;
        nomeStand.disabled = !eStand;
        nomeStand.required = eStand;

        googleArea.hidden = eStand;

        opcaoParticular.setAttribute(
            'aria-pressed',
            String(!eStand)
        );

        opcaoStand.setAttribute(
            'aria-pressed',
            String(eStand)
        );
    }

    opcaoParticular.addEventListener('click', () => {
        selecionarConta('particular');
    });

    opcaoStand.addEventListener('click', () => {
        selecionarConta('stand');
    });

    selecionarConta(tipo.value);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
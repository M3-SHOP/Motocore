
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
        $erro = 'Indica um nome de stand válido.';
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
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);

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

                session_regenerate_id(true);
                $_SESSION['utilizador_id'] = (int) $pdo->lastInsertId();
                $_SESSION['utilizador_nome'] = $nome;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                if ($tipoConta === 'stand') {
                    redirecionar('minha_conta.php?stand=pendente');
                }

                redirecionar('index.php');
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $erro = 'Não foi possível criar a conta. Tenta novamente.';
            }
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
    margin: 20px 0;
    text-align: center;
    color: #777;
}

.registo-google {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: not-allowed;
    opacity: .75;
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
            <span>Para comprar motas, guardar favoritos e contactar vendedores.</span>
        </button>

        <button
            type="button"
            class="registo-opcao"
            id="opcao-stand"
            aria-pressed="<?= $tipoConta === 'stand' ? 'true' : 'false' ?>"
        >
            <strong>🏢 Conta Empresarial</strong>
            <span>Para stands profissionais que pretendem anunciar motas.</span>
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

        <div id="campos-stand"
             <?= $tipoConta !== 'stand' ? 'hidden' : '' ?>>
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

        <button class="botao principal largura-total" type="submit">
            Criar conta
        </button>

        <div id="google-area"
             <?= $tipoConta !== 'particular' ? 'hidden' : '' ?>>
            <div class="registo-separador">ou</div>

            <button
                type="button"
                class="registo-google"
                disabled
                title="A integração Google será configurada posteriormente"
            >
                G Continuar com Google — brevemente
            </button>

            <p class="registo-nota">
                O acesso com Google ainda não está ativo.
            </p>
        </div>
    </form>

    <p>Já tens conta? <a href="login.php">Inicia sessão</a>.</p>
</section>

<script>
(() => {
    const tipo = document.getElementById('tipo_conta');
    const camposStand = document.getElementById('campos-stand');
    const nomeStand = document.getElementById('nome_stand');
    const googleArea = document.getElementById('google-area');
    const opcaoParticular = document.getElementById('opcao-particular');
    const opcaoStand = document.getElementById('opcao-stand');

    function selecionarConta(valor) {
        tipo.value = valor;

        const eStand = valor === 'stand';

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

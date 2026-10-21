
<?php
require_once __DIR__ . '/includes/funcoes.php';

if (!autenticado()) {
    redirecionar('login.php');
}

$conversaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$conversaId || $conversaId < 1) {
    http_response_code(404);
    exit('Conversa não encontrada.');
}

$utilizadorId = (int) $_SESSION['utilizador_id'];

$stmt = $pdo->prepare("
    SELECT
        c.*,
        a.titulo,
        a.preco,
        comprador.nome AS nome_comprador,
        vendedor.nome AS nome_vendedor
    FROM conversas c
    INNER JOIN anuncios a ON a.id = c.anuncio_id
    INNER JOIN utilizadores comprador
        ON comprador.id = c.comprador_id
    INNER JOIN utilizadores vendedor
        ON vendedor.id = c.vendedor_id
    WHERE c.id = ?
      AND (c.comprador_id = ? OR c.vendedor_id = ?)
    LIMIT 1
");

$stmt->execute([$conversaId, $utilizadorId, $utilizadorId]);
$conversa = $stmt->fetch();

if (!$conversa) {
    http_response_code(404);
    exit('Conversa não encontrada ou sem permissão de acesso.');
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $texto = trim($_POST['mensagem'] ?? '');

    if ($texto === '' || mb_strlen($texto) > 5000) {
        $erro = 'A mensagem deve ter entre 1 e 5000 caracteres.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO mensagens
                    (conversa_id, remetente_id, mensagem)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $conversaId,
                $utilizadorId,
                $texto
            ]);

            $stmt = $pdo->prepare("
                UPDATE conversas
                SET atualizado_em = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $stmt->execute([$conversaId]);

            $pdo->commit();

            redirecionar('conversa.php?id=' . $conversaId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $erro = 'Não foi possível enviar a mensagem. Tenta novamente.';
        }
    }
}

// Marcar as mensagens recebidas como lidas
$stmt = $pdo->prepare("
    UPDATE mensagens
    SET lida_em = CURRENT_TIMESTAMP
    WHERE conversa_id = ?
      AND remetente_id <> ?
      AND lida_em IS NULL
");

$stmt->execute([$conversaId, $utilizadorId]);

// Carregar as mensagens da conversa
$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.mensagem,
        m.remetente_id,
        m.criado_em,
        m.lida_em,
        u.nome AS remetente
    FROM mensagens m
    INNER JOIN utilizadores u ON u.id = m.remetente_id
    WHERE m.conversa_id = ?
    ORDER BY m.id ASC
");

$stmt->execute([$conversaId]);
$mensagens = $stmt->fetchAll();

$tituloPagina = 'Conversa';
require __DIR__ . '/includes/header.php';
?>

<section class="mensagens-pagina">

    <a class="voltar-mensagens" href="conversas.php">
        &larr; Voltar às mensagens
    </a>

    <div class="cabecalho-conversa">
        <div>
            <h1><?= e($conversa['titulo']) ?></h1>

            <p>
                <?= e(
                    $utilizadorId === (int) $conversa['comprador_id']
                        ? $conversa['nome_vendedor']
                        : $conversa['nome_comprador']
                ) ?>
            </p>
        </div>

        <strong class="preco-conversa">
            <?= number_format(
                (float) $conversa['preco'],
                2,
                ',',
                '.'
            ) ?> €
        </strong>
    </div>

    <div class="caixa-mensagens" id="caixa-mensagens">

        <?php if (!$mensagens): ?>
            <p class="sem-mensagens">
                Esta conversa ainda não tem mensagens.
                Envia a primeira!
            </p>
        <?php endif; ?>

        <?php foreach ($mensagens as $m): ?>
            <?php
            $minha = (int) $m['remetente_id'] === $utilizadorId;
            ?>

            <article class="mensagem <?= $minha
                ? 'mensagem-minha'
                : 'mensagem-outra' ?>">

                <?php if (!$minha): ?>
                    <strong><?= e($m['remetente']) ?></strong>
                <?php endif; ?>

                <p><?= nl2br(e($m['mensagem'])) ?></p>

                <time>
                    <?= date(
                        'd/m/Y H:i',
                        strtotime($m['criado_em'])
                    ) ?>

                    <?php if ($minha && $m['lida_em']): ?>
                        · Lida
                    <?php endif; ?>
                </time>
            </article>
        <?php endforeach; ?>

    </div>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post"
          action="conversa.php?id=<?= (int) $conversaId ?>"
          class="formulario formulario-mensagem">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(tokenCsrf()) ?>"
        >

        <label for="mensagem">A tua mensagem</label>

        <textarea
            id="mensagem"
            name="mensagem"
            rows="3"
            maxlength="5000"
            required
            placeholder="Escreve a tua mensagem..."
        ></textarea>

        <button class="botao principal" type="submit">
            Enviar mensagem
        </button>
    </form>

</section>

<script>
const caixaMensagens = document.getElementById('caixa-mensagens');

if (caixaMensagens) {
    caixaMensagens.scrollTop = caixaMensagens.scrollHeight;
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

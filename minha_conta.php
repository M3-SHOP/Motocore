<?php
require_once __DIR__ . '/includes/funcoes.php';

if (!autenticado()) {
    redirecionar('login.php');
}

$utilizadorId = (int) $_SESSION['utilizador_id'];
$erro = '';
$sucesso = '';

if (isset($_SESSION['flash_sucesso'])) {
    $sucesso = $_SESSION['flash_sucesso'];
    unset($_SESSION['flash_sucesso']);
}

// Alterações de estado e eliminação só podem ser feitas por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $acao = $_POST['acao'] ?? '';
    $anuncioId = filter_input(
        INPUT_POST,
        'anuncio_id',
        FILTER_VALIDATE_INT
    );

    if (!$anuncioId || $anuncioId < 1) {
        $erro = 'Anúncio inválido.';
    } else {
        // Confirmar que o anúncio pertence ao utilizador
        $stmt = $pdo->prepare("
            SELECT id, estado_anuncio
            FROM anuncios
            WHERE id = ? AND utilizador_id = ?
            LIMIT 1
        ");
        $stmt->execute([$anuncioId, $utilizadorId]);
        $anuncio = $stmt->fetch();

        if (!$anuncio) {
            $erro = 'Não tens permissão para alterar este anúncio.';
        } elseif ($acao === 'vendido') {
            if ($anuncio['estado_anuncio'] !== 'ativo') {
                $erro = 'Este anúncio não está ativo.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE anuncios
                    SET estado_anuncio = 'vendido'
                    WHERE id = ? AND utilizador_id = ?
                ");
                $stmt->execute([$anuncioId, $utilizadorId]);

                $_SESSION['flash_sucesso'] =
                    'O anúncio foi marcado como vendido.';

                redirecionar('minha_conta.php');
            }
        } elseif ($acao === 'reativar') {
            if ($anuncio['estado_anuncio'] !== 'vendido') {
                $erro = 'Só podes reativar anúncios vendidos.';
            } else {
                $stmt = $pdo->prepare("
                    UPDATE anuncios
                    SET estado_anuncio = 'ativo'
                    WHERE id = ? AND utilizador_id = ?
                ");
                $stmt->execute([$anuncioId, $utilizadorId]);

                $_SESSION['flash_sucesso'] =
                    'O anúncio foi reativado.';

                redirecionar('minha_conta.php');
            }
        } elseif ($acao === 'eliminar') {
            try {
                $pdo->beginTransaction();

                // Obter imagens para as remover do disco após a transação
                $stmt = $pdo->prepare("
                    SELECT caminho
                    FROM imagens_anuncios
                    WHERE anuncio_id = ?
                ");
                $stmt->execute([$anuncioId]);
                $imagens = $stmt->fetchAll();

                // Apagar primeiro os registos dependentes, caso não
                // exista eliminação em cascata na base de dados.
                $stmt = $pdo->prepare("
                    DELETE FROM mensagens
                    WHERE conversa_id IN (
                        SELECT id FROM conversas WHERE anuncio_id = ?
                    )
                ");
                $stmt->execute([$anuncioId]);

                $stmt = $pdo->prepare("
                    DELETE FROM conversas WHERE anuncio_id = ?
                ");
                $stmt->execute([$anuncioId]);

                $stmt = $pdo->prepare("
                    DELETE FROM imagens_anuncios WHERE anuncio_id = ?
                ");
                $stmt->execute([$anuncioId]);

                $stmt = $pdo->prepare("
                    DELETE FROM anuncios
                    WHERE id = ? AND utilizador_id = ?
                ");
                $stmt->execute([$anuncioId, $utilizadorId]);

                $pdo->commit();

                // Apagar ficheiros apenas depois de a base de dados confirmar
                foreach ($imagens as $imagem) {
                    $caminho = $imagem['caminho'] ?? '';

                    if (
                        $caminho !== '' &&
                        strpos($caminho, 'uploads/motas/') === 0
                    ) {
                        $ficheiro = __DIR__ . '/' . $caminho;

                        if (is_file($ficheiro)) {
                            @unlink($ficheiro);
                        }
                    }
                }

                $_SESSION['flash_sucesso'] =
                    'O anúncio foi eliminado.';

                redirecionar('minha_conta.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log($e->getMessage());
                $erro = 'Não foi possível eliminar o anúncio.';
            }
        } else {
            $erro = 'Ação inválida.';
        }
    }
}

// Carregar os anúncios do utilizador autenticado
$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.titulo,
        a.preco,
        a.marca,
        a.modelo,
        a.ano,
        a.estado_anuncio,
        a.moderacao,
        a.criado_em,
        (
            SELECT ia.caminho
            FROM imagens_anuncios ia
            WHERE ia.anuncio_id = a.id
            ORDER BY ia.principal DESC, ia.ordem ASC, ia.id ASC
            LIMIT 1
        ) AS fotografia
    FROM anuncios a
    WHERE a.utilizador_id = ?
    ORDER BY a.criado_em DESC, a.id DESC
");

$stmt->execute([$utilizadorId]);
$anuncios = $stmt->fetchAll();

$tituloPagina = 'Os meus anúncios';
require __DIR__ . '/includes/header.php';
?>

<section class="minha-conta-pagina">

    <div class="minha-conta-topo">
        <div>
            <h1>Os meus anúncios</h1>
            <p>Gere as tuas motas publicadas no MotoCore.</p>
        </div>

        <a href="publicar.php" class="botao principal">
            + Publicar mota
        </a>
    </div>

    <?php if ($sucesso !== ''): ?>
        <div class="alerta sucesso"><?= e($sucesso) ?></div>
    <?php endif; ?>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro"><?= e($erro) ?></div>
    <?php endif; ?>

    <div class="anuncios-contagem">
        <?= count($anuncios) ?>
        <?= count($anuncios) === 1 ? 'anúncio' : 'anúncios' ?>
    </div>

    <?php if (!$anuncios): ?>

        <div class="estado-vazio">
            <h2>Ainda não publicaste nenhuma mota</h2>
            <p>Publica o teu primeiro anúncio para aparecer no MotoCore.</p>

            <a href="publicar.php" class="botao principal">
                Publicar a primeira mota
            </a>
        </div>

    <?php else: ?>

        <div class="lista-meus-anuncios">

            <?php foreach ($anuncios as $a): ?>

                <article class="meu-anuncio">

                    <a class="meu-anuncio-foto"
                       href="anuncio.php?id=<?= (int) $a['id'] ?>">

                        <?php if (!empty($a['fotografia'])): ?>
                            <img
                                src="<?= e($a['fotografia']) ?>"
                                alt="<?= e($a['titulo']) ?>"
                            >
                        <?php else: ?>
                            <div class="sem-foto">Sem fotografia</div>
                        <?php endif; ?>

                    </a>

                    <div class="meu-anuncio-info">

                        <div class="meu-anuncio-titulo">
                            <h2>
                                <a href="anuncio.php?id=<?= (int) $a['id'] ?>">
                                    <?= e($a['titulo']) ?>
                                </a>
                            </h2>

                            <span class="estado-anuncio estado-<?= e($a['estado_anuncio']) ?>">
                                <?= e(ucfirst($a['estado_anuncio'])) ?>
                            </span>
                        </div>

                        <p class="meu-anuncio-detalhes">
                            <?= e($a['marca']) ?>
                            <?= e($a['modelo']) ?>
                            · <?= (int) $a['ano'] ?>
                        </p>

                        <p class="meu-anuncio-preco">
                            <?= number_format((float) $a['preco'], 2, ',', '.') ?> €
                        </p>

                        <p class="meu-anuncio-data">
                            Publicado em
                            <?= date('d/m/Y', strtotime($a['criado_em'])) ?>
                            <?php if ($a['moderacao'] !== 'publicado'): ?>
                                · Moderação: <?= e($a['moderacao']) ?>
                            <?php endif; ?>
                        </p>

                        
<div class="meu-anuncio-acoes">

    <a class="botao secundario"
       href="anuncio.php?id=<?= (int) $a['id'] ?>">
        Ver anúncio
    </a>

    <a class="botao secundario"
       href="editar_anuncio.php?id=<?= (int) $a['id'] ?>">
        Editar anúncio
    </a>

    <?php if ($a['estado_anuncio'] === 'ativo'): ?>
        <form method="post" action="minha_conta.php">
            <input type="hidden" name="csrf_token"
                   value="<?= e(tokenCsrf()) ?>">
            <input type="hidden" name="anuncio_id"
                   value="<?= (int) $a['id'] ?>">
            <input type="hidden" name="acao" value="vendido">

            <button class="botao vendido" type="submit">
                Marcar vendido
            </button>
        </form>
    <?php elseif ($a['estado_anuncio'] === 'vendido'): ?>
        <form method="post" action="minha_conta.php">
            <input type="hidden" name="csrf_token"
                   value="<?= e(tokenCsrf()) ?>">
            <input type="hidden" name="anuncio_id"
                   value="<?= (int) $a['id'] ?>">
            <input type="hidden" name="acao" value="reativar">

            <button class="botao secundario" type="submit">
                Reativar anúncio
            </button>
        </form>
    <?php endif; ?>

    <form method="post" action="minha_conta.php"
          onsubmit="return confirm('Tens a certeza de que queres eliminar este anúncio e as respetivas conversas? Esta ação não pode ser desfeita.');">

        <input type="hidden" name="csrf_token"
               value="<?= e(tokenCsrf()) ?>">
        <input type="hidden" name="anuncio_id"
               value="<?= (int) $a['id'] ?>">
        <input type="hidden" name="acao" value="eliminar">

        <button class="botao eliminar" type="submit">
            Eliminar
        </button>
    </form>

</div>

                    </div>
                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
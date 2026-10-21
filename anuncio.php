<?php
require_once __DIR__ . '/includes/funcoes.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(404);
    exit('Anúncio não encontrado.');
}

$stmt = $pdo->prepare("
    SELECT
        a.*,
        u.nome AS vendedor,
        u.tipo_conta,
        u.nome_stand,
        u.localidade AS localidade_vendedor,
        u.criado_em AS vendedor_desde,
        c.nome AS categoria
    FROM anuncios a
    INNER JOIN utilizadores u ON u.id = a.utilizador_id
    LEFT JOIN categorias c ON c.id = a.categoria_id
    WHERE a.id = ?
      AND a.estado_anuncio = 'ativo'
      AND a.moderacao = 'publicado'
      AND u.estado = 'ativo'
    LIMIT 1
");
$stmt->execute([$id]);
$anuncio = $stmt->fetch();

if (!$anuncio) {
    http_response_code(404);
    exit('Este anúncio não existe ou já não está disponível.');
}

// Incrementar visualizações
$stmt = $pdo->prepare(
    'UPDATE anuncios SET visualizacoes = visualizacoes + 1 WHERE id = ?'
);
$stmt->execute([$id]);

// Carregar fotografias
$stmt = $pdo->prepare("
    SELECT caminho, texto_alternativo, principal
    FROM imagens_anuncios
    WHERE anuncio_id = ?
    ORDER BY principal DESC, ordem ASC, id ASC
");
$stmt->execute([$id]);
$imagens = $stmt->fetchAll();

$eDono = autenticado()
    && (int) $_SESSION['utilizador_id'] === (int) $anuncio['utilizador_id'];

$tituloPagina = $anuncio['titulo'];
require __DIR__ . '/includes/header.php';
?>

<section class="detalhe-mota">

    <div class="caminho">
        <a href="index.php">Início</a>
        <span> / </span>
        <span><?= e($anuncio['marca']) ?></span>
        <span> / </span>
        <?= e($anuncio['modelo']) ?>
    </div>

    <div class="detalhe-grelha">

        <div class="detalhe-conteudo">

            <div class="galeria-principal">
                <?php if ($imagens): ?>
                    <img
                        id="imagem-principal"
                        src="<?= e($imagens[0]['caminho']) ?>"
                        alt="<?= e($imagens[0]['texto_alternativo'] ?: $anuncio['titulo']) ?>"
                    >
                <?php else: ?>
                    <div class="imagem-indisponivel">
                        Sem fotografia disponível
                    </div>
                <?php endif; ?>
            </div>

            <?php if (count($imagens) > 1): ?>
                <div class="miniaturas">
                    <?php foreach ($imagens as $imagem): ?>
                        <button
                            type="button"
                            class="miniatura"
                            data-imagem="<?= e($imagem['caminho']) ?>"
                            aria-label="Ver outra fotografia"
                        >
                            <img
                                src="<?= e($imagem['caminho']) ?>"
                                alt=""
                            >
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="detalhe-descricao">
                <h2>Descrição</h2>
                <p class="descricao-texto"><?= nl2br(e($anuncio['descricao'])) ?></p>
            </div>

            <div class="detalhe-descricao">
                <h2>Características</h2>

                <div class="caracteristicas">
                    <div>
                        <span>Marca</span>
                        <strong><?= e($anuncio['marca']) ?></strong>
                    </div>

                    <div>
                        <span>Modelo</span>
                        <strong><?= e($anuncio['modelo']) ?></strong>
                    </div>

                    <div>
                        <span>Ano</span>
                        <strong><?= (int) $anuncio['ano'] ?></strong>
                    </div>

                    <div>
                        <span>Quilómetros</span>
                        <strong>
                            <?= number_format((int) $anuncio['quilometros'], 0, ',', '.') ?> km
                        </strong>
                    </div>

                    <div>
                        <span>Cilindrada</span>
                        <strong>
                            <?= $anuncio['cilindrada'] !== null
                                ? (int) $anuncio['cilindrada'] . ' cc'
                                : 'Não indicada' ?>
                        </strong>
                    </div>

                    <div>
                        <span>Estado</span>
                        <strong>
                            <?= $anuncio['tipo_mota'] === 'nova' ? 'Nova' : 'Usada' ?>
                        </strong>
                    </div>

                    <div>
                        <span>Categoria</span>
                        <strong><?= e($anuncio['categoria'] ?? 'Outra') ?></strong>
                    </div>

                    <div>
                        <span>Localização</span>
                        <strong>
                            <?= e($anuncio['localidade']) ?>,
                            <?= e($anuncio['distrito']) ?>
                        </strong>
                    </div>
                </div>
            </div>

        </div>

        <aside class="detalhe-lateral">

            <div class="caixa-preco">
                <p class="preco-grande">
                    <?= number_format((float) $anuncio['preco'], 2, ',', '.') ?> €
                </p>

                <?php if ($anuncio['negociavel']): ?>
                    <span class="preco-negociavel">Preço negociável</span>
                <?php endif; ?>

                <h1><?= e($anuncio['titulo']) ?></h1>

                <p class="localizacao-detalhe">
                    <?= e($anuncio['localidade']) ?>,
                    <?= e($anuncio['distrito']) ?>
                </p>

                <p class="data-anuncio">
                    Publicado em
                    <?= date('d/m/Y', strtotime($anuncio['criado_em'])) ?>
                    · <?= number_format((int) $anuncio['visualizacoes'] + 1, 0, ',', '.') ?> visualizações
                </p>

                <?php if (!autenticado()): ?>
                    <a class="botao principal largura-total"
                       href="login.php">
                        Inicia sessão para contactar
                    </a>

                <?php elseif ($eDono): ?>
                    <a class="botao principal largura-total"
                       href="minha_conta.php">
                        Gerir o meu anúncio
                    </a>

                <?php else: ?>
                    <form action="iniciar_conversa.php" method="post">
                        <input type="hidden" name="csrf_token"
                               value="<?= e(tokenCsrf()) ?>">
                        <input type="hidden" name="anuncio_id"
                               value="<?= (int) $anuncio['id'] ?>">

                        <button class="botao principal largura-total"
                                type="submit">
                            Enviar mensagem ao vendedor
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="caixa-vendedor">
                <h2>Sobre o vendedor</h2>

                <div class="vendedor-nome">
                    <div class="avatar-vendedor">
                        <?= e(mb_strtoupper(mb_substr(
                            $anuncio['tipo_conta'] === 'stand'
                                ? ($anuncio['nome_stand'] ?: $anuncio['vendedor'])
                                : $anuncio['vendedor'],
                            0,
                            1
                        ))) ?>
                    </div>

                    <div>
                        <strong>
                            <?= e(
                                $anuncio['tipo_conta'] === 'stand'
                                    ? ($anuncio['nome_stand'] ?: $anuncio['vendedor'])
                                    : $anuncio['vendedor']
                            ) ?>
                        </strong>

                        <p>
                            <?= $anuncio['tipo_conta'] === 'stand'
                                ? 'Stand profissional'
                                : 'Vendedor particular' ?>
                        </p>
                    </div>
                </div>

                <p class="data-anuncio">
                    Membro desde
                    <?= date('m/Y', strtotime($anuncio['vendedor_desde'])) ?>
                </p>
            </div>

        </aside>
    </div>
</section>

<script>
document.querySelectorAll('.miniatura').forEach(botao => {
    botao.addEventListener('click', () => {
        document.getElementById('imagem-principal').src =
            botao.dataset.imagem;
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
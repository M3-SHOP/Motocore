
<?php
require_once __DIR__ . '/includes/funcoes.php';

$tituloPagina = 'Comprar e vender motas';

$pesquisa = trim($_GET['q'] ?? '');
$pesquisa = mb_substr($pesquisa, 0, 100);

$sql = "
    SELECT
        a.id,
        a.titulo,
        a.marca,
        a.modelo,
        a.preco,
        a.ano,
        a.quilometros,
        a.localidade,
        a.distrito,
        a.criado_em,
        u.nome AS vendedor,
        u.tipo_conta,
        u.nome_stand,
        (
            SELECT i.caminho
            FROM imagens_anuncios i
            WHERE i.anuncio_id = a.id
            ORDER BY i.principal DESC, i.ordem ASC, i.id ASC
            LIMIT 1
        ) AS imagem
    FROM anuncios a
    INNER JOIN utilizadores u ON u.id = a.utilizador_id
    WHERE a.estado_anuncio = 'ativo'
      AND a.moderacao = 'publicado'
      AND u.estado = 'ativo'
";

$parametros = [];

if ($pesquisa !== '') {
    $sql .= "
        AND (
            a.titulo LIKE :q1
            OR a.marca LIKE :q2
            OR a.modelo LIKE :q3
            OR a.localidade LIKE :q4
            OR a.distrito LIKE :q5
        )
    ";

    $termo = '%' . $pesquisa . '%';

    $parametros = [
        ':q1' => $termo,
        ':q2' => $termo,
        ':q3' => $termo,
        ':q4' => $termo,
        ':q5' => $termo,
    ];
}

$sql .= ' ORDER BY a.criado_em DESC LIMIT 60';

$stmt = $pdo->prepare($sql);
$stmt->execute($parametros);
$anuncios = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="hero-texto">
        <span class="etiqueta">O teu marketplace de motas</span>
        <h1>Encontra a tua próxima mota.</h1>
        <p>
            Descobre anúncios de particulares e stands de todo o país.
            Publica gratuitamente e fala diretamente com os vendedores.
        </p>

        <?php if (autenticado()): ?>
            <a class="botao principal" href="publicar.php">
                + Publicar anúncio
            </a>
        <?php else: ?>
            <a class="botao principal" href="registo.php">
                Começar a vender
            </a>
        <?php endif; ?>
    </div>
</section>

<section class="secao-anuncios">
    <div class="titulo-secao">
        <div>
            <h2>
                <?= $pesquisa !== '' ? 'Resultados da pesquisa' : 'Anúncios recentes' ?>
            </h2>
            <p>
                <?= count($anuncios) ?> anúncios encontrados nesta página
            </p>
        </div>
    </div>

    <?php if (!$anuncios): ?>
        <div class="estado-vazio">
            <h3>Ainda não encontrámos anúncios</h3>
            <p>
                <?php if ($pesquisa !== ''): ?>
                    Experimenta pesquisar por outra marca ou modelo.
                <?php else: ?>
                    Os anúncios publicados aparecerão aqui.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="grelha-anuncios">
            <?php foreach ($anuncios as $anuncio): ?>
                <article class="cartao-anuncio">
                    <a class="imagem-anuncio"
                       href="anuncio.php?id=<?= (int) $anuncio['id'] ?>">

                        <?php if ($anuncio['imagem']): ?>
                            <img
                                src="<?= e($anuncio['imagem']) ?>"
                                alt="<?= e($anuncio['titulo']) ?>"
                                loading="lazy"
                            >
                        <?php else: ?>
                            <div class="sem-imagem">
                                <span>Sem fotografia</span>
                            </div>
                        <?php endif; ?>
                    </a>

                    <div class="detalhes-anuncio">
                        <h3>
                            <a href="anuncio.php?id=<?= (int) $anuncio['id'] ?>">
                                <?= e($anuncio['titulo']) ?>
                            </a>
                        </h3>

                        <p class="preco">
                            <?= number_format(
                                (float) $anuncio['preco'],
                                2,
                                ',',
                                '.'
                            ) ?> €
                        </p>

                        <p class="especificacoes">
                            <?= (int) $anuncio['ano'] ?>
                            · <?= number_format((int) $anuncio['quilometros'], 0, ',', '.') ?> km
                        </p>

                        <p class="localizacao">
                            <?= e($anuncio['localidade']) ?>,
                            <?= e($anuncio['distrito']) ?>
                        </p>

                        <p class="vendedor">
                            <?= e(
                                $anuncio['tipo_conta'] === 'stand'
                                    ? ($anuncio['nome_stand'] ?: $anuncio['vendedor'])
                                    : $anuncio['vendedor']
                            ) ?>
                            <?php if ($anuncio['tipo_conta'] === 'stand'): ?>
                                <span class="selo-stand">Stand</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

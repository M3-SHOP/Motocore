<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/funcoes.php';

$tituloPagina = 'Motocore — O teu mundo sobre duas rodas';

$pesquisa = $_GET['q'] ?? '';
$pesquisa = is_string($pesquisa) ? trim($pesquisa) : '';
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
    INNER JOIN utilizadores u
        ON u.id = a.utilizador_id
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
$anuncios = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Sem pesquisa:
 * - os primeiros quatro anúncios aparecem em destaque;
 * - os restantes aparecem na secção de recentes.
 *
 * Com pesquisa:
 * - todos os resultados aparecem na mesma secção.
 */
if ($pesquisa === '') {
    $destaques = array_slice($anuncios, 0, 4);
    $recentes = array_slice($anuncios, 4);
} else {
    $destaques = [];
    $recentes = $anuncios;
}

require __DIR__ . '/includes/header.php';
?>

<main class="pagina-inicial">

    <!-- DESTAQUE PRINCIPAL -->
    <section class="hero-motocore">

        <img
            class="hero-motocore-imagem"
            src="https://images.unsplash.com/photo-1558981806-ec527fa84c39?auto=format&fit=crop&w=1800&q=85"
            alt="Mota para os amantes das duas rodas"
            fetchpriority="high"
        >

        <div class="hero-motocore-overlay" aria-hidden="true"></div>

        <div class="hero-motocore-conteudo">

            <span class="hero-motocore-etiqueta">
                O teu próximo destino começa aqui
            </span>

            <h1>
                A tua próxima<br>
                <span>mota espera por ti.</span>
            </h1>

            <p>
                Descobre motas incríveis, compara oportunidades
                e encontra a máquina ideal para ti.
            </p>

            <form
                action="index.php"
                method="get"
                class="pesquisa-motocore"
                role="search"
            >
                <span class="pesquisa-icone" aria-hidden="true">⌕</span>

                <input
                    type="search"
                    name="q"
                    maxlength="100"
                    placeholder="Marca, modelo ou localização..."
                    value="<?= e($pesquisa) ?>"
                    aria-label="Pesquisar motas"
                >

                <button type="submit">Pesquisar</button>
            </form>

            <div class="hero-motocore-acoes">

                <?php if (autenticado()): ?>
                    <a href="publicar.php" class="botao principal">
                        + Publicar anúncio
                    </a>
                <?php else: ?>
                    <a href="registo.php" class="botao principal">
                        Começar a vender
                    </a>
                <?php endif; ?>

                <span class="hero-nota">
                    Encontra a mota certa para ti.
                </span>

            </div>
        </div>
    </section>

    <?php if ($pesquisa === ''): ?>

        <!-- MOTAS EM DESTAQUE -->
        <section class="secao-motocore">

            <div class="cabecalho-motocore">
                <div>
                    <span class="subtitulo-motocore">
                        SELEÇÃO MOTOCORE
                    </span>

                    <h2>Motas em destaque</h2>

                    <p>
                        Descobre algumas das oportunidades mais recentes.
                    </p>
                </div>
            </div>

            <?php if (empty($destaques)): ?>

                <div class="estado-vazio">
                    <h3>Em breve, novas motas</h3>
                    <p>
                        Assim que existirem anúncios publicados,
                        aparecerão aqui.
                    </p>
                </div>

            <?php else: ?>

                <div class="grelha-anuncios">
                    <?php foreach ($destaques as $anuncio): ?>
                        <?php
                        require __DIR__ . '/includes/cartao_anuncio.php';
                        ?>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

    <!-- ANÚNCIOS RECENTES OU RESULTADOS DA PESQUISA -->
    <section class="secao-motocore">

        <div class="cabecalho-motocore">
            <div>
                <span class="subtitulo-motocore">
                    <?= $pesquisa !== ''
                        ? 'RESULTADOS DA PESQUISA'
                        : 'ACABARAM DE CHEGAR' ?>
                </span>

                <h2>
                    <?= $pesquisa !== ''
                        ? 'Motas encontradas'
                        : 'Anúncios recentes' ?>
                </h2>

                <p>
                    <?= count($recentes) ?>
                    <?= count($recentes) === 1
                        ? 'anúncio encontrado'
                        : 'anúncios encontrados' ?>
                </p>
            </div>

            <?php if ($pesquisa !== ''): ?>
                <a href="index.php" class="link-ver-todos">
                    Limpar pesquisa
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($recentes)): ?>

            <div class="estado-vazio">
                <h3>
                    <?= $pesquisa !== ''
                        ? 'Não encontrámos motas'
                        : 'Ainda não há mais anúncios' ?>
                </h3>

                <p>
                    <?php if ($pesquisa !== ''): ?>
                        Experimenta pesquisar por outra marca,
                        modelo ou localização.
                    <?php else: ?>
                        Publica novos anúncios para os veres aqui.
                    <?php endif; ?>
                </p>

                <?php if ($pesquisa !== ''): ?>
                    <a href="index.php" class="botao principal">
                        Ver todos os anúncios
                    </a>
                <?php elseif (autenticado()): ?>
                    <a href="publicar.php" class="botao principal">
                        Publicar anúncio
                    </a>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="grelha-anuncios">
                <?php foreach ($recentes as $anuncio): ?>
                    <?php
                    require __DIR__ . '/includes/cartao_anuncio.php';
                    ?>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </section>

</main>

<?php require __DIR__ . '/includes/footer.php'; ?>

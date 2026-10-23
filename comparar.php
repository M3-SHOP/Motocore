<?php
require_once __DIR__ . '/includes/funcoes.php';

$selecionadas = [];
$ids = $_GET['motas'] ?? [];

if (!is_array($ids)) {
    $ids = [];
}

$ids = array_slice(array_unique(array_filter(
    array_map('intval', $ids),
    fn($id) => $id > 0
)), 0, 3);

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
    ORDER BY a.criado_em DESC
    LIMIT 200
";

$stmt = $pdo->query($sql);
$anuncios = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare("
        SELECT
            a.id, a.titulo, a.marca, a.modelo, a.preco,
            a.ano, a.quilometros, a.localidade, a.distrito,
            (
                SELECT i.caminho
                FROM imagens_anuncios i
                WHERE i.anuncio_id = a.id
                ORDER BY i.principal DESC, i.ordem ASC, i.id ASC
                LIMIT 1
            ) AS imagem
        FROM anuncios a
        INNER JOIN utilizadores u ON u.id = a.utilizador_id
        WHERE a.id IN ($placeholders)
          AND a.estado_anuncio = 'ativo'
          AND a.moderacao = 'publicado'
          AND u.estado = 'ativo'
    ");

    $stmt->execute($ids);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $porId = [];
    foreach ($resultados as $mota) {
        $porId[(int)$mota['id']] = $mota;
    }

    foreach ($ids as $id) {
        if (isset($porId[$id])) {
            $selecionadas[] = $porId[$id];
        }
    }
}

function valorMota($valor): string
{
    return ($valor === null || $valor === '')
        ? 'Não indicado'
        : e((string)$valor);
}

require __DIR__ . '/includes/header.php';
?>

<style>
.comparador {
    max-width: 1200px;
    margin: auto;
    padding: 35px 0 60px;
    color: var(--texto);
}

.comparador h1 {
    font-size: clamp(2rem, 5vw, 3rem);
    margin-bottom: 10px;
}

.comparador .descricao {
    color: var(--texto-secundario);
    margin-bottom: 30px;
    line-height: 1.7;
}

.painel {
    background: var(--branco);
    border: 1px solid var(--borda);
    border-radius: var(--raio);
    padding: 24px;
    margin-bottom: 30px;
}

.seletores {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
}

.campo label {
    display: block;
    margin-bottom: 8px;
    font-weight: bold;
}

.campo select {
    width: 100%;
    padding: 13px;
    border-radius: 8px;
    border: 1px solid var(--borda);
    background: var(--fundo);
    color: var(--texto);
}

.campo select:focus {
    outline: none;
    border-color: var(--cor-principal);
}

.campo select option {
    background: var(--branco);
    color: var(--texto);
}

.botao {
    display: inline-block;
    padding: 12px 20px;
    margin-top: 20px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--cor-principal);
    color: var(--texto);
    text-decoration: none;
    font-weight: bold;
    cursor: pointer;
}

.botao:hover {
    background: var(--cor-principal-hover);
}

.botao-limpar {
    background: var(--branco);
    border-color: var(--borda);
    color: var(--texto);
}

.botao-limpar:hover {
    background: var(--fundo);
    border-color: var(--cor-principal);
}

.tabela-wrapper {
    overflow-x: auto;
    border-radius: var(--raio);
    border: 1px solid var(--borda);
}

.tabela {
    width: 100%;
    min-width: 600px;
    border-collapse: collapse;
    background: var(--branco);
}

.tabela th,
.tabela td {
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid var(--borda);
}

.tabela thead th {
    background: var(--branco);
    vertical-align: top;
}

.tabela tbody th {
    color: var(--texto-secundario);
}

.tabela tbody tr:hover {
    background: var(--fundo);
}

.foto-mota {
    width: 100%;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    border-radius: 8px;
    margin-bottom: 12px;
    background: var(--fundo);
}

.sem-foto {
    aspect-ratio: 4 / 3;
    display: grid;
    place-items: center;
    background: var(--fundo);
    border-radius: 8px;
    margin-bottom: 12px;
    color: var(--texto-secundario);
}

.nome-mota {
    display: block;
    color: var(--texto);
    text-decoration: none;
    font-weight: bold;
    margin-bottom: 10px;
}

.nome-mota:hover {
    color: var(--cor-principal);
}

.preco {
    color: var(--cor-principal);
    font-size: 1.2rem;
    font-weight: bold;
}

.vazio {
    text-align: center;
    padding: 30px 15px;
    border: 1px dashed var(--borda);
    border-radius: var(--raio);
    background: var(--branco);
    line-height: 1.7;
}

@media (max-width: 700px) {
    .comparador {
        padding: 25px 0;
    }

    .painel {
        padding: 16px;
    }

    .seletores {
        grid-template-columns: 1fr;
    }
}
</style>
<main class="comparador">

    <h1>Comparar motas</h1>

    <p class="descricao">
        Escolhe até três motas e compara preços, características
        e quilómetros para encontrares a melhor opção.
    </p>

    <section class="painel">
        <h2>Seleciona as motas</h2>

        <?php if (empty($anuncios)): ?>

            <p>Não existem anúncios disponíveis para comparar.</p>

        <?php else: ?>

            <form action="comparar.php" method="get">
                <div class="seletores">

                    <?php for ($i = 0; $i < 3; $i++): ?>
                        <div class="campo">
                            <label for="mota<?= $i ?>">
                                Mota <?= $i + 1 ?>
                            </label>

                            <select id="mota<?= $i ?>" name="motas[]">
                                <option value="">Escolher mota...</option>

                                <?php foreach ($anuncios as $anuncio): ?>
                                    <option
                                        value="<?= (int)$anuncio['id'] ?>"
                                        <?= isset($ids[$i]) &&
                                            $ids[$i] === (int)$anuncio['id']
                                            ? 'selected' : '' ?>
                                    >
                                        <?= e($anuncio['titulo']) ?>
                                        — <?= number_format(
                                            (float)$anuncio['preco'],
                                            2, ',', '.'
                                        ) ?> €
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endfor; ?>

                </div>

                <button class="botao" type="submit">
                    Comparar motas
                </button>

                <a class="botao" href="comparar.php"
                   style="background:#444">
                    Limpar
                </a>
            </form>

        <?php endif; ?>
    </section>

    <?php if (count($selecionadas) > 0): ?>

        <h2>Resultado da comparação</h2>

        <div class="tabela-wrapper">
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Característica</th>

                        <?php foreach ($selecionadas as $mota): ?>
                            <th>
                                <?php if (!empty($mota['imagem'])): ?>
                                    <img
                                        class="foto-mota"
                                        src="<?= e($mota['imagem']) ?>"
                                        alt="<?= e($mota['titulo']) ?>"
                                    >
                                <?php else: ?>
                                    <div class="sem-foto">
                                        Sem fotografia
                                    </div>
                                <?php endif; ?>

                                <a class="nome-mota"
                                   href="anuncio.php?id=<?= (int)$mota['id'] ?>">
                                    <?= e($mota['titulo']) ?>
                                </a>

                                <div class="preco">
                                    <?= number_format(
                                        (float)$mota['preco'],
                                        2, ',', '.'
                                    ) ?> €
                                </div>

                                <a class="botao"
                                   href="anuncio.php?id=<?= (int)$mota['id'] ?>"
                                   style="margin-top:12px">
                                    Ver anúncio
                                </a>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php
                    $caracteristicas = [
                        'Marca' => 'marca',
                        'Modelo' => 'modelo',
                        'Ano' => 'ano',
                        'Quilómetros' => 'quilometros',
                        'Localidade' => 'localidade',
                        'Distrito' => 'distrito'
                    ];
                    ?>

                    <?php foreach ($caracteristicas as $nome => $coluna): ?>
                        <tr>
                            <th><?= e($nome) ?></th>

                            <?php foreach ($selecionadas as $mota): ?>
                                <td>
                                    <?php if (
                                        $coluna === 'quilometros' &&
                                        $mota[$coluna] !== null &&
                                        $mota[$coluna] !== ''
                                    ): ?>
                                        <?= number_format(
                                            (float)$mota[$coluna],
                                            0, ',', '.'
                                        ) ?> km
                                    <?php else: ?>
                                        <?= valorMota($mota[$coluna]) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>

                    <tr>
                        <th>Diferença de preço</th>

                        <?php
                        $precos = array_map(
                            fn($m) => (float)$m['preco'],
                            $selecionadas
                        );
                        $minimo = min($precos);
                        ?>

                        <?php foreach ($selecionadas as $mota): ?>
                            <td>
                                <?php $dif = (float)$mota['preco'] - $minimo; ?>

                                <?php if (abs($dif) < 0.005): ?>
                                    <strong>Preço mais baixo</strong>
                                <?php else: ?>
                                    +<?= number_format($dif, 2, ',', '.') ?> €
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>

    <?php else: ?>

        <div class="vazio">
            <h2>A tua comparação começa aqui</h2>
            <p>
                Seleciona duas ou três motas e carrega em
                <strong>Comparar motas</strong>.
            </p>
        </div>

    <?php endif; ?>

</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
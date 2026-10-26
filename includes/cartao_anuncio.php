
<article class="cartao-anuncio">
    <a class="imagem-anuncio"
       href="anuncio.php?id=<?= (int) $anuncio['id'] ?>">

        <?php if (!empty($anuncio['imagem'])): ?>
            <img
                src="<?= e($anuncio['imagem']) ?>"
                alt="<?= e($anuncio['titulo']) ?>"
                loading="lazy"
            >
        <?php else: ?>
            <div class="sem-imagem">Sem fotografia</div>
        <?php endif; ?>
    </a>

    <div class="detalhes-anuncio">
        <h3>
            <a href="anuncio.php?id=<?= (int) $anuncio['id'] ?>">
                <?= e($anuncio['titulo']) ?>
            </a>
        </h3>

        <p class="preco">
            <?= number_format((float) $anuncio['preco'], 2, ',', '.') ?> €
        </p>

        <p class="especificacoes">
            <?= (int) $anuncio['ano'] ?>
            · <?= number_format((int) $anuncio['quilometros'], 0, ',', '.') ?> km
        </p>

        <p class="localizacao">
            <?= e($anuncio['localidade'] ?? '') ?>
            <?php if (!empty($anuncio['distrito'])): ?>
                , <?= e($anuncio['distrito']) ?>
            <?php endif; ?>
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

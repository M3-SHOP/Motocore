
<?php
require_once __DIR__ . '/includes/funcoes.php';

if (!autenticado()) {
    redirecionar('login.php');
}

$utilizadorId = (int) $_SESSION['utilizador_id'];

$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.anuncio_id,
        c.atualizado_em,
        a.titulo,
        a.preco,
        CASE
            WHEN c.comprador_id = :uid1 THEN vendedor.nome
            ELSE comprador.nome
        END AS outra_pessoa,
        (
            SELECT m.mensagem
            FROM mensagens m
            WHERE m.conversa_id = c.id
            ORDER BY m.id DESC
            LIMIT 1
        ) AS ultima_mensagem,
        (
            SELECT COUNT(*)
            FROM mensagens m2
            WHERE m2.conversa_id = c.id
              AND m2.remetente_id <> :uid2
              AND m2.lida_em IS NULL
        ) AS nao_lidas
    FROM conversas c
    INNER JOIN anuncios a ON a.id = c.anuncio_id
    INNER JOIN utilizadores comprador
        ON comprador.id = c.comprador_id
    INNER JOIN utilizadores vendedor
        ON vendedor.id = c.vendedor_id
    WHERE c.comprador_id = :uid3
       OR c.vendedor_id = :uid4
    ORDER BY c.atualizado_em DESC
");

$stmt->execute([
    ':uid1' => $utilizadorId,
    ':uid2' => $utilizadorId,
    ':uid3' => $utilizadorId,
    ':uid4' => $utilizadorId
]);

$conversas = $stmt->fetchAll();

$tituloPagina = 'As minhas mensagens';
require __DIR__ . '/includes/header.php';
?>

<section class="mensagens-pagina">
    <h1>As minhas mensagens</h1>
    <p class="texto-secundario">
        Fala com compradores e vendedores através do MotoMarket.
    </p>

    <?php if (!$conversas): ?>
        <div class="estado-vazio">
            <h3>Ainda não tens conversas</h3>
            <p>Abre um anúncio e contacta o vendedor para começar.</p>
            <a class="botao principal" href="index.php">
                Explorar motas
            </a>
        </div>
    <?php else: ?>
        <div class="lista-conversas">
            <?php foreach ($conversas as $c): ?>
                <a class="item-conversa"
                   href="conversa.php?id=<?= (int) $c['id'] ?>">

                    <div class="conversa-avatar">
                        <?= e(mb_strtoupper(mb_substr(
                            $c['outra_pessoa'], 0, 1
                        ))) ?>
                    </div>

                    <div class="conversa-info">
                        <strong><?= e($c['outra_pessoa']) ?></strong>
                        <span><?= e($c['titulo']) ?></span>
                        <p>
                            <?= e($c['ultima_mensagem'] ?: 'Conversa iniciada. Envia uma mensagem.') ?>
                        </p>
                    </div>

                    <div class="conversa-meta">
                        <span>
                            <?= date('d/m H:i', strtotime($c['atualizado_em'])) ?>
                        </span>

                        <?php if ((int) $c['nao_lidas'] > 0): ?>
                            <span class="contador-nao-lidas">
                                <?= (int) $c['nao_lidas'] ?>
                            </span>
                        <?php endif; ?>
                    </div>

                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

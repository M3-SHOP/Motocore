
<?php
require_once __DIR__ . '/includes/funcoes.php';

if (!autenticado()) {
    redirecionar('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

validarCsrf();

$anuncioId = filter_input(INPUT_POST, 'anuncio_id', FILTER_VALIDATE_INT);

if (!$anuncioId || $anuncioId < 1) {
    http_response_code(400);
    exit('Anúncio inválido.');
}

$compradorId = (int) $_SESSION['utilizador_id'];

$stmt = $pdo->prepare("
    SELECT id, utilizador_id
    FROM anuncios
    WHERE id = ?
      AND estado_anuncio = 'ativo'
      AND moderacao = 'publicado'
");
$stmt->execute([$anuncioId]);
$anuncio = $stmt->fetch();

if (!$anuncio) {
    http_response_code(404);
    exit('Este anúncio já não está disponível.');
}

$vendedorId = (int) $anuncio['utilizador_id'];

if ($compradorId === $vendedorId) {
    redirecionar('anuncio.php?id=' . $anuncioId);
}

// Reutilizar a conversa, caso já exista
$stmt = $pdo->prepare("
    INSERT INTO conversas (anuncio_id, comprador_id, vendedor_id)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
");
$stmt->execute([$anuncioId, $compradorId, $vendedorId]);

$conversaId = (int) $pdo->lastInsertId();

redirecionar('conversa.php?id=' . $conversaId);

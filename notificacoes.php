
<?php
require_once __DIR__ . '/includes/funcoes.php';

if (!autenticado()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$utilizadorId = (int) $_SESSION['utilizador_id'];

// Total de mensagens não lidas recebidas.
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM mensagens m
    INNER JOIN conversas c ON c.id = m.conversa_id
    WHERE m.remetente_id <> ?
      AND m.lida_em IS NULL
      AND (c.comprador_id = ? OR c.vendedor_id = ?)
");

$stmt->execute([
    $utilizadorId,
    $utilizadorId,
    $utilizadorId
]);

$total = (int) $stmt->fetchColumn();

// Mensagem mais recente ainda não lida.
$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.mensagem,
        m.conversa_id,
        u.nome AS remetente
    FROM mensagens m
    INNER JOIN conversas c ON c.id = m.conversa_id
    INNER JOIN utilizadores u ON u.id = m.remetente_id
    WHERE m.remetente_id <> ?
      AND m.lida_em IS NULL
      AND (c.comprador_id = ? OR c.vendedor_id = ?)
    ORDER BY m.id DESC
    LIMIT 1
");

$stmt->execute([
    $utilizadorId,
    $utilizadorId,
    $utilizadorId
]);

$ultima = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'total' => $total,
    'ultima' => $ultima ? [
        'id' => (int) $ultima['id'],
        'remetente' => $ultima['remetente'],
        'mensagem' => mb_substr($ultima['mensagem'], 0, 100),
        'conversa_id' => (int) $ultima['conversa_id']
    ] : null
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

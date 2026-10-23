
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

// API AJAX DO CHAT: enviar, receber, entregar e ler mensagens.

function responderJson(array $dados): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

// RECEBER MENSAGENS E ESTADOS
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['ajax'] ?? '') === 'mensagens'
) {
    $depois = max(0, (int) ($_GET['depois'] ?? 0));
    $ativa = ($_GET['ativa'] ?? '1') === '1';

    // Mensagens recebidas pelo destinatário.
    $stmt = $pdo->prepare("
        UPDATE mensagens
        SET entregue_em = COALESCE(
            entregue_em, CURRENT_TIMESTAMP
        )
        WHERE conversa_id = ?
          AND remetente_id <> ?
          AND entregue_em IS NULL
    ");
    $stmt->execute([$conversaId, $utilizadorId]);

    // Só marcar como lidas se a conversa estiver visível.
    if ($ativa) {
        $stmt = $pdo->prepare("
            UPDATE mensagens
            SET lida_em = CURRENT_TIMESTAMP
            WHERE conversa_id = ?
              AND remetente_id <> ?
              AND lida_em IS NULL
        ");
        $stmt->execute([$conversaId, $utilizadorId]);
    }

    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.mensagem,
            m.remetente_id,
            m.criado_em,
            m.entregue_em,
            m.lida_em,
            u.nome AS remetente
        FROM mensagens m
        INNER JOIN utilizadores u ON u.id = m.remetente_id
        WHERE m.conversa_id = ?
          AND m.id > ?
        ORDER BY m.id ASC
        LIMIT 100
    ");
    $stmt->execute([$conversaId, $depois]);

    $novas = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $novas[] = [
            'id' => (int) $m['id'],
            'mensagem' => $m['mensagem'],
            'remetente_id' => (int) $m['remetente_id'],
            'remetente' => $m['remetente'],
            'hora' => date('H:i', strtotime($m['criado_em'])),
            'entregue' => $m['entregue_em'] !== null,
            'lida' => $m['lida_em'] !== null
        ];
    }

    // Estado das últimas mensagens enviadas pelo utilizador.
    $stmt = $pdo->prepare("
        SELECT id, entregue_em, lida_em
        FROM mensagens
        WHERE conversa_id = ?
          AND remetente_id = ?
        ORDER BY id DESC
        LIMIT 100
    ");
    $stmt->execute([$conversaId, $utilizadorId]);

    $estados = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $estados[] = [
            'id' => (int) $m['id'],
            'entregue' => $m['entregue_em'] !== null,
            'lida' => $m['lida_em'] !== null
        ];
    }

    // Verificar se a outra pessoa está a escrever.
    $stmt = $pdo->prepare("
        SELECT u.nome
        FROM estado_escrita e
        INNER JOIN utilizadores u ON u.id = e.utilizador_id
        WHERE e.conversa_id = ?
        AND e.utilizador_id <> ?
        AND e.atualizado_em >= DATE_SUB(
            CURRENT_TIMESTAMP, INTERVAL 5 SECOND
        )
        LIMIT 1
    ");

    $stmt->execute([$conversaId, $utilizadorId]);
    $nomeAEscrever = $stmt->fetchColumn();

    responderJson([
        'mensagens' => $novas,
        'estados' => $estados,
        'escrevendo' => $nomeAEscrever !== false,
        'nome_a_escrever' => $nomeAEscrever ?: ''
    ]);
}

// ENVIAR MENSAGEM OU ATUALIZAR ESTADO DE ESCRITA
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax'])
) {
    if (!hash_equals(
        (string) tokenCsrf(),
        (string) ($_POST['csrf_token'] ?? '')
    )) {
        http_response_code(403);
        responderJson(['erro' => 'Token de segurança inválido.']);
    }

    $acao = (string) $_POST['ajax'];

    if ($acao === 'escrevendo') {
        $stmt = $pdo->prepare("
            INSERT INTO estado_escrita
                (conversa_id, utilizador_id, atualizado_em)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                atualizado_em = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$conversaId, $utilizadorId]);

        responderJson(['ok' => true]);
    }

    if ($acao === 'enviar') {
        $texto = trim((string) ($_POST['mensagem'] ?? ''));

        if ($texto === '' || mb_strlen($texto) > 5000) {
            http_response_code(422);
            responderJson([
                'erro' => 'A mensagem deve ter entre 1 e 5000 caracteres.'
            ]);
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO mensagens
                    (conversa_id, remetente_id, mensagem)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$conversaId, $utilizadorId, $texto]);

            $mensagemId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                UPDATE conversas
                SET atualizado_em = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$conversaId]);

            $pdo->commit();

            responderJson([
                'ok' => true,
                'mensagem' => [
                    'id' => $mensagemId,
                    'mensagem' => $texto,
                    'remetente_id' => $utilizadorId,
                    'remetente' => $_SESSION['utilizador_nome'] ?? '',
                    'hora' => date('H:i'),
                    'entregue' => false,
                    'lida' => false
                ]
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            http_response_code(500);
            responderJson([
                'erro' => 'Não foi possível enviar a mensagem.'
            ]);
        }
    }

    http_response_code(400);
    responderJson(['erro' => 'Ação inválida.']);
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

                <article
                    class="mensagem <?= $minha
                        ? 'mensagem-minha'
                        : 'mensagem-outra' ?>"
                    data-id="<?= (int) $m['id'] ?>"
                >

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

    <div id="indicador-escrita" class="mensagem mensagem-outra"
        aria-live="polite" hidden>
        <strong id="nome-quem-escreve"></strong>
        <p><span class="pontos-escrita">● ● ●</span> está a escrever...</p>
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

    <div class="linha-envio">
        <textarea
            id="mensagem"
            name="mensagem"
            rows="2"
            maxlength="5000"
            required
            placeholder="Escreve uma mensagem..."
        ></textarea>

        <button
            class="botao principal botao-enviar"
            type="submit"
            aria-label="Enviar mensagem"
            title="Enviar mensagem"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="m22 2-7 20-4-9-9-4Z"/>
                <path d="M22 2 11 13"/>
            </svg>
        </button>
    </div>
    </form>

</section>

<script>
(() => {
    const caixa = document.getElementById('caixa-mensagens');
    const formulario = document.querySelector('.formulario-mensagem');
    const textarea = document.getElementById('mensagem');
    const indicador = document.getElementById('indicador-escrita');

    if (!caixa || !formulario || !textarea) return;

    const conversaId = <?= (int) $conversaId ?>;
    const utilizadorId = <?= (int) $utilizadorId ?>;
    const csrf = formulario.querySelector(
        '[name="csrf_token"]'
    ).value;

    let ultimaId = 0;
    let ocupado = false;
    let temporizadorEscrita;

    caixa.querySelectorAll('[data-id]').forEach(el => {
        ultimaId = Math.max(ultimaId, Number(el.dataset.id));
    });

    caixa.scrollTop = caixa.scrollHeight;

    function estadoTexto(m) {
        if (m.lida) return '✓✓ Vista';
        if (m.entregue) return '✓✓ Entregue';
        return '✓ Enviada';
    }

    function adicionarMensagem(m) {
        if (caixa.querySelector(`[data-id="${m.id}"]`)) return;

        caixa.querySelector('.sem-mensagens')?.remove();

        const minha = Number(m.remetente_id) === utilizadorId;
        const artigo = document.createElement('article');

        artigo.className = 'mensagem ' +
            (minha ? 'mensagem-minha' : 'mensagem-outra');
        artigo.dataset.id = m.id;

        if (!minha) {
            const nome = document.createElement('strong');
            nome.textContent = m.remetente;
            artigo.appendChild(nome);
        }

        const texto = document.createElement('p');
        texto.textContent = m.mensagem;
        texto.style.whiteSpace = 'pre-wrap';
        artigo.appendChild(texto);

        const hora = document.createElement('time');
        hora.textContent = m.hora;
        artigo.appendChild(hora);

        if (minha) {
            const estado = document.createElement('span');
            estado.className = 'estado-mensagem';
            estado.textContent = ' · ' + estadoTexto(m);
            hora.appendChild(estado);
        }

        caixa.appendChild(artigo);
        ultimaId = Math.max(ultimaId, Number(m.id));
        caixa.scrollTop = caixa.scrollHeight;
    }

    function atualizarEstados(estados) {
        estados.forEach(m => {
            const artigo = caixa.querySelector(
                `[data-id="${m.id}"]`
            );

            if (!artigo) return;

            let estado = artigo.querySelector('.estado-mensagem');

            if (!estado) {
                estado = document.createElement('span');
                estado.className = 'estado-mensagem';
                artigo.querySelector('time')?.appendChild(estado);
            }

            estado.textContent = ' · ' + estadoTexto(m);
        });
    }

    async function consultar() {
        if (ocupado || document.hidden) return;
        ocupado = true;

        try {
            const url = new URL('conversa.php', location.href);
            url.searchParams.set('id', conversaId);
            url.searchParams.set('ajax', 'mensagens');
            url.searchParams.set('depois', ultimaId);
            url.searchParams.set('ativa', '1');

            const resposta = await fetch(url, {
                cache: 'no-store',
                credentials: 'same-origin'
            });

            if (!resposta.ok) throw new Error('Falha ao consultar.');

            const dados = await resposta.json();

            dados.mensagens.forEach(adicionarMensagem);
            atualizarEstados(dados.estados);

            indicador.hidden = !dados.escrevendo;

            if (dados.escrevendo) {
                document.getElementById('nome-quem-escreve').textContent =
                    dados.nome_a_escrever;
            }
        } catch (erro) {
            console.error('Erro no chat:', erro);
        } finally {
            ocupado = false;
        }
    }

    async function enviarEstado(acao, dados = {}) {
        const formData = new FormData();

        formData.set('ajax', acao);
        formData.set('csrf_token', csrf);

        Object.entries(dados).forEach(([chave, valor]) => {
            formData.set(chave, valor);
        });

        const resposta = await fetch(
            `conversa.php?id=${conversaId}`,
            {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }
        );

        const resultado = await resposta.json();

        if (!resposta.ok) {
            throw new Error(resultado.erro || 'Ocorreu um erro.');
        }

        return resultado;
    }

    // Enviar sem recarregar a página.
    async function enviarMensagem() {
        const texto = textarea.value.trim();

        if (!texto) return;

        textarea.disabled = true;

        try {
            const resultado = await enviarEstado('enviar', {
                mensagem: texto
            });

            adicionarMensagem(resultado.mensagem);
            textarea.value = '';
            await consultar();
        } catch (erro) {
            alert(erro.message);
        } finally {
            textarea.disabled = false;
            textarea.focus();
        }
    }

    formulario.addEventListener('submit', event => {
        event.preventDefault();
        enviarMensagem();
    });

    // Enter envia; Shift+Enter faz uma nova linha.
    textarea.addEventListener('keydown', event => {
        if (
            event.key === 'Enter' &&
            !event.shiftKey &&
            !event.isComposing
        ) {
            event.preventDefault();
            formulario.requestSubmit();
        }
    });

    // Notificar o servidor quando o utilizador escreve.
    textarea.addEventListener('input', () => {
        clearTimeout(temporizadorEscrita);

        if (textarea.value.trim() !== '') {
            enviarEstado('escrevendo').catch(() => {});
        }
    });

    document.addEventListener('visibilitychange', consultar);

    consultar();
    setInterval(consultar, 1500);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

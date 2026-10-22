
<?php
require_once __DIR__ . '/includes/funcoes.php';

if (!autenticado()) {
    redirecionar('login.php');
}

$stmtConta = $pdo->prepare(
    'SELECT tipo_conta, stand_aprovado
     FROM utilizadores
     WHERE id = ?
     LIMIT 1'
);
$stmtConta->execute([$_SESSION['utilizador_id']]);
$contaPublicacao = $stmtConta->fetch(PDO::FETCH_ASSOC);

if (!$contaPublicacao) {
    http_response_code(403);
    exit('Não foi possível validar a tua conta.');
}

if (
    $contaPublicacao['tipo_conta'] === 'stand' &&
    (int) $contaPublicacao['stand_aprovado'] !== 1
) {
    $tituloPagina = 'Validação do stand';
    require __DIR__ . '/includes/header.php';
    ?>
    <main class="formulario-container publicar-container">
        <div class="publicar-pendente">
            <div class="publicar-pendente-icone">⌛</div>
            <h1>Estamos a validar o teu stand</h1>
            <p>
                A tua conta empresarial foi criada com sucesso.
                Antes de publicares motas, precisamos de validar
                os dados do teu stand.
            </p>
            <p>
                Entretanto, podes continuar a explorar a Motocore.
            </p>
            <a class="botao principal" href="index.php">
                Voltar à página inicial
            </a>
        </div>
    </main>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$erro = '';
$marcas = [
    'Aprilia', 'BMW', 'Ducati', 'Honda', 'Kawasaki',
    'KTM', 'Suzuki', 'Triumph', '-', 'Outra'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $marca = trim($_POST['marca'] ?? '');
    $modelo = trim($_POST['modelo'] ?? '');
    $categoriaId = (int) ($_POST['categoria_id'] ?? 0);
    $ano = (int) ($_POST['ano'] ?? 0);
    $quilometros = filter_var(
        $_POST['quilometros'] ?? null,
        FILTER_VALIDATE_INT
    );
    $cilindrada = trim($_POST['cilindrada'] ?? '');
    $precoInput = str_replace(
        ',',
        '.',
        trim($_POST['preco'] ?? '')
    );
    $localidade = trim($_POST['localidade'] ?? '');
    $distrito = trim($_POST['distrito'] ?? '');
    $tipoMota = $_POST['tipo_mota'] ?? 'usada';
    $negociavel = isset($_POST['negociavel']) ? 1 : 0;

    $erros = [];

    if (mb_strlen($titulo) < 5 || mb_strlen($titulo) > 180) {
        $erros[] = 'O título deve ter entre 5 e 180 caracteres.';
    }

    if (mb_strlen($descricao) < 20) {
        $erros[] = 'A descrição deve ter pelo menos 20 caracteres.';
    }

    if (!in_array($marca, $marcas, true)) {
        $erros[] = 'Seleciona uma marca válida.';
    }

    if ($modelo === '' || mb_strlen($modelo) > 100) {
        $erros[] = 'Indica um modelo válido.';
    }

    $stmtCategoria = $pdo->prepare(
        'SELECT id FROM categorias WHERE id = ? AND ativa = 1'
    );
    $stmtCategoria->execute([$categoriaId]);

    if (!$stmtCategoria->fetch()) {
        $erros[] = 'Seleciona uma categoria válida.';
    }

    if ($ano < 1900 || $ano > (int) date('Y') + 1) {
        $erros[] = 'O ano indicado não é válido.';
    }

    if (
        $quilometros === false ||
        $quilometros === null ||
        $quilometros < 0
    ) {
        $erros[] = 'Indica uma quilometragem válida.';
    }

    if (
        $cilindrada !== '' &&
        (
            filter_var($cilindrada, FILTER_VALIDATE_INT) === false ||
            (int) $cilindrada < 1 ||
            (int) $cilindrada > 3000
        )
    ) {
        $erros[] = 'A cilindrada deve estar entre 1 e 3000 cc.';
    }

    if (
        !is_numeric($precoInput) ||
        (float) $precoInput < 0 ||
        (float) $precoInput > 99999999
    ) {
        $erros[] = 'Indica um preço válido.';
    }

    if (
        $localidade === '' ||
        mb_strlen($localidade) > 100 ||
        $distrito === '' ||
        mb_strlen($distrito) > 100
    ) {
        $erros[] = 'Indica uma localidade e um distrito válidos.';
    }

    if (!in_array($tipoMota, ['nova', 'usada'], true)) {
        $erros[] = 'Seleciona se a mota é nova ou usada.';
    }

    // Validar fotografias
    $ficheiros = [];
    $imagens = $_FILES['imagens'] ?? null;

    if (!$imagens || !isset($imagens['name']) ||
        !is_array($imagens['name'])) {
        $erros[] = 'O envio das fotografias é inválido.';
    } else {
        $total = count($imagens['name']);

        if ($total > 8) {
            $erros[] = 'Podes enviar no máximo 8 fotografias.';
        }

        $permitidos = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        for ($i = 0; $i < min($total, 8); $i++) {
            $codigo = $imagens['error'][$i];

            if ($codigo === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($codigo !== UPLOAD_ERR_OK) {
                $erros[] = 'Uma das fotografias não foi enviada corretamente.';
                continue;
            }

            if ($imagens['size'][$i] > 5 * 1024 * 1024) {
                $erros[] = 'Cada fotografia deve ter no máximo 5 MB.';
                continue;
            }

            $tmp = $imagens['tmp_name'][$i];

            if (!is_uploaded_file($tmp)) {
                $erros[] = 'Foi detetado um ficheiro inválido.';
                continue;
            }

            $mime = $finfo->file($tmp);

            if (
                !isset($permitidos[$mime]) ||
                @getimagesize($tmp) === false
            ) {
                $erros[] = 'Usa apenas imagens JPG, PNG ou WebP.';
                continue;
            }

            $ficheiros[] = [
                'tmp' => $tmp,
                'ext' => $permitidos[$mime]
            ];
        }

        if (count($ficheiros) === 0) {
            $erros[] = 'Envia pelo menos uma fotografia válida.';
        }
    }

    if ($erros) {
        $erro = implode(' ', array_unique($erros));
    } else {
        $pdo->beginTransaction();
        $guardadas = [];

        try {
            $slugBase = strtolower(
                preg_replace('/[^a-zA-Z0-9]+/', '-', $marca . '-' . $modelo)
            );
            $slug = trim($slugBase, '-') . '-' . bin2hex(random_bytes(4));

            $stmt = $pdo->prepare(
                "INSERT INTO anuncios (
                    utilizador_id, categoria_id, titulo, slug,
                    descricao, marca, modelo, cilindrada, ano,
                    quilometros, preco, negociavel, tipo_mota,
                    localidade, distrito, estado_anuncio, moderacao
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    'ativo', 'publicado'
                )"
            );

            $stmt->execute([
                $_SESSION['utilizador_id'],
                $categoriaId,
                $titulo,
                $slug,
                $descricao,
                $marca,
                $modelo,
                $cilindrada === '' ? null : (int) $cilindrada,
                $ano,
                $quilometros,
                number_format((float) $precoInput, 2, '.', ''),
                $negociavel,
                $tipoMota,
                $localidade,
                $distrito
            ]);

            $anuncioId = (int) $pdo->lastInsertId();

            $pasta = __DIR__ . '/uploads/motas/';

            if (!is_dir($pasta) &&
                !mkdir($pasta, 0755, true) &&
                !is_dir($pasta)) {
                throw new RuntimeException('Não foi possível criar a pasta de imagens.');
            }

            $stmtImagem = $pdo->prepare(
                'INSERT INTO imagens_anuncios
                    (anuncio_id, caminho, ordem, principal)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($ficheiros as $indice => $ficheiro) {
                $nomeFicheiro = bin2hex(random_bytes(16))
                    . '.' . $ficheiro['ext'];

                $destino = $pasta . $nomeFicheiro;

                if (!move_uploaded_file($ficheiro['tmp'], $destino)) {
                    throw new RuntimeException('Não foi possível guardar uma fotografia.');
                }

                $guardadas[] = $destino;

                $stmtImagem->execute([
                    $anuncioId,
                    'uploads/motas/' . $nomeFicheiro,
                    $indice,
                    $indice === 0 ? 1 : 0
                ]);
            }

            $pdo->commit();

            redirecionar('anuncio.php?id=' . $anuncioId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($guardadas as $ficheiro) {
                if (is_file($ficheiro)) {
                    unlink($ficheiro);
                }
            }

            error_log($e->getMessage());
            $erro = 'Não foi possível publicar o anúncio. Verifica a configuração e tenta novamente.';
        }
    }
}

$stmtCategorias = $pdo->query(
    'SELECT id, nome FROM categorias WHERE ativa = 1 ORDER BY nome'
);
$categorias = $stmtCategorias->fetchAll();

$tituloPagina = 'Publicar anúncio';
require __DIR__ . '/includes/header.php';
?>

<section class="formulario-container publicar-container">
    <h1>Publicar uma mota</h1>
    <p>Preenche os dados e apresenta a tua mota à comunidade.</p>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data"
          class="formulario">

        <input type="hidden" name="csrf_token"
               value="<?= e(tokenCsrf()) ?>">

        <label for="titulo">Título do anúncio</label>
        <input id="titulo" name="titulo" maxlength="180"
               required placeholder="Ex.: Yamaha MT-07 de 2022">

        <label for="marca">Marca</label>
        <select id="marca" name="marca" required>
            <option value="">Seleciona uma marca</option>
            <?php foreach ($marcas as $m): ?>
                <option value="<?= e($m) ?>"><?= e($m) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="modelo">Modelo</label>
        <input id="modelo" name="modelo" maxlength="100"
               required placeholder="Ex.: MT-07">

        <label for="categoria_id">Categoria</label>
        <select id="categoria_id" name="categoria_id" required>
            <option value="">Seleciona uma categoria</option>
            <?php foreach ($categorias as $categoria): ?>
                <option value="<?= (int) $categoria['id'] ?>">
                    <?= e($categoria['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="campo-duplo">
            <div>
                <label for="ano">Ano</label>
                <input id="ano" type="number" name="ano"
                       min="1900" max="<?= (int) date('Y') + 1 ?>"
                       required>
            </div>
            <div>
                <label for="quilometros">Quilómetros</label>
                <input id="quilometros" type="number"
                       name="quilometros" min="0" required>
            </div>
        </div>

        <label for="cilindrada">Cilindrada (cc)</label>
        <input id="cilindrada" type="number" name="cilindrada"
               min="1" max="3000" placeholder="Ex.: 689">

        <label for="preco">Preço (€)</label>
        <input id="preco" type="number" name="preco"
               min="0" max="99999999" step="0.01" required>

        <label for="tipo_mota">Estado da mota</label>
        <select id="tipo_mota" name="tipo_mota" required>
            <option value="usada">Usada</option>
            <option value="nova">Nova</option>
        </select>

        <label for="localidade">Localidade</label>
        <input id="localidade" name="localidade"
               maxlength="100" required>

        <label for="distrito">Distrito</label>
        <input id="distrito" name="distrito"
               maxlength="100" required>

        <label for="descricao">Descrição</label>
        <textarea id="descricao" name="descricao"
                  rows="6" minlength="20" required
                  placeholder="Descreve o estado, o equipamento e o histórico da mota..."></textarea>

        <label for="imagens">Fotografias (máximo 8, 5 MB cada)</label>
        <input id="imagens" type="file" name="imagens[]"
               accept="image/jpeg,image/png,image/webp"
               multiple required>

        <div id="lista-ficheiros" class="texto-ajuda">
            Ainda não selecionaste fotografias.
        </div>

        <label class="opcao-checkbox">
            <input type="checkbox" name="negociavel" value="1">
            Preço negociável
        </label>

        <button class="botao principal largura-total" type="submit">
            Publicar anúncio
        </button>
    </form>
</section>

<script>
const campoImagens = document.getElementById('imagens');
const listaFicheiros = document.getElementById('lista-ficheiros');

campoImagens.addEventListener('change', () => {
    const ficheiros = Array.from(campoImagens.files);

    if (ficheiros.length === 0) {
        listaFicheiros.textContent = 'Ainda não selecionaste fotografias.';
        return;
    }

    listaFicheiros.textContent = ficheiros.length > 8
        ? 'Selecionaste demasiadas fotografias. O máximo é 8.'
        : ficheiros.map(f => f.name).join(', ');
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
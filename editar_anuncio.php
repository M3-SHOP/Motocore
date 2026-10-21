<?php
require_once __DIR__ . '/includes/funcoes.php';

if (!autenticado()) {
    redirecionar('login.php');
}

$utilizadorId = (int) $_SESSION['utilizador_id'];
$anuncioId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$anuncioId || $anuncioId < 1) {
    redirecionar('index.php');
}

$erro = '';

$marcas = [
    'Aprilia', 'BMW', 'Ducati', 'Honda', 'Kawasaki',
    'KTM', 'Suzuki', 'Triumph', 'Yamaha', 'Outra'
];

// Verificar se o anúncio pertence ao utilizador.
$stmt = $pdo->prepare(
    'SELECT * FROM anuncios
     WHERE id = ? AND utilizador_id = ?
     LIMIT 1'
);
$stmt->execute([$anuncioId, $utilizadorId]);
$anuncio = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$anuncio) {
    http_response_code(404);
    exit('Anúncio não encontrado ou sem autorização.');
}

// Carregar categorias.
$stmt = $pdo->query(
    'SELECT id, nome FROM categorias
     WHERE ativa = 1 ORDER BY nome'
);
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Carregar fotografias.
$stmt = $pdo->prepare(
    'SELECT id, caminho, ordem, principal
     FROM imagens_anuncios
     WHERE anuncio_id = ?
     ORDER BY principal DESC, ordem ASC, id ASC'
);
$stmt->execute([$anuncioId]);
$imagensAtuais = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dados = $anuncio;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validarCsrf();

    $dados['titulo'] = trim($_POST['titulo'] ?? '');
    $dados['descricao'] = trim($_POST['descricao'] ?? '');
    $dados['marca'] = trim($_POST['marca'] ?? '');
    $dados['modelo'] = trim($_POST['modelo'] ?? '');
    $dados['categoria_id'] = (int) ($_POST['categoria_id'] ?? 0);
    $dados['ano'] = (int) ($_POST['ano'] ?? 0);

    $dados['quilometros'] = filter_var(
        $_POST['quilometros'] ?? null,
        FILTER_VALIDATE_INT
    );

    $dados['cilindrada'] = trim($_POST['cilindrada'] ?? '');
    $preco = str_replace(',', '.', trim($_POST['preco'] ?? ''));
    $dados['localidade'] = trim($_POST['localidade'] ?? '');
    $dados['distrito'] = trim($_POST['distrito'] ?? '');
    $dados['tipo_mota'] = $_POST['tipo_mota'] ?? '';
    $dados['negociavel'] = isset($_POST['negociavel']) ? 1 : 0;

    $erros = [];

    if (mb_strlen($dados['titulo']) < 5 ||
        mb_strlen($dados['titulo']) > 180) {
        $erros[] = 'O título deve ter entre 5 e 180 caracteres.';
    }

    if (mb_strlen($dados['descricao']) < 20) {
        $erros[] = 'A descrição deve ter pelo menos 20 caracteres.';
    }

    if (!in_array($dados['marca'], $marcas, true)) {
        $erros[] = 'Seleciona uma marca válida.';
    }

    if ($dados['modelo'] === '' ||
        mb_strlen($dados['modelo']) > 100) {
        $erros[] = 'Indica um modelo válido.';
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM categorias WHERE id = ? AND ativa = 1'
    );
    $stmt->execute([$dados['categoria_id']]);

    if (!$stmt->fetch()) {
        $erros[] = 'Seleciona uma categoria válida.';
    }

    if ($dados['ano'] < 1900 ||
        $dados['ano'] > (int) date('Y') + 1) {
        $erros[] = 'O ano indicado não é válido.';
    }

    if ($dados['quilometros'] === false ||
        $dados['quilometros'] === null ||
        $dados['quilometros'] < 0) {
        $erros[] = 'Indica uma quilometragem válida.';
    }

    if ($dados['cilindrada'] !== '' &&
        (
            filter_var($dados['cilindrada'], FILTER_VALIDATE_INT) === false ||
            (int) $dados['cilindrada'] < 1 ||
            (int) $dados['cilindrada'] > 3000
        )) {
        $erros[] = 'A cilindrada deve estar entre 1 e 3000 cc.';
    }

    if ($preco === '' || !is_numeric($preco) ||
        (float) $preco < 0 || (float) $preco > 99999999) {
        $erros[] = 'Indica um preço válido.';
    }

    if ($dados['localidade'] === '' ||
        mb_strlen($dados['localidade']) > 100 ||
        $dados['distrito'] === '' ||
        mb_strlen($dados['distrito']) > 100) {
        $erros[] = 'Indica uma localidade e um distrito válidos.';
    }

    if (!in_array($dados['tipo_mota'], ['nova', 'usada'], true)) {
        $erros[] = 'Seleciona se a mota é nova ou usada.';
    }

    // Fotografias que o utilizador quer remover.
    $remover = $_POST['remover_imagens'] ?? [];

    if (!is_array($remover)) {
        $remover = [];
        $erros[] = 'Seleção de fotografias inválida.';
    }

    $remover = array_values(array_unique(array_filter(
        array_map(
            static function ($id) {
                $id = filter_var($id, FILTER_VALIDATE_INT);
                return $id !== false && $id > 0 ? $id : null;
            },
            $remover
        ),
        static function ($id) {
            return $id !== null;
        }
    )));

    $imagensRestantes = array_values(array_filter(
        $imagensAtuais,
        static function ($imagem) use ($remover) {
            return !in_array((int) $imagem['id'], $remover, true);
        }
    ));

    // Validar fotografias novas.
    $ficheiros = [];
    $upload = $_FILES['imagens'] ?? null;

    if ($upload !== null) {
        if (!isset(
            $upload['name'],
            $upload['tmp_name'],
            $upload['error'],
            $upload['size']
        ) || !is_array($upload['name'])) {
            $erros[] = 'O envio das fotografias é inválido.';
        } else {
            if (count($upload['name']) > 8) {
                $erros[] = 'Podes selecionar no máximo 8 fotografias novas.';
            }

            $permitidos = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp'
            ];

            $finfo = new finfo(FILEINFO_MIME_TYPE);

            for ($i = 0; $i < min(count($upload['name']), 8); $i++) {
                if ($upload['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if ($upload['error'][$i] !== UPLOAD_ERR_OK) {
                    $erros[] = 'Uma fotografia não foi enviada corretamente.';
                    continue;
                }

                if ($upload['size'][$i] > 5 * 1024 * 1024) {
                    $erros[] = 'Cada fotografia deve ter no máximo 5 MB.';
                    continue;
                }

                $tmp = $upload['tmp_name'][$i];

                if (!is_uploaded_file($tmp)) {
                    $erros[] = 'Foi detetado um ficheiro inválido.';
                    continue;
                }

                $mime = $finfo->file($tmp);

                if (!isset($permitidos[$mime]) ||
                    @getimagesize($tmp) === false) {
                    $erros[] = 'Usa apenas imagens JPG, PNG ou WebP.';
                    continue;
                }

                $ficheiros[] = [
                    'tmp' => $tmp,
                    'ext' => $permitidos[$mime]
                ];
            }
        }
    }

    if (count($imagensRestantes) + count($ficheiros) > 8) {
        $erros[] = 'O anúncio pode ter no máximo 8 fotografias.';
    }

    if (count($imagensRestantes) + count($ficheiros) < 1) {
        $erros[] = 'O anúncio tem de ter pelo menos uma fotografia.';
    }

    if ($erros) {
        $erro = implode(' ', array_unique($erros));
    } else {
        $guardadas = [];
        $caminhosRemovidos = [];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE anuncios SET
                    categoria_id = ?,
                    titulo = ?,
                    descricao = ?,
                    marca = ?,
                    modelo = ?,
                    cilindrada = ?,
                    ano = ?,
                    quilometros = ?,
                    preco = ?,
                    negociavel = ?,
                    tipo_mota = ?,
                    localidade = ?,
                    distrito = ?
                 WHERE id = ? AND utilizador_id = ?'
            );

            $stmt->execute([
                $dados['categoria_id'],
                $dados['titulo'],
                $dados['descricao'],
                $dados['marca'],
                $dados['modelo'],
                $dados['cilindrada'] === ''
                    ? null : (int) $dados['cilindrada'],
                $dados['ano'],
                $dados['quilometros'],
                number_format((float) $preco, 2, '.', ''),
                $dados['negociavel'],
                $dados['tipo_mota'],
                $dados['localidade'],
                $dados['distrito'],
                $anuncioId,
                $utilizadorId
            ]);

            // Apagar da base de dados apenas imagens deste anúncio.
            if ($remover) {
                $placeholders = implode(
                    ',',
                    array_fill(0, count($remover), '?')
                );

                $stmt = $pdo->prepare(
                    "SELECT id, caminho FROM imagens_anuncios
                     WHERE anuncio_id = ?
                     AND id IN ($placeholders)"
                );
                $stmt->execute(array_merge([$anuncioId], $remover));
                $caminhosRemovidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare(
                    "DELETE FROM imagens_anuncios
                     WHERE anuncio_id = ?
                     AND id IN ($placeholders)"
                );
                $stmt->execute(array_merge([$anuncioId], $remover));
            }

            // Guardar as fotografias novas.
            $pasta = __DIR__ . '/uploads/motas/';

            if ($ficheiros && !is_dir($pasta) &&
                !mkdir($pasta, 0755, true) && !is_dir($pasta)) {
                throw new RuntimeException('Não foi possível criar a pasta.');
            }

            $stmtImagem = $pdo->prepare(
                'INSERT INTO imagens_anuncios
                    (anuncio_id, caminho, ordem, principal)
                 VALUES (?, ?, ?, 0)'
            );

            foreach ($ficheiros as $ficheiro) {
                $nome = bin2hex(random_bytes(16)) . '.' . $ficheiro['ext'];
                $destino = $pasta . $nome;

                if (!move_uploaded_file($ficheiro['tmp'], $destino)) {
                    throw new RuntimeException('Erro ao guardar uma fotografia.');
                }

                $guardadas[] = $destino;

                $stmtImagem->execute([
                    $anuncioId,
                    'uploads/motas/' . $nome,
                    0
                ]);
            }

            // Atualizar a ordem e definir a fotografia principal.
            $stmt = $pdo->prepare(
                'SELECT id FROM imagens_anuncios
                 WHERE anuncio_id = ?
                 ORDER BY principal DESC, ordem ASC, id ASC'
            );
            $stmt->execute([$anuncioId]);
            $idsImagens = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $stmtOrdem = $pdo->prepare(
                'UPDATE imagens_anuncios
                 SET ordem = ?, principal = ?
                 WHERE id = ? AND anuncio_id = ?'
            );

            foreach ($idsImagens as $indice => $idImagem) {
                $stmtOrdem->execute([
                    $indice,
                    $indice === 0 ? 1 : 0,
                    $idImagem,
                    $anuncioId
                ]);
            }

            $pdo->commit();

            foreach ($caminhosRemovidos as $imagem) {
                $caminho = __DIR__ . '/' .
                    ltrim($imagem['caminho'], '/');

                if (is_file($caminho)) {
                    @unlink($caminho);
                }
            }

            redirecionar('anuncio.php?id=' . $anuncioId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($guardadas as $ficheiro) {
                if (is_file($ficheiro)) {
                    @unlink($ficheiro);
                }
            }

            error_log($e->getMessage());
            $erro = 'Não foi possível guardar as alterações. Tenta novamente.';
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id, caminho, ordem, principal
         FROM imagens_anuncios
         WHERE anuncio_id = ?
         ORDER BY principal DESC, ordem ASC, id ASC'
    );
    $stmt->execute([$anuncioId]);
    $imagensAtuais = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$tituloPagina = 'Editar anúncio';
require __DIR__ . '/includes/header.php';
?>

<section class="formulario-container publicar-container">
    <h1>Editar anúncio</h1>
    <p>Atualiza os dados da tua mota e guarda as alterações.</p>

    <?php if ($erro !== ''): ?>
        <div class="alerta erro"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data"
          class="formulario">

        <input type="hidden" name="csrf_token"
               value="<?= e(tokenCsrf()) ?>">

        <label for="titulo">Título do anúncio</label>
        <input id="titulo" name="titulo" maxlength="180"
               required value="<?= e($dados['titulo']) ?>">

        <label for="marca">Marca</label>
        <select id="marca" name="marca" required>
            <option value="">Seleciona uma marca</option>
            <?php foreach ($marcas as $m): ?>
                <option value="<?= e($m) ?>"
                    <?= $dados['marca'] === $m ? 'selected' : '' ?>>
                    <?= e($m) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="modelo">Modelo</label>
        <input id="modelo" name="modelo" maxlength="100"
               required value="<?= e($dados['modelo']) ?>">

        <label for="categoria_id">Categoria</label>
        <select id="categoria_id" name="categoria_id" required>
            <option value="">Seleciona uma categoria</option>
            <?php foreach ($categorias as $categoria): ?>
                <option value="<?= (int) $categoria['id'] ?>"
                    <?= (int) $dados['categoria_id'] ===
                        (int) $categoria['id'] ? 'selected' : '' ?>>
                    <?= e($categoria['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="campo-duplo">
            <div>
                <label for="ano">Ano</label>
                <input id="ano" type="number" name="ano"
                       min="1900" max="<?= (int) date('Y') + 1 ?>"
                       required value="<?= e((string) $dados['ano']) ?>">
            </div>

            <div>
                <label for="quilometros">Quilómetros</label>
                <input id="quilometros" type="number"
                       name="quilometros" min="0" required
                       value="<?= e((string) $dados['quilometros']) ?>">
            </div>
        </div>

        <label for="cilindrada">Cilindrada (cc)</label>
        <input id="cilindrada" type="number" name="cilindrada"
               min="1" max="3000"
               value="<?= e((string) ($dados['cilindrada'] ?? '')) ?>">

        <label for="preco">Preço (€)</label>
        <input id="preco" type="number" name="preco"
               min="0" max="99999999" step="0.01" required
               value="<?= e((string) $dados['preco']) ?>">

        <label for="tipo_mota">Estado da mota</label>
        <select id="tipo_mota" name="tipo_mota" required>
            <option value="usada"
                <?= $dados['tipo_mota'] === 'usada' ? 'selected' : '' ?>>
                Usada
            </option>
            <option value="nova"
                <?= $dados['tipo_mota'] === 'nova' ? 'selected' : '' ?>>
                Nova
            </option>
        </select>

        <label for="localidade">Localidade</label>
        <input id="localidade" name="localidade" maxlength="100"
               required value="<?= e($dados['localidade']) ?>">

        <label for="distrito">Distrito</label>
        <input id="distrito" name="distrito" maxlength="100"
               required value="<?= e($dados['distrito']) ?>">

        <label for="descricao">Descrição</label>
        <textarea id="descricao" name="descricao"
                  rows="6" minlength="20" required><?= e($dados['descricao']) ?></textarea>

        <label>Fotografias atuais</label>

        <?php if (empty($imagensAtuais)): ?>
            <p class="texto-ajuda">Não existem fotografias neste anúncio.</p>
        <?php else: ?>
            <div class="imagens-editar">
                <?php foreach ($imagensAtuais as $imagem): ?>
                    <div class="imagem-editar">
                        <img src="<?= e($imagem['caminho']) ?>"
                             alt="Fotografia da mota">

                        <?php if ((int) $imagem['principal'] === 1): ?>
                            <p>Fotografia principal</p>
                        <?php endif; ?>

                        <label class="opcao-checkbox">
                            <input type="checkbox"
                                   name="remover_imagens[]"
                                   value="<?= (int) $imagem['id'] ?>">
                            Remover fotografia
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <label for="imagens">Adicionar fotografias</label>
        <input id="imagens" type="file" name="imagens[]"
               accept="image/jpeg,image/png,image/webp" multiple>

        <p id="lista-ficheiros" class="texto-ajuda">
            As fotografias atuais serão mantidas.
        </p>

        <label class="opcao-checkbox">
            <input type="checkbox" name="negociavel" value="1"
                <?= (int) $dados['negociavel'] === 1 ? 'checked' : '' ?>>
            Preço negociável
        </label>

        <button class="botao principal largura-total" type="submit">
            Guardar alterações
        </button>

        <a class="botao largura-total"
           href="anuncio.php?id=<?= (int) $anuncioId ?>">
            Cancelar
        </a>
    </form>
</section>

<style>
.imagens-editar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 16px;
    margin: 12px 0 20px;
}

.imagem-editar img {
    display: block;
    width: 100%;
    height: 140px;
    object-fit: cover;
    border-radius: 8px;
}

.imagem-editar p {
    font-size: 0.9rem;
}

.imagem-editar .opcao-checkbox {
    font-size: 0.9rem;
}
</style>

<script>
const campoImagens = document.getElementById('imagens');
const listaFicheiros = document.getElementById('lista-ficheiros');

campoImagens.addEventListener('change', () => {
    const ficheiros = Array.from(campoImagens.files);

    listaFicheiros.textContent = ficheiros.length
        ? ficheiros.map(f => f.name).join(', ')
        : 'As fotografias atuais serão mantidas.';
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
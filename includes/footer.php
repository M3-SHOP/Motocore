</main>

<footer class="rodape">
    <div class="container">
        <p>&copy; <?= date('Y') ?> MotoCore — O teu marketplace de motas.</p>
    </div>
</footer>

</body>
</html>


<div id="aviso-notificacao" class="aviso-notificacao" hidden>
    <div class="aviso-notificacao-icone" aria-hidden="true">✉</div>

    <div class="aviso-notificacao-conteudo">
        <strong id="aviso-remetente">Nova mensagem</strong>
        <p id="aviso-texto"></p>
        <span class="aviso-notificacao-ajuda">Clica para abrir a conversa</span>
    </div>

    <button
        type="button"
        id="fechar-notificacao"
        aria-label="Fechar notificação"
    >&times;</button>
</div>

<style>
/* CONTADOR NO CABEÇALHO */

.link-mensagens {
    position: relative;
}

#contador-mensagens {
    display: inline-grid;
    place-items: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    margin-left: 4px;
    border-radius: 20px;
    background: var(--cor-principal, #2589ff);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    vertical-align: middle;
}

#contador-mensagens[hidden],
.aviso-notificacao[hidden] {
    display: none !important;
}

/* AVISO DE NOVA MENSAGEM */

.aviso-notificacao {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 9999;

    width: min(370px, calc(100% - 40px));
    display: flex;
    align-items: flex-start;
    gap: 12px;

    padding: 16px;
    background: var(--branco, #151b23);
    color: var(--texto, #f1f5f9);

    border: 1px solid var(--borda, #283241);
    border-left: 4px solid var(--cor-principal, #2589ff);
    border-radius: var(--raio, 12px);

    box-shadow: 0 8px 28px #00000060;
    animation: aparecer-notificacao .25s ease-out;
    cursor: pointer;
}

.aviso-notificacao-icone {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;

    display: grid;
    place-items: center;

    border-radius: 10px;
    background: #172d48;
    color: #60aaff;
    font-size: 22px;
}

.aviso-notificacao-conteudo {
    flex: 1;
    min-width: 0;
}

.aviso-notificacao-conteudo strong {
    display: block;
    color: var(--cor-principal, #2589ff);
    font-size: 14px;
    overflow-wrap: anywhere;
}

.aviso-notificacao-conteudo p {
    margin: 5px 0 8px;
    color: var(--texto, #f1f5f9);
    font-size: 14px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.aviso-notificacao-ajuda {
    display: block;
    color: var(--texto-secundario, #9ca3af);
    font-size: 11px;
}

#fechar-notificacao {
    flex: 0 0 auto;
    border: 0;
    background: transparent;
    color: var(--texto-secundario, #9ca3af);
    cursor: pointer;
    font-size: 23px;
    line-height: 1;
    padding: 0 2px;
}

#fechar-notificacao:hover {
    color: var(--texto, #f1f5f9);
}

@keyframes aparecer-notificacao {
    from {
        opacity: 0;
        transform: translateY(12px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (max-width: 500px) {
    .aviso-notificacao {
        right: 10px;
        bottom: 10px;
        width: calc(100% - 20px);
        padding: 13px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .aviso-notificacao {
        animation: none;
    }
}
</style>

<script>
(() => {
    const contador = document.getElementById('contador-mensagens');
    const aviso = document.getElementById('aviso-notificacao');

    if (!contador || !aviso) return;

    const remetente = document.getElementById('aviso-remetente');
    const texto = document.getElementById('aviso-texto');
    const fechar = document.getElementById('fechar-notificacao');

    let primeiraConsulta = true;
    let ultimaMensagemNotificada = 0;
    let consultaEmCurso = false;
    let conversaAberta = null;

    // Identificar a conversa aberta, se existir.
    const parametros = new URLSearchParams(location.search);

    if (
        location.pathname.endsWith('/conversa.php') ||
        location.pathname.endsWith('conversa.php')
    ) {
        conversaAberta = parametros.get('id');
    }

    // Fechar o aviso sem abrir a conversa.
    fechar.addEventListener('click', event => {
        event.stopPropagation();
        aviso.hidden = true;
    });

    // Abrir a conversa ao clicar no aviso.
    aviso.addEventListener('click', () => {
        const id = aviso.dataset.conversa;

        if (id) {
            location.href = 'conversa.php?id=' +
                encodeURIComponent(id);
        }
    });

    async function atualizarNotificacoes() {
        if (consultaEmCurso || document.hidden) return;

        consultaEmCurso = true;

        try {
            const resposta = await fetch('notificacoes.php', {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!resposta.ok) {
                throw new Error('Não foi possível consultar as notificações.');
            }

            const dados = await resposta.json();

            // Atualizar o contador.
            contador.textContent = dados.total;
            contador.hidden = dados.total <= 0;

            if (dados.ultima) {
                const id = Number(dados.ultima.id);
                const idConversa = String(dados.ultima.conversa_id);

                // Não mostrar aviso de mensagens da conversa já aberta.
                const conversaEstaAberta =
                    conversaAberta === idConversa;

                if (
                    !primeiraConsulta &&
                    id > ultimaMensagemNotificada &&
                    !conversaEstaAberta
                ) {
                    remetente.textContent = dados.ultima.remetente;
                    texto.textContent = dados.ultima.mensagem;
                    aviso.dataset.conversa = idConversa;
                    aviso.hidden = false;
                }

                ultimaMensagemNotificada = Math.max(
                    ultimaMensagemNotificada,
                    id
                );
            }

            primeiraConsulta = false;
        } catch (erro) {
            console.error('Erro nas notificações:', erro);
        } finally {
            consultaEmCurso = false;
        }
    }

    // Consultar imediatamente e depois a cada 3 segundos.
    atualizarNotificacoes();
    setInterval(atualizarNotificacoes, 3000);

    // Atualizar ao regressar ao separador.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            atualizarNotificacoes();
        }
    });
})();
</script>

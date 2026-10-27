# Assistente de IA do CRM

Chat dentro do CRM para tirar dúvida sobre pedidos, clientes da carteira, metas e o uso do
sistema, e para abrir chamado quando não resolver. Fase 1 (2026-10-26): só leitura, mais o
chamado.

## O princípio: o modelo não lê o banco

O modelo (Claude Sonnet 4.6 no Amazon Bedrock, `us.anthropic.claude-sonnet-4-6`) não tem acesso a dado nenhum. Ele só **chama ferramentas
nossas** (`app/Services/Ia/Ferramentas/`), e cada uma roda **como o usuário que perguntou**,
reaproveitando os resolvers que as telas já usam:

| Ferramenta | Reaproveita | Escopo |
|---|---|---|
| `consultar_pedido` | `DashboardScopeResolver::alcanca`, `StatusPedidoResolver::paraTela` | carteira do usuário |
| `buscar_cliente` | `BuscaTitularidade::clientesQueCasam` (com escopo) | carteira do usuário |
| `resumo_cliente` | `ClienteStatusResolver::statusPara`, compra mais recente entre filiais | carteira do usuário |
| `quem_cuida_do_cliente` | `BuscaTitularidade::buscar` | empresa inteira, **saída estreita** |
| `minha_meta` | `MetaRankingResolver::metaVsRealizado` | escopo do Painel |
| `frescor_dos_dados` | `FrescorDoDado::porDominio` | — |
| `abrir_chamado` | `NotificacaoService` | grava em `ia_chamados`, avisa o suporte |

- Vazamento entre carteiras fica impossível por construção, e o número dito no chat sai do
  mesmo lugar que o número da tela (Regra de ouro nº 8).
- ⚠️ **"Não encontrado" é a mesma frase** para pedido inexistente e pedido de outra
  carteira. Dizer "existe, mas não é seu" já vaza que o colega vendeu.
- ⚠️ **Nenhuma ferramenta devolve texto livre de terceiros** (observação de lead, mensagem
  do formulário do site). É por aí que chegaria uma injeção de prompt. Ferramenta nova que
  precise disso exige cuidado próprio.
- Ferramenta nova entra em `CatalogoDeFerramentas::FERRAMENTAS` (é a whitelist).

## Arquitetura: fila + Reverb, não SSE

```
POST /ia/mensagens ──► grava pergunta + resposta "processando" ──► 200 em ~100 ms
        │
        └─► ResponderMensagemIaJob (fila `ia`)
                 └─► Orquestrador: modelo → ferramentas → modelo… (máx. 6 voltas)
                          ├─► IaEvento `delta` / `ferramenta` (Reverb, canal do usuário)
                          └─► grava texto, conteudo_api, tokens, custo → IaEvento `concluida`
```

- **Por que não SSE**: uma resposta leva de 5 a 20 s, e o pool tem 12 workers FPM por nó.
  Segurar um worker por resposta travaria o CRM com poucos chats ao mesmo tempo (Regra de
  ouro nº 9: acima de 2 s não é síncrono).
- ⚠️ **Fila própria, worker próprio** (`crm-worker-ia`, 3 processos, em
  `infra/configurar-daemons.sh`). O worker da `default` processa exportação de 95 s e import
  do TOTVS; atrás dele, o chat esperaria minutos.
- ⚠️ `IaEvento` é `ShouldBroadcastNow`: enfileirar a transmissão na `default` faria o texto
  chegar depois da resposta pronta.
- **Rede de segurança**: se o canal ficar mudo por 8 s, o painel faz polling em
  `GET /ia/mensagens/{id}`. Testado derrubando o Reverb.
- ⚠️ **O job restaura o modo Equipe/Minha carteira** (`ModoVisao::restaurarSemSessao`, o
  mesmo da exportação). Sem isso, o supervisor em "Minha carteira" receberia resposta da
  equipe inteira. O teste disso só morde rodando o job **com a sessão vazia**: com a fila
  `sync`, ele enxerga a sessão da requisição e passa mesmo com o bug.
- ⚠️ **O painel se reinscreve no canal a cada montagem do layout.** O sino faz
  `Echo.leave()` ao desmontar, e isso derruba todos os ouvintes do canal.
- ⚠️ **O estado do chat mora no módulo** (`composables/useAssistenteIa.js`), não no
  componente: o layout é remontado a cada navegação, e a conversa sumiria a cada clique no
  menu.

## Botão "Parar"

Enquanto a resposta sai, o botão de enviar vira **Parar**. Serve para quando o modelo
entendeu errado: corta a resposta, devolve a pergunta para a caixa e a tentativa sai do
histórico.

- `POST /ia/mensagens/{id}/parar` marca a resposta **e a pergunta** como `cancelada` (as
  duas saem do histórico: a tentativa descartada só confundiria a próxima) e avisa o job
  pela chave `ia:parar:{id}` no Redis, com TTL.
- O job confere o pedido **entre as voltas** e **a cada meio segundo enquanto o texto
  chega**. A conferência lança `RespostaCancelada` de dentro do streaming, e a leitura
  interrompida fecha a conexão com o modelo, que para de gerar e de cobrar.
- ⚠️ **Três janelas, três mecanismos, três testes** (`CancelamentoIaTest`): antes de o job
  começar (o job vê o status e nem chama o modelo), no meio do texto (a conferência no
  callback) e no último instante (a gravação final é um UPDATE condicional a
  "processando" — um `update()` comum ressuscitaria a resposta cancelada).
- Resposta que já terminou não é cancelada: o clique atrasado não apaga do histórico algo
  que o usuário já leu.
- ⚠️ Com o **modelo local na CPU** o corte só acontece quando chega o primeiro pedaço: o
  Ollama passa minutos lendo o prompt sem mandar nada, e não há o que conferir nesse
  intervalo. A tela para na hora; o job termina sozinho depois e descarta o resultado. Com a
  Anthropic o primeiro pedaço chega em ~1 s, e o corte é imediato.

## Histórico só por anexação

- `ia_mensagens.conteudo_api` guarda a parte de cada mensagem **no formato exato da API**
  (blocos de raciocínio, `tool_use`, `tool_result`). A conversa enviada a cada pergunta é a
  concatenação desses blocos, **sem edição**: o Claude Opus 5.5 recusa histórico editado, e o
  cache de prompt depende disso.
- ⚠️ A coluna é **`longText`, não `json`**: a coluna JSON do MySQL reordena as chaves de cada
  objeto ao gravar.
- ⚠️ O único ajuste permitido é `input: []` → `{}` em `tool_use` vazio (artefato do PHP, que
  decodifica `{}` como array). Coberto por teste.
- Resposta que falhou fica fora do histórico inteira. As duas perguntas seguidas vão juntas,
  e a API as une num turno só.

## Prompt e cache

- `system` = `resources/ia/instrucoes.md` + `resources/ia/manual.md`, **byte a byte igual
  entre requisições** (cache de prompt da Anthropic, leitura a ~5% do preço). O que varia
  (data, usuário, tela) vai num bloco `<contexto>` dentro da própria pergunta.
- 🚨 **O manual é a fonte das respostas de "como faço X".** Mudou uma tela? Mude o manual,
  senão o assistente ensina o caminho errado. Ele está marcado como RASCUNHO e precisa da
  revisão do Tony antes do beta.

## Modelo, custo e limites (`config/ia.php`)

| Chave | Padrão | |
|---|---|---|
| `IA_HABILITADO` | `false` | desligado, só admin vê o botão |
| `IA_PROVEDOR` | `bedrock` | `local` (Ollama) só em dev; `anthropic` é a API direta |
| `IA_MODELO` | `us.anthropic.claude-sonnet-4-6` | Sonnet 4.6. O 5.5 não invoca nesta conta |
| `IA_REGIAO` | `us-east-1` | região do Bedrock |
| `ANTHROPIC_API_KEY` | — | só no provedor `anthropic`; sem chave em `local`, responde um roteiro de demonstração |
| `IA_USUARIOS_LIBERADOS` | — | beta testers (e-mails, vírgula) |
| `IA_CHAMADOS_PARA` | e-mail do Tony | quem recebe chamado |
| `IA_LIMITE_DIARIO` | 40 | perguntas por usuário por dia (admin não tem limite) |
| `IA_TETO_MENSAL_USD` | 300 | acima disso para para todos; somado com cache de 5 min |

- No Opus 5.5 vai `fallbacks: "default"` (beta `server-side-fallback-2026-07-01`): a recusa
  dos classificadores de segurança é refeita em outro modelo, em vez de virar resposta vazia.
- Custo é **estimativa** pela tabela de `config('ia.precos')`, não a fatura.
- Admin simulando um vendedor usa o assistente como ele, e a conversa grava
  `admin_simulando_id`.

## Modelo local (Ollama) para testar sem chave — só dev

`ClienteOpenAiCompativel` fala com qualquer servidor "compatível com OpenAI" e traduz na ida
e na volta. O resto do sistema, inclusive o histórico gravado, continua no formato da
Anthropic. O motor em uso é o Bedrock; o Ollama só volta se `IA_PROVEDOR=local`. **Recusado em produção**
(AppServiceProvider), porque modelo pequeno erra mais na escolha das ferramentas, e apontar
isto para um serviço de terceiros mandaria dado de cliente para fora sem o OK de LGPD.

```bash
docker run -d --name ia-ollama --network crm_v2_default --network-alias ollama -p 11434:11434 -v crm_v2_ollama:/root/.ollama -e OLLAMA_CONTEXT_LENGTH=12288 -e OLLAMA_KEEP_ALIVE=30m ollama/ollama
```

```bash
docker exec ia-ollama ollama pull qwen2.5:7b
```

No `.env`: `IA_PROVEDOR=local`, `IA_TIMEOUT_JOB=900`. Reiniciar o worker com
`--timeout` maior que isso.

- ⚠️ **`OLLAMA_CONTEXT_LENGTH` é obrigatório.** O padrão do Ollama (2 a 4 mil tokens) é menor
  que instruções + manual + ferramentas (~5 mil), e ele **corta o começo do prompt em
  silêncio**: o modelo responderia sem as instruções e sem saber que o resultado da
  ferramenta é dado e não ordem.
- ⚠️ **Na CPU de um notebook é lento** (i5 de 11ª geração, sem placa de vídeo): a primeira
  pergunta processa o prompt inteiro. As seguintes reaproveitam o começo enquanto o modelo
  estiver carregado (`OLLAMA_KEEP_ALIVE`). Serve para ver o fluxo, não para julgar a
  qualidade nem a latência — para isso, `ia:avaliar` com a chave da Anthropic.
- Custo registrado: zero (`local:` no nome do modelo).

## Avaliação: decidir modelo com número (Regra de ouro nº 6)

```bash
php artisan ia:avaliar --modelo=claude-opus-5-5
php artisan ia:avaliar --modelo=claude-haiku-5-5
```

- Casos em `resources/ia/avaliacao.json`: quem pergunta, a pergunta, as ferramentas
  esperadas, e o que deve ou não pode aparecer. Os 15 iniciais usam o banco de dev de
  2026-10; **completar até ~50 com perguntas reais dos vendedores**.
- ⚠️ **Gasta dinheiro de verdade** (pede confirmação). Cada caso roda numa transação
  desfeita no fim: lê o banco real e não grava nada.
- O relatório vai para `storage/app/relatorios/ia-avaliacao-*.md` (acertos, latência p50/p95,
  custo, a resposta inteira).

## Telas

- **Botão flutuante** (canto inferior direito; no celular, acima da barra) e **painel**:
  gaveta de 420 px no desktop (não trava o scroll da página), tela cheia no celular.
- **`/ia/admin`** (menu do usuário → "Assistente: chamados e uso", só admin): chamados com
  resposta (notifica quem abriu), custo do mês e respostas marcadas com 👎, com a conversa
  inteira.

## Medido (2026-10-26, dev, `artisan serve`)

- `POST /ia/mensagens`: **106 a 220 ms** (meta de escrita: 500 ms).
- No navegador: resposta chegando ao vivo pelo Reverb, conversa sobrevivendo à troca de
  página, polling entregando com o Reverb derrubado, celular em 375 px sem overflow.
- ⚠️ **Latência do modelo real ainda não medida**: não havia chave da API. É o primeiro
  número que o `ia:avaliar` traz.

## Fora da Fase 1 (de propósito)

- Ações com escrita no CRM (agendar ligação, registrar observação): Fase 3, sempre com
  confirmação na tela.
- Ferramentas sobre leads e observações, que são texto livre de terceiros.
- Roteamento de chamado por setor.

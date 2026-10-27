<?php

/*
|---------------------------------------------------------------------------
| Assistente de IA (chat do CRM)
|---------------------------------------------------------------------------
|
| O modelo NÃO lê o banco: responde chamando as ferramentas de
| app/Services/Ia/Ferramentas, que rodam como o usuário logado e reaproveitam os
| resolvers de escopo. Detalhe em docs/ia-assistente.md.
|
| ⚠️ Sempre via config(), nunca env(): com o config cacheado em produção env()
| devolve null — e aqui isso desligaria o limite de gasto em silêncio.
|
*/

return [

    // Interruptor mestre. Desligado, só admin vê o assistente (para testar em produção).
    'habilitado' => (bool) env('IA_HABILITADO', false),

    'api_key' => (string) env('ANTHROPIC_API_KEY', ''),

    // `bedrock` (padrão: Claude no Amazon Bedrock, us-east-1), `anthropic` (API direta)
    // ou `local` (Ollama, só dev — recusado em produção).
    'provedor' => (string) env('IA_PROVEDOR', 'bedrock'),

    'regiao' => (string) env('IA_REGIAO', 'us-east-1'),

    'local' => [
        'url' => (string) env('IA_LOCAL_URL', 'http://ollama:11434/v1'),
        'modelo' => (string) env('IA_LOCAL_MODELO', 'qwen2.5:7b'),
        'chave' => (string) env('IA_LOCAL_CHAVE', ''),
        // Na CPU de um notebook uma volta passa de minuto. Ver docs/ia-assistente.md.
        'timeout' => (int) env('IA_LOCAL_TIMEOUT', 600),
    ],

    // Explícito: Sonnet 4.6 no perfil US do Bedrock. Sonnet 5.5 existe na conta mas
    // a invocação é recusada ("not available for this account").
    'modelo' => (string) env('IA_MODELO', 'us.anthropic.claude-sonnet-4-6'),

    // low | medium | high. Chat de consulta não ganha com raciocínio longo e perde em latência.
    'effort' => (string) env('IA_EFFORT', 'low'),

    'max_tokens' => 4000,

    // Beta testers: e-mails separados por vírgula. Admin entra sempre.
    'usuarios_liberados' => array_values(array_filter(array_map(
        fn (string $e) => mb_strtolower(trim($e)),
        explode(',', (string) env('IA_USUARIOS_LIBERADOS', '')),
    ))),

    // Quem recebe os chamados que o assistente abre.
    'chamados_para' => (string) env('IA_CHAMADOS_PARA', 'antonio.barbosa@autopel.com'),

    // Perguntas por usuário por dia. Protege o bolso, não o servidor.
    'limite_diario_por_usuario' => (int) env('IA_LIMITE_DIARIO', 40),

    // Acima disto no mês o assistente para para todos e diz por quê.
    'teto_mensal_usd' => (float) env('IA_TETO_MENSAL_USD', 300),

    // Voltas de ferramenta por pergunta. Uma pergunta do diretor (achar a pessoa,
    // achar o cliente, somar, abrir o orçamento) usa até 5. O teto fica um pouco acima.
    'max_voltas' => 8,

    // Teto do job. O worker da fila `ia` roda com --timeout=180 (infra/configurar-daemons.sh).
    'timeout_job' => (int) env('IA_TIMEOUT_JOB', 150),

    'fila' => 'ia',

    // US$ por milhão de tokens — só para estimar custo na tela de uso. Conferir na
    // tabela de preços da Anthropic quando trocar de modelo.
    'precos' => [
        'claude-opus-5-5' => ['entrada' => 4.00, 'saida' => 20.00, 'cache_leitura' => 0.20, 'cache_escrita' => 5.00],
        'us.anthropic.claude-sonnet-4-6' => ['entrada' => 3.00, 'saida' => 15.00, 'cache_leitura' => 0.30, 'cache_escrita' => 3.75],
        'claude-sonnet-5-5' => ['entrada' => 2.00, 'saida' => 10.00, 'cache_leitura' => 0.20, 'cache_escrita' => 2.50],
        'claude-haiku-5-5' => ['entrada' => 0.10, 'saida' => 0.50, 'cache_leitura' => 0.01, 'cache_escrita' => 0.125],
    ],

];

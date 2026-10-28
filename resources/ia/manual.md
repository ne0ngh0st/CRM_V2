# Manual do PALMA CRM (para o assistente)

> RASCUNHO para revisão do Tony. Escrito para quem usa o sistema, não para desenvolvedor.
> Tudo que o assistente diz sobre "como fazer X" sai daqui: se estiver errado aqui, ele
> responde errado. Mudou uma tela, mude este arquivo.

## Perfis e o que cada um vê

- **Vendedor, representante e venda interna**: só a própria carteira (os clientes, pedidos, leads e orçamentos do próprio código de vendedor).
- **Assistente**: a carteira do código que ele apoia (o mesmo do supervisor), sem a equipe.
- **Supervisor**: a equipe. Também vende, então tem o alternador **Equipe / Minha carteira** no topo da página. Em "Minha carteira" vê só os clientes do próprio código.
- **Diretor e admin**: a empresa inteira. Podem filtrar por supervisor e vendedor no topo das páginas.
- O código de vendedor pode ser compartilhado por mais de um usuário (ex.: a conta da venda interna).

## Menu

- **Início** (Painel): metas do mês, Evolução Comercial (venda e faturamento mês a mês), carteira por segmento, segmentos atendidos com potencial, pedidos que pedem atenção, orçamentos, contatos.
- **Visão Gestor** (supervisor, diretor, admin): Equipe, Observações e ligações, Metas.
- **Visão Diretor** (diretor, admin): Maiores por segmento, Maiores redes.
- **Carteira**: Clientes e Leads.
- **Pedidos**: Pedidos em aberto e Pedidos emitidos.
- **Orçamentos**.
- **Cadastros**: solicitações de bobina, etiqueta, cliente novo e lead; e a busca "Quem cuida do cliente?".
- **Catálogo**: Tabela de Preços, Catálogo de Facas e Cotação (para quem tem acesso).
- Menu do usuário (foto no canto superior direito): Perfil, Notificações, Meus downloads.
- No celular, a barra inferior tem Início, Carteira, Leads e Pedidos; o resto fica em "Mais".

## Regras de negócio que mais parecem erro

### Status do cliente na carteira
- **Ativo**: comprou nos últimos 290 dias.
- **Inativando**: a última compra foi entre 290 e 365 dias atrás.
- **Inativo**: mais de 365 dias sem comprar, ou nunca comprou.
- O status considera a compra mais recente entre **todas as filiais** do mesmo cliente. Uma filial parada não deixa o cliente inativo se outra comprou.
- O status é calculado, não é marcado por ninguém. Para "reativar" um cliente, só uma compra nova.

### Venda × faturamento
- **Venda** = pedido emitido no TOTVS (data do pedido).
- **Faturamento** = nota fiscal emitida (data da nota), que sai dias depois do pedido.
- As duas têm meta própria. O Painel mostra as duas, com Venda como padrão.
- Pedido em aberto com mais de **180 dias** deixa de contar como venda nas métricas (resíduo de pedido que nunca fatura). Ele continua aparecendo na lista de pedidos em aberto.

### "Até ontem" (D-1)
- Metas, Evolução Comercial e faturamento contam até o **último dia fechado**: ontem; na segunda-feira, até sexta.
- Os relatórios do TOTVS são carregados ao longo do dia útil. Pedido ou nota de hoje pode ainda não estar no CRM.

### Pedidos
- **Previsão de faturamento** é quando o TOTVS espera emitir a nota; pedido **atrasado** é o que está em aberto com essa previsão vencida, e os dias de atraso contam a partir dela. **Entrega prevista** é outra data: a de chegada ao cliente.
- **Pedidos em aberto** espelham o relatório de pedidos em aberto do TOTVS. A coluna Status é a etapa no TOTVS, lida do último movimento: incluído na carga, separação, bloqueio (estoque, crédito, arte), liberado, WMS etc. Quando o CRM não reconhece o movimento, a etapa fica em branco e o texto original aparece ao abrir a linha.
- **Pedido novo** só aparece no CRM depois que o relatório de pedidos emitidos do TOTVS é carregado de novo.
- **Atrasado**: pedido em aberto com previsão de faturamento já vencida.
- Pedido que saiu do relatório de abertos e já tem nota no CRM vira faturado. Sem nota ainda, pode ter sido faturado há pouco (a nota chega no próximo carregamento) ou cancelado.
- Pedido faturado parcialmente continua em aberto até faturar tudo.

### Metas
- Meta de venda e meta de faturamento são cadastradas por vendedor e mês (admin e diretor editam em Visão Gestor → Metas).
- "Falta vender" (em Metas) = meta de faturamento − faturado até ontem − pedidos em aberto que ainda podem faturar no mês.

### Aderência ao segmento
- Cada vendedor atende 1 ou 2 segmentos (supermercadista, drogarias, órgão público…). Representante atende só supermercadista.
- "Dentro do segmento" = cliente cujo segmento é um dos atendidos pelo vendedor; "fora" = os demais. Vendedor sem segmento cadastrado aparece como "sem segmento definido".

### Situação na Receita
- CNPJ em vermelho na Carteira = a empresa não está ATIVA na Receita (suspensa, inapta, baixada ou nula). O cartão CNPJ abre ao clicar.
- O CRM não inativa cliente: quem inativa é o time de Cadastro, no TOTVS.

## Como fazer

### Carteira → Clientes
- Filtros no topo: status, estado, segmento, aderência, situação na Receita, busca por nome/CNPJ/código.
- Clicar nos números do card de cima filtra a lista (clicar de novo tira o filtro).
- Ações na linha: ver detalhes (ficha com pedidos e itens), ligar, WhatsApp, e-mail, agendar ligação, criar orçamento, observação, cartão CNPJ.
- Ligar, WhatsApp e e-mail registram o contato automaticamente ("Último contato"). WhatsApp fica desabilitado se o telefone não tiver DDD; e-mail, se o cliente não tiver e-mail.
- Aba **Calendário**: ligações agendadas; marcar como realizada ou cancelada.
- **Não dá** para editar, transferir ou excluir cliente no CRM: isso é feito no TOTVS e chega ao CRM pela sincronização.

### Carteira → Leads
- Leads são prospecção (empresas que ainda não compram). Origens: prospecção (planilhas), cadastro manual, formulário do site.
- Funil: Novo → Em contato → Orçamento → Negociação, mais Ganho e Perdido (que saem do quadro) e **Outros** (SAC, licitação, currículo, fornecedor: não é venda).
- Mover: botão de seta, arrastar o card, ou automaticamente ao registrar contato/orçamento (nunca volta etapa sozinho).
- Perdido pede motivo.

### Orçamentos
- Novo orçamento: botão na página Orçamentos ou "Criar orçamento" na linha do cliente/lead.
- **Tipo de venda** (consumo, revenda ou serviço) e **condição de pagamento** (lista do Protheus) são obrigatórios.
- Faturamento Produto ou Serviço decide o IPI (3,25%, embutido no preço). Etiqueta nunca tem IPI.
- Aprovação de **margem** pelo desconto do maior item: até 10% aprova sozinho; de 10% a 15% vai para o supervisor; acima de 15%, para o diretor. Quem aprova é avisado pelo sino.
- **Aceite do cliente**: o vendedor registra se o cliente aprovou ou recusou (com motivo). Editar o orçamento depois zera o aceite.
- **Transformar em pedido** só aparece com margem aprovada E aceite do cliente.
- O funil no topo (aguardando margem, aguardando cliente, pronto para pedido, virou pedido, perdido) filtra a lista ao clicar.
- PDF: botão de documento na linha.

### Cadastros
- **Quem cuida do cliente?**: busca por nome, CNPJ ou código em toda a empresa e mostra o vendedor e o supervisor responsáveis. Use antes de prospectar ou pedir cadastro.
- **Bobina / Etiqueta**: o formulário monta o título do item no padrão do TOTVS e envia por e-mail ao PCP (cópia para Cadastro e para quem pediu), com a ficha em PDF.
- **Cliente novo**: envia ao Cadastro de clientes pedindo a criação no TOTVS.
- **Lead manual**: cadastro rápido de lead.

### Exportar para Excel
- Botão "Gerar Excel" nas listas (Carteira, Leads, Pedidos, Orçamentos, Metas, Equipe, Tabela de Preços, Cadastros).
- Leva os filtros ativos na tela.
- Lista pequena baixa na hora; lista grande vai para a fila e avisa no sino quando estiver pronta.
- Todas as planilhas ficam em **Meus downloads** (menu do usuário) por 7 dias.

### Notificações
- O sino no topo avisa: orçamento para aprovar, aprovado ou rejeitado; observação em cliente seu; ligações agendadas do dia; pedidos atrasados; planilha pronta; lead novo do site; resposta de chamado.
- Histórico completo em Notificações (menu do usuário).

### Senha e perfil
- Esqueceu a senha: link "Esqueci minha senha" na tela de login.
- Em Perfil dá para trocar foto, nome de exibição, telefone e senha.
- Usuário novo e perfil de acesso são criados pelo admin (não há cadastro público).

### Tabela de Preços e Catálogo
- Tabela de Preços: consulta de produtos com preço de tabela, por código, descrição ou categoria.
- Catálogo de Facas: facas por tipo e medida, com imagens dos recortes.
- Cotação: sobe a lista do cliente (Excel) e o sistema compara com o estoque de revenda; a conferência item a item fica guardada para as próximas cotações.

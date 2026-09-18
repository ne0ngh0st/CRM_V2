/**
 * A ESTRUTURA DO MENU DO SISTEMA — fonte única, consumida por três telas.
 *
 * Até 2026-09-15 o menu era escrito DUAS VEZES no `AuthenticatedLayout.vue`: o nav
 * horizontal (com dropdowns) e a lista do hambúrguer. E já havia divergido — o link
 * "Atualização de dados" (admin) existia só no desktop, então admin nenhum alcançava
 * aquela tela pelo celular. Com a barra inferior seria uma TERCEIRA cópia, e uma cópia
 * nova nasce sempre igual à mais recente, nunca à correta (Regra de ouro nº 8).
 *
 * ⚠️ AQUI SÓ MORA O NOME DA ROTA, nunca a URL resolvida. Este arquivo é dado puro, igual
 * aos outros `constants/*.js`, e quem chama resolve com `route(item.rota)`. Resolver aqui
 * amarraria o módulo ao Ziggy já carregado, e o `@routes` do Blade é injetado antes do
 * bundle por sorte de ordem, não por contrato.
 *
 * ⚠️ `ativoEm` é lista de PADRÕES do Ziggy (`carteira.*`), não de rotas exatas: o estado
 * ativo de um grupo tem que acender em qualquer filha dele. Um grupo cujo `ativoEm` não
 * cobre as filhas fica apagado navegando dentro dele mesmo — que era o comportamento do
 * "Catálogo" antes de existir `catalogoAtivo`.
 */

/**
 * Visibilidade por perfil. Recebe sempre o mesmo objeto de papéis
 * (`{ isGestor, isAssistente, isAdmin }`), montado uma vez pelo layout.
 *
 * Item sem `visivel` aparece para todos — é o default de propósito: perfil comercial novo
 * deve nascer vendo o menu inteiro e perdendo o que não é dele, nunca o contrário.
 */
const soGestor = ({ isGestor }) => isGestor;
const soAssistente = ({ isAssistente }) => isAssistente;
const soAdmin = ({ isAdmin }) => isAdmin;

/**
 * Assistente não atende carteira nem pedido — o trabalho dele é lead e cadastro.
 * Escrito como negação do assistente (e não como lista dos outros cinco perfis) porque é
 * assim que o `CarteiraController` e os resolvers de escopo já decidem isso no servidor.
 */
const comercial = ({ isAssistente }) => !isAssistente;

/**
 * O menu principal, na ordem em que aparece.
 *
 * Grupo com `itens` é dropdown no desktop e seção na gaveta; grupo com `rota` é link
 * direto nos dois. `largura` é só do dropdown desktop (a prop `width` do `Dropdown.vue`).
 */
export const NAV_PRINCIPAL = [
    {
        chave: 'inicio',
        rotulo: 'Início',
        icone: 'inicio',
        rota: 'dashboard',
        ativoEm: ['dashboard'],
        prefetch: 'hover',
    },
    {
        chave: 'visao-gestor',
        rotulo: 'Visão Gestor',
        icone: 'gestor',
        visivel: soGestor,
        largura: '56',
        ativoEm: ['equipe.*', 'visao-gestor.*', 'metas.*'],
        itens: [
            { chave: 'equipe', rotulo: 'Equipe', icone: 'equipe', rota: 'equipe.index', ativoEm: ['equipe.*'], prefetch: 'hover' },
            { chave: 'visao-gestor-index', rotulo: 'Observações e ligações', icone: 'observacoes', rota: 'visao-gestor.index', ativoEm: ['visao-gestor.*'], prefetch: 'hover' },
            { chave: 'metas', rotulo: 'Metas', icone: 'metas', rota: 'metas.index', ativoEm: ['metas.*'], prefetch: 'hover' },
        ],
    },
    {
        chave: 'carteira',
        rotulo: 'Carteira',
        icone: 'carteira',
        visivel: comercial,
        largura: '48',
        ativoEm: ['carteira.*', 'leads.*'],
        itens: [
            { chave: 'clientes', rotulo: 'Clientes', icone: 'clientes', rota: 'carteira.index', ativoEm: ['carteira.*'], prefetch: 'hover' },
            { chave: 'leads', rotulo: 'Leads', icone: 'leads', rota: 'leads.index', ativoEm: ['leads.*'], prefetch: 'hover' },
        ],
    },
    {
        chave: 'pedidos',
        rotulo: 'Pedidos',
        icone: 'pedidos',
        visivel: comercial,
        largura: '48',
        ativoEm: ['pedidos.*'],
        itens: [
            { chave: 'pedidos-abertos', rotulo: 'Pedidos em aberto', icone: 'pedidos', rota: 'pedidos.index', ativoEm: ['pedidos.index'], prefetch: 'hover' },
            { chave: 'pedidos-emitidos', rotulo: 'Pedidos emitidos', icone: 'pedidos-emitidos', rota: 'pedidos.emitidos', ativoEm: ['pedidos.emitidos'], prefetch: 'hover' },
        ],
    },
    {
        // O assistente não tem o grupo "Carteira", então Leads sobe para link de topo.
        chave: 'leads-assistente',
        rotulo: 'Leads',
        icone: 'leads',
        visivel: soAssistente,
        rota: 'leads.index',
        ativoEm: ['leads.*'],
        prefetch: 'hover',
    },
    {
        chave: 'orcamentos',
        rotulo: 'Orçamentos',
        icone: 'orcamentos',
        rota: 'orcamentos.index',
        ativoEm: ['orcamentos.index'],
        prefetch: 'click',
    },
    {
        chave: 'cadastros',
        rotulo: 'Cadastros',
        icone: 'cadastros',
        rota: 'cadastros.index',
        ativoEm: ['cadastros.*'],
        prefetch: 'click',
    },
    {
        chave: 'catalogo',
        rotulo: 'Catálogo',
        icone: 'catalogo',
        largura: '48',
        ativoEm: ['tabela-precos.*', 'catalogo-facas.*'],
        itens: [
            { chave: 'tabela-precos', rotulo: 'Tabela de Preços', icone: 'tabela-precos', rota: 'tabela-precos.index', ativoEm: ['tabela-precos.*'], prefetch: 'hover' },
            { chave: 'facas', rotulo: 'Catálogo de Facas', icone: 'facas', rota: 'catalogo-facas.index', ativoEm: ['catalogo-facas.*'], prefetch: 'hover' },
        ],
    },
    {
        // Sem `visivel`: a intranet é de TODOS os perfis. Quem publica é decidido no
        // servidor (IntranetPublicacao::podePublicar), não pelo menu.
        chave: 'intranet',
        rotulo: 'Intranet',
        icone: 'intranet',
        rota: 'intranet.index',
        ativoEm: ['intranet.*'],
        prefetch: 'hover',
    },
];

/**
 * O menu do usuário (avatar no desktop, pé da gaveta no mobile).
 *
 * ⚠️ "Meus downloads" fica aqui e NÃO na navegação principal porque a lista é PESSOAL:
 * cada um vê só as próprias planilhas. Era um comentário no layout e vem junto para não
 * se perder na extração.
 */
export const NAV_USUARIO = [
    { chave: 'perfil', rotulo: 'Perfil', icone: 'perfil', rota: 'profile.edit', ativoEm: ['profile.*'] },
    { chave: 'downloads', rotulo: 'Meus downloads', icone: 'downloads', rota: 'exportacoes.index', ativoEm: ['exportacoes.*'] },
    {
        // Mostra estado de infraestrutura e dispara carga pesada no banco — só admin,
        // igual à matéria-prima de etiqueta e ao CRUD do Catálogo de Facas.
        chave: 'atualizacoes',
        rotulo: 'Atualização de dados',
        icone: 'atualizacoes',
        rota: 'atualizacoes.index',
        ativoEm: ['atualizacoes.*'],
        visivel: soAdmin,
    },
];

/**
 * Os quatro destinos da barra inferior do celular. O quinto alvo ("Mais", que abre a
 * gaveta) é do componente, não daqui: ele não é um destino, é um controle.
 *
 * ⚠️ São LINKS DIRETOS, não os grupos do menu principal — uma barra inferior que abre
 * submenu perde o motivo de existir, que é chegar na tela com um toque só. Por isso
 * "Carteira" aqui aponta para `carteira.index` em vez de abrir Clientes/Leads.
 *
 * ⚠️ Escolhidos para VENDEDOR EM CAMPO, que é quem usa o CRM no celular (decisão do Tony,
 * 2026-09-15). Gestor recebe os mesmos quatro; Equipe, Metas e Visão Gestor ficam na
 * gaveta, porque tela de gestão se usa sentado. Mudar isso é mexer só nesta lista.
 *
 * ⚠️ A lista TEM que resolver em exatamente 4 para qualquer perfil — a barra é um grid de
 * 5 colunas e um destino a menos deixa um buraco. Ver `barraInferior()`.
 */
const DESTINOS_MOBILE = [
    { chave: 'inicio', rotulo: 'Início', icone: 'inicio', rota: 'dashboard', ativoEm: ['dashboard'] },
    { chave: 'carteira', rotulo: 'Carteira', icone: 'carteira', rota: 'carteira.index', ativoEm: ['carteira.*'], visivel: comercial },
    { chave: 'leads', rotulo: 'Leads', icone: 'leads', rota: 'leads.index', ativoEm: ['leads.*'] },
    { chave: 'pedidos', rotulo: 'Pedidos', icone: 'pedidos', rota: 'pedidos.index', ativoEm: ['pedidos.*'], visivel: comercial },
    { chave: 'orcamentos', rotulo: 'Orçamentos', icone: 'orcamentos', rota: 'orcamentos.index', ativoEm: ['orcamentos.index'], visivel: soAssistente },
    { chave: 'cadastros', rotulo: 'Cadastros', icone: 'cadastros', rota: 'cadastros.index', ativoEm: ['cadastros.*'], visivel: soAssistente },
];

/** Aplica a visibilidade por perfil a uma lista, preservando a ordem declarada. */
function visiveis(lista, perfil) {
    return lista.filter((item) => !item.visivel || item.visivel(perfil));
}

export function menuPrincipal(perfil) {
    return visiveis(NAV_PRINCIPAL, perfil).map((grupo) => ({
        ...grupo,
        itens: grupo.itens ? visiveis(grupo.itens, perfil) : undefined,
    }));
}

export function menuUsuario(perfil) {
    return visiveis(NAV_USUARIO, perfil);
}

export function barraInferior(perfil) {
    /*
     * `slice(0, 4)` é rede de segurança, não regra: hoje todo perfil resolve em
     * exatamente quatro. Se alguém acrescentar um destino sem `visivel`, o corte mantém a
     * barra inteira em vez de deixá-la estourar em cinco colunas de conteúdo mais o
     * "Mais" — o defeito passa a ser um destino que não aparece, e não a barra quebrada.
     */
    return visiveis(DESTINOS_MOBILE, perfil).slice(0, 4);
}

/**
 * Se algum dos padrões do Ziggy casa com a rota atual.
 *
 * ⚠️ Mora aqui, e não em cada componente, porque os três consumidores fazem a MESMA
 * pergunta sobre a MESMA lista. Era esse `.some()` que estava copiado como
 * `visaoGestorAtiva`/`carteiraAtiva`/`pedidosAtivo`/`catalogoAtivo` no layout, um computed
 * por grupo.
 *
 * `route` é global (vem do `@routes` no `<head>`, antes do bundle), então não precisa de
 * import — é o mesmo acesso que o layout já fazia.
 */
export function estaAtivo(ativoEm) {
    if (!ativoEm?.length) {
        return false;
    }

    return ativoEm.some((padrao) => route().current(padrao));
}

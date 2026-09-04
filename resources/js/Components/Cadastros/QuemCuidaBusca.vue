<script setup>
/**
 * "Quem cuida do cliente?" — de quem é este CNPJ.
 *
 * ⚠️ Vive no NÍVEL DA PÁGINA de Cadastros, logo abaixo da barra de filtros, e não dentro
 * da aba de solicitação de cliente (onde ficou até 2026-09-04). A pergunta "esse cliente
 * já existe e é de quem?" precede QUALQUER solicitação — inclusive bobina e etiqueta, que
 * também se pedem para um cliente —, então ela é contexto de página, não passo de um
 * formulário. Continua respondida ANTES de o formulário ser preenchido, que é o que evita
 * o retrabalho.
 *
 * ⚠️ Mostra SÓ titularidade — razão social, documento, responsável e supervisor. Nada de
 * status, última compra, telefone ou valores. É o que torna defensável o fato de esta ser
 * a única consulta de cliente do sistema que ignora o escopo do vendedor. Ver o docblock
 * de App\Services\Cadastros\BuscaTitularidade antes de acrescentar qualquer campo.
 *
 * ⚠️ É um DarkCard, e não um painel próprio, por decisão de Design System (Regra de ouro
 * nº 8): a versão anterior era o único bloco da página desenhado do zero. Além de destoar,
 * o `bg-gray-50` do painel era EXATAMENTE a mesma cor do `.tbl-head-row`, então a tabela
 * de resultados não tinha separação do fundo e parecia vazar do container. Num corpo
 * branco de card, os tokens de tabela do projeto voltam a funcionar como em todas as
 * outras telas.
 */
import { ref } from 'vue';
import DarkCard from '@/Components/DarkCard.vue';

const termo = ref('');
const resultados = ref(null);
const buscando = ref(false);
const erro = ref('');
const minimo = ref(3);

/**
 * Espelha o `LIMITE` de BuscaTitularidade. Serve só para avisar que a lista pode estar
 * cortada — sem isso, quem busca "LOJAS" recebe 30 linhas achando que é a base inteira.
 */
const LIMITE = 30;

async function buscar() {
    const valor = termo.value.trim();

    if (valor.length < minimo.value) {
        erro.value = `Digite pelo menos ${minimo.value} caracteres.`;
        resultados.value = null;

        return;
    }

    buscando.value = true;
    erro.value = '';

    try {
        const res = await fetch(route('cadastros.titularidade', { termo: valor }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const dados = await res.json();
        minimo.value = dados.minimo ?? minimo.value;
        resultados.value = dados.resultados;
    } catch {
        erro.value = 'Não foi possível consultar agora. Tente de novo.';
        resultados.value = null;
    } finally {
        buscando.value = false;
    }
}

function limpar() {
    termo.value = '';
    resultados.value = null;
    erro.value = '';
}
</script>

<template>
    <DarkCard
        title="Quem cuida do cliente?"
        subtitle="Confira de quem é o cliente antes de solicitar — vale para a base inteira, não só para a sua carteira"
    >
        <template #icon>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-full w-full">
                <circle cx="11" cy="11" r="6.5" />
                <line x1="15.8" y1="15.8" x2="21" y2="21" stroke-linecap="round" />
            </svg>
        </template>

        <!--
            Mesma gramática visual da barra de filtros do PageHero, logo acima: rótulo
            micro em maiúsculas, campo `py-1.5 text-xs` e botão secundário de limpar. É o
            que faz as duas linhas parecerem uma coisa só em vez de dois controles
            empilhados por acaso.
        -->
        <div class="flex flex-wrap items-end gap-3">
            <div class="flex min-w-[200px] max-w-[360px] flex-1 flex-col gap-1">
                <label class="text-[0.68rem] font-semibold uppercase tracking-wide text-gray-500">Nome ou CNPJ</label>
                <input
                    v-model="termo"
                    type="text"
                    maxlength="200"
                    autocomplete="off"
                    placeholder="Razão social, nome fantasia ou documento..."
                    class="w-full rounded border-gray-300 py-1.5 text-xs text-gray-700 focus:border-cyan focus:ring-cyan"
                    @keyup.enter="buscar"
                />
            </div>

            <button
                type="button"
                class="rounded bg-corp-black px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-corp-black/85 disabled:opacity-40"
                :disabled="buscando"
                @click="buscar"
            >
                {{ buscando ? 'Buscando…' : 'Verificar' }}
            </button>

            <button
                v-if="resultados !== null || termo"
                type="button"
                class="rounded border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100"
                @click="limpar"
            >
                Limpar
            </button>
        </div>

        <p v-if="erro" class="mt-3 text-xs text-red-600">{{ erro }}</p>

        <div v-if="resultados !== null && !erro" class="mt-4 border-t border-gray-100 pt-4">
            <p v-if="!resultados.length" class="text-xs text-gray-500">
                Nenhum cliente nem solicitação pendente com esse nome ou documento. Pode seguir com
                a solicitação.
            </p>

            <template v-else>
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
                    {{ resultados.length }} resultado{{ resultados.length !== 1 ? 's' : '' }}
                    <span v-if="resultados.length >= LIMITE" class="font-medium normal-case tracking-normal text-gray-400">
                        · mostrando os primeiros {{ LIMITE }}, refine a busca
                    </span>
                </p>

                <div class="tbl-wrap">
                    <table class="tbl min-w-[720px]">
                        <thead>
                            <tr class="tbl-head-row">
                                <th class="tbl-th">Cliente</th>
                                <th class="tbl-th">CNPJ / CPF</th>
                                <th class="tbl-th">Quem cuida</th>
                                <th class="tbl-th">Supervisor</th>
                            </tr>
                        </thead>
                        <tbody class="tbl-body">
                            <tr v-for="(r, i) in resultados" :key="i" class="tbl-row">
                                <td class="tbl-td">
                                    <span class="tbl-main max-w-[280px]">{{ r.razaoSocial }}</span>
                                    <!-- O nome fantasia entra SEMPRE que existe, e não só
                                         quando falta o código: é comum a razão social ser o
                                         nome do dono ("54.339.302 THYAGO BARROS") e o fantasia
                                         ser a marca ("FEDEX BRASIL..."). Escondê-lo faz a
                                         linha parecer um resultado errado para quem buscou
                                         pela marca. -->
                                    <span class="tbl-sub">
                                        <template v-if="r.tipo === 'pendente'">Solicitação pendente de cadastro</template>
                                        <template v-else>
                                            <template v-if="r.nomeFantasia">{{ r.nomeFantasia }}</template>
                                            <template v-if="r.nomeFantasia && r.codCliente"> · </template>
                                            <template v-if="r.codCliente">{{ r.codCliente }}/{{ r.loja }}</template>
                                        </template>
                                    </span>
                                </td>
                                <td class="tbl-td">{{ r.cnpj || '—' }}</td>
                                <td class="tbl-td">
                                    <span v-if="r.responsaveis?.length">{{ r.responsaveis.join(', ') }}</span>
                                    <span v-else class="text-gray-400">sem responsável</span>
                                </td>
                                <td class="tbl-td">{{ r.supervisor || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </DarkCard>
</template>

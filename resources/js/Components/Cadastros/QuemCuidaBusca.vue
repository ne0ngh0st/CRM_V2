<script setup>
/**
 * "Quem cuida do cliente?" — de quem é este CNPJ.
 *
 * ⚠️ Vive AQUI, no topo da solicitação de cliente novo, e não numa página própria, porque
 * é aqui que a resposta evita o erro: descobrir, antes de pedir, que o cliente já existe e
 * já tem dono. No legado era o próprio H1 da tela de cadastro.
 *
 * ⚠️ Mostra SÓ titularidade — razão social, documento, responsável e supervisor. Nada de
 * status, última compra, telefone ou valores. É o que torna defensável o fato de esta ser
 * a única consulta de cliente do sistema que ignora o escopo do vendedor. Ver o docblock
 * de App\Services\Cadastros\BuscaTitularidade antes de acrescentar qualquer campo.
 */
import { ref } from 'vue';

const termo = ref('');
const resultados = ref(null);
const buscando = ref(false);
const erro = ref('');
const minimo = ref(3);

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
</script>

<template>
    <div class="mb-4 rounded border border-gray-200 bg-gray-50 p-3">
        <p class="text-sm font-semibold text-gray-700">Quem cuida do cliente?</p>
        <p class="mt-0.5 text-xs text-gray-500">
            Antes de solicitar, confira se o cliente já existe e de quem ele é. Vale para a base
            inteira, não só para a sua carteira.
        </p>

        <div class="mt-2 flex gap-2">
            <input
                v-model="termo"
                type="text"
                maxlength="200"
                autocomplete="off"
                placeholder="Nome ou CNPJ"
                class="w-full max-w-md rounded border-gray-300 text-sm focus:border-cyan focus:ring-cyan"
                @keyup.enter="buscar"
            />
            <button
                type="button"
                class="rounded bg-corp-black px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-40"
                :disabled="buscando"
                @click="buscar"
            >
                {{ buscando ? 'Buscando…' : 'Verificar' }}
            </button>
        </div>

        <p v-if="erro" class="mt-2 text-xs text-red-600">{{ erro }}</p>

        <div v-if="resultados !== null && !erro" class="mt-3">
            <p v-if="!resultados.length" class="text-xs text-gray-500">
                Nenhum cliente nem solicitação pendente com esse nome ou documento. Pode seguir com
                a solicitação abaixo.
            </p>

            <div v-else class="tbl-wrap">
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
        </div>
    </div>
</template>

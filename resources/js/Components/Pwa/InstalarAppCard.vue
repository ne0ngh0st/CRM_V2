<script setup>
/**
 * Como colocar o PALMA na tela inicial — um card no Perfil, não um banner em toda página.
 *
 * O Chrome no Android oferece o atalho sozinho quando o manifesto + SW estão no ar;
 * o iPhone não oferece. Sem este card, o vendedor iOS nunca descobre o caminho
 * (Compartilhar → Adicionar à Tela de Início). Mora no Perfil porque é configuração
 * do aparelho, não destino do dia a dia — não entra na barra inferior nem na gaveta.
 */
import DarkCard from '@/Components/DarkCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { usePwa } from '@/composables/usePwa';

const { instalado, podeInstalar, ehIos, instalar } = usePwa();
</script>

<template>
    <DarkCard
        v-if="!instalado"
        title="Usar como aplicativo"
        subtitle="Atalho na tela inicial do celular, sem loja"
    >
        <template #icon>
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"
                />
            </svg>
        </template>

        <div class="space-y-3 text-sm text-gray-600">
            <template v-if="podeInstalar">
                <p>Abre em tela cheia, igual a um app, sem a barra do navegador.</p>
                <PrimaryButton type="button" class="min-h-11" @click="instalar">
                    Adicionar à tela inicial
                </PrimaryButton>
            </template>

            <ol v-else-if="ehIos" class="list-decimal space-y-1.5 ps-5">
                <li>Toque em <strong class="font-medium text-gray-800">Compartilhar</strong> (o quadrado com a seta para cima).</li>
                <li>Role e toque em <strong class="font-medium text-gray-800">Adicionar à Tela de Início</strong>.</li>
                <li>Toque em <strong class="font-medium text-gray-800">Adicionar</strong>.</li>
            </ol>

            <p v-else>
                No menu do navegador, procure por <strong class="font-medium text-gray-800">Instalar PALMA</strong>
                ou <strong class="font-medium text-gray-800">Adicionar à tela inicial</strong>.
                No iPhone o caminho é Compartilhar → Adicionar à Tela de Início.
            </p>
        </div>
    </DarkCard>
</template>

<?php

namespace Database\Seeders;

use App\Models\Faca;
use App\Models\FacaRecurso;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de facas — dado de referência estático, veio dos 8 JSONs que o legado lia
 * direto do disco (assets/data/catalogo-facas-*.json), copiados pra database/data/facas.
 *
 * O legado casava `detalhes[i]` com `icones[i]` posicionalmente na view, o que só
 * funciona quando os dois arrays têm o mesmo tamanho — e em 74 dos 127 registros eles
 * não têm. Aqui a decisão de pareamento é feita uma vez, na importação:
 *
 *  - linhas "Observação: ..." saem de `detalhes` e viram `facas.observacao` (era isso
 *    que desalinhava a maioria dos itens de balança);
 *  - se sobrar detalhe pra cada ícone, parear (catálogo de balança/especiais/lacres:
 *    cada imagem É um tipo de recorte e o detalhe é o nome dele);
 *  - senão, a imagem é ilustração da faca inteira (sem legenda) e os detalhes viram
 *    recursos só-texto (tags/rótulos/A4/bag tag).
 */
class FacaSeeder extends Seeder
{
    /** arquivo JSON => tipo na tabela (= subpasta em public/images/facas) */
    private const CATALOGOS = [
        'catalogo-facas-balanca.json' => 'balanca',
        'catalogo-facas-etiquetas.json' => 'retangulares',
        'catalogo-facas-etiquetas-a4.json' => 'etiquetas-a4',
        'catalogo-facas-tags.json' => 'tags',
        'catalogo-facas-rotulos.json' => 'rotulos',
        'catalogo-facas-lacres.json' => 'lacres',
        'catalogo-facas-bagtag.json' => 'bagtag',
        'catalogo-facas-especiais.json' => 'especiais',
    ];

    public function run(): void
    {
        // ⚠️ Só popula banco vazio. Desde que a tela ganhou CRUD (admin cadastra faca
        // nova e sobe imagem), reimportar por cima apagaria o que foi cadastrado à mão —
        // e o `db:seed` roda inteiro em toda restauração. Pra forçar a reimportação dos
        // JSONs originais, limpar as duas tabelas antes de rodar.
        if (Faca::query()->exists()) {
            $this->command?->info('Facas já cadastradas ('.Faca::count().') — seeder pulado para não sobrescrever cadastro manual.');

            return;
        }

        DB::transaction(function () {
            foreach (self::CATALOGOS as $arquivo => $tipo) {
                $caminho = database_path('data/facas/'.$arquivo);
                if (! is_readable($caminho)) {
                    $this->command?->warn("Catálogo de facas não encontrado: {$arquivo}");

                    continue;
                }

                $registros = json_decode((string) file_get_contents($caminho), true);
                if (! is_array($registros)) {
                    $this->command?->warn("Catálogo de facas ilegível: {$arquivo}");

                    continue;
                }

                foreach ($registros as $registro) {
                    $this->importarFaca($tipo, $registro);
                }
            }
        });

        $this->command?->info('Facas importadas: '.Faca::count().' ('.FacaRecurso::count().' recursos)');
    }

    /** @param array<string, mixed> $registro */
    private function importarFaca(string $tipo, array $registro): void
    {
        $detalhes = $this->limpar($registro['detalhes'] ?? []);
        $imagens = $this->limpar($registro['icones'] ?? []);

        // "Observação: ..." é nota da faca, não legenda de imagem.
        $observacoes = array_values(array_filter($detalhes, $this->ehObservacao(...)));
        $detalhes = array_values(array_filter($detalhes, fn ($d) => ! $this->ehObservacao($d)));

        // O catálogo de retangulares usa uma chave própria em vez de `detalhes`.
        if (is_string($registro['observacao'] ?? null) && trim($registro['observacao']) !== '') {
            $observacoes[] = trim($registro['observacao']);
        }

        $faca = Faca::create([
            'tipo' => $tipo,
            'item' => (int) ($registro['item'] ?? 0),
            'largura' => $this->medida($registro['largura'] ?? null),
            'altura' => $this->medida($registro['altura'] ?? null),
            'observacao' => $observacoes === [] ? null : implode(' ', array_map(
                fn (string $o) => preg_replace('/^observa[cç][aã]o:\s*/iu', '', $o),
                $observacoes,
            )),
        ]);

        foreach ($this->montarRecursos($tipo, $detalhes, $imagens) as $ordem => $recurso) {
            $faca->recursos()->create($recurso + ['ordem' => $ordem]);
        }
    }

    /**
     * @param  list<string>  $detalhes
     * @param  list<string>  $imagens
     * @return list<array{descricao: ?string, imagem: ?string}>
     */
    private function montarRecursos(string $tipo, array $detalhes, array $imagens): array
    {
        // Caminho web relativo à raiz pública. As imagens que vieram do legado moram em
        // public/images/facas (versionadas no git); as enviadas pela tela vão pra
        // storage/app/public/facas e ficam como "storage/facas/...". Guardar o caminho
        // completo aqui deixa os dois casos uniformes pra quem lê.
        $caminho = fn (string $img) => 'images/facas/'.$tipo.'/'.$img;

        // Cada imagem é um recorte nomeado pelo detalhe correspondente.
        if ($imagens !== [] && count($detalhes) === count($imagens)) {
            return array_map(
                fn (string $descricao, string $imagem) => [
                    'descricao' => $descricao,
                    'imagem' => $caminho($imagem),
                ],
                $detalhes,
                $imagens,
            );
        }

        // Imagem ilustra a faca inteira; detalhes são atributos soltos. Quando o nome do
        // arquivo é descritivo ("LATERAIS + X.png") ele vira legenda; "1.png" não vira.
        $recursos = [];
        foreach ($imagens as $imagem) {
            $recursos[] = [
                'descricao' => $this->legendaDoArquivo($imagem),
                'imagem' => $caminho($imagem),
            ];
        }
        foreach ($detalhes as $descricao) {
            $recursos[] = ['descricao' => $descricao, 'imagem' => null];
        }

        return $recursos;
    }

    private function legendaDoArquivo(string $arquivo): ?string
    {
        $nome = pathinfo($arquivo, PATHINFO_FILENAME);
        if ($nome === '' || is_numeric($nome)) {
            return null;
        }

        return ucfirst(mb_strtolower(str_replace(['_', '-'], ' ', $nome)));
    }

    private function ehObservacao(string $texto): bool
    {
        return (bool) preg_match('/^observa[cç][aã]o\s*:/iu', $texto);
    }

    /** @return list<string> */
    private function limpar(mixed $valores): array
    {
        if (! is_array($valores)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $valores,
        ), fn (string $v) => $v !== ''));
    }

    private function medida(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return trim((string) $valor);
    }
}

<?php

use App\Models\Segmento;
use App\Services\VisaoDiretor\SugestaoDeVinculo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A aba Supermercadista da Maiores por Segmento passa a ser SÓ o ranking da ABRAS (Tony,
 * 2026-10-19): uma linha por rede, na ordem do ranking. Ver `UniversoDoSegmento`.
 *
 * - `contas_estrategicas.posicao_abras` diz qual conta É do ranking. A `ordem` não serve:
 *   conta criada à mão ou pela prospecção também tem ordem. Quem escreve é
 *   `diretor:importar-ranking-abras`; aqui só o preenchimento inicial, pela mesma regra do
 *   comando (nome da conta casando com o nome ou a razão social do ranking, pela
 *   `chaveDeNome`).
 * - `visao_diretor_universo.conta_id` / `posicao`: a linha da rede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->unsignedInteger('posicao_abras')->nullable()->after('ordem');
        });

        Schema::table('visao_diretor_universo', function (Blueprint $table) {
            $table->unsignedBigInteger('conta_id')->nullable()->after('lead_id');
            $table->unsignedInteger('posicao')->nullable()->after('conta_id');
        });

        $this->preencherPosicoes();
    }

    private function preencherPosicoes(): void
    {
        $arquivo = base_path('database/data/visao-diretor/ranking-abras-2026.json');
        $segmentoId = DB::table('segmentos')->where('codigo', Segmento::CODIGO_SUPERMERCADISTA)->value('id');

        if (! $segmentoId || ! is_file($arquivo)) {
            return;
        }

        $nomes = app(SugestaoDeVinculo::class);
        $posicaoPorChave = [];
        foreach (json_decode((string) file_get_contents($arquivo), true)['ranking'] ?? [] as $r) {
            foreach ([$r['nome'], $r['razao_social']] as $n) {
                $posicaoPorChave[$nomes->chaveDeNome($n)] ??= (int) $r['posicao'];
            }
        }

        foreach (DB::table('contas_estrategicas')->where('segmento_id', $segmentoId)->get(['id', 'nome']) as $c) {
            $posicao = $posicaoPorChave[$nomes->chaveDeNome($c->nome)] ?? null;
            if ($posicao !== null) {
                DB::table('contas_estrategicas')->where('id', $c->id)->update(['posicao_abras' => $posicao]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('visao_diretor_universo', function (Blueprint $table) {
            $table->dropColumn(['conta_id', 'posicao']);
        });

        Schema::table('contas_estrategicas', function (Blueprint $table) {
            $table->dropColumn('posicao_abras');
        });
    }
};

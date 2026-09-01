<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarketingWpLeadRaw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportMarketingWpCsvTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_vira_staging_e_lead_usando_data_do_form(): void
    {
        $caminho = sys_get_temp_dir().'/wp-leads-teste.csv';
        File::put($caminho, implode("\n", [
            'Nome,Email,Submitted',
            'Ana,ana@x.com,2024-03-15 10:30:00',
            '',
            'Bruno,bruno@x.com,2024-03-16 09:00:00',
        ]));

        $this->artisan('marketing:import-wp-csv', [
            'arquivo' => $caminho,
            'rotulo' => 'form-contato',
            '--force' => true,
        ])->assertSuccessful();

        File::delete($caminho);

        $this->assertSame(2, MarketingWpLeadRaw::query()->count());
        $this->assertSame(2, Lead::query()->where('origem', Lead::ORIGEM_WORDPRESS)->count());

        $ana = Lead::query()->where('email', 'ana@x.com')->first();
        $this->assertSame('Ana', $ana->nome);
        $this->assertSame('010617', $ana->cod_vendedor);

        $stagingAna = MarketingWpLeadRaw::query()->where('lead_id', $ana->id)->first();
        $this->assertSame('historico_csv', $stagingAna->fonte);
        $this->assertSame('2024-03-15', $stagingAna->recebido_em->toDateString());

        $envelope = json_decode($stagingAna->payload_json, true);
        $this->assertSame('form-contato', $envelope['rotulo']);
        $this->assertSame('Ana', $envelope['colunas']['Nome']);
    }
}

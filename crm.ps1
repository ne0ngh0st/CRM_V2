<#
.SYNOPSIS
    Atalhos para o dia a dia do CRM-V2 rodando em Docker.

.DESCRIPTION
    Nada do stack (PHP, MySQL, Redis) está instalado na máquina — tudo vive dentro dos
    containers. Sem isto, todo comando vira `docker compose exec -T app php artisan ...`.

    Uso:
      .\crm.ps1 up                      # sobe tudo
      .\crm.ps1 down                    # derruba tudo
      .\crm.ps1 art migrate             # qualquer comando artisan
      .\crm.ps1 test                    # suíte completa (banco palma_v2_test)
      .\crm.ps1 test tests/Feature/X.php
      .\crm.ps1 perf --rotas=dashboard  # baseline de performance
      .\crm.ps1 redis INFO keyspace     # redis-cli
      .\crm.ps1 redis -n 1 KEYS *       # chaves de cache (db 1)
      .\crm.ps1 mysql                   # cliente mysql no banco de dev
      .\crm.ps1 logs app                # logs de um serviço
      .\crm.ps1 sh app                  # shell dentro de um container
#>
param(
    [Parameter(Position = 0)]
    [string]$Comando = 'help',

    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$Resto = @()
)

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

switch ($Comando) {
    'up'    { docker compose up -d @Resto }
    'down'  { docker compose down @Resto }
    'ps'    { docker compose ps }
    'logs'  { docker compose logs -f @Resto }

    # Artisan e derivados
    { $_ -in 'art', 'artisan' } { docker compose exec -T app php artisan @Resto }
    'tinker' { docker compose exec app php artisan tinker @Resto }

    'test' {
        # O phpunit.xml aponta pra palma_v2_test com force="true", e tests/TestCase.php
        # aborta se o banco não tiver "test" no nome (Regra de ouro nº 7).
        docker compose exec -T app php artisan test @Resto
    }

    'perf' { docker compose exec -T app php artisan perf:baseline @Resto }

    'redis' {
        if ($Resto.Count -eq 0) { docker compose exec redis redis-cli }
        else { docker compose exec -T redis redis-cli @Resto }
    }

    'mysql' {
        if ($Resto.Count -eq 0) { docker compose exec mysql mysql -uroot -proot palma_v2 }
        else { docker compose exec -T mysql mysql -uroot -proot palma_v2 @Resto }
    }

    'sh' {
        $servico = if ($Resto.Count -gt 0) { $Resto[0] } else { 'app' }
        docker compose exec $servico sh
    }

    default {
        Get-Help $PSCommandPath -Detailed
    }
}

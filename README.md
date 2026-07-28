# PALMA CRM v2

CRM comercial da **Autopel Soluções** — refatoração do PALMA legado (PHP procedural) para **Laravel 11 + Inertia.js + Vue 3**.

Objetivo: manter só o núcleo comercial (~16 páginas), com performance bem melhor que o sistema atual em produção (`gestao-comercial.autopel.com`).

> Contexto detalhado pra agentes/IA e decisões de produto: ver [`CLAUDE.md`](./CLAUDE.md).  
> Planejamento de importação TOTVS: ver [`docs/importacao-dados-legado.md`](./docs/importacao-dados-legado.md).

## Stack

| Camada | Tecnologia |
|--------|------------|
| Backend | Laravel 11 (PHP 8.2+), travado em 11.x |
| Frontend | Inertia.js + Vue 3 (Breeze) |
| Estilo | Tailwind CSS |
| Auth / roles | Laravel Breeze + spatie/laravel-permission |
| Tempo real | Laravel Reverb + Laravel Echo (sino de notificações) |
| PDF | barryvdh/laravel-dompdf |
| Banco local | MySQL (`palma_v2` no XAMPP) |

**Perfis no escopo:** vendedor, representante, supervisor, assistente, admin, diretor.

Fora de escopo: SAC e Licitação (sistemas separados).

## O que já existe

- Login (sem registro público; usuário precisa estar `is_active`)
- **Perfil** — dados, senha e foto de perfil
- **Notificações** — sino em tempo real (Reverb); orçamentos, observações, agendamentos e pedidos em atenção
- **Home / Dashboard** — metas, faturamento, ligações, observações, sugestões, widgets de carteira/orçamentos/pedidos
- **Carteira** — leitura, motivo de inatividade, ligação, agendamento, observações e detalhes do cliente
- **Leads** — prospecção com ligação/agendamento
- **Orçamentos** — formulário completo (IPI/etiqueta), aprovação/rejeição, PDF, copiar
- **Pedidos** — em aberto e emitidos
- **Tabela de preços** — consulta de produtos
- **Cadastros** — solicitações de bobina/etiqueta, cliente e lead manual
- **Metas** — visualização/edição conforme perfil
- **Visão do gestor** — painel gerencial
- **Equipe** — usuários, organograma, segmentos, ações administrativas

## Regras de ouro

1. **Redesenhar > copiar o legado** — não portar gambiarra por inércia.
2. **Só comercial** — nada de SAC/Licitação.
3. **Nunca usar coluna `raiz_cnpj`** — cliente é `(cod_cliente, loja)`.
4. **Carteira/cliente é só leitura no CRM** — cadastro e transferência ficam no TOTVS.
5. **UI consistente** — reusar o design system (`DarkCard`, `PageHero`, `KpiTile`, `StatusPill`, `FilterField`) e o padrão de tabelas/ações da página Equipe.

## Setup local

### Pré-requisitos

- PHP 8.2+, Composer, Node.js 18+
- MySQL do XAMPP ligado (`C:\xampp\mysql_start.bat`)

### Instalação

```bash
composer install
cp .env.example .env   # se ainda não tiver .env
php artisan key:generate

# Ajuste DB_* no .env para o MySQL local (banco palma_v2)
# Confira também BROADCAST_CONNECTION=reverb e as vars REVERB_* / VITE_REVERB_*
php artisan migrate --seed

npm install
npm run build
```

### Rodar (recomendado)

Sobe API, fila, Vite e Reverb num terminal só:

```bash
composer run dev
```

App: [http://localhost:8000](http://localhost:8000)

Sem o Reverb, o app funciona; o sino de notificação só não atualiza em tempo real.

**Usuário de homologação (local):** `antonio.barbosa@autopel.com` / `homolog123`

## Estrutura útil

```
app/
  Events/             # broadcast (ex.: NotificacaoCriada)
  Http/Controllers/
  Jobs/               # notificações agendadas / expurgo
  Services/           # regras de negócio
  Models/
docs/                 # planejamento (import TOTVS etc.)
resources/js/
  Pages/              # uma página Inertia por tela core
  Components/         # design system + domínio (NotificationBell…)
database/
  migrations/
  seeders/
```

## Banco

O CRM-V2 **não conecta no banco de produção** (KingHost) no dia a dia da app. Roda no MySQL local `palma_v2`.

Usuários comerciais vieram de dump/import pontual do legado. Demais domínios hoje usam seeders de desenvolvimento (plano de import real em `docs/`).

## Deploy (planejado)

- Hospedagem: **AWS via Laravel Forge**
- Antes de qualquer deploy real: **`APP_DEBUG=false`**
- Em produção: processos de **queue** e **Reverb** (ou equivalente) além do PHP-FPM/web

## Licença

Projeto interno Autopel — não é open source.

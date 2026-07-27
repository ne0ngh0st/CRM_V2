# PALMA CRM v2

CRM comercial da **Autopel Soluções** — refatoração do PALMA legado (PHP procedural) para **Laravel 11 + Inertia.js + Vue 3**.

Objetivo: manter só o núcleo comercial (~16 páginas), com performance bem melhor que o sistema atual em produção (`gestao-comercial.autopel.com`).

> Contexto detalhado pra agentes/IA e decisões de produto: ver [`CLAUDE.md`](./CLAUDE.md).

## Stack

| Camada | Tecnologia |
|--------|------------|
| Backend | Laravel 11 (PHP 8.2+), travado em 11.x |
| Frontend | Inertia.js + Vue 3 (Breeze) |
| Estilo | Tailwind CSS |
| Auth / roles | Laravel Breeze + spatie/laravel-permission |
| PDF | barryvdh/laravel-dompdf |
| Banco local | MySQL (`palma_v2` no XAMPP) |

**Perfis no escopo:** vendedor, representante, supervisor, assistente, admin, diretor.

Fora de escopo: SAC e Licitação (sistemas separados).

## O que já existe

- Login (sem registro público; usuário precisa estar `is_active`)
- **Home / Dashboard** — metas, faturamento, ligações, observações, sugestões, widgets de carteira/orçamentos/pedidos
- **Carteira** — leitura + anotações (contato, motivo de inatividade, ocultar)
- **Orçamentos** — listagem, formulário, aprovação/rejeição, PDF
- **Pedidos** — pedidos em aberto / emitidos
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
php artisan migrate --seed

npm install
npm run build
```

### Rodar

```bash
# terminal 1 — API / Inertia
php artisan serve --port=8000

# terminal 2 — assets com hot reload (opcional)
npm run dev
```

App: [http://localhost:8000](http://localhost:8000)

**Usuário de homologação (local):** `antonio.barbosa@autopel.com` / `homolog123`

## Estrutura útil

```
app/
  Http/Controllers/   # Dashboard, Carteira, Orçamentos, Pedidos, Equipe…
  Services/           # regras de negócio (escopo, aderência, aprovação…)
  Models/
resources/js/
  Pages/              # uma página Inertia por tela core
  Components/         # design system + componentes por domínio
database/
  migrations/
  seeders/
```

## Banco

O CRM-V2 **não conecta no banco de produção** (KingHost). Roda só no MySQL local `palma_v2`.

Usuários comerciais vieram de um dump pontual do legado (snapshot, sem sync ao vivo). Dados de carteira/orçamentos/pedidos/etc. hoje vêm dos seeders de desenvolvimento.

## Deploy (planejado)

- Hospedagem: **AWS via Laravel Forge**
- Antes de qualquer deploy real: **`APP_DEBUG=false`**

## Licença

Projeto interno Autopel — não é open source.

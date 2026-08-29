# IAM do CRM-V2

## Por que estes arquivos existem separados

O usuário `crm-v2-deploy` — o que o dia a dia do projeto usa — **não tem nenhuma permissão
de IAM**, de propósito (ver `docs/deploy-aws.md` §4.0). Tudo aqui precisa de um profile
admin e é executado raramente, então mora numa pasta própria em vez de misturado ao
`deploy.sh`.

```bash
# PowerShell (o `bash` do PATH é o do WSL e não funciona aqui)
& "$env:LOCALAPPDATA\Programs\Git\bin\bash.exe" -c "AWS_PROFILE=default bash infra/iam/criar-role-s3.sh"
```

## O que a role dá acesso

`crm-v2-app` permite ler, gravar e apagar objetos **de um bucket só**
(`crm-v2-arquivos-890615325644`), e listar esse mesmo bucket. Nada além disso.

### ⚠️ Por que o bucket inteiro, e não prefixo por prefixo

A primeira versão liberava só `uploads/*` e `exports/*`. Parecia mais seguro e estava
**errado**: a aplicação grava em três prefixos diferentes, e nenhum deles se chama
`uploads/`.

| Recurso | Prefixo real | Onde no código |
|---|---|---|
| Imagem de faca | `facas/` | `CatalogoFacaController::186` |
| Foto de perfil | `perfis/` | `ProfileController::65` |
| Planilha exportada | `exports/{id}/` | `GerarExportacaoCarteiraJob::64` |

Pior: o teste de ativação escrevia em `uploads/_teste.txt` — um prefixo que **a aplicação
nunca usa** — então ele passava com folga enquanto todo upload real teria dado
`AccessDenied` em produção. Prova que não percorre o caminho real não prova nada.

Enumerar prefixo por prefixo só funciona se a lista estiver completa e continuar completa;
o bucket é dedicado a esta aplicação e não guarda mais nada, então o ganho de segurança de
enumerar é praticamente zero e o risco de esquecer um prefixo novo é real. Por isso a
política cobre `bucket/*` — **mas segue restrita a este bucket**, que é o limite que
importa.

## Por que role e não usuário com chave

A credencial de uma role vem do metadata service da instância, rotaciona sozinha e **nunca
fica escrita em disco**. Um `.env` vazado não leva o S3 junto.

⚠️ Corolário: **não colocar `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` no `.env`.** Se
alguém colar chaves lá, elas ganham precedência sobre a role e passam a ser a credencial
usada — em silêncio. O `ativar-s3.sh` remove essas duas linhas justamente por isso.

⚠️ **Nunca reaproveitar as chaves do `crm-v2-deploy` na aplicação.** Elas criam EC2, RDS e
ALB; num servidor exposto à internet, isso transforma um comprometimento da aplicação em
comprometimento da infraestrutura inteira.

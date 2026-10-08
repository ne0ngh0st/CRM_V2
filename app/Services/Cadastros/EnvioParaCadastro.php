<?php

namespace App\Services\Cadastros;

use App\Mail\CadastroSolicitacaoMail;
use Illuminate\Support\Facades\Mail;

/**
 * Envio de e-mail para os setores de Cadastro e PCP — o único lugar que sabe os
 * endereços, quem vai em cópia e o modo de teste.
 *
 * Morava `private` dentro do `CadastroController` e saiu de lá quando apareceu o segundo
 * remetente (o pedido de inativação de cliente com CNPJ irregular, na Carteira): copiar
 * destino, cópia do solicitante e o interruptor `CADASTROS_REDIRECIONAR_PARA` para outro
 * controller é exatamente o tipo de cópia que deixa um caminho mandando e-mail para o
 * setor de verdade enquanto o outro está em modo teste (Regra de ouro nº 8).
 *
 * Também guarda o formato do corpo ("TÍTULO\n=====") para os e-mails de todos os
 * remetentes saírem iguais.
 */
class EnvioParaCadastro
{
    /**
     * Destravado em 2026-08-28 depois do Tony confirmar o SMTP validado.
     * `cadastroCliente` é `cadastro.geral@autopel.com` — mudou recentemente,
     * não é mais `cadastro.cliente@autopel.com`.
     */
    public const EMAILS = [
        'pcp' => 'pcp.sp@autopel.com',
        'cadastro' => 'cadastro@autopel.com',
        'cadastroCliente' => 'cadastro.geral@autopel.com',
    ];

    /**
     * Envia (enfileirado) o e-mail de notificação pro setor responsável.
     * O solicitante SEMPRE vai em cópia (pedido do Tony, 2026-08-28) — além
     * do `cc` fixo do setor (quando houver), nunca no lugar dele.
     *
     * `cc` aceita uma lista: a lista diária de inativação leva em cópia todo mundo que
     * pediu algum cliente nela.
     *
     * @param  array{to: string, cc?: string|list<string>|null, solicitanteEmail?: ?string, subject: string, body: string}  $dados
     */
    public function enviar(array $dados, ?string $anexoConteudo = null, ?string $anexoNome = null): void
    {
        $ccs = self::copias($dados);

        $assunto = $dados['subject'];
        $destino = $dados['to'];

        /*
         * Modo teste: manda tudo para um endereço só, sem cc.
         *
         * ⚠️ `config()`, nunca `env()`: em produção o config é cacheado e o .env sequer
         * é lido, então `env()` devolveria null e o redirecionamento sumiria justamente
         * onde ele protege (armadilha 9.2 do docs/deploy-aws.md).
         *
         * O prefixo no assunto carrega o destino real porque o objetivo do teste é
         * conferir o ROTEAMENTO; sem ele você vê o conteúdo e continua sem saber se
         * teria ido pro PCP ou pro Cadastro.
         */
        if ($redirecionar = config('cadastros.redirecionar_emails_para')) {
            $reais = implode(', ', array_values(array_unique(array_filter(
                array_merge([$destino], $ccs)
            ))));

            $assunto = "[TESTE → {$reais}] {$assunto}";
            $destino = $redirecionar;
            $ccs = [];
        }

        $mail = Mail::to($destino);
        if ($ccs !== []) {
            $mail = $mail->cc($ccs);
        }

        $mail->queue(new CadastroSolicitacaoMail($assunto, $dados['body'], $anexoConteudo, $anexoNome));
    }

    /**
     * "pcp.sp@autopel.com (cc: fulano@autopel.com)" — para a mensagem de confirmação.
     *
     * @param  array{to: string, cc?: ?string, solicitanteEmail?: ?string}  $dados
     */
    public static function destinoDescrito(array $dados): string
    {
        $ccs = self::copias($dados);

        return $dados['to'].($ccs !== [] ? ' (cc: '.implode(', ', $ccs).')' : '');
    }

    /** @return list<string> */
    private static function copias(array $dados): array
    {
        return array_values(array_unique(array_filter([
            ...(array) ($dados['cc'] ?? []),
            $dados['solicitanteEmail'] ?? null,
        ])));
    }

    /**
     * Cabeçalho de seção no padrão "TÍTULO\n======\n\n" — formato do legado
     * (`pages/SISTEMA/cadastro.php::montarCorpoEmailCadastro`), unificado aqui
     * pros e-mails todos em vez das duas convenções que coexistiam lá
     * (cabeçalho sublinhado pro cliente, "— TÍTULO —" pra bobina/etiqueta).
     */
    public static function cabecalhoSecao(string $titulo): string
    {
        return $titulo."\n".str_repeat('=', mb_strlen($titulo))."\n\n";
    }

    /** @param array<string, mixed> $pares rótulo => valor (null/vazio vira "-") */
    public static function linhas(array $pares): string
    {
        $corpo = '';
        foreach ($pares as $rotulo => $valor) {
            $corpo .= $rotulo.': '.($valor === null || $valor === '' ? '-' : $valor)."\n";
        }

        return $corpo;
    }

    /** @param array<string, mixed> $pares */
    public static function secao(string $titulo, array $pares): string
    {
        return self::cabecalhoSecao($titulo).self::linhas($pares)."\n";
    }

    public static function blocoTexto(string $titulo, string $texto): string
    {
        return self::cabecalhoSecao($titulo).$texto."\n\n";
    }

    public static function assinatura(): string
    {
        return "Atenciosamente,\nSistema de Gestão Comercial Autopel\n© ".now()->year.' Autopel - Todos os direitos reservados';
    }
}

<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Models\VendedorPerfil;
use App\Services\Escopo\ModoVisao;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Único lugar que decide "quais cod_vendedor entram na agregação" pro usuário logado.
 * No legado essa lógica estava duplicada (e levemente inconsistente) em 4 arquivos
 * PHP diferentes — aqui é resolvida uma vez e reaproveitada por todos os blocos da Home.
 */
class DashboardScopeResolver
{
    /**
     * Memória curta, viva só enquanto esta instância existir.
     *
     * ⚠️ POR QUE AQUI E NÃO NO CONTAINER (`scoped()`/`singleton()`):
     * o resolver é injetado no construtor dos controllers, então dentro de uma requisição
     * já existe uma instância só — memoizar no objeto basta, e o cache morre junto com ele.
     * Registrar como `scoped()` faria a instância sobreviver entre as várias requisições
     * de um mesmo teste, e um teste que alterasse `vendedor_perfis` entre dois `$this->get()`
     * passaria a ler escopo velho. Ganho idêntico, risco menor.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

    /** Perfis que, sem filtro, enxergam a empresa inteira. */
    private const PERFIS_EMPRESA = ['admin', 'diretor'];

    public function __construct(private readonly ModoVisao $modo) {}

    /**
     * Os três níveis do filtro de visão, na ordem da hierarquia: Direção → Supervisão →
     * Vendedor. Só admin e diretor têm o primeiro; para os outros perfis ele é ignorado.
     *
     * @return array{codVendedores: array<string>|null, visaoDiretor: ?string, visaoSupervisor: ?string, visaoVendedor: ?string}
     */
    public function resolve(User $user, ?string $visaoSupervisor, ?string $visaoVendedor, ?string $visaoDiretor = null): array
    {
        // ⚠️ O modo entra na chave do memo: dentro de uma requisição ele não muda, mas
        // um teste que alterne o modo entre dois `resolve()` na mesma instância leria
        // escopo velho — e leria em silêncio, que é o pior jeito de errar escopo.
        $chave = "resolve:{$user->id}:{$visaoDiretor}:{$visaoSupervisor}:{$visaoVendedor}:{$this->modo->atual()}";

        return $this->memo[$chave] ??= $this->resolverEscopo($user, $visaoSupervisor, $visaoVendedor, $visaoDiretor)
            + ['visaoDiretor' => null];
    }

    /**
     * O escopo pedido pelos filtros da tela (`visao_diretor`, `visao_supervisor`,
     * `visao_vendedor`). Os nomes dos três parâmetros moram aqui, e não em cada
     * controller: filtro novo que um deles esquecesse de ler seria uma tela ignorando o
     * filtro em silêncio (Regra de ouro nº 8). Vale também para a Request sintética da
     * exportação em fila, que carrega os mesmos filtros.
     *
     * @return array{codVendedores: array<string>|null, visaoDiretor: ?string, visaoSupervisor: ?string, visaoVendedor: ?string}
     */
    public function resolveDaRequest(User $user, Request $request, bool $comVendedor = true): array
    {
        return $this->resolve(
            $user,
            $request->string('visao_supervisor')->value() ?: null,
            $comVendedor ? ($request->string('visao_vendedor')->value() ?: null) : null,
            $request->string('visao_diretor')->value() ?: null,
        );
    }

    /**
     * A prop `visao` das telas: as opções dos três seletores e o que está selecionado.
     *
     * @param  array{codVendedores: array<string>|null, visaoDiretor: ?string, visaoSupervisor: ?string, visaoVendedor: ?string}  $scope
     * @return array<string, mixed>
     */
    public function visaoParaTela(User $user, array $scope): array
    {
        $role = $this->roleDe($user);
        $gestorDaEmpresa = in_array($role, self::PERFIS_EMPRESA, true);

        // Admin/diretor em "Minha carteira" está olhando o próprio código: os seletores
        // não teriam efeito nenhum, e seletor que não faz nada parece defeito.
        $mostrar = User::temSeletorDeVendedor($role)
            && ! ($gestorDaEmpresa && $this->modo->pessoal() && $user->vendedorPerfil?->cod_vendedor);

        return [
            'mostrarSeletor' => $mostrar,
            'diretores' => $mostrar && $gestorDaEmpresa ? $this->opcoesDiretores() : [],
            'supervisores' => $mostrar && $gestorDaEmpresa ? $this->opcoesSupervisores($scope['visaoDiretor']) : [],
            'vendedores' => $mostrar ? $this->opcoesVendedores($user, $scope['visaoSupervisor'], $scope['visaoDiretor']) : [],
            'visaoDiretor' => $scope['visaoDiretor'],
            'visaoSupervisor' => $scope['visaoSupervisor'],
            'visaoVendedor' => $scope['visaoVendedor'],
        ];
    }

    /**
     * O registro deste vendedor está ao alcance do usuário? A pergunta de "posso abrir
     * este cliente/pedido?" — feita pela Carteira e pelas ferramentas do assistente de
     * IA com a mesma regra (Regra de ouro nº 8).
     *
     * Escopo `null` é a empresa inteira (alcança tudo, inclusive registro sem vendedor);
     * qualquer outro escopo exige o código na lista.
     */
    public function alcanca(User $user, ?string $codVendedor): bool
    {
        $codigos = $this->resolve($user, null, null)['codVendedores'];

        return $codigos === null || ($codVendedor !== null && in_array($codVendedor, $codigos, true));
    }

    /**
     * @return array{codVendedores: array<string>|null, visaoSupervisor: ?string, visaoVendedor: ?string}
     */
    private function resolverEscopo(User $user, ?string $visaoSupervisor, ?string $visaoVendedor, ?string $visaoDiretor): array
    {
        $role = $this->roleDe($user);
        $proprio = $user->vendedorPerfil?->cod_vendedor;

        if (in_array($role, User::PERFIS_CARTEIRA, true)) {
            return ['codVendedores' => $proprio ? [$proprio] : [], 'visaoSupervisor' => null, 'visaoVendedor' => null];
        }

        // O assistente usa o código do supervisor que apoia e vê o mesmo que ele, com o
        // mesmo alternador (User::PERFIS_COM_EQUIPE, desde 2026-11-04).
        if (in_array($role, User::PERFIS_COM_EQUIPE, true)) {
            /*
             * Modo "Minha carteira": o supervisor opera como vendedor.
             *
             * Na Autopel supervisor também vende — exceção da casa, não prática de
             * mercado. Sem isto, os 9.387 clientes que são carteira pessoal de supervisor
             * (10,2% da base) não são vistos por ninguém neste perfil, e o supervisor
             * ROBERTO (000197), que tem equipe VAZIA, via tela em branco no sistema
             * inteiro apesar de ter 1.649 clientes no nome dele.
             *
             * ⚠️ O modo EQUIPE segue sendo equipe PURA (decisão do Tony): a carteira
             * pessoal não se mistura com a da equipe. Quem quiser os dois números soma —
             * o que não pode é o sistema somar por conta própria e ninguém saber o que
             * está olhando.
             */
            if ($this->modo->pessoal() && $proprio) {
                return ['codVendedores' => [$proprio], 'visaoSupervisor' => null, 'visaoVendedor' => null];
            }

            $equipe = $this->equipeDoSupervisor($proprio);

            if ($visaoVendedor && $equipe->contains($visaoVendedor)) {
                return ['codVendedores' => [$visaoVendedor], 'visaoSupervisor' => null, 'visaoVendedor' => $visaoVendedor];
            }

            return ['codVendedores' => $equipe->all(), 'visaoSupervisor' => null, 'visaoVendedor' => null];
        }

        if (in_array($role, self::PERFIS_EMPRESA, true)) {
            /*
             * "Minha carteira" também para admin e diretor (2026-11-12): diretor na
             * Autopel tem carteira no próprio código (Paulo Borin, Roberto Baroli), e sem
             * o alternador ele só chegava nela escolhendo o próprio nome num filtro.
             * No outro modo nada muda: empresa inteira, com os seletores.
             */
            if ($this->modo->pessoal() && $proprio) {
                return ['codVendedores' => [$proprio], 'visaoSupervisor' => null, 'visaoVendedor' => null];
            }

            // Diretor que não está na lista é ignorado (o parâmetro vem da URL).
            $diretor = $this->diretorValido($visaoDiretor);
            $estrutura = $diretor ? $this->estruturaDoDiretor($diretor) : null;
            $dentro = fn (string $cod) => $estrutura === null || $estrutura->contains($cod);

            if ($visaoSupervisor && $dentro($visaoSupervisor)) {
                $equipe = $this->equipeDoSupervisor($visaoSupervisor);

                // Supervisor sem equipe (Roberto Jude): a "equipe" dele é ele mesmo. Sem
                // isto, escolher o nome no filtro daria tela em branco.
                if ($equipe->isEmpty()) {
                    return ['codVendedores' => [$visaoSupervisor], 'visaoDiretor' => $diretor, 'visaoSupervisor' => $visaoSupervisor, 'visaoVendedor' => null];
                }

                // O próprio supervisor é escolhível no seletor de vendedor: é como o
                // gestor chega na carteira pessoal dele sem sair do filtro da equipe.
                if ($visaoVendedor && ($equipe->contains($visaoVendedor) || $visaoVendedor === $visaoSupervisor)) {
                    return ['codVendedores' => [$visaoVendedor], 'visaoDiretor' => $diretor, 'visaoSupervisor' => $visaoSupervisor, 'visaoVendedor' => $visaoVendedor];
                }

                return ['codVendedores' => $equipe->all(), 'visaoDiretor' => $diretor, 'visaoSupervisor' => $visaoSupervisor, 'visaoVendedor' => null];
            }

            if ($visaoVendedor && $dentro($visaoVendedor)) {
                return ['codVendedores' => [$visaoVendedor], 'visaoDiretor' => $diretor, 'visaoSupervisor' => null, 'visaoVendedor' => $visaoVendedor];
            }

            if ($estrutura !== null) {
                return ['codVendedores' => $estrutura->all(), 'visaoDiretor' => $diretor, 'visaoSupervisor' => null, 'visaoVendedor' => null];
            }

            // Sem seleção: agrega a empresa toda.
            return ['codVendedores' => null, 'visaoSupervisor' => null, 'visaoVendedor' => null];
        }

        // Qualquer outro perfil sem escopo de vendas.
        return ['codVendedores' => [], 'visaoSupervisor' => null, 'visaoVendedor' => null];
    }

    /**
     * O primeiro nível do filtro: quem é GERENTE de alguém no TOTVS (`cod_gerente`) —
     * Roberto Baroli, Daniel Dayan, Paulo Borin. Ver `estruturaDoDiretor()`.
     *
     * Não filtra `is_active`: o Daniel não usa o CRM e o usuário dele existe só para dar
     * nome ao código 000054 (migration `2026_11_12_100000`).
     *
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    public function opcoesDiretores(): Collection
    {
        return $this->memo['opcoesDiretores'] ??= $this->gestoresComEquipe(self::PERFIS_EMPRESA, 'cod_gerente');
    }

    private function diretorValido(?string $visaoDiretor): ?string
    {
        return $visaoDiretor && $this->opcoesDiretores()->contains('cod_vendedor', $visaoDiretor) ? $visaoDiretor : null;
    }

    /**
     * O seletor "Supervisão" de admin/diretor: todo supervisor com código, mais os
     * diretores que têm gente respondendo direto a eles (Paulo e Beto também são
     * supervisores de alguém). Com um diretor escolhido, só quem está na estrutura dele.
     *
     * Supervisor SEM equipe entra (Roberto Jude): escolhê-lo mostra a carteira dele — ver
     * `resolverEscopo()`.
     *
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    public function opcoesSupervisores(?string $visaoDiretor = null): Collection
    {
        $todos = $this->memo['opcoesSupervisores'] ??= User::comPerfil(['supervisor'])
            ->where('is_active', true)
            ->whereHas('vendedorPerfil')
            ->with('vendedorPerfil')
            ->get()
            ->map(fn (User $u) => ['cod_vendedor' => $u->vendedorPerfil->cod_vendedor, 'nome' => $u->display_name ?: $u->name])
            // Só os diretores que SUPERVISIONAM alguém (`cod_super`): Paulo e Beto.
            ->concat($this->gestoresComEquipe(self::PERFIS_EMPRESA, 'cod_super'))
            ->unique('cod_vendedor')
            ->sortBy('nome')
            ->values();
        $diretor = $this->diretorValido($visaoDiretor);

        if (! $diretor) {
            return $todos;
        }

        $estrutura = $this->estruturaDoDiretor($diretor);

        return $todos->filter(fn (array $s) => $estrutura->contains($s['cod_vendedor']))->values();
    }

    /**
     * @param  list<string>  $perfis
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    private function gestoresComEquipe(array $perfis, string $coluna): Collection
    {
        return User::comPerfil($perfis)
            ->whereHas('vendedorPerfil', fn ($q) => $q->whereIn('cod_vendedor', VendedorPerfil::query()->whereNotNull($coluna)->select($coluna)))
            ->with('vendedorPerfil')
            ->get()
            ->map(fn (User $u) => ['cod_vendedor' => $u->vendedorPerfil->cod_vendedor, 'nome' => $u->display_name ?: $u->name])
            ->sortBy('nome')
            ->values();
    }

    /**
     * Vendedores/representantes disponíveis pro dropdown, dado quem está logado e
     * (se aplicável) o diretor e o supervisor já selecionados nos outros dropdowns.
     *
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    public function opcoesVendedores(User $user, ?string $visaoSupervisor, ?string $visaoDiretor = null): Collection
    {
        return $this->memo["opcoesVendedores:{$user->id}:{$visaoDiretor}:{$visaoSupervisor}"]
            ??= in_array($this->roleDe($user), self::PERFIS_EMPRESA, true)
                ? $this->opcoesDeNomesDoGestor($visaoSupervisor, $visaoDiretor)
                : $this->calcularOpcoesVendedores($user);
    }

    /**
     * O seletor "Vendedor" de admin/diretor: TODO nome que tem carteira ou equipe —
     * vendedores, representantes, supervisores e os diretores com estrutura.
     *
     * Até 2026-11-12 só entrava quem tinha perfil de vendedor, e a diretoria sentiu falta
     * dos próprios nomes: supervisor e diretor também vendem aqui (Paulo Borin tem 14 mil
     * clientes no código dele) e não eram escolhíveis em filtro nenhum.
     *
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    private function opcoesDeNomesDoGestor(?string $visaoSupervisor, ?string $visaoDiretor): Collection
    {
        // Só usuário ativo: quem saiu não vira nome no seletor. A carteira que ficou no
        // código dele continua dentro da equipe e da diretoria.
        $pessoas = User::comPerfil([...User::PERFIS_CARTEIRA, 'supervisor'])
            ->where('is_active', true)
            ->with('vendedorPerfil')
            ->get()
            ->filter(fn (User $u) => $u->vendedorPerfil !== null)
            ->map(fn (User $u) => [
                'cod_vendedor' => $u->vendedorPerfil->cod_vendedor,
                'nome' => $u->display_name ?: $u->name,
                'cod_super' => $u->vendedorPerfil->cod_super,
            ])
            ->concat($this->opcoesDiretores()->map(fn (array $d) => $d + ['cod_super' => null]));

        $diretor = $this->diretorValido($visaoDiretor);
        $estrutura = $diretor ? $this->estruturaDoDiretor($diretor) : null;

        // Mesmos recortes de `resolverEscopo()`: o que aparece na lista é o que o
        // servidor aceita como seleção.
        if ($visaoSupervisor && ($estrutura === null || $estrutura->contains($visaoSupervisor))) {
            $pessoas = $pessoas->filter(fn (array $p) => $p['cod_super'] === $visaoSupervisor || $p['cod_vendedor'] === $visaoSupervisor);
        } elseif ($estrutura !== null) {
            $pessoas = $pessoas->filter(fn (array $p) => $estrutura->contains($p['cod_vendedor']));
        }

        return $pessoas
            ->map(fn (array $p) => ['cod_vendedor' => $p['cod_vendedor'], 'nome' => $p['nome']])
            ->unique(fn (array $p) => $p['cod_vendedor'].'|'.$p['nome'])
            ->sortBy('nome')
            ->values();
    }

    /**
     * O seletor de quem olha a própria equipe (supervisor e o assistente que o espelha).
     *
     * @return Collection<int, array{cod_vendedor: string, nome: string}>
     */
    private function calcularOpcoesVendedores(User $user): Collection
    {
        $codSuper = in_array($this->roleDe($user), User::PERFIS_COM_EQUIPE, true)
            ? $user->vendedorPerfil?->cod_vendedor
            : null;

        $query = User::comPerfil(User::PERFIS_CARTEIRA)->with('vendedorPerfil');

        if ($codSuper) {
            $query->whereHas('vendedorPerfil', fn ($q) => $q->where('cod_super', $codSuper));
        }

        return $query->get()
            ->filter(fn (User $u) => $u->vendedorPerfil !== null)
            ->map(fn (User $u) => ['cod_vendedor' => $u->vendedorPerfil->cod_vendedor, 'nome' => $u->display_name ?: $u->name])
            ->sortBy('nome')
            ->values();
    }

    /**
     * IDs de `users` dentro do escopo resolvido — pra agregar ligações/observações
     * (que são por `usuario_id`, não por `cod_vendedor`, já que o código pode ser
     * compartilhado entre contas).
     *
     * @param  array{codVendedores: array<string>|null, visaoSupervisor: ?string, visaoVendedor: ?string}  $scope
     * @return array<int>
     */
    public function usuarioIds(User $user, array $scope): array
    {
        $codVendedores = $scope['codVendedores'];
        $chave = "usuarioIds:{$user->id}:".($codVendedores === null ? 'todos' : implode(',', $codVendedores));

        return $this->memo[$chave] ??= $this->calcularUsuarioIds($user, $scope);
    }

    /**
     * @param  array{codVendedores: array<string>|null, visaoSupervisor: ?string, visaoVendedor: ?string}  $scope
     * @return array<int>
     */
    private function calcularUsuarioIds(User $user, array $scope): array
    {
        // Vendedor/representante agrega só o próprio histórico, mesmo que o código seja
        // compartilhado com outra conta. O assistente, desde 2026-11-04, agrega como o
        // supervisor que espelha: todos os usuários do escopo.
        if (in_array($this->roleDe($user), User::PERFIS_CARTEIRA, true)) {
            return [$user->id];
        }

        return $this->usuarioIdsDoEscopo($scope['codVendedores']);
    }

    /**
     * Ids de `users` de um escopo, sem precisar de um usuário logado.
     *
     * Existe para o job de cache warming, que enumera escopos em vez de partir de quem
     * está olhando. Fica aqui, e não no job, para que a regra de "quais usuários compõem
     * este escopo" continue existindo num lugar só (Regra de ouro nº 8) — se divergir, o
     * job aquece uma chave que nenhuma requisição vai procurar.
     *
     * @param  array<string>|null  $codVendedores
     * @return array<int>
     */
    public function usuarioIdsDoEscopo(?array $codVendedores): array
    {
        if ($codVendedores === null) {
            return User::comPerfil(User::PERFIS_CARTEIRA)->pluck('id')->all();
        }

        if ($codVendedores === []) {
            return [];
        }

        return VendedorPerfil::query()
            ->whereIn('cod_vendedor', $codVendedores)
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, string>
     */
    private function equipeDoSupervisor(?string $codSupervisor): Collection
    {
        if (! $codSupervisor) {
            return collect();
        }

        // ⚠️ `titulares()`: a assistente do supervisor tem o código DELE e às vezes o
        // `cod_super` também (Ana Clara: 000115 / 000115). Sem o filtro, a carteira pessoal
        // do supervisor entraria na "equipe pura". Mesma consulta em
        // `EscoposAquecidos::listar()` — se divergir, o aquecimento erra a chave.
        return $this->memo["equipe:{$codSupervisor}"] ??= VendedorPerfil::query()
            ->titulares()
            ->where('cod_super', $codSupervisor)
            ->pluck('cod_vendedor');
    }

    /**
     * A diretoria como o TOTVS a descreve: o código do diretor e todo mundo que tem ele
     * como GERENTE (`vendedor_perfis.cod_gerente`, o COD_GERENTE dos relatórios).
     *
     * ⚠️ É `cod_gerente`, e NÃO a cadeia de `cod_super` (decisão do Tony, 2026-11-12): o
     * filtro tem que ser reflexo da hierarquia dos relatórios, e lá as duas não coincidem —
     * o Américo responde ao Paulo como supervisor e ao Daniel Dayan como gerente. Por isso
     * um supervisor pode estar numa diretoria com a equipe dele em outra.
     *
     * ⚠️ Só entra código que tem usuário no CRM (`titulares()`). Os ~320 códigos do TOTVS
     * sem usuário (gente que saiu) não pertencem a diretoria nenhuma e só aparecem em
     * "Todas as Diretorias" — logo, as diretorias somadas NÃO fecham com a empresa.
     *
     * @return Collection<int, string>
     */
    public function estruturaDoDiretor(string $codDiretor): Collection
    {
        return $this->memo["estrutura:{$codDiretor}"] ??= VendedorPerfil::query()
            ->titulares()
            ->where('cod_gerente', $codDiretor)
            ->pluck('cod_vendedor')
            ->prepend($codDiretor)
            ->unique()
            ->values();
    }

    /**
     * O perfil do usuário é consultado por quase todo método daqui. Sem memoizar, cada
     * chamada de `getRoleNames()` pode ir ao banco de novo — e como os controllers chamam
     * o resolver várias vezes por requisição (o de Orçamentos, oito), isso se multiplica.
     */
    private function roleDe(User $user): ?string
    {
        return $this->memo["role:{$user->id}"] ??= $user->getRoleNames()->first();
    }
}

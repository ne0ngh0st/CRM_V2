<?php

namespace App\Services\Ia;

use App\Models\IaConversa;
use App\Models\User;

/** Quem perguntou, em qual conversa e de qual tela. */
final class ContextoIa
{
    public function __construct(
        public readonly User $user,
        public readonly IaConversa $conversa,
        public readonly ?string $tela = null,
    ) {}
}

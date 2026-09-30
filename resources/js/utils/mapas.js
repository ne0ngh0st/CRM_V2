/*
 * Link de um endereço para o Google Maps — o ÚNICO lugar do front que conhece essa URL.
 *
 * É a URL pública de busca do Maps (`/maps/search/?api=1&query=`): não usa chave de API,
 * não tem custo e não exige geocodificação do nosso lado — quem resolve o endereço é o
 * Google, na hora do clique. O Street View fica a um clique de lá.
 *
 * ⚠️ Sem logradouro E município não há link: "SP" ou só o nome da cidade abriria o mapa
 * num lugar que não é o cliente, e link que leva ao lugar errado é pior que texto puro.
 *
 * ⚠️ CPF não ganha link (LGPD): o endereço de pessoa física é a casa de alguém, e o
 * Street View mostraria a fachada dela. O cadastro continua exibindo o texto.
 */
export function ehCpf(documento) {
    return String(documento ?? '').replace(/\D/g, '').length === 11;
}

export function urlDoMapa({ logradouro, municipio, uf, cep, documento } = {}) {
    const rua = String(logradouro ?? '').trim();
    const cidade = String(municipio ?? '').trim();

    if (! rua || ! cidade || ehCpf(documento)) {
        return null;
    }

    const consulta = [rua, uf ? `${cidade} - ${uf}` : cidade, String(cep ?? '').trim()]
        .filter(Boolean)
        .join(', ');

    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(consulta)}`;
}

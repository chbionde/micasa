import axios from 'axios'

/**
 * Toda resposta de API Resource do Laravel vem embrulhada em `data`.
 * O envelope existe para caber metadados (paginação, por exemplo) sem
 * mudar o formato do conteúdo — por isso o mantemos e desembrulhamos aqui.
 */
export type Envelope<T> = { data: T }

// withCredentials: os cookies de sessão viajam nas requisições cross-origin.
// withXSRFToken: axios lê o cookie XSRF-TOKEN e ecoa no header X-XSRF-TOKEN,
// que é o contrato anti-CSRF do Laravel.
//
// baseURL depende do ambiente (ADR-020):
//   produção — API e SPA na mesma origem; string vazia faz o axios usar
//              caminhos relativos, então o domínio não fica embutido no build.
//   dev      — duas origens (Vite em :5173, API em :8000), que é onde CORS e
//              Sanctum continuam sendo exercitados.
// O default por `import.meta.env.DEV` é proposital: um build de produção sem
// variável nenhuma vai para a origem certa em vez de apontar para localhost.
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? (import.meta.env.DEV ? 'http://localhost:8000' : ''),
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

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
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://localhost:8000',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

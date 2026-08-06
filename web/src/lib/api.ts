import axios from 'axios'

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

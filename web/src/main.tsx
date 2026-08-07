import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router/dom'
import { AuthProvider } from './features/auth/AuthContext'
import { router } from './router'
import './index.css'

// staleTime: por quanto tempo o dado em cache é considerado fresco. Zero
// (padrão) refaz a busca a cada foco na aba; 30s evita rajada de
// requisições ao trocar de tela, sem deixar a lista velha na cozinha.
const queryClient = new QueryClient({
  defaultOptions: {
    queries: { staleTime: 30_000, retry: 1 },
  },
})

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </QueryClientProvider>
  </StrictMode>,
)

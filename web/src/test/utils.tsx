import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import type { RouteObject } from 'react-router'
import { AuthProvider } from '../features/auth/AuthContext'

/**
 * QueryClient novo a cada teste: cache compartilhado entre testes faria um
 * teste enxergar dado de outro. retry desligado para o teste de erro não
 * esperar as tentativas automáticas.
 */
export function novoQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, staleTime: 0 },
      mutations: { retry: false },
    },
  })
}

export function renderComRotas(rotas: RouteObject[], entrada: string) {
  const router = createMemoryRouter(rotas, { initialEntries: [entrada] })

  return render(
    <QueryClientProvider client={novoQueryClient()}>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </QueryClientProvider>,
  )
}

export function comProvedores(children: ReactNode) {
  return (
    <QueryClientProvider client={novoQueryClient()}>
      <AuthProvider>{children}</AuthProvider>
    </QueryClientProvider>
  )
}

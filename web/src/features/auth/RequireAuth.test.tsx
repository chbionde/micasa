import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { api } from '../../lib/api'
import { AuthProvider } from './AuthContext'
import { RequireAuth } from './RequireAuth'

vi.mock('../../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

const apiGet = vi.mocked(api.get)

function renderRotaPrivada() {
  const router = createMemoryRouter(
    [
      {
        element: <RequireAuth />,
        children: [{ path: '/', element: <p>conteúdo privado</p> }],
      },
      { path: '/login', element: <p>tela de login</p> },
    ],
    { initialEntries: ['/'] },
  )

  render(
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>,
  )
}

describe('RequireAuth', () => {
  beforeEach(() => {
    vi.resetAllMocks()
  })

  it('redireciona visitante para o login', async () => {
    apiGet.mockRejectedValue(new Error('401'))

    renderRotaPrivada()

    expect(await screen.findByText('tela de login')).toBeInTheDocument()
    expect(screen.queryByText('conteúdo privado')).not.toBeInTheDocument()
  })

  it('renderiza o conteúdo para quem tem sessão', async () => {
    apiGet.mockResolvedValue({
      data: { data: { id: 1, name: 'Carlos', email: 'carlos@exemplo.com.br', casa_ativa: null } },
    })

    renderRotaPrivada()

    expect(await screen.findByText('conteúdo privado')).toBeInTheDocument()
  })

  it('mostra carregamento enquanto a sessão é verificada', () => {
    apiGet.mockReturnValue(new Promise(() => {})) // nunca resolve

    renderRotaPrivada()

    expect(screen.getByText('Carregando…')).toBeInTheDocument()
  })
})

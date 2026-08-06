import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError } from 'axios'
import type { AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AuthProvider } from '../features/auth/AuthContext'
import { api } from '../lib/api'
import { LoginPage } from './LoginPage'

vi.mock('../lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
  },
}))

const apiGet = vi.mocked(api.get)
const apiPost = vi.mocked(api.post)

function erro422(errors: Record<string, string[]>): AxiosError {
  const response = {
    status: 422,
    data: { errors },
  } as AxiosResponse

  return new AxiosError(
    'Unprocessable Content',
    'ERR_BAD_REQUEST',
    {} as InternalAxiosRequestConfig,
    null,
    response,
  )
}

function renderLogin() {
  const router = createMemoryRouter(
    [
      { path: '/login', element: <LoginPage /> },
      { path: '/', element: <p>área logada</p> },
    ],
    { initialEntries: ['/login'] },
  )

  render(
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    // Checagem de sessão do AuthProvider ao montar: sem sessão.
    apiGet.mockRejectedValue(erro422({}))
  })

  it('mostra a mensagem de erro do servidor em credenciais inválidas', async () => {
    const user = userEvent.setup()
    apiGet.mockImplementation((url) =>
      url === '/sanctum/csrf-cookie' ? Promise.resolve({}) : Promise.reject(erro422({})),
    )
    apiPost.mockRejectedValue(
      erro422({ email: ['As credenciais informadas não correspondem aos nossos registros.'] }),
    )

    renderLogin()

    await user.type(await screen.findByLabelText('E-mail'), 'carlos@exemplo.com.br')
    await user.type(screen.getByLabelText('Senha'), 'senha-errada')
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'As credenciais informadas não correspondem aos nossos registros.',
    )
  })

  it('navega para a área logada quando o login dá certo', async () => {
    const user = userEvent.setup()
    const usuario = { id: 1, name: 'Carlos', email: 'carlos@exemplo.com.br' }
    apiGet.mockImplementation((url) => {
      if (url === '/sanctum/csrf-cookie') return Promise.resolve({})
      if (apiPost.mock.calls.length > 0) return Promise.resolve({ data: usuario })
      return Promise.reject(erro422({}))
    })
    apiPost.mockResolvedValue({})

    renderLogin()

    await user.type(await screen.findByLabelText('E-mail'), 'carlos@exemplo.com.br')
    await user.type(screen.getByLabelText('Senha'), 'senha-certa')
    await user.click(screen.getByRole('button', { name: 'Entrar' }))

    expect(await screen.findByText('área logada')).toBeInTheDocument()
    expect(apiPost).toHaveBeenCalledWith('/login', {
      email: 'carlos@exemplo.com.br',
      password: 'senha-certa',
    })
  })
})

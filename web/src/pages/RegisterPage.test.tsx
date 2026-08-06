import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AuthProvider } from '../features/auth/AuthContext'
import { api } from '../lib/api'
import { RegisterPage } from './RegisterPage'

vi.mock('../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
}))

const apiGet = vi.mocked(api.get)
const apiPost = vi.mocked(api.post)

function renderRegistro() {
  const router = createMemoryRouter(
    [
      { path: '/registrar', element: <RegisterPage /> },
      { path: '/', element: <p>área logada</p> },
    ],
    { initialEntries: ['/registrar'] },
  )

  render(
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>,
  )
}

async function preencherCamposObrigatorios(user: ReturnType<typeof userEvent.setup>) {
  await user.type(await screen.findByLabelText('Nome'), 'Carlos Bionde')
  await user.type(screen.getByLabelText('E-mail'), 'carlos@exemplo.com.br')
  await user.type(screen.getByLabelText('Senha'), 'senha-forte-123')
  await user.type(screen.getByLabelText('Confirme a senha'), 'senha-forte-123')
}

describe('RegisterPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    apiGet.mockImplementation((url) =>
      url === '/sanctum/csrf-cookie'
        ? Promise.resolve({})
        : apiPost.mock.calls.length > 0
          ? Promise.resolve({
              data: {
                data: {
                  id: 1,
                  name: 'Carlos Bionde',
                  email: 'carlos@exemplo.com.br',
                  casa_ativa: null,
                },
              },
            })
          : Promise.reject(new Error('sem sessão')),
    )
    apiPost.mockResolvedValue({})
  })

  it('envia o nome da casa quando informado', async () => {
    const user = userEvent.setup()
    renderRegistro()

    await preencherCamposObrigatorios(user)
    await user.type(screen.getByLabelText('Nome da casa (opcional)'), 'Apê da Praia')
    await user.click(screen.getByRole('button', { name: 'Criar conta' }))

    expect(await screen.findByText('área logada')).toBeInTheDocument()
    expect(apiPost).toHaveBeenCalledWith(
      '/register',
      expect.objectContaining({ household_name: 'Apê da Praia' }),
    )
  })

  it('omite o nome da casa quando deixado em branco', async () => {
    const user = userEvent.setup()
    renderRegistro()

    await preencherCamposObrigatorios(user)
    await user.click(screen.getByRole('button', { name: 'Criar conta' }))

    expect(await screen.findByText('área logada')).toBeInTheDocument()
    expect(apiPost).toHaveBeenCalledWith(
      '/register',
      expect.objectContaining({ household_name: undefined }),
    )
  })
})

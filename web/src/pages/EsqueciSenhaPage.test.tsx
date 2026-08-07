import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { api } from '../lib/api'
import { EsqueciSenhaPage } from './EsqueciSenhaPage'
import { RedefinirSenhaPage } from './RedefinirSenhaPage'
import { renderComRotas } from '../test/utils'

vi.mock('../lib/api', async () => {
  const real = await vi.importActual<typeof import('../lib/api')>('../lib/api')
  return { ...real, api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

const apiGet = vi.mocked(api.get)
const apiPost = vi.mocked(api.post)

const NEUTRA = 'Se existir uma conta com esse e-mail, enviamos o link de redefinição.'

describe('EsqueciSenhaPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    apiGet.mockRejectedValue(new Error('sem sessão'))
  })

  it('mostra a mensagem neutra do servidor', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue({ data: { message: NEUTRA } })

    renderComRotas([{ path: '/esqueci-senha', element: <EsqueciSenhaPage /> }], '/esqueci-senha')

    await user.type(await screen.findByLabelText('E-mail'), 'carlos@exemplo.com.br')
    await user.click(screen.getByRole('button', { name: 'Enviar link' }))

    // A tela não revela se a conta existe — repete o que a API respondeu.
    expect(await screen.findByText(NEUTRA)).toBeInTheDocument()
    expect(apiPost).toHaveBeenCalledWith('/forgot-password', { email: 'carlos@exemplo.com.br' })
  })
})

describe('RedefinirSenhaPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    apiGet.mockRejectedValue(new Error('sem sessão'))
  })

  it('preenche o e-mail vindo do link e redefine a senha', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue({})

    renderComRotas(
      [
        { path: '/redefinir-senha/:token', element: <RedefinirSenhaPage /> },
        { path: '/login', element: <p>tela de login</p> },
      ],
      '/redefinir-senha/token-abc?email=carlos%40exemplo.com.br',
    )

    expect(await screen.findByLabelText('E-mail')).toHaveValue('carlos@exemplo.com.br')

    await user.type(screen.getByLabelText('Nova senha'), 'senha-nova-forte-1')
    await user.type(screen.getByLabelText('Confirme a nova senha'), 'senha-nova-forte-1')
    await user.click(screen.getByRole('button', { name: 'Salvar nova senha' }))

    expect(await screen.findByText('tela de login')).toBeInTheDocument()
    expect(apiPost).toHaveBeenCalledWith('/reset-password', {
      token: 'token-abc',
      email: 'carlos@exemplo.com.br',
      password: 'senha-nova-forte-1',
      password_confirmation: 'senha-nova-forte-1',
    })
  })
})

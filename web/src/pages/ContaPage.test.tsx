import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError } from 'axios'
import type { AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { api } from '../lib/api'
import { ContaPage } from './ContaPage'
import { renderComRotas } from '../test/utils'

vi.mock('../lib/api', async () => {
  const real = await vi.importActual<typeof import('../lib/api')>('../lib/api')
  return { ...real, api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

const apiGet = vi.mocked(api.get)
const apiPatch = vi.mocked(api.patch)
const apiPut = vi.mocked(api.put)
const apiDelete = vi.mocked(api.delete)

const USUARIO = {
  data: { data: { id: 1, name: 'Carlos', email: 'carlos@exemplo.com.br', casa_ativa: null } },
}

function erro422(errors: Record<string, string[]>): AxiosError {
  return new AxiosError('', 'ERR_BAD_REQUEST', {} as InternalAxiosRequestConfig, null, {
    status: 422,
    data: { errors },
  } as AxiosResponse)
}

function renderConta() {
  renderComRotas(
    [
      { path: '/conta', element: <ContaPage /> },
      { path: '/entrar', element: <p>tela de login</p> },
    ],
    '/conta',
  )
}

describe('ContaPage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    apiGet.mockResolvedValue(USUARIO)
  })

  it('salva nome e e-mail', async () => {
    const user = userEvent.setup()
    apiPatch.mockResolvedValue(USUARIO)
    renderConta()

    const campoNome = await screen.findByLabelText('Nome')
    await user.clear(campoNome)
    await user.type(campoNome, 'Carlos Bionde')
    await user.click(screen.getByRole('button', { name: 'Salvar dados' }))

    expect(apiPatch).toHaveBeenCalledWith('/api/user/profile', {
      name: 'Carlos Bionde',
      email: 'carlos@exemplo.com.br',
    })
    expect(await screen.findByText('Dados atualizados.')).toBeInTheDocument()
  })

  it('não pede senha para trocar só o nome', async () => {
    const user = userEvent.setup()
    apiPatch.mockResolvedValue(USUARIO)
    renderConta()

    const campoNome = await screen.findByLabelText('Nome')
    await user.clear(campoNome)
    await user.type(campoNome, 'Carlos Bionde')

    expect(screen.queryByLabelText('Confirme sua senha')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Salvar dados' }))

    // Sem `current_password` na carga: a senha não viaja quando não é exigida.
    expect(apiPatch).toHaveBeenCalledWith('/api/user/profile', {
      name: 'Carlos Bionde',
      email: 'carlos@exemplo.com.br',
    })
  })

  it('pede a senha atual assim que o e-mail muda, e a envia junto', async () => {
    const user = userEvent.setup()
    apiPatch.mockResolvedValue(USUARIO)
    renderConta()

    const campoEmail = await screen.findByLabelText('E-mail')
    await user.clear(campoEmail)
    await user.type(campoEmail, 'novo@exemplo.com.br')

    await user.type(screen.getByLabelText('Confirme sua senha'), 'minha-senha')
    await user.click(screen.getByRole('button', { name: 'Salvar dados' }))

    expect(apiPatch).toHaveBeenCalledWith('/api/user/profile', {
      name: 'Carlos',
      email: 'novo@exemplo.com.br',
      current_password: 'minha-senha',
    })
  })

  it('esconde o pedido de senha se o e-mail voltar ao original', async () => {
    const user = userEvent.setup()
    renderConta()

    const campoEmail = await screen.findByLabelText('E-mail')
    await user.type(campoEmail, 'x')
    expect(screen.getByLabelText('Confirme sua senha')).toBeInTheDocument()

    await user.clear(campoEmail)
    await user.type(campoEmail, 'carlos@exemplo.com.br')
    expect(screen.queryByLabelText('Confirme sua senha')).not.toBeInTheDocument()
  })

  it('mostra o erro quando a senha da troca de e-mail está errada', async () => {
    const user = userEvent.setup()
    apiPatch.mockRejectedValue(erro422({ current_password: ['A senha informada está incorreta.'] }))
    renderConta()

    const campoEmail = await screen.findByLabelText('E-mail')
    await user.clear(campoEmail)
    await user.type(campoEmail, 'novo@exemplo.com.br')
    await user.type(screen.getByLabelText('Confirme sua senha'), 'chute')
    await user.click(screen.getByRole('button', { name: 'Salvar dados' }))

    expect(await screen.findByText('A senha informada está incorreta.')).toBeInTheDocument()
  })

  it('mostra erro de e-mail já cadastrado', async () => {
    const user = userEvent.setup()
    apiPatch.mockRejectedValue(erro422({ email: ['Este e-mail já está em uso.'] }))
    renderConta()

    await user.click(await screen.findByRole('button', { name: 'Salvar dados' }))

    expect(await screen.findByText('Este e-mail já está em uso.')).toBeInTheDocument()
  })

  it('troca a senha e limpa os campos', async () => {
    const user = userEvent.setup()
    apiPut.mockResolvedValue({})
    renderConta()

    await user.type(await screen.findByLabelText('Senha atual'), 'senha-antiga')
    await user.type(screen.getByLabelText('Nova senha'), 'senha-nova-forte-1')
    await user.type(screen.getByLabelText('Confirme a nova senha'), 'senha-nova-forte-1')
    await user.click(screen.getByRole('button', { name: 'Trocar senha' }))

    expect(await screen.findByText('Senha trocada.')).toBeInTheDocument()
    expect(screen.getByLabelText('Senha atual')).toHaveValue('')
  })

  it('mostra erro quando a senha atual está errada', async () => {
    const user = userEvent.setup()
    apiPut.mockRejectedValue(erro422({ current_password: ['A senha informada está incorreta.'] }))
    renderConta()

    await user.type(await screen.findByLabelText('Senha atual'), 'chute')
    await user.type(screen.getByLabelText('Nova senha'), 'senha-nova-forte-1')
    await user.type(screen.getByLabelText('Confirme a nova senha'), 'senha-nova-forte-1')
    await user.click(screen.getByRole('button', { name: 'Trocar senha' }))

    expect(await screen.findByText('A senha informada está incorreta.')).toBeInTheDocument()
  })

  it('exige senha em duas etapas para apagar a conta', async () => {
    const user = userEvent.setup()
    apiDelete.mockResolvedValue({})
    renderConta()

    // Primeiro clique só revela a confirmação — nada é enviado ainda.
    await user.click(await screen.findByRole('button', { name: 'Quero apagar minha conta' }))
    expect(apiDelete).not.toHaveBeenCalled()

    await user.type(screen.getByLabelText('Confirme sua senha para apagar'), 'minha-senha')
    await user.click(screen.getByRole('button', { name: 'Apagar definitivamente' }))

    expect(apiDelete).toHaveBeenCalledWith('/api/user', { data: { password: 'minha-senha' } })
  })

  it('mostra o motivo quando a exclusão é bloqueada', async () => {
    const user = userEvent.setup()
    apiDelete.mockRejectedValue(
      erro422({ password: ['Você é o único administrador de: Casa da Família.'] }),
    )
    renderConta()

    await user.click(await screen.findByRole('button', { name: 'Quero apagar minha conta' }))
    await user.type(screen.getByLabelText('Confirme sua senha para apagar'), 'senha')
    await user.click(screen.getByRole('button', { name: 'Apagar definitivamente' }))

    expect(await screen.findByText(/único administrador/)).toBeInTheDocument()
  })
})

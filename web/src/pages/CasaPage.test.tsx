import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { api } from '../lib/api'
import { CasaPage } from './CasaPage'
import { renderComRotas } from '../test/utils'

vi.mock('../lib/api', async () => {
  const real = await vi.importActual<typeof import('../lib/api')>('../lib/api')
  return { ...real, api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }
})

const apiGet = vi.mocked(api.get)
const apiPost = vi.mocked(api.post)
const apiPatch = vi.mocked(api.patch)
const apiDelete = vi.mocked(api.delete)

const CASA = { id: 7, nome: 'Casa de Carlos', fuso: 'America/Sao_Paulo', meu_papel: 'admin' }
const MEMBROS = [
  {
    id: 1,
    nome: 'Carlos',
    email: 'carlos@exemplo.com.br',
    papel: 'admin',
    papel_label: 'Administrador',
    sou_eu: true,
  },
  {
    id: 2,
    nome: 'Maria',
    email: 'maria@exemplo.com.br',
    papel: 'member',
    papel_label: 'Membro',
    sou_eu: false,
  },
]

function mockarApi(papelDoUsuario: 'admin' | 'member' = 'admin') {
  apiGet.mockImplementation((url: string) => {
    if (url === '/api/user') {
      return Promise.resolve({
        data: {
          data: {
            id: 1,
            name: 'Carlos',
            email: 'carlos@exemplo.com.br',
            casa_ativa: { ...CASA, meu_papel: papelDoUsuario },
          },
        },
      })
    }
    if (url.endsWith('/members')) return Promise.resolve({ data: { data: MEMBROS } })
    if (url.endsWith('/invitations')) return Promise.resolve({ data: { data: [] } })
    return Promise.reject(new Error(`URL não mockada: ${url}`))
  })
}

function renderCasa() {
  renderComRotas([{ path: '/casa', element: <CasaPage /> }], '/casa')
}

describe('CasaPage', () => {
  beforeEach(() => vi.resetAllMocks())

  it('lista os membros da casa ativa', async () => {
    mockarApi()
    renderCasa()

    expect(await screen.findByText('Maria')).toBeInTheDocument()
    expect(screen.getByText('carlos@exemplo.com.br')).toBeInTheDocument()
    expect(screen.getByText('(você)')).toBeInTheDocument()
  })

  it('admin muda o papel de um membro', async () => {
    const user = userEvent.setup()
    mockarApi()
    apiPatch.mockResolvedValue({ data: { data: { ...MEMBROS[1], papel: 'admin' } } })
    renderCasa()

    await user.selectOptions(await screen.findByLabelText('Papel de Maria'), 'admin')

    expect(apiPatch).toHaveBeenCalledWith('/api/households/7/members/2', { papel: 'admin' })
  })

  it('membro comum não vê controles de administração', async () => {
    mockarApi('member')
    renderCasa()

    expect(await screen.findByText('Maria')).toBeInTheDocument()
    expect(screen.queryByLabelText('Papel de Maria')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Gerar link de convite' })).not.toBeInTheDocument()
    // Sair da própria casa continua disponível.
    expect(screen.getByRole('button', { name: 'Sair da casa' })).toBeInTheDocument()
  })

  it('gera o link de convite e mostra o aviso de que ele aparece uma vez só', async () => {
    const user = userEvent.setup()
    mockarApi()
    apiPost.mockResolvedValue({
      data: {
        data: { id: 9, papel: 'member', papel_label: 'Membro', situacao: 'pendente', expira_em: '', criado_em: null },
        token: 'token-secreto-123',
      },
    })
    renderCasa()

    await user.click(await screen.findByRole('button', { name: 'Gerar link de convite' }))

    const aviso = await screen.findByText(/aparece só agora/i)
    expect(aviso).toBeInTheDocument()
    expect(screen.getByText(/\/convite\/token-secreto-123$/)).toBeInTheDocument()
  })

  it('admin renomeia a casa', async () => {
    const user = userEvent.setup()
    mockarApi()
    apiPatch.mockResolvedValue({ data: { data: { ...CASA, nome: 'Apê da Praia' } } })
    renderCasa()

    await user.click(await screen.findByRole('button', { name: 'Renomear' }))
    const campo = screen.getByLabelText('Nome da casa')
    await user.clear(campo)
    await user.type(campo, 'Apê da Praia')
    await user.click(screen.getByRole('button', { name: 'Salvar' }))

    expect(apiPatch).toHaveBeenCalledWith('/api/households/7', { nome: 'Apê da Praia' })
  })

  it('membro comum não vê o botão de renomear', async () => {
    mockarApi('member')
    renderCasa()

    expect(await screen.findByText('Maria')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Renomear' })).not.toBeInTheDocument()
  })

  it('avisa que a casa será apagada antes de sair sozinho', async () => {
    const user = userEvent.setup()
    // Casa com uma pessoa só: sair apaga a casa.
    apiGet.mockImplementation((url: string) => {
      if (url === '/api/user') {
        return Promise.resolve({
          data: { data: { id: 1, name: 'Carlos', email: 'c@e.com', casa_ativa: CASA } },
        })
      }
      if (url.endsWith('/members')) return Promise.resolve({ data: { data: [MEMBROS[0]] } })
      if (url.endsWith('/invitations')) return Promise.resolve({ data: { data: [] } })
      return Promise.reject(new Error('não mockado'))
    })
    const confirmar = vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderCasa()

    await user.click(await screen.findByRole('button', { name: 'Sair da casa' }))

    expect(confirmar).toHaveBeenCalledWith(expect.stringContaining('a casa e os convites pendentes serão apagados'))
    // Recusou a confirmação: nada foi enviado.
    expect(apiDelete).not.toHaveBeenCalled()
    confirmar.mockRestore()
  })

  it('avisa quando não há casa ativa', async () => {
    apiGet.mockResolvedValue({
      data: { data: { id: 1, name: 'Carlos', email: 'c@e.com', casa_ativa: null } },
    })
    renderCasa()

    expect(await screen.findByText(/não está em nenhuma casa/i)).toBeInTheDocument()
  })

  it('mostra erro do servidor ao tentar rebaixar o último admin', async () => {
    const user = userEvent.setup()
    mockarApi()
    apiPatch.mockRejectedValue({
      response: { status: 422, data: { message: 'A casa precisa de pelo menos um administrador.' } },
    })
    renderCasa()

    await user.selectOptions(await screen.findByLabelText('Papel de Carlos'), 'member')

    const alerta = await screen.findByRole('alert')
    expect(within(alerta).getByText(/pelo menos um administrador/i)).toBeInTheDocument()
  })
})

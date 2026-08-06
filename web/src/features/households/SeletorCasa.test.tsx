import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import { api } from '../../lib/api'
import { SeletorCasa } from './SeletorCasa'
import { renderComRotas } from '../../test/utils'

vi.mock('../../lib/api', async () => {
  const real = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return { ...real, api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() } }
})

const apiGet = vi.mocked(api.get)

const CASA_A = { id: 1, nome: 'Casa de Carlos', fuso: 'America/Sao_Paulo', meu_papel: 'admin' }
const CASA_B = { id: 2, nome: 'Casa dos pais', fuso: 'America/Sao_Paulo', meu_papel: 'member' }

function mockar(casas: unknown[]) {
  apiGet.mockImplementation((url: string) => {
    if (url === '/api/user') {
      return Promise.resolve({
        data: { data: { id: 1, name: 'Carlos', email: 'c@e.com', casa_ativa: CASA_A } },
      })
    }
    if (url === '/api/households') return Promise.resolve({ data: { data: casas } })
    return Promise.reject(new Error('não mockado'))
  })
}

function renderSeletor() {
  renderComRotas([{ path: '/', element: <SeletorCasa /> }], '/')
}

describe('SeletorCasa', () => {
  beforeEach(() => vi.resetAllMocks())

  it('não aparece quando a pessoa tem uma casa só', async () => {
    mockar([CASA_A])
    renderSeletor()

    // Espera a busca resolver antes de afirmar a ausência, senão o teste
    // passaria só porque a tela ainda não terminou de carregar.
    await vi.waitFor(() => expect(apiGet).toHaveBeenCalledWith('/api/households'))
    expect(screen.queryByLabelText('Casa ativa')).not.toBeInTheDocument()
  })

  it('lista as casas quando há mais de uma', async () => {
    mockar([CASA_A, CASA_B])
    renderSeletor()

    const seletor = await screen.findByLabelText('Casa ativa')
    expect(seletor).toHaveValue('1')
    expect(screen.getByRole('option', { name: 'Casa dos pais' })).toBeInTheDocument()
  })
})

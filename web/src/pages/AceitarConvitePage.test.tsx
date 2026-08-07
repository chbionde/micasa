import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError } from 'axios'
import type { AxiosResponse, InternalAxiosRequestConfig } from 'axios'
import { api } from '../lib/api'
import { AceitarConvitePage } from './AceitarConvitePage'
import { renderComRotas } from '../test/utils'

vi.mock('../lib/api', async () => {
  const real = await vi.importActual<typeof import('../lib/api')>('../lib/api')
  return { ...real, api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }
})

const apiGet = vi.mocked(api.get)
const apiPost = vi.mocked(api.post)

const USUARIO = {
  data: { data: { id: 1, name: 'Maria', email: 'maria@exemplo.com.br', casa_ativa: null } },
}

function erro422(errors: Record<string, string[]>): AxiosError {
  return new AxiosError(
    'Unprocessable Content',
    'ERR_BAD_REQUEST',
    {} as InternalAxiosRequestConfig,
    null,
    { status: 422, data: { errors } } as AxiosResponse,
  )
}

function renderAceite() {
  renderComRotas(
    [
      { path: '/convite/:token', element: <AceitarConvitePage /> },
      { path: '/casa', element: <p>tela da casa</p> },
    ],
    '/convite/token-abc',
  )
}

describe('AceitarConvitePage', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    apiGet.mockResolvedValue(USUARIO)
  })

  it('aceita o convite e leva para a casa', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue({ data: { casa: { id: 7, nome: 'Casa de Carlos' } } })

    renderAceite()
    await user.click(await screen.findByRole('button', { name: 'Aceitar convite' }))

    expect(await screen.findByText('tela da casa')).toBeInTheDocument()
    expect(apiPost).toHaveBeenCalledWith('/api/invitations/token-abc/accept')
  })

  it('mostra a mensagem do servidor quando o convite não vale mais', async () => {
    const user = userEvent.setup()
    apiPost.mockRejectedValue(
      erro422({ token: ['Este convite não é mais válido. Peça um novo link a quem administra a casa.'] }),
    )

    renderAceite()
    await user.click(await screen.findByRole('button', { name: 'Aceitar convite' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('não é mais válido')
  })
})

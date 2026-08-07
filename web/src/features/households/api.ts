import { api } from '../../lib/api'
import type { Envelope } from '../../lib/api'
import type { Household, User } from '../auth/types'

export type Membro = {
  id: number
  nome: string
  email: string
  papel: 'admin' | 'member'
  papel_label: string
  sou_eu: boolean
}

export type Convite = {
  id: number
  papel: 'admin' | 'member'
  papel_label: string
  situacao: 'pendente' | 'aceito' | 'revogado' | 'expirado'
  expira_em: string
  criado_em: string | null
  criado_por?: string
}

export async function buscarCasas(): Promise<Household[]> {
  const { data } = await api.get<Envelope<Household[]>>('/api/households')
  return data.data
}

export async function trocarCasaAtiva(householdId: number): Promise<User> {
  const { data } = await api.put<Envelope<User>>('/api/user/active-household', {
    household_id: householdId,
  })
  return data.data
}

export async function renomearCasa(householdId: number, nome: string): Promise<Household> {
  const { data } = await api.patch<Envelope<Household>>(`/api/households/${householdId}`, { nome })
  return data.data
}

export async function buscarMembros(householdId: number): Promise<Membro[]> {
  const { data } = await api.get<Envelope<Membro[]>>(`/api/households/${householdId}/members`)
  return data.data
}

export async function alterarPapel(
  householdId: number,
  membroId: number,
  papel: 'admin' | 'member',
): Promise<Membro> {
  const { data } = await api.patch<Envelope<Membro>>(
    `/api/households/${householdId}/members/${membroId}`,
    { papel },
  )
  return data.data
}

export async function removerMembro(householdId: number, membroId: number): Promise<void> {
  await api.delete(`/api/households/${householdId}/members/${membroId}`)
}

export async function buscarConvites(householdId: number): Promise<Convite[]> {
  const { data } = await api.get<Envelope<Convite[]>>(`/api/households/${householdId}/invitations`)
  return data.data
}

/** O token vem fora do envelope e só nesta resposta — não dá para recuperá-lo depois. */
export async function criarConvite(
  householdId: number,
  papel: 'admin' | 'member',
): Promise<{ convite: Convite; token: string }> {
  const { data } = await api.post<Envelope<Convite> & { token: string }>(
    `/api/households/${householdId}/invitations`,
    { papel },
  )
  return { convite: data.data, token: data.token }
}

export async function revogarConvite(householdId: number, conviteId: number): Promise<void> {
  await api.delete(`/api/households/${householdId}/invitations/${conviteId}`)
}

export async function aceitarConvite(token: string): Promise<{ id: number; nome: string }> {
  const { data } = await api.post<{ casa: { id: number; nome: string } }>(
    `/api/invitations/${token}/accept`,
  )
  return data.casa
}

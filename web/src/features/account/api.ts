import { api } from '../../lib/api'
import type { Envelope } from '../../lib/api'
import type { User } from '../auth/types'

export async function atualizarPerfil(dados: {
  name: string
  email: string
  /** Só quando o e-mail muda — ver ContaPage e docs/seguranca.md (#43, A1). */
  current_password?: string
}): Promise<User> {
  const { data } = await api.patch<Envelope<User>>('/api/user/profile', dados)
  return data.data
}

export async function trocarSenha(dados: {
  current_password: string
  password: string
  password_confirmation: string
}): Promise<void> {
  await api.put('/api/user/password', dados)
}

export async function apagarConta(password: string): Promise<void> {
  await api.delete('/api/user', { data: { password } })
}

export async function pedirLinkDeRedefinicao(email: string): Promise<string> {
  const { data } = await api.post<{ message: string }>('/forgot-password', { email })
  return data.message
}

export async function redefinirSenha(dados: {
  token: string
  email: string
  password: string
  password_confirmation: string
}): Promise<void> {
  await api.post('/reset-password', dados)
}

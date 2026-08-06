import { createContext, useContext } from 'react'
import type { User } from './types'

export type AuthStatus = 'carregando' | 'autenticado' | 'visitante'

export type RegisterData = {
  name: string
  email: string
  password: string
  password_confirmation: string
}

export type AuthContextValue = {
  user: User | null
  status: AuthStatus
  login: (email: string, password: string) => Promise<void>
  register: (data: RegisterData) => Promise<void>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)

  if (ctx === null) {
    throw new Error('useAuth precisa estar dentro de <AuthProvider>')
  }

  return ctx
}

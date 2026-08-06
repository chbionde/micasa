import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { api } from '../../lib/api'
import { AuthContext } from './auth-context'
import type { AuthStatus, RegisterData } from './auth-context'
import type { User } from './types'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [status, setStatus] = useState<AuthStatus>('carregando')

  useEffect(() => {
    // A flag "ativo" descarta a resposta se o componente desmontar antes
    // dela chegar (StrictMode monta duas vezes em dev e exercita isso).
    let ativo = true

    api
      .get<User>('/api/user')
      .then((res) => {
        if (ativo) {
          setUser(res.data)
          setStatus('autenticado')
        }
      })
      .catch(() => {
        if (ativo) {
          setUser(null)
          setStatus('visitante')
        }
      })

    return () => {
      ativo = false
    }
  }, [])

  const carregarUsuario = useCallback(async () => {
    const { data } = await api.get<User>('/api/user')
    setUser(data)
    setStatus('autenticado')
  }, [])

  const login = useCallback(
    async (email: string, password: string) => {
      await api.get('/sanctum/csrf-cookie')
      await api.post('/login', { email, password })
      await carregarUsuario()
    },
    [carregarUsuario],
  )

  const register = useCallback(
    async (data: RegisterData) => {
      await api.get('/sanctum/csrf-cookie')
      await api.post('/register', data)
      await carregarUsuario()
    },
    [carregarUsuario],
  )

  const logout = useCallback(async () => {
    await api.post('/logout')
    setUser(null)
    setStatus('visitante')
  }, [])

  const value = useMemo(
    () => ({ user, status, login, register, logout }),
    [user, status, login, register, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { api } from '../../lib/api'
import type { Envelope } from '../../lib/api'
import { AuthContext } from './auth-context'
import type { AuthStatus, RegisterData } from './auth-context'
import type { User } from './types'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [status, setStatus] = useState<AuthStatus>('carregando')
  const geracaoSessao = useRef(0)

  useEffect(() => {
    // A flag "ativo" descarta a resposta se o componente desmontar antes
    // dela chegar (StrictMode monta duas vezes em dev e exercita isso).
    let ativo = true
    const geracaoDaRequisicao = geracaoSessao.current

    api
      .get<Envelope<User>>('/api/user')
      .then((res) => {
        if (ativo && geracaoDaRequisicao === geracaoSessao.current) {
          setUser(res.data.data)
          setStatus('autenticado')
        }
      })
      .catch(() => {
        if (ativo && geracaoDaRequisicao === geracaoSessao.current) {
          setUser(null)
          setStatus('visitante')
        }
      })

    return () => {
      ativo = false
    }
  }, [])

  const carregarUsuario = useCallback(async () => {
    const geracaoDaRequisicao = geracaoSessao.current
    const { data } = await api.get<Envelope<User>>('/api/user')

    // Uma resposta iniciada antes do encerramento local pertence à sessão
    // anterior e não pode autenticar novamente a interface.
    if (geracaoDaRequisicao !== geracaoSessao.current) return

    setUser(data.data)
    setStatus('autenticado')
  }, [])

  const encerrarSessaoLocal = useCallback(() => {
    geracaoSessao.current += 1
    setUser(null)
    setStatus('visitante')
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
    encerrarSessaoLocal()
  }, [encerrarSessaoLocal])

  const value = useMemo(
    () => ({
      user,
      status,
      login,
      register,
      logout,
      encerrarSessaoLocal,
      recarregar: carregarUsuario,
    }),
    [user, status, login, register, logout, encerrarSessaoLocal, carregarUsuario],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

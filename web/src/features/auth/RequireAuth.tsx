import { Navigate, Outlet } from 'react-router'
import { useAuth } from './auth-context'

/**
 * Guarda das rotas privadas: visitante é mandado para /login.
 * Enquanto a sessão é verificada, mostra um estado de carregamento —
 * sem ele, o visitante veria a tela privada piscar antes do redirect.
 */
export function RequireAuth() {
  const { status } = useAuth()

  if (status === 'carregando') {
    return (
      <div className="flex min-h-dvh items-center justify-center text-stone-500">
        Carregando…
      </div>
    )
  }

  if (status === 'visitante') {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}

import { Navigate, Outlet, useLocation } from 'react-router'
import { useAuth } from './auth-context'

/**
 * Guarda das rotas privadas: visitante é mandado para /login.
 * Enquanto a sessão é verificada, mostra um estado de carregamento —
 * sem ele, o visitante veria a tela privada piscar antes do redirect.
 */
export function RequireAuth() {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'carregando') {
    return (
      <div className="flex min-h-dvh items-center justify-center text-stone-500">
        Carregando…
      </div>
    )
  }

  if (status === 'visitante') {
    // Guardamos de onde a pessoa veio: quem clicou num link de convite
    // precisa voltar para ele depois de entrar, não cair no início.
    return <Navigate to="/login" state={{ de: location.pathname }} replace />
  }

  return <Outlet />
}

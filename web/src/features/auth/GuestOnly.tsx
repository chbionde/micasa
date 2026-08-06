import { Navigate, Outlet } from 'react-router'
import { useAuth } from './auth-context'

/** Inverso do RequireAuth: quem já tem sessão não vê login/registro. */
export function GuestOnly() {
  const { status } = useAuth()

  if (status === 'carregando') {
    return (
      <div className="flex min-h-dvh items-center justify-center text-stone-500">
        Carregando…
      </div>
    )
  }

  if (status === 'autenticado') {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}

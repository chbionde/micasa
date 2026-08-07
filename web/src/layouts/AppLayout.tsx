import { NavLink, Outlet } from 'react-router'
import { useAuth } from '../features/auth/auth-context'
import { SeletorCasa } from '../features/households/SeletorCasa'

const linkClasses = ({ isActive }: { isActive: boolean }) =>
  `rounded-lg px-3 py-1.5 text-sm font-medium ${
    isActive ? 'bg-emerald-50 text-emerald-800' : 'text-stone-600 hover:bg-stone-100'
  }`

/** Casca das telas autenticadas: cabeçalho fixo + conteúdo da rota. */
export function AppLayout() {
  const { user, logout } = useAuth()

  return (
    <div className="min-h-dvh bg-stone-50">
      <header className="sticky top-0 border-b border-stone-200 bg-white">
        <div className="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-2 px-4 py-3">
          <span className="text-lg font-bold text-emerald-700">MiCasa</span>

          <nav className="flex items-center gap-1">
            <NavLink to="/" className={linkClasses} end>
              Início
            </NavLink>
            <NavLink to="/casa" className={linkClasses}>
              Casa
            </NavLink>
          </nav>

          <div className="flex items-center gap-3">
            <SeletorCasa />
            <span className="hidden text-sm text-stone-600 sm:inline">{user?.name}</span>
            <button
              type="button"
              onClick={() => void logout()}
              className="rounded-lg px-3 py-1.5 text-sm font-medium text-stone-600 hover:bg-stone-100"
            >
              Sair
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-6">
        <Outlet />
      </main>
    </div>
  )
}

import { Link } from 'react-router'
import { useAuth } from '../features/auth/auth-context'

export function DashboardPage() {
  const { user } = useAuth()

  return (
    <section className="space-y-3">
      <h1 className="text-xl font-bold text-stone-900">Olá, {user?.name} 👋</h1>

      {user?.casa_ativa !== null && user?.casa_ativa !== undefined ? (
        <p className="text-stone-600">
          Você está em <strong>{user.casa_ativa.nome}</strong>. Veja{' '}
          <Link to="/casa" className="font-medium text-emerald-700 hover:underline">
            quem mora aqui
          </Link>{' '}
          ou convide alguém. As listas de compras chegam na próxima fatia.
        </p>
      ) : (
        <p className="text-stone-600">
          Você ainda não está em nenhuma casa. Peça um link de convite a quem administra uma.
        </p>
      )}
    </section>
  )
}

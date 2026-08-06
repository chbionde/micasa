import { useAuth } from '../features/auth/auth-context'

export function DashboardPage() {
  const { user } = useAuth()

  return (
    <section className="space-y-2">
      <h1 className="text-xl font-bold text-stone-900">Olá, {user?.name} 👋</h1>
      <p className="text-stone-600">
        Sua sessão está ativa. As listas de compras chegam na próxima fatia.
      </p>
    </section>
  )
}

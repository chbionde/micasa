import { useAuth } from '../features/auth/auth-context'
import { ListaMembros } from '../features/households/ListaMembros'
import { PainelConvites } from '../features/households/PainelConvites'

export function CasaPage() {
  const { user } = useAuth()
  const casa = user?.casa_ativa

  if (casa === null || casa === undefined) {
    return (
      <p className="text-stone-600">
        Você não está em nenhuma casa no momento. Peça um link de convite a quem administra uma.
      </p>
    )
  }

  const souAdmin = casa.meu_papel === 'admin'

  return (
    <div className="space-y-8">
      <header>
        <h1 className="text-xl font-bold text-stone-900">{casa.nome}</h1>
        <p className="text-sm text-stone-500">
          Você é {souAdmin ? 'administrador' : 'membro'} desta casa.
        </p>
      </header>

      <ListaMembros casaId={casa.id} souAdmin={souAdmin} />

      {souAdmin && <PainelConvites casaId={casa.id} />}
    </div>
  )
}

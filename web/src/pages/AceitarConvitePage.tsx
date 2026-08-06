import { useQueryClient } from '@tanstack/react-query'
import { useMutation } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { useAuth } from '../features/auth/auth-context'
import { aceitarConvite } from '../features/households/api'
import { getValidationErrors } from '../lib/validation'

export function AceitarConvitePage() {
  const { token = '' } = useParams()
  const { user, recarregar } = useAuth()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const aceite = useMutation({
    mutationFn: () => aceitarConvite(token),
    onSuccess: async () => {
      await recarregar()
      await queryClient.invalidateQueries()
      navigate('/casa', { replace: true })
    },
  })

  const erro = aceite.error
  const mensagemErro =
    erro !== null ? (getValidationErrors(erro)?.token?.[0] ?? 'Não foi possível aceitar o convite.') : null

  return (
    <div className="mx-auto max-w-sm space-y-4 py-8">
      <h1 className="text-xl font-bold text-stone-900">Convite para uma casa</h1>
      <p className="text-stone-600">
        Olá, {user?.name}. Ao aceitar, você passa a participar desta casa no MiCasa.
      </p>

      {mensagemErro !== null && (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
          {mensagemErro}
        </p>
      )}

      <button
        type="button"
        onClick={() => aceite.mutate()}
        disabled={aceite.isPending}
        className="w-full rounded-lg bg-emerald-700 py-2.5 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
      >
        {aceite.isPending ? 'Entrando…' : 'Aceitar convite'}
      </button>
    </div>
  )
}

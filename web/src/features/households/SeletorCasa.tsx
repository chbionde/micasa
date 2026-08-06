import { useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../auth/auth-context'
import { trocarCasaAtiva } from './api'
import { useCasas } from './hooks'

/**
 * Só aparece quando a pessoa participa de mais de uma casa — com uma casa
 * só, o seletor seria ruído permanente no cabeçalho.
 */
export function SeletorCasa() {
  const { user, recarregar } = useAuth()
  const { data: casas } = useCasas()
  const queryClient = useQueryClient()

  if (casas === undefined || casas.length < 2) {
    return null
  }

  async function aoTrocar(householdId: number) {
    await trocarCasaAtiva(householdId)
    await recarregar()
    // Tudo que dependia da casa anterior está velho.
    await queryClient.invalidateQueries()
  }

  return (
    <label className="flex items-center gap-2 text-sm">
      <span className="sr-only">Casa ativa</span>
      <select
        value={user?.casa_ativa?.id ?? ''}
        onChange={(e) => void aoTrocar(Number(e.target.value))}
        className="rounded-lg border border-stone-300 bg-white px-2 py-1 text-stone-700"
      >
        {casas.map((casa) => (
          <option key={casa.id} value={casa.id}>
            {casa.nome}
          </option>
        ))}
      </select>
    </label>
  )
}

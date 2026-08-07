import { useState } from 'react'
import type { FormEvent } from 'react'
import { useAuth } from '../auth/auth-context'
import { CampoTexto } from '../../components/CampoTexto'
import { getValidationErrors } from '../../lib/validation'
import { useRenomearCasa } from './hooks'

type Props = { casaId: number; nome: string; souAdmin: boolean }

export function NomeDaCasa({ casaId, nome, souAdmin }: Props) {
  const { recarregar } = useAuth()
  const renomear = useRenomearCasa(casaId)
  const [editando, setEditando] = useState(false)
  const [rascunho, setRascunho] = useState(nome)

  function salvar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    renomear.mutate(rascunho, {
      onSuccess: async () => {
        // A casa ativa vive no contexto de auth; sem recarregar, o
        // cabeçalho continuaria mostrando o nome antigo.
        await recarregar()
        setEditando(false)
      },
    })
  }

  if (!editando) {
    return (
      <div className="flex flex-wrap items-center gap-2">
        <h1 className="text-xl font-bold text-stone-900">{nome}</h1>
        {souAdmin && (
          <button
            type="button"
            onClick={() => {
              setRascunho(nome)
              setEditando(true)
            }}
            className="rounded-lg px-2 py-1 text-sm font-medium text-emerald-700 hover:bg-emerald-50"
          >
            Renomear
          </button>
        )}
      </div>
    )
  }

  return (
    <form onSubmit={salvar} className="flex flex-wrap items-end gap-2" noValidate>
      <div className="min-w-48 flex-1">
        <CampoTexto
          id="nome-da-casa"
          label="Nome da casa"
          value={rascunho}
          onChange={setRascunho}
          erro={getValidationErrors(renomear.error)?.nome?.[0]}
        />
      </div>
      <button
        type="submit"
        disabled={renomear.isPending}
        className="rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
      >
        Salvar
      </button>
      <button
        type="button"
        onClick={() => setEditando(false)}
        className="rounded-lg px-3 py-2 text-stone-600 hover:bg-stone-100"
      >
        Cancelar
      </button>
    </form>
  )
}

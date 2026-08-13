import { useAuth } from '../auth/auth-context'
import type { Membro } from './api'
import { useAlterarPapel, useMembros, useRemoverMembro } from './hooks'

type Props = { casaId: number; souAdmin: boolean }

export function ListaMembros({ casaId, souAdmin }: Props) {
  const { recarregar } = useAuth()
  const { data: membros, isPending, isError } = useMembros(casaId)
  const alterarPapel = useAlterarPapel(casaId)
  const removerMembro = useRemoverMembro(casaId)

  if (isPending) return <p className="text-stone-500">Carregando membros…</p>
  if (isError) return <p role="alert" className="text-red-700">Não foi possível carregar os membros.</p>

  const erro = alterarPapel.error ?? removerMembro.error
  const souAUltimaPessoa = membros.length === 1

  function confirmarESair(membro: Membro) {
    // Sair sendo a última pessoa apaga a casa — o aviso precisa dizer isso
    // com todas as letras antes de a ação acontecer.
    const pergunta = membro.sou_eu
      ? souAUltimaPessoa
        ? 'Você é a única pessoa nesta casa. Ao sair, a casa e os convites pendentes serão apagados. Tem certeza?'
        : 'Tem certeza que deseja sair desta casa?'
      : `Remover ${membro.nome} desta casa?`

    if (!window.confirm(pergunta)) return

    removerMembro.mutate({ membroId: membro.id, souEu: membro.sou_eu }, {
      onSuccess: async () => {
        if (membro.sou_eu) await recarregar()
      },
    })
  }

  return (
    <section className="space-y-3">
      <h2 className="text-lg font-semibold text-stone-900">Quem mora aqui</h2>

      {erro !== null && (
        <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
          {mensagemDeErro(erro)}
        </p>
      )}

      <ul className="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        {membros.map((membro) => (
          <li key={membro.id} className="flex flex-wrap items-center gap-3 p-3">
            <div className="min-w-0 flex-1">
              <p className="truncate font-medium text-stone-900">
                {membro.nome}
                {membro.sou_eu && <span className="text-stone-500"> (você)</span>}
              </p>
              <p className="truncate text-sm text-stone-500">{membro.email}</p>
            </div>

            {souAdmin ? (
              <select
                aria-label={`Papel de ${membro.nome}`}
                value={membro.papel}
                onChange={(e) =>
                  alterarPapel.mutate({
                    membroId: membro.id,
                    papel: e.target.value as Membro['papel'],
                  })
                }
                className="rounded-lg border border-stone-300 px-2 py-1 text-sm"
              >
                <option value="admin">Administrador</option>
                <option value="member">Membro</option>
              </select>
            ) : (
              <span className="text-sm text-stone-600">{membro.papel_label}</span>
            )}

            {(souAdmin || membro.sou_eu) && (
              <button
                type="button"
                onClick={() => confirmarESair(membro)}
                className="rounded-lg px-2 py-1 text-sm font-medium text-red-700 hover:bg-red-50"
              >
                {membro.sou_eu ? 'Sair da casa' : 'Remover'}
              </button>
            )}
          </li>
        ))}
      </ul>
    </section>
  )
}

function mensagemDeErro(erro: unknown): string {
  const resposta = (erro as { response?: { data?: { message?: string } } }).response
  return resposta?.data?.message ?? 'Não foi possível concluir a ação.'
}

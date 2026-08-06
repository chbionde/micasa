import { useState } from 'react'
import { useConvites, useCriarConvite, useRevogarConvite } from './hooks'

type Props = { casaId: number }

export function PainelConvites({ casaId }: Props) {
  const { data: convites } = useConvites(casaId, true)
  const criarConvite = useCriarConvite(casaId)
  const revogarConvite = useRevogarConvite(casaId)
  const [linkGerado, setLinkGerado] = useState<string | null>(null)
  const [copiado, setCopiado] = useState(false)

  function gerar() {
    setCopiado(false)
    criarConvite.mutate('member', {
      onSuccess: ({ token }) => {
        setLinkGerado(`${window.location.origin}/convite/${token}`)
      },
    })
  }

  async function copiar() {
    if (linkGerado === null) return
    await navigator.clipboard.writeText(linkGerado)
    setCopiado(true)
  }

  const pendentes = convites?.filter((c) => c.situacao === 'pendente') ?? []

  return (
    <section className="space-y-3">
      <h2 className="text-lg font-semibold text-stone-900">Convidar alguém</h2>

      <button
        type="button"
        onClick={gerar}
        disabled={criarConvite.isPending}
        className="rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
      >
        {criarConvite.isPending ? 'Gerando…' : 'Gerar link de convite'}
      </button>

      {linkGerado !== null && (
        <div className="space-y-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3">
          <p className="text-sm text-emerald-900">
            Mande este link para quem vai entrar. Ele aparece só agora — se perder, gere outro.
          </p>
          <code className="block overflow-x-auto rounded-lg bg-white p-2 text-xs text-stone-700">
            {linkGerado}
          </code>
          <button
            type="button"
            onClick={() => void copiar()}
            className="rounded-lg border border-emerald-700 px-3 py-1.5 text-sm font-medium text-emerald-800"
          >
            {copiado ? 'Copiado!' : 'Copiar link'}
          </button>
        </div>
      )}

      {pendentes.length > 0 && (
        <ul className="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
          {pendentes.map((convite) => (
            <li key={convite.id} className="flex items-center justify-between gap-3 p-3">
              <span className="text-sm text-stone-700">
                Convite de {convite.papel_label.toLowerCase()} · expira em{' '}
                {new Date(convite.expira_em).toLocaleDateString('pt-BR')}
              </span>
              <button
                type="button"
                onClick={() => revogarConvite.mutate(convite.id)}
                className="rounded-lg px-2 py-1 text-sm font-medium text-red-700 hover:bg-red-50"
              >
                Revogar
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

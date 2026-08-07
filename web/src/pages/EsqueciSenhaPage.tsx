import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router'
import { CampoTexto } from '../components/CampoTexto'
import { pedirLinkDeRedefinicao } from '../features/account/api'
import { getValidationErrors } from '../lib/validation'

export function EsqueciSenhaPage() {
  const [email, setEmail] = useState('')
  const [mensagem, setMensagem] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)

  async function submeter(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setEnviando(true)
    setErro(null)

    try {
      // A resposta é a mesma existindo ou não a conta — a tela não pode
      // revelar quem tem cadastro (mesma razão do lado da API).
      setMensagem(await pedirLinkDeRedefinicao(email))
    } catch (e) {
      setErro(getValidationErrors(e)?.email?.[0] ?? 'Não foi possível enviar agora. Tente de novo.')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-stone-50 px-4">
      <div className="w-full max-w-sm">
        <h1 className="mb-6 text-center text-2xl font-bold text-emerald-700">Recuperar acesso</h1>

        {mensagem !== null ? (
          <div className="space-y-4 rounded-2xl border border-stone-200 bg-white p-6">
            <p className="text-stone-700">{mensagem}</p>
            <Link to="/login" className="block text-center font-medium text-emerald-700 hover:underline">
              Voltar para entrar
            </Link>
          </div>
        ) : (
          <form
            onSubmit={(e) => void submeter(e)}
            className="space-y-4 rounded-2xl border border-stone-200 bg-white p-6"
            noValidate
          >
            <p className="text-sm text-stone-600">
              Informe seu e-mail e enviaremos um link para você criar uma senha nova.
            </p>

            <CampoTexto
              id="email"
              label="E-mail"
              type="email"
              value={email}
              onChange={setEmail}
              erro={erro ?? undefined}
              autoComplete="email"
            />

            <button
              type="submit"
              disabled={enviando}
              className="w-full rounded-lg bg-emerald-700 py-2.5 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
            >
              {enviando ? 'Enviando…' : 'Enviar link'}
            </button>
          </form>
        )}

        <p className="mt-4 text-center text-sm text-stone-600">
          <Link to="/login" className="font-medium text-emerald-700 hover:underline">
            Lembrei minha senha
          </Link>
        </p>
      </div>
    </div>
  )
}

import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { CampoTexto } from '../components/CampoTexto'
import { redefinirSenha } from '../features/account/api'
import { getValidationErrors } from '../lib/validation'
import type { ValidationErrors } from '../lib/validation'

export function RedefinirSenhaPage() {
  const { token = '' } = useParams()
  // O e-mail vem na query do link enviado por e-mail; o campo fica
  // editável para o caso de alguém abrir o link truncado.
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()

  const [email, setEmail] = useState(searchParams.get('email') ?? '')
  const [password, setPassword] = useState('')
  const [confirmacao, setConfirmacao] = useState('')
  const [erros, setErros] = useState<ValidationErrors | null>(null)
  const [enviando, setEnviando] = useState(false)

  async function submeter(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setEnviando(true)
    setErros(null)

    try {
      await redefinirSenha({
        token,
        email,
        password,
        password_confirmation: confirmacao,
      })
      navigate('/entrar', { replace: true })
    } catch (erro) {
      setErros(getValidationErrors(erro) ?? { email: ['Não foi possível redefinir a senha.'] })
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-stone-50 px-4">
      <div className="w-full max-w-sm">
        <h1 className="mb-6 text-center text-2xl font-bold text-emerald-700">Criar nova senha</h1>

        <form
          onSubmit={(e) => void submeter(e)}
          className="space-y-4 rounded-2xl border border-stone-200 bg-white p-6"
          noValidate
        >
          <CampoTexto
            id="email"
            label="E-mail"
            type="email"
            value={email}
            onChange={setEmail}
            erro={erros?.email?.[0]}
            autoComplete="email"
          />
          <CampoTexto
            id="password"
            label="Nova senha"
            type="password"
            value={password}
            onChange={setPassword}
            erro={erros?.password?.[0]}
            autoComplete="new-password"
          />
          <CampoTexto
            id="password_confirmation"
            label="Confirme a nova senha"
            type="password"
            value={confirmacao}
            onChange={setConfirmacao}
            autoComplete="new-password"
          />

          <button
            type="submit"
            disabled={enviando}
            className="w-full rounded-lg bg-emerald-700 py-2.5 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
          >
            {enviando ? 'Salvando…' : 'Salvar nova senha'}
          </button>
        </form>

        <p className="mt-4 text-center text-sm text-stone-600">
          <Link to="/esqueci-senha" className="font-medium text-emerald-700 hover:underline">
            Pedir um novo link
          </Link>
        </p>
      </div>
    </div>
  )
}

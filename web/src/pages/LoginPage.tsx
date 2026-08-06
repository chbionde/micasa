import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { CampoTexto } from '../components/CampoTexto'
import { useAuth } from '../features/auth/auth-context'
import { getValidationErrors } from '../lib/validation'
import type { ValidationErrors } from '../lib/validation'

export function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [erros, setErros] = useState<ValidationErrors | null>(null)
  const [enviando, setEnviando] = useState(false)

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setEnviando(true)
    setErros(null)

    try {
      await login(email, password)
      navigate('/', { replace: true })
    } catch (error) {
      setErros(
        getValidationErrors(error) ?? {
          email: ['Não foi possível entrar. Tente novamente em instantes.'],
        },
      )
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-stone-50 px-4">
      <div className="w-full max-w-sm">
        <h1 className="mb-6 text-center text-2xl font-bold text-emerald-700">MiCasa</h1>

        <form
          onSubmit={(e) => void handleSubmit(e)}
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
            label="Senha"
            type="password"
            value={password}
            onChange={setPassword}
            erro={erros?.password?.[0]}
            autoComplete="current-password"
          />

          <button
            type="submit"
            disabled={enviando}
            className="w-full rounded-lg bg-emerald-700 py-2.5 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
          >
            {enviando ? 'Entrando…' : 'Entrar'}
          </button>
        </form>

        <p className="mt-4 text-center text-sm text-stone-600">
          Primeira vez aqui?{' '}
          <Link to="/registrar" className="font-medium text-emerald-700 hover:underline">
            Crie sua conta
          </Link>
        </p>
      </div>
    </div>
  )
}

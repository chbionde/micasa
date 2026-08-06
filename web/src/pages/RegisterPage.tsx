import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { CampoTexto } from '../components/CampoTexto'
import { useAuth } from '../features/auth/auth-context'
import { getValidationErrors } from '../lib/validation'
import type { ValidationErrors } from '../lib/validation'

export function RegisterPage() {
  const { register } = useAuth()
  const navigate = useNavigate()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [householdName, setHouseholdName] = useState('')
  const [erros, setErros] = useState<ValidationErrors | null>(null)
  const [enviando, setEnviando] = useState(false)

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setEnviando(true)
    setErros(null)

    try {
      await register({
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
        household_name: householdName.trim() || undefined,
      })
      navigate('/', { replace: true })
    } catch (error) {
      setErros(
        getValidationErrors(error) ?? {
          email: ['Não foi possível criar a conta. Tente novamente em instantes.'],
        },
      )
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="flex min-h-dvh items-center justify-center bg-stone-50 px-4">
      <div className="w-full max-w-sm">
        <h1 className="mb-6 text-center text-2xl font-bold text-emerald-700">
          Criar conta no MiCasa
        </h1>

        <form
          onSubmit={(e) => void handleSubmit(e)}
          className="space-y-4 rounded-2xl border border-stone-200 bg-white p-6"
          noValidate
        >
          <CampoTexto
            id="name"
            label="Nome"
            value={name}
            onChange={setName}
            erro={erros?.name?.[0]}
            autoComplete="name"
          />
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
            id="household_name"
            label="Nome da casa (opcional)"
            value={householdName}
            onChange={setHouseholdName}
            erro={erros?.household_name?.[0]}
            ajuda="Em branco, usamos “Casa de {seu primeiro nome}”."
          />
          <CampoTexto
            id="password"
            label="Senha"
            type="password"
            value={password}
            onChange={setPassword}
            erro={erros?.password?.[0]}
            autoComplete="new-password"
          />
          <CampoTexto
            id="password_confirmation"
            label="Confirme a senha"
            type="password"
            value={passwordConfirmation}
            onChange={setPasswordConfirmation}
            autoComplete="new-password"
          />

          <button
            type="submit"
            disabled={enviando}
            className="w-full rounded-lg bg-emerald-700 py-2.5 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
          >
            {enviando ? 'Criando…' : 'Criar conta'}
          </button>
        </form>

        <p className="mt-4 text-center text-sm text-stone-600">
          Já tem conta?{' '}
          <Link to="/login" className="font-medium text-emerald-700 hover:underline">
            Entre
          </Link>
        </p>
      </div>
    </div>
  )
}

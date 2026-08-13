import { useState } from 'react'
import type { FormEvent } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { CampoTexto } from '../components/CampoTexto'
import { useAuth } from '../features/auth/auth-context'
import { apagarConta, atualizarPerfil, trocarSenha } from '../features/account/api'
import { getValidationErrors } from '../lib/validation'
import type { ValidationErrors } from '../lib/validation'

export function ContaPage() {
  const { user, recarregar, encerrarSessaoLocal } = useAuth()
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  // SecaoPerfil inicializa o estado a partir das props, e useState só
  // aproveita o valor da PRIMEIRA renderização. Renderizar antes de o
  // usuário chegar deixaria os campos vazios para sempre.
  if (user === null) {
    return <p className="text-stone-500">Carregando…</p>
  }

  return (
    <div className="space-y-10">
      <h1 className="text-xl font-bold text-stone-900">Sua conta</h1>

      <SecaoPerfil nomeInicial={user.name} emailInicial={user.email} aoSalvar={recarregar} />
      <SecaoSenha />
      <SecaoExcluir
        aoExcluir={async () => {
          // O DELETE já apaga todas as sessões no servidor. Chamar /logout
          // depois dele sempre recebe 401 e impediria esta limpeza local.
          queryClient.clear()
          encerrarSessaoLocal()
          navigate('/entrar', { replace: true })
        }}
      />
    </div>
  )
}

function SecaoPerfil({
  nomeInicial,
  emailInicial,
  aoSalvar,
}: {
  nomeInicial: string
  emailInicial: string
  aoSalvar: () => Promise<void>
}) {
  const [name, setName] = useState(nomeInicial)
  const [email, setEmail] = useState(emailInicial)
  const [senhaAtual, setSenhaAtual] = useState('')
  const [erros, setErros] = useState<ValidationErrors | null>(null)
  const [salvo, setSalvo] = useState(false)
  const [enviando, setEnviando] = useState(false)

  // VALOR DERIVADO, não estado. `emailMudou` é uma pergunta que se responde
  // olhando para `email` e `emailInicial`, e ambos já estão aqui — então ele
  // se calcula a cada render, de graça.
  //
  // A alternativa comum seria um `useState(false)` mais um `useEffect` que o
  // sincroniza a cada tecla. Isso custa um render extra e cria uma janela em
  // que `email` já mudou e `emailMudou` ainda diz que não: dois fatos sobre a
  // mesma coisa, com chance de discordarem. A regra prática: se dá para
  // calcular a partir do que você já tem, não guarde.
  const emailMudou = email.trim().toLowerCase() !== emailInicial.toLowerCase()

  async function submeter(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setEnviando(true)
    setErros(null)
    setSalvo(false)

    try {
      // A senha só viaja quando é exigida. Mandá-la sempre significaria a
      // senha da pessoa cruzando a rede a cada troca de nome, sem motivo.
      await atualizarPerfil({
        name,
        email,
        ...(emailMudou ? { current_password: senhaAtual } : {}),
      })
      await aoSalvar()
      setSenhaAtual('')
      setSalvo(true)
    } catch (erro) {
      setErros(getValidationErrors(erro) ?? { email: ['Não foi possível salvar.'] })
    } finally {
      setEnviando(false)
    }
  }

  return (
    <form onSubmit={(e) => void submeter(e)} className="space-y-4" noValidate>
      <h2 className="text-lg font-semibold text-stone-900">Dados pessoais</h2>

      <CampoTexto id="conta-nome" label="Nome" value={name} onChange={setName} erro={erros?.name?.[0]} />
      <CampoTexto
        id="conta-email"
        label="E-mail"
        type="email"
        value={email}
        onChange={setEmail}
        erro={erros?.email?.[0]}
      />

      {/*
        O campo aparece só quando é preciso. `emailMudou && <campo/>` é
        renderização condicional pura: quando é falso, o React não põe nada no
        DOM — o campo não fica escondido por CSS, ele não existe. Quem navega
        por teclado ou leitor de tela não tropeça num campo invisível.

        O texto explica POR QUE a senha está sendo pedida. Pedir senha sem
        motivo declarado é o que ensina as pessoas a digitá-la em qualquer
        lugar que peça.
      */}
      {emailMudou && (
        <CampoTexto
          id="conta-senha-atual"
          // "Senha atual" seria ambíguo: a seção de trocar senha, logo abaixo,
          // já usa esse rótulo. Dois campos com o mesmo nome acessível deixam
          // quem usa leitor de tela sem saber qual é qual.
          label="Confirme sua senha"
          type="password"
          value={senhaAtual}
          onChange={setSenhaAtual}
          erro={erros?.current_password?.[0]}
          autoComplete="current-password"
          ajuda="Trocar o e-mail muda para onde vai o link de recuperação da conta, por isso pedimos sua senha."
        />
      )}

      {salvo && <p className="text-sm text-emerald-700">Dados atualizados.</p>}

      <button
        type="submit"
        disabled={enviando}
        className="rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
      >
        Salvar dados
      </button>
    </form>
  )
}

function SecaoSenha() {
  const [atual, setAtual] = useState('')
  const [nova, setNova] = useState('')
  const [confirmacao, setConfirmacao] = useState('')
  const [erros, setErros] = useState<ValidationErrors | null>(null)
  const [trocada, setTrocada] = useState(false)
  const [enviando, setEnviando] = useState(false)

  async function submeter(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setEnviando(true)
    setErros(null)
    setTrocada(false)

    try {
      await trocarSenha({
        current_password: atual,
        password: nova,
        password_confirmation: confirmacao,
      })
      setAtual('')
      setNova('')
      setConfirmacao('')
      setTrocada(true)
    } catch (erro) {
      setErros(getValidationErrors(erro) ?? { password: ['Não foi possível trocar a senha.'] })
    } finally {
      setEnviando(false)
    }
  }

  return (
    <form onSubmit={(e) => void submeter(e)} className="space-y-4" noValidate>
      <h2 className="text-lg font-semibold text-stone-900">Trocar senha</h2>

      <CampoTexto
        id="senha-atual"
        label="Senha atual"
        type="password"
        value={atual}
        onChange={setAtual}
        erro={erros?.current_password?.[0]}
        autoComplete="current-password"
      />
      <CampoTexto
        id="senha-nova"
        label="Nova senha"
        type="password"
        value={nova}
        onChange={setNova}
        erro={erros?.password?.[0]}
        autoComplete="new-password"
      />
      <CampoTexto
        id="senha-confirmacao"
        label="Confirme a nova senha"
        type="password"
        value={confirmacao}
        onChange={setConfirmacao}
        autoComplete="new-password"
      />

      {trocada && <p className="text-sm text-emerald-700">Senha trocada.</p>}

      <button
        type="submit"
        disabled={enviando}
        className="rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-50"
      >
        Trocar senha
      </button>
    </form>
  )
}

function SecaoExcluir({ aoExcluir }: { aoExcluir: () => Promise<void> }) {
  const [senha, setSenha] = useState('')
  const [erros, setErros] = useState<ValidationErrors | null>(null)
  const [confirmando, setConfirmando] = useState(false)
  const [enviando, setEnviando] = useState(false)

  async function submeter(e: FormEvent<HTMLFormElement>) {
    e.preventDefault()
    setEnviando(true)
    setErros(null)

    try {
      await apagarConta(senha)
      await aoExcluir()
    } catch (erro) {
      setErros(getValidationErrors(erro) ?? { password: ['Não foi possível apagar a conta.'] })
    } finally {
      setEnviando(false)
    }
  }

  return (
    <section className="space-y-3 rounded-xl border border-red-200 bg-red-50 p-4">
      <h2 className="text-lg font-semibold text-red-900">Apagar conta</h2>
      <p className="text-sm text-red-900">
        Esta ação é permanente. As casas em que você mora sozinho serão apagadas junto.
      </p>

      {!confirmando ? (
        <button
          type="button"
          onClick={() => setConfirmando(true)}
          className="rounded-lg border border-red-700 px-4 py-2 font-medium text-red-800 hover:bg-red-100"
        >
          Quero apagar minha conta
        </button>
      ) : (
        <form onSubmit={(e) => void submeter(e)} className="space-y-3" noValidate>
          <CampoTexto
            id="excluir-senha"
            label="Confirme sua senha para apagar"
            type="password"
            value={senha}
            onChange={setSenha}
            erro={erros?.password?.[0]}
            autoComplete="current-password"
          />
          <div className="flex gap-2">
            <button
              type="submit"
              disabled={enviando}
              className="rounded-lg bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800 disabled:opacity-50"
            >
              Apagar definitivamente
            </button>
            <button
              type="button"
              onClick={() => setConfirmando(false)}
              className="rounded-lg px-3 py-2 text-stone-700 hover:bg-stone-100"
            >
              Cancelar
            </button>
          </div>
        </form>
      )}
    </section>
  )
}

type CampoTextoProps = {
  id: string
  label: string
  type?: 'text' | 'email' | 'password'
  value: string
  onChange: (valor: string) => void
  erro?: string
  autoComplete?: string
}

/**
 * Campo controlado: o valor mora no estado do componente pai e desce via
 * prop; cada tecla sobe pelo onChange. Uma única responsabilidade —
 * label + input + mensagem de erro, com a fiação de acessibilidade.
 */
export function CampoTexto({
  id,
  label,
  type = 'text',
  value,
  onChange,
  erro,
  autoComplete,
}: CampoTextoProps) {
  const erroId = `${id}-erro`

  return (
    <div className="space-y-1">
      <label htmlFor={id} className="block text-sm font-medium text-stone-700">
        {label}
      </label>
      <input
        id={id}
        type={type}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        autoComplete={autoComplete}
        aria-invalid={erro !== undefined}
        aria-describedby={erro !== undefined ? erroId : undefined}
        className="w-full rounded-lg border border-stone-300 px-3 py-2 text-stone-900 focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/30"
      />
      {erro !== undefined && (
        <p id={erroId} role="alert" className="text-sm text-red-700">
          {erro}
        </p>
      )}
    </div>
  )
}
